<?php
/**
 * Calendar home as category rows: a horizontally scrolling row of featured events per category
 * (Featured first), each with a "See all" link into that category's calendar page. The rows
 * replace the category navigation; category pages link back here instead.
 * Rows scroll with swipe, trackpad or keyboard; previous/next buttons appear with JS.
 *
 * Override by copying to {your-theme}/evvnt-calendar/calendar-rows.php.
 *
 * @var array $args {
 *     @type array[]                 $rows         Each array( id, title, url, events ).
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
?>
<div class="<?php echo esc_attr( $evvnt_renderer->root_class( 'calendar' ) ); ?> evvnt-cal--rows">
	<div class="evvnt-cal__toolbar">
		<?php if ( $args['submit_url'] ) : ?>
			<a class="evvnt-cal__button evvnt-cal__submit" href="<?php echo esc_url( $args['submit_url'] ); ?>" rel="nofollow noopener" target="_blank"><?php echo esc_html( $args['submit_label'] ); ?></a>
		<?php endif; ?>
	</div>

	<?php echo $evvnt_renderer->search_form( $args['base'], '', isset( $args['atts'] ) ? $args['atts'] : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?>

	<?php foreach ( $args['rows'] as $evvnt_row ) : ?>
		<?php $evvnt_heading = 'evvnt-cal-row-' . sanitize_html_class( $evvnt_row['id'] ); ?>
		<section class="evvnt-cal__row" aria-labelledby="<?php echo esc_attr( $evvnt_heading ); ?>">
			<div class="evvnt-cal__row-head">
				<h2 class="evvnt-cal__heading" id="<?php echo esc_attr( $evvnt_heading ); ?>"><?php echo esc_html( $evvnt_row['title'] ); ?></h2>
				<?php if ( $evvnt_row['url'] ) : ?>
					<a class="evvnt-cal__row-more" href="<?php echo esc_url( $evvnt_row['url'] ); ?>">
						<?php
						/* translators: %s: category name (visually hidden, for screen readers). */
						printf( esc_html__( 'See all %s', 'evvnt-calendar' ), '<span class="screen-reader-text">' . esc_html( $evvnt_row['title'] ) . '</span>' );
						?>
						<?php echo Evvnt_Calendar_Renderer::icon( 'arrow', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					</a>
				<?php endif; ?>
				<div class="evvnt-cal__row-controls" hidden>
					<button type="button" data-dir="-1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: row title. */ __( 'Scroll %s back', 'evvnt-calendar' ), $evvnt_row['title'] ) ); ?>"><?php echo Evvnt_Calendar_Renderer::icon( 'back', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
					<button type="button" data-dir="1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: row title. */ __( 'Scroll %s forward', 'evvnt-calendar' ), $evvnt_row['title'] ) ); ?>"><?php echo Evvnt_Calendar_Renderer::icon( 'arrow', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
				</div>
			</div>
			<ul class="evvnt-cal__row-track" tabindex="0" aria-label="<?php echo esc_attr( $evvnt_row['title'] ); ?>">
				<?php foreach ( $evvnt_row['events'] as $evvnt_event ) : ?>
					<li><?php echo $evvnt_renderer->render_card( $evvnt_event, $args['base'], $args['detail_pages'], 'featured' === $evvnt_row['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?></li>
				<?php endforeach; ?>
				<?php if ( $evvnt_row['url'] ) : ?>
					<li class="evvnt-cal__row-end"><a href="<?php echo esc_url( $evvnt_row['url'] ); ?>"><?php echo esc_html( ! empty( $evvnt_row['more'] ) ? $evvnt_row['more'] : sprintf( /* translators: %s: category name. */ __( 'View more %s events', 'evvnt-calendar' ), $evvnt_row['title'] ) ); ?> <?php echo Evvnt_Calendar_Renderer::icon( 'arrow', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a></li>
				<?php endif; ?>
			</ul>
		</section>
	<?php endforeach; ?>

	<p class="evvnt-cal__powered"><?php esc_html_e( 'Powered by', 'evvnt-calendar' ); ?> <a href="https://evvnt.com/" rel="noopener" target="_blank">evvnt</a></p>
</div>
