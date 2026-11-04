<?php
/**
 * Standard event detail view (non-featured events), with the discovery plugin's event page
 * header: title, summary, date/place/price and the ticket button beside the image. The
 * body below is the shared event-body.php. Featured events use event-detail-featured.php.
 *
 * Override by copying to {your-theme}/evvnt-calendar/event-detail.php.
 *
 * @var array $args {
 *     @type Evvnt_Calendar_Event    $event
 *     @type string                  $url          Canonical URL of this event page.
 *     @type string                  $back_url
 *     @type string                  $base         Calendar page URL.
 *     @type Evvnt_Calendar_Event[]  $recommended  Other events to suggest.
 *     @type Evvnt_Calendar_Renderer $renderer
 * }
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evvnt_event   = $args['event'];
$evvnt_icon    = array( 'Evvnt_Calendar_Renderer', 'icon' );
$evvnt_image   = $evvnt_event->hero_image_url();
$evvnt_link    = $evvnt_event->cta_url();
$evvnt_cta     = $evvnt_event->cta();
$evvnt_rel     = $evvnt_event->is_affiliate_link() ? 'sponsored noopener' : 'noopener';
$evvnt_price   = $evvnt_event->price_range();
$evvnt_where   = $evvnt_event->location_summary();
$evvnt_expired = $evvnt_event->is_expired();
?>
<div class="<?php echo esc_attr( $args['renderer']->root_class( 'detail' ) ); ?> evvnt-cal--standard">
	<div class="evvnt-cal__std">
		<a class="evvnt-cal__back evvnt-cal__back--std" href="<?php echo esc_url( $args['back_url'] ); ?>"><?php echo $evvnt_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'All events', 'evvnt-calendar' ); ?></a>

		<div class="evvnt-cal__std-top">
			<div class="evvnt-cal__std-main">
				<?php if ( $evvnt_expired ) : ?>
					<p class="evvnt-cal__std-badge"><?php esc_html_e( 'This event has ended', 'evvnt-calendar' ); ?></p>
				<?php endif; ?>

				<h1 class="evvnt-cal__std-title"><?php echo esc_html( $evvnt_event->title() ); ?></h1>

				<?php if ( $evvnt_event->summary() ) : ?>
					<p class="evvnt-cal__std-summary"><?php echo esc_html( $evvnt_event->summary() ); ?></p>
				<?php endif; ?>

				<ul class="evvnt-cal__std-facts">
					<?php if ( $evvnt_event->start() ) : ?>
						<li><?php echo $evvnt_icon( 'calendar', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><time datetime="<?php echo esc_attr( $evvnt_event->start()->format( DATE_ATOM ) ); ?>"><?php echo esc_html( $evvnt_event->display_date() ); ?></time></li>
					<?php endif; ?>
					<?php if ( $evvnt_where ) : ?>
						<li><?php echo $evvnt_icon( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $evvnt_where ); ?></span></li>
					<?php endif; ?>
					<?php if ( $evvnt_price ) : ?>
						<li><?php echo $evvnt_icon( 'ticket', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $evvnt_price ); ?></span></li>
					<?php endif; ?>
				</ul>

				<?php if ( $evvnt_link && ! $evvnt_expired ) : ?>
					<p class="evvnt-cal__std-cta">
						<a class="evvnt-cal__button evvnt-cal__button--std" href="<?php echo esc_url( $evvnt_link ); ?>" rel="<?php echo esc_attr( $evvnt_rel ); ?>" target="_blank"<?php echo $evvnt_cta['color'] ? ' style="background-color:' . esc_attr( $evvnt_cta['color'] ) . '"' : ''; ?>><?php echo esc_html( $evvnt_cta['label'] ); ?><?php echo $evvnt_icon( 'arrow', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( $evvnt_image ) : ?>
				<figure class="evvnt-cal__std-media">
					<img src="<?php echo esc_url( $evvnt_image ); ?>" alt="<?php echo esc_attr( $evvnt_event->title() ); ?>" decoding="async" fetchpriority="high">
				</figure>
			<?php endif; ?>
		</div>

		<?php echo $args['renderer']->template( 'event-body', array( 'summary_shown' => true ) + $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?>
	</div>
</div>
