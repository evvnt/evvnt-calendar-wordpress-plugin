<?php
/**
 * Shown (with a 404 status) when an event link no longer exists.
 *
 * Override by copying to {your-theme}/evvnt-calendar/event-not-found.php.
 *
 * @var array $args { @type string $back_url }
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="evvnt-cal evvnt-cal--not-found">
	<p class="evvnt-cal__notice"><?php esc_html_e( 'Sorry, that event could not be found. It may have been removed.', 'evvnt-calendar' ); ?></p>
	<p class="evvnt-cal__back"><a href="<?php echo esc_url( $args['back_url'] ); ?>"><?php esc_html_e( '← All events', 'evvnt-calendar' ); ?></a></p>
</div>
