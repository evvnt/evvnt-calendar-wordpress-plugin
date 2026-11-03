<?php
/**
 * One event in the calendar list.
 *
 * Override by copying to {your-theme}/evvnt-calendar/event-card.php.
 *
 * @var array $args {
 *     @type Evvnt_Calendar_Event $event
 *     @type string               $url       Detail page URL (or the event's own link).
 *     @type bool                 $external  Whether $url leaves the site.
 *     @type bool                 $featured
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
?>
<article class="evvnt-cal__card<?php echo $args['featured'] ? ' evvnt-cal__card--featured' : ''; ?>">
	<?php if ( $evvnt_image ) : ?>
		<a class="evvnt-cal__card-image" href="<?php echo esc_url( $args['url'] ); ?>"<?php echo $evvnt_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?> tabindex="-1" aria-hidden="true">
			<img src="<?php echo esc_url( $evvnt_image ); ?>" alt="" loading="lazy" decoding="async">
		</a>
	<?php endif; ?>
	<div class="evvnt-cal__card-body">
		<h3 class="evvnt-cal__card-title"><a href="<?php echo esc_url( $args['url'] ); ?>"<?php echo $evvnt_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?>><?php echo esc_html( $evvnt_event->title() ); ?></a></h3>
		<ul class="evvnt-cal__meta">
			<?php if ( $evvnt_event->start() ) : ?>
				<li class="evvnt-cal__meta-date"><time datetime="<?php echo esc_attr( $evvnt_event->start()->format( DATE_ATOM ) ); ?>"><?php echo esc_html( $evvnt_event->display_date() ); ?></time></li>
			<?php endif; ?>
			<?php if ( $evvnt_where ) : ?>
				<li class="evvnt-cal__meta-place"><?php echo esc_html( $evvnt_where ); ?></li>
			<?php endif; ?>
			<?php if ( $evvnt_price ) : ?>
				<li class="evvnt-cal__meta-price"><?php echo esc_html( $evvnt_price ); ?></li>
			<?php endif; ?>
		</ul>
	</div>
</article>
