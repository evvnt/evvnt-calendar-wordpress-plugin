<?php
/**
 * Every event category, A–Z, with its sub-categories nested below it (A–Z, at every depth),
 * each linking to its calendar page.
 *
 * Override by copying to {your-theme}/evvnt-calendar/categories.php.
 *
 * @var array $args {
 *     @type array[]                 $categories   Root categories: { id, name, slug, path, depth, url, children (same shape) }.
 *     @type string                  $base         Calendar page URL.
 *     @type array                   $atts         Shortcode attributes (search box filters).
 *     @type string                  $home_url     Calendar home.
 *     @type string                  $submit_url
 *     @type string                  $submit_label
 *     @type Evvnt_Calendar_Renderer $renderer
 * }
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="<?php echo esc_attr( $args['renderer']->root_class( 'categories' ) ); ?>">
	<div class="evvnt-cal__toolbar">
		<p class="evvnt-cal__home"><a class="evvnt-cal__back" href="<?php echo esc_url( $args['home_url'] ); ?>"><?php echo Evvnt_Calendar_Renderer::icon( 'back', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Events home', 'evvnt-calendar' ); ?></a></p>
		<?php if ( $args['submit_url'] ) : ?>
			<a class="evvnt-cal__button evvnt-cal__submit" href="<?php echo esc_url( $args['submit_url'] ); ?>" rel="nofollow noopener" target="_blank"><?php echo esc_html( $args['submit_label'] ); ?></a>
		<?php endif; ?>
	</div>

	<?php echo $args['renderer']->search_form( $args['base'], '', isset( $args['atts'] ) ? $args['atts'] : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?>

	<h2 class="evvnt-cal__category-title"><?php esc_html_e( 'All categories', 'evvnt-calendar' ); ?></h2>

	<?php if ( $args['categories'] ) : ?>
		<?php
		// Sub-categories at every depth, as nested lists.
		$evvnt_sub_list = function ( array $nodes ) use ( &$evvnt_sub_list ) {
			if ( ! $nodes ) {
				return '';
			}
			$html = '<ul>';
			foreach ( $nodes as $node ) {
				$html .= '<li><a href="' . esc_url( $node['url'] ) . '">' . esc_html( $node['name'] ) . '</a>' . $evvnt_sub_list( $node['children'] ) . '</li>';
			}
			return $html . '</ul>';
		};
		?>
		<div class="evvnt-cal__category-index">
			<?php foreach ( $args['categories'] as $evvnt_category ) : ?>
				<section class="evvnt-cal__category-group" aria-labelledby="evvnt-cal-cat-<?php echo (int) $evvnt_category['id']; ?>">
					<h3 id="evvnt-cal-cat-<?php echo (int) $evvnt_category['id']; ?>"><a href="<?php echo esc_url( $evvnt_category['url'] ); ?>"><span><?php echo esc_html( $evvnt_category['name'] ); ?></span><?php echo Evvnt_Calendar_Renderer::icon( 'arrow', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a></h3>
					<?php echo $evvnt_sub_list( $evvnt_category['children'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
				</section>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="evvnt-cal__empty"><?php esc_html_e( 'No categories found.', 'evvnt-calendar' ); ?></p>
	<?php endif; ?>

	<div class="evvnt-cal__footer">
		<p class="evvnt-cal__powered"><?php esc_html_e( 'Powered by', 'evvnt-calendar' ); ?> <a href="https://evvnt.com/" rel="noopener" target="_blank">evvnt</a></p>
	</div>
</div>
