<?php
/**
 * Shown when events cannot be loaded from Evvnt.
 *
 * Override by copying to {your-theme}/evvnt-calendar/unavailable.php.
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="evvnt-cal evvnt-cal--unavailable">
	<p class="evvnt-cal__notice"><?php esc_html_e( 'Events are temporarily unavailable. Please try again in a few minutes.', 'evvnt-calendar' ); ?></p>
</div>
