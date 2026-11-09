<?php
/**
 * The publisher's full category tree: its root (navigation) categories and every
 * sub-category below them, from Evvnt's category API (the discovery plugin's
 * MarketingClient: GET https://api.evvnt.com/categories/{id} lists a category's children).
 *
 * Walking the tree takes one request per category (several hundred: minutes when requests
 * cannot run in parallel), so it is only ever built in the background (WP-Cron) and stored in
 * one option, refreshed daily. Until the first build finishes, pages see the root categories
 * only; a single sub-category URL is still resolved meanwhile by walking just its own path
 * (a few small, cached requests).
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Category_Tree {

	const OPTION        = 'evvnt_calendar_category_tree';
	const LOCK          = 'evvnt_calendar_category_tree_lock';
	const CRON_HOOK     = 'evvnt_calendar_refresh_category_tree';
	const REFRESH_AFTER = DAY_IN_SECONDS;
	const RETRY_AFTER   = 15 * MINUTE_IN_SECONDS; // An incomplete build (API errors) is retried sooner.
	const MAX_DEPTH     = 6;
	const MAX_NODES     = 3000;

	/** @var Evvnt_Calendar_Discovery_Client */
	private $client;

	/** @var array[] Root categories: { id, name, slug }. */
	private $roots;

	/** @var int */
	private $publisher_id;

	/** @var array|null Indexed nodes, once read. */
	private $nodes = null;

	/**
	 * @param Evvnt_Calendar_Discovery_Client $client       API client.
	 * @param array[]                         $roots        Root categories ({ id, name, slug }).
	 * @param int                             $publisher_id Publisher the roots belong to.
	 */
	public function __construct( Evvnt_Calendar_Discovery_Client $client, array $roots, $publisher_id ) {
		$this->client       = $client;
		$this->roots        = $roots;
		$this->publisher_id = (int) $publisher_id;
	}

	/**
	 * Every category, indexed by id: { id, name, slug, parent, depth, path, root }. `path` is
	 * the slug path from the root ("arts/performing-arts/comedy"); roots have depth 0.
	 * Until the tree has been built (in the background) only the roots are returned.
	 *
	 * @return array[]
	 */
	public function nodes() {
		if ( null !== $this->nodes ) {
			return $this->nodes;
		}
		$stored = $this->stored();
		if ( ! $stored || $this->is_stale( $stored ) ) {
			self::schedule_refresh();
		}
		$flat = $stored ? $stored['nodes'] : array();
		if ( ! $stored ) {
			foreach ( $this->roots as $root ) {
				$flat[ (int) $root['id'] ] = array( 'name' => $root['name'], 'parent' => 0 );
			}
		}
		return $this->nodes = $this->index( $flat );
	}

	/**
	 * Whether the full tree has been built (else nodes() has the roots only).
	 */
	public function is_built() {
		return (bool) $this->stored();
	}

	/**
	 * The stored tree for this publisher's roots, or null.
	 */
	private function stored() {
		$stored = get_option( self::OPTION );
		return ( is_array( $stored ) && isset( $stored['sig'], $stored['at'], $stored['nodes'] ) && $this->signature() === $stored['sig'] && is_array( $stored['nodes'] ) ) ? $stored : null;
	}

	/**
	 * Whether the stored tree should be rebuilt.
	 */
	private function is_stale( array $stored ) {
		$age = time() - (int) $stored['at'];
		return empty( $stored['complete'] ) ? $age >= self::RETRY_AFTER : $age >= self::REFRESH_AFTER;
	}

	/**
	 * Walk the tree from the roots, level by level, and store it.
	 *
	 * @return array|null The stored record, or null if it could not be built.
	 */
	public function refresh() {
		if ( ! $this->roots || get_transient( self::LOCK ) ) {
			return null; // Nothing to build, or a build is already running.
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) && wp_doing_cron() ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled; the build is mostly network waits.
		}

		$flat     = array();
		$failed   = array();
		$complete = true;
		$level    = array();
		foreach ( $this->roots as $root ) {
			$flat[ (int) $root['id'] ] = array( 'name' => (string) $root['name'], 'parent' => 0 );
			$level[]                   = (int) $root['id'];
		}
		for ( $depth = 1; $level && $depth <= self::MAX_DEPTH; $depth++ ) {
			$next = array();
			foreach ( $this->client->category_children( $level ) as $parent => $children ) {
				if ( is_wp_error( $children ) && in_array( Evvnt_Calendar_Cache::error_status( $children ), array( 404, 410 ), true ) ) {
					continue; // A category that no longer exists has no children.
				}
				if ( is_wp_error( $children ) || ! is_array( $children ) ) {
					$complete = false;
					$failed[] = (int) $parent;
					continue;
				}
				foreach ( $children as $child ) {
					$id = ( is_array( $child ) && isset( $child['id'], $child['name'] ) && is_scalar( $child['name'] ) ) ? absint( $child['id'] ) : 0;
					// Each category once (the API is a tree, but never loop on bad data).
					if ( ! $id || isset( $flat[ $id ] ) || '' === trim( (string) $child['name'] ) || count( $flat ) >= self::MAX_NODES ) {
						continue;
					}
					$flat[ $id ] = array( 'name' => trim( (string) $child['name'] ), 'parent' => (int) $parent );
					$next[]      = $id;
				}
			}
			$level = $next;
		}

		// A branch that failed to load keeps what the last build knew of it, so a bad refresh
		// never takes working sub-category pages away.
		$previous = $this->stored();
		if ( $failed && $previous ) {
			$flat = self::graft( $flat, $previous['nodes'], $failed );
		}

		$stored = array(
			'sig'      => $this->signature(),
			'at'       => time(),
			'complete' => $complete,
			'nodes'    => $flat,
		);
		update_option( self::OPTION, $stored, false );
		delete_transient( self::LOCK );
		$this->nodes = null;
		return $stored;
	}

	/**
	 * Copy the descendants of the given categories from an older tree into a new one.
	 *
	 * @param array $flat   New { id => { name, parent } } records.
	 * @param array $old    Older records.
	 * @param int[] $parents Categories whose children could not be loaded.
	 * @return array
	 */
	private static function graft( array $flat, array $old, array $parents ) {
		$children = array();
		foreach ( $old as $id => $record ) {
			$children[ (int) $record['parent'] ][] = (int) $id;
		}
		$queue = array_values( array_filter( $parents, function ( $id ) use ( $flat ) {
			return isset( $flat[ $id ] );
		} ) );
		while ( $queue && count( $flat ) < self::MAX_NODES ) {
			$parent = array_shift( $queue );
			foreach ( isset( $children[ $parent ] ) ? $children[ $parent ] : array() as $id ) {
				if ( ! isset( $flat[ $id ] ) ) {
					$flat[ $id ] = $old[ $id ];
					$queue[]     = $id;
				}
			}
		}
		return $flat;
	}

	/**
	 * Run a refresh soon in the background (once).
	 */
	public static function schedule_refresh() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	/**
	 * The tree as stored for this publisher's roots; a change of roots means a rebuild.
	 */
	private function signature() {
		return md5( $this->publisher_id . '|' . implode( ',', wp_list_pluck( $this->roots, 'id' ) ) );
	}

	/**
	 * Index raw { name, parent } records: slugs (unique among siblings), depth, path and root.
	 */
	private function index( array $flat ) {
		$children = array();
		foreach ( $flat as $id => $node ) {
			$children[ (int) $node['parent'] ][] = (int) $id;
		}
		$root_slugs = array();
		foreach ( $this->roots as $root ) {
			$root_slugs[ (int) $root['id'] ] = $root['slug'];
		}

		$nodes = array();
		$walk  = function ( $parent, $depth, $path, $root ) use ( &$walk, &$nodes, $children, $flat, $root_slugs ) {
			$taken = array();
			foreach ( isset( $children[ $parent ] ) ? $children[ $parent ] : array() as $id ) {
				if ( isset( $nodes[ $id ] ) ) {
					continue;
				}
				$raw  = (string) $flat[ $id ]['name'];
				$slug = ( 0 === $depth && isset( $root_slugs[ $id ] ) ) ? $root_slugs[ $id ] : $this->child_slug( $raw, $id, $taken );
				$taken[ $slug ] = true;
				$node_path      = '' === $path ? $slug : $path . '/' . $slug;
				$nodes[ $id ]   = array(
					'id'     => $id,
					// Displayed as the discovery JS does ("Food / Drink" => "Food & Drink").
					'name'   => str_replace( ' / ', ' & ', $raw ),
					'slug'   => $slug,
					'parent' => (int) $parent,
					'depth'  => $depth,
					'path'   => $node_path,
					'root'   => 0 === $depth ? $id : $root,
				);
				$walk( $id, $depth + 1, $node_path, 0 === $depth ? $id : $root );
			}
		};
		$walk( 0, 0, '', 0 );
		return $nodes;
	}

	/**
	 * The category at a slug path ("arts/performing-arts"), with its ancestors (root first) in
	 * 'trail'; null if there is none; WP_Error if that cannot be told now (the API is failing).
	 * Before the tree is fully built, the path alone is walked (one cached request per level).
	 *
	 * @param string $path Slug path from the root.
	 * @return array|null|WP_Error
	 */
	public function by_path( $path ) {
		$slugs = explode( '/', (string) $path );
		if ( ! $slugs || count( $slugs ) > self::MAX_DEPTH + 1 ) {
			return null;
		}
		$stored = $this->stored();
		if ( $stored ) {
			foreach ( $this->nodes() as $node ) {
				if ( $node['path'] === $path ) {
					return $node + array( 'trail' => $this->ancestors( $node ) );
				}
			}
			if ( ! empty( $stored['complete'] ) ) {
				return null;
			}
			// An incomplete tree may be missing this branch: walk the path to be sure.
		}

		// Walk this path only. Sibling slugs are made unique as index() does.
		$node  = null;
		$trail = array();
		foreach ( $this->nodes() as $root ) {
			if ( 0 === $root['depth'] && $root['slug'] === $slugs[0] ) {
				$node = $root;
			}
		}
		for ( $i = 1; $node && $i < count( $slugs ); $i++ ) {
			$children = $this->client->category_children_cached( $node['id'] );
			if ( is_wp_error( $children ) && ! in_array( Evvnt_Calendar_Cache::error_status( $children ), array( 404, 410 ), true ) ) {
				return new WP_Error( 'evvnt_calendar_unavailable', 'Evvnt categories temporarily unavailable.', array( 'status' => 503 ) );
			}
			$flat     = array( $node['id'] => array( 'name' => $node['name'], 'parent' => 0 ) );
			foreach ( is_array( $children ) ? $children : array() as $child ) {
				if ( is_array( $child ) && isset( $child['id'], $child['name'] ) && is_scalar( $child['name'] ) && absint( $child['id'] ) && '' !== trim( (string) $child['name'] ) ) {
					$flat[ absint( $child['id'] ) ] = array( 'name' => trim( (string) $child['name'] ), 'parent' => $node['id'] );
				}
			}
			$found = null;
			foreach ( $this->index_below( $node, $flat ) as $child ) {
				if ( $child['slug'] === $slugs[ $i ] ) {
					$found = $child;
				}
			}
			$trail[] = $node;
			$node    = $found;
		}
		return $node ? $node + array( 'trail' => $trail ) : null;
	}

	/**
	 * Index the direct children of a known category from raw { name, parent } records.
	 */
	private function index_below( array $parent, array $flat ) {
		$nodes = array();
		$taken = array();
		foreach ( $flat as $id => $record ) {
			if ( (int) $record['parent'] !== $parent['id'] ) {
				continue;
			}
			$slug = $this->child_slug( (string) $record['name'], $id, $taken );
			$taken[ $slug ] = true;
			$nodes[ $id ]   = array(
				'id'     => (int) $id,
				'name'   => str_replace( ' / ', ' & ', (string) $record['name'] ),
				'slug'   => $slug,
				'parent' => $parent['id'],
				'depth'  => $parent['depth'] + 1,
				'path'   => $parent['path'] . '/' . $slug,
				'root'   => $parent['root'],
			);
		}
		return $nodes;
	}

	/**
	 * Slug of a sub-category: from its name, unique among its siblings and never a word the
	 * calendar's URLs reserve.
	 */
	private function child_slug( $name, $id, array $taken ) {
		$slug = Evvnt_Calendar_Route::category_slug( $name );
		if ( '' === $slug || in_array( $slug, array( Evvnt_Calendar_Route::CATEGORIES_PATH, 'event', 'page', 'category' ), true ) || isset( $taken[ $slug ] ) ) {
			$slug = trim( $slug . '-' . $id, '-' );
		}
		return $slug;
	}

	/**
	 * The category with this id, with its ancestors in 'trail', if the tree is built.
	 *
	 * @param int $id Category id.
	 * @return array|null
	 */
	public function by_id( $id ) {
		$nodes = $this->nodes();
		return isset( $nodes[ (int) $id ] ) ? $nodes[ (int) $id ] + array( 'trail' => $this->ancestors( $nodes[ (int) $id ] ) ) : null;
	}

	/**
	 * Ancestors of a category, root first (not including it).
	 *
	 * @param array $node Category.
	 * @return array[]
	 */
	public function ancestors( array $node ) {
		$nodes = $this->nodes();
		$trail = array();
		for ( $id = $node['parent']; $id && isset( $nodes[ $id ] ) && count( $trail ) <= self::MAX_DEPTH; $id = $nodes[ $id ]['parent'] ) {
			array_unshift( $trail, $nodes[ $id ] );
		}
		return $trail;
	}

	/**
	 * The tree nested for display: roots A–Z, each with children A–Z ( node + 'children' ).
	 *
	 * @return array[]
	 */
	public function nested() {
		$nodes = $this->nodes();
		$by    = array();
		foreach ( $nodes as $node ) {
			$by[ $node['parent'] ][] = $node['id'];
		}
		$nest = function ( $parent ) use ( &$nest, $by, $nodes ) {
			$list = array();
			foreach ( isset( $by[ $parent ] ) ? $by[ $parent ] : array() as $id ) {
				$list[] = $nodes[ $id ] + array( 'children' => $nest( $id ) );
			}
			usort(
				$list,
				function ( $a, $b ) {
					return strnatcasecmp( $a['name'], $b['name'] );
				}
			);
			return $list;
		};
		return $nest( 0 );
	}
}
