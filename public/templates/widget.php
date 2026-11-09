<?php
/**
 * The compact events widget, laid out as the discovery plugin's JS widget (portrait):
 * "Create an Event", a search box, a few events (one slot may be a promotional ad),
 * "More Events" and the evvnt credit. Each event is a tile in the event page's hero style:
 * its image blurred and darkened behind it, the image itself inset, title (two lines at
 * most), date and place in white.
 *
 * Override by copying to {your-theme}/evvnt-calendar/widget.php.
 *
 * @var array $args {
 *     @type array[]                 $items        Each { type: 'event', event, image, url, external } or { type: 'ad', image, url }.
 *     @type bool                    $suggest      Whether the search box offers live suggestions (links on this site).
 *     @type bool                    $unavailable  The events could not be loaded.
 *     @type string                  $base         Calendar the widget links into.
 *     @type array                   $atts         Widget options ( detail_page_enabled, virtual, category_id ).
 *     @type string                  $more_url     "More Events" link.
 *     @type string                  $create_url   "Create an Event" link.
 *     @type string                  $powered_logo "Powered by evvnt" image URL.
 *     @type Evvnt_Calendar_Renderer $renderer
 * }
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evvnt_icon = array( 'Evvnt_Calendar_Renderer', 'icon' );
?>
<div class="evvnt-cal evvnt-cal--widget">
	<a class="evvnt-cal__button evvnt-cal__widget-create" href="<?php echo esc_url( $args['create_url'] ); ?>" rel="nofollow noopener" target="_blank"><?php esc_html_e( 'Create an Event', 'evvnt-calendar' ); ?></a>

	<?php echo $args['renderer']->search_form( $args['base'], '', $args['atts'], array( 'placeholder' => __( 'Search for events', 'evvnt-calendar' ), 'variant' => 'widget', 'suggest' => ! empty( $args['suggest'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?>

	<?php if ( $args['items'] ) : ?>
		<ul class="evvnt-cal__widget-list">
			<?php foreach ( $args['items'] as $evvnt_item ) : ?>
				<?php if ( 'ad' === $evvnt_item['type'] ) : ?>
					<li class="evvnt-cal__widget-ad">
						<a href="<?php echo esc_url( $evvnt_item['url'] ); ?>" rel="nofollow noopener sponsored" target="_blank"><img src="<?php echo esc_url( $evvnt_item['image'] ); ?>" alt="<?php esc_attr_e( 'Promote your event', 'evvnt-calendar' ); ?>" loading="lazy" decoding="async"></a>
					</li>
					<?php continue; ?>
				<?php endif; ?>
				<?php
				$evvnt_event = $evvnt_item['event'];
				$evvnt_image = $evvnt_item['image'];
				$evvnt_where = $evvnt_event->location_summary();
				$evvnt_attrs = $evvnt_item['external'] ? ' rel="nofollow noopener" target="_blank"' : '';
				?>
				<li>
					<a class="evvnt-cal__widget-item" href="<?php echo esc_url( $evvnt_item['url'] ); ?>"<?php echo $evvnt_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?><?php echo isset( $evvnt_item['tracking'] ) ? $evvnt_item['tracking'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Evvnt_Calendar_Tracking. ?>>
						<?php // The hero treatment: the event image, blurred and darkened, behind the tile. ?>
						<img class="evvnt-cal__widget-backdrop" src="<?php echo esc_url( $evvnt_image ); ?>" alt="" aria-hidden="true" loading="lazy" decoding="async">
						<span class="evvnt-cal__widget-image"><img src="<?php echo esc_url( $evvnt_image ); ?>" alt="" loading="lazy" decoding="async"></span>
						<span class="evvnt-cal__widget-body">
							<h3 class="evvnt-cal__widget-title"><?php echo esc_html( $evvnt_event->title() ); ?></h3>
							<span class="evvnt-cal__widget-meta">
								<?php if ( $evvnt_event->start() ) : ?>
									<span><?php echo $evvnt_icon( 'calendar', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><time datetime="<?php echo esc_attr( $evvnt_event->start()->format( 'Y-m-d' ) ); ?>"><?php echo esc_html( $evvnt_event->short_date_label() ); ?></time></span>
								<?php endif; ?>
								<?php if ( $evvnt_where ) : ?>
									<span><?php echo $evvnt_icon( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $evvnt_where ); ?></span></span>
								<?php endif; ?>
							</span>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="evvnt-cal__widget-empty"><?php echo $args['unavailable'] ? esc_html__( 'Events are unavailable right now. Please try again later.', 'evvnt-calendar' ) : esc_html__( 'No upcoming events found.', 'evvnt-calendar' ); ?></p>
	<?php endif; ?>

	<div class="evvnt-cal__widget-footer">
		<a class="evvnt-cal__button evvnt-cal__widget-more" href="<?php echo esc_url( $args['more_url'] ); ?>"><?php esc_html_e( 'More Events', 'evvnt-calendar' ); ?></a>
		<a class="evvnt-cal__widget-powered" href="https://evvnt.com/" rel="noopener" target="_blank"><img src="<?php echo esc_url( $args['powered_logo'] ); ?>" alt="<?php esc_attr_e( 'Powered by evvnt', 'evvnt-calendar' ); ?>" width="96" height="18"></a>
	</div>
</div>
