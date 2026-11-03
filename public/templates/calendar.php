<?php
/**
 * Calendar list view.
 *
 * Override by copying to {your-theme}/evvnt-calendar/calendar.php.
 *
 * @var array $args {
 *     @type Evvnt_Calendar_Event[]  $events
 *     @type Evvnt_Calendar_Event[]  $featured
 *     @type array[]                 $categories   Each array( id, name, slug ).
 *     @type array|null              $selected     Selected category.
 *     @type bool                    $not_found    The requested category or path does not exist.
 *     @type int                     $page
 *     @type string                  $prev_url
 *     @type string                  $next_url
 *     @type string                  $base
 *     @type bool                    $detail_pages
 *     @type string                  $submit_url
 *     @type string                  $submit_label
 *     @type string                  $calendar_url
 *     @type Evvnt_Calendar_Renderer $renderer
 * }
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evvnt_renderer = $args['renderer'];
$evvnt_selected = $args['selected'];
?>
<div class="evvnt-cal evvnt-cal--calendar">
	<div class="evvnt-cal__toolbar">
		<?php if ( $args['submit_url'] ) : ?>
			<a class="evvnt-cal__button evvnt-cal__submit" href="<?php echo esc_url( $args['submit_url'] ); ?>" rel="nofollow noopener" target="_blank"><?php echo esc_html( $args['submit_label'] ); ?></a>
		<?php endif; ?>
	</div>

	<?php if ( $args['categories'] ) : ?>
		<nav class="evvnt-cal__nav" aria-label="<?php esc_attr_e( 'Event categories', 'evvnt-calendar' ); ?>">
			<ul>
				<li><a href="<?php echo esc_url( $args['calendar_url'] ); ?>"<?php echo $evvnt_selected ? '' : ' aria-current="page"'; ?>><?php esc_html_e( 'All Events', 'evvnt-calendar' ); ?></a></li>
				<?php foreach ( $args['categories'] as $evvnt_category ) : ?>
					<li><a href="<?php echo esc_url( Evvnt_Calendar_Route::calendar_url( $args['base'], $evvnt_category['slug'] ) ); ?>"<?php echo ( $evvnt_selected && $evvnt_selected['id'] === $evvnt_category['id'] ) ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $evvnt_category['name'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>

	<?php if ( $args['not_found'] ) : ?>
		<p class="evvnt-cal__notice"><?php esc_html_e( 'That page of the calendar could not be found. Showing all events instead.', 'evvnt-calendar' ); ?></p>
	<?php endif; ?>

	<?php if ( $args['featured'] ) : ?>
		<section class="evvnt-cal__section evvnt-cal__section--featured">
			<h2 class="evvnt-cal__heading"><?php esc_html_e( 'Featured Events', 'evvnt-calendar' ); ?></h2>
			<ul class="evvnt-cal__grid">
				<?php foreach ( $args['featured'] as $evvnt_event ) : ?>
					<li><?php echo $evvnt_renderer->render_card( $evvnt_event, $args['base'], $args['detail_pages'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<section class="evvnt-cal__section">
		<h2 class="evvnt-cal__heading">
			<?php
			echo esc_html( $evvnt_selected ? $evvnt_selected['name'] : __( 'Upcoming Events', 'evvnt-calendar' ) );
			if ( $args['page'] > 1 ) {
				/* translators: %d: page number. */
				echo ' <span class="evvnt-cal__page">' . esc_html( sprintf( __( 'Page %d', 'evvnt-calendar' ), $args['page'] ) ) . '</span>';
			}
			?>
		</h2>
		<?php if ( $args['events'] ) : ?>
			<ul class="evvnt-cal__list">
				<?php foreach ( $args['events'] as $evvnt_event ) : ?>
					<li><?php echo $evvnt_renderer->render_card( $evvnt_event, $args['base'], $args['detail_pages'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="evvnt-cal__empty"><?php esc_html_e( 'No upcoming events found.', 'evvnt-calendar' ); ?></p>
		<?php endif; ?>
	</section>

	<?php if ( $args['prev_url'] || $args['next_url'] ) : ?>
		<nav class="evvnt-cal__pagination" aria-label="<?php esc_attr_e( 'Calendar pages', 'evvnt-calendar' ); ?>">
			<?php if ( $args['prev_url'] ) : ?>
				<a class="evvnt-cal__button" rel="prev" href="<?php echo esc_url( $args['prev_url'] ); ?>"><?php esc_html_e( '← Previous', 'evvnt-calendar' ); ?></a>
			<?php endif; ?>
			<?php if ( $args['next_url'] ) : ?>
				<a class="evvnt-cal__button" rel="next" href="<?php echo esc_url( $args['next_url'] ); ?>"><?php esc_html_e( 'More events →', 'evvnt-calendar' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

	<p class="evvnt-cal__powered"><?php esc_html_e( 'Powered by', 'evvnt-calendar' ); ?> <a href="https://evvnt.com/" rel="noopener" target="_blank">evvnt</a></p>
</div>
