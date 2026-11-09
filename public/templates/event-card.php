<?php
/**
 * One event in the calendar list. A date of a recurring series shows a repeat icon in place
 * of the calendar icon (named for screen readers and on hover: "Weekly series", "Recurring"…).
 *
 * Override by copying to {your-theme}/evvnt-calendar/event-card.php.
 *
 * @var array $args {
 *     @type Evvnt_Calendar_Event $event
 *     @type string               $url       Detail page URL (or the event's own link).
 *     @type bool                 $external  Whether $url leaves the site.
 *     @type bool                 $featured
 *     @type string               $tracking  Analytics attributes (Evvnt_Calendar_Tracking::view_attrs()).
 * }
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evvnt_event = $args['event'];
$evvnt_image = $evvnt_event->card_image_url();
$evvnt_attrs = $args['external'] ? ' rel="nofollow noopener" target="_blank"' : '';
$evvnt_price = $evvnt_event->price_range();
$evvnt_where = $evvnt_event->location_summary();
// One date of a recurring series: a repeat icon instead of the calendar icon, naming the series.
$evvnt_series = $evvnt_event->shows_recurrence() ? $evvnt_event->recurrence()->badge_label() : '';
?>
<article class="evvnt-cal__card<?php echo $args['featured'] ? ' evvnt-cal__card--featured' : ''; ?>"<?php echo isset( $args['tracking'] ) ? $args['tracking'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Evvnt_Calendar_Tracking. ?>>
	<?php if ( $evvnt_image ) : ?>
		<a class="evvnt-cal__card-image" href="<?php echo esc_url( $args['url'] ); ?>"<?php echo $evvnt_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?> tabindex="-1" aria-hidden="true">
			<img src="<?php echo esc_url( $evvnt_image ); ?>" alt="" loading="lazy" decoding="async">
		</a>
	<?php endif; ?>
	<div class="evvnt-cal__card-body">
		<h3 class="evvnt-cal__card-title"><a href="<?php echo esc_url( $args['url'] ); ?>"<?php echo $evvnt_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?>><?php echo esc_html( $evvnt_event->title() ); ?></a></h3>
		<ul class="evvnt-cal__meta">
			<?php if ( $evvnt_event->start() ) : ?>
				<li class="evvnt-cal__meta-date">
					<?php if ( $evvnt_series ) : ?>
						<span class="evvnt-cal__card-series" title="<?php echo esc_attr( $evvnt_series ); ?>"><?php echo Evvnt_Calendar_Renderer::icon( 'repeat', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span class="screen-reader-text"><?php echo esc_html( $evvnt_series ); ?></span></span>
					<?php else : ?>
						<?php echo Evvnt_Calendar_Renderer::icon( 'calendar', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<?php endif; ?>
					<time datetime="<?php echo esc_attr( $evvnt_event->start()->format( DATE_ATOM ) ); ?>"><?php echo esc_html( $evvnt_event->display_date() ); ?></time>
				</li>
			<?php endif; ?>
			<?php if ( $evvnt_where ) : ?>
				<li class="evvnt-cal__meta-place"><?php echo Evvnt_Calendar_Renderer::icon( 'pin', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $evvnt_where ); ?></span></li>
			<?php endif; ?>
			<?php if ( $evvnt_price ) : ?>
				<li class="evvnt-cal__meta-price"><?php echo Evvnt_Calendar_Renderer::icon( 'ticket', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $evvnt_price ); ?></span></li>
			<?php endif; ?>
		</ul>
	</div>
</article>
