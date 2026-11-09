<?php
/**
 * Featured event detail view: a full-width hero (blurred, enlarged event image behind the title,
 * key facts and ticket button), followed by the shared event-body.php.
 *
 * Override by copying to {your-theme}/evvnt-calendar/event-detail-featured.php.
 *
 * @var array $args {
 *     @type Evvnt_Calendar_Event $event
 *     @type string               $url          Canonical URL of this event page.
 *     @type string               $back_url
 *     @type string               $submit_url
 *     @type string               $submit_label
 *     @type Evvnt_Calendar_Event[] $recommended Other events to suggest.
 *     @type string               $base         Calendar page URL.
 * }
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evvnt_event    = $args['event'];
$evvnt_image    = $evvnt_event->hero_image_url();
$evvnt_backdrop = $evvnt_event->card_image_url() ? $evvnt_event->card_image_url() : $evvnt_image;
$evvnt_link     = $evvnt_event->cta_url();
$evvnt_rel      = $evvnt_event->is_affiliate_link() ? 'sponsored noopener' : 'noopener';
$evvnt_price    = $evvnt_event->price_range();
$evvnt_expired  = $evvnt_event->is_expired();
$evvnt_cta      = $evvnt_event->tickets_link() ? __( 'Buy Tickets', 'evvnt-calendar' ) : __( 'More Information', 'evvnt-calendar' );

$evvnt_icon   = array( 'Evvnt_Calendar_Renderer', 'icon' );
$evvnt_series = $evvnt_event->shows_recurrence();
?>
<div class="<?php echo esc_attr( $args['renderer']->root_class( 'detail' ) ); ?> evvnt-cal--featured"<?php echo Evvnt_Calendar_Tracking::view_attrs( $evvnt_event, Evvnt_Calendar_Tracking::VIEWED_FROM_PAGE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Evvnt_Calendar_Tracking. ?>>
	<section class="evvnt-cal__hero<?php echo $evvnt_image ? '' : ' evvnt-cal__hero--no-image'; ?>" aria-labelledby="evvnt-cal-event-title">
		<?php if ( $evvnt_backdrop ) : ?>
			<img class="evvnt-cal__hero-backdrop" src="<?php echo esc_url( $evvnt_backdrop ); ?>" alt="" aria-hidden="true" decoding="async">
		<?php endif; ?>
		<div class="evvnt-cal__hero-inner">
			<div class="evvnt-cal__hero-text">
				<a class="evvnt-cal__back" href="<?php echo esc_url( $args['back_url'] ); ?>"><?php echo $evvnt_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'All events', 'evvnt-calendar' ); ?></a>

				<?php if ( $evvnt_expired ) : ?>
					<p class="evvnt-cal__badge"><?php esc_html_e( 'This event has ended', 'evvnt-calendar' ); ?></p>
				<?php endif; ?>
				<?php if ( $evvnt_series ) : ?>
					<p class="evvnt-cal__badge evvnt-cal__badge--series"><?php echo $evvnt_icon( 'repeat', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( $evvnt_event->recurrence()->badge_label() ); ?></p>
				<?php endif; ?>

				<h1 class="evvnt-cal__title" id="evvnt-cal-event-title"><?php echo esc_html( $evvnt_event->title() ); ?></h1>

				<ul class="evvnt-cal__facts">
					<?php if ( $evvnt_series ) : ?>
						<li class="evvnt-cal__fact evvnt-cal__series-next">
							<?php echo $evvnt_icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
							<time datetime="<?php echo esc_attr( $evvnt_event->highlighted_date()['start']->format( DATE_ATOM ) ); ?>"><?php echo esc_html( $evvnt_event->series_date_label() ); ?></time>
						</li>
						<li class="evvnt-cal__fact evvnt-cal__series-repeats">
							<?php echo $evvnt_icon( 'repeat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
							<span><?php echo esc_html( $evvnt_event->recurrence()->frequency_line() ); ?></span>
						</li>
					<?php elseif ( $evvnt_event->start() ) : ?>
						<li class="evvnt-cal__fact">
							<?php echo $evvnt_icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
							<time datetime="<?php echo esc_attr( $evvnt_event->start()->format( DATE_ATOM ) ); ?>"><?php echo esc_html( $evvnt_event->date_label() ); ?></time>
						</li>
						<?php if ( $evvnt_event->time_label() ) : ?>
							<li class="evvnt-cal__fact">
								<?php echo $evvnt_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
								<span><?php echo esc_html( $evvnt_event->time_label() ); ?></span>
							</li>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( $evvnt_event->venue_name() || $evvnt_event->venue( 'town' ) ) : ?>
						<li class="evvnt-cal__fact evvnt-cal__fact--place">
							<?php echo $evvnt_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
							<span>
								<strong><?php echo esc_html( $evvnt_event->venue_name() ); ?></strong>
								<?php if ( ! $evvnt_event->is_virtual() && $evvnt_event->location_summary() ) : ?>
									<span class="evvnt-cal__muted"><?php echo esc_html( $evvnt_event->location_summary() ); ?></span>
								<?php endif; ?>
							</span>
						</li>
					<?php endif; ?>
				</ul>

				<div class="evvnt-cal__cta">
					<?php if ( $evvnt_price ) : ?>
						<p class="evvnt-cal__price"><?php echo $evvnt_icon( 'ticket', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $evvnt_price ); ?></span></p>
					<?php endif; ?>
					<?php if ( $evvnt_link && ! $evvnt_expired ) : ?>
						<a class="evvnt-cal__button evvnt-cal__button--large" href="<?php echo esc_url( $evvnt_link ); ?>" rel="<?php echo esc_attr( $evvnt_rel ); ?>" target="_blank"<?php echo Evvnt_Calendar_Tracking::click_attrs( $evvnt_event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Evvnt_Calendar_Tracking. ?>><?php echo esc_html( $evvnt_cta ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $evvnt_image ) : ?>
				<figure class="evvnt-cal__hero-media">
					<img src="<?php echo esc_url( $evvnt_image ); ?>" alt="<?php echo esc_attr( $evvnt_event->title() ); ?>" decoding="async" fetchpriority="high">
				</figure>
			<?php endif; ?>
		</div>
	</section>

	<?php echo $args['renderer']->template( 'event-body', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?>
</div>
