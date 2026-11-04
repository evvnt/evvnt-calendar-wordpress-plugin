<?php
/**
 * Calendar search box. Submits to the results page (?searchTerm=…); with JavaScript it also
 * shows live suggestions (matching categories and events) as an accessible combobox.
 *
 * Override by copying to {your-theme}/evvnt-calendar/search-form.php (keep the
 * data-suggest / data-base attributes and the listbox for suggestions to work).
 *
 * @var array $args {
 *     @type string $action  Form target (calendar page URL without its query string).
 *     @type array  $hidden  Query arguments the action needs (e.g. page_id on plain permalinks).
 *     @type string $value   Current search text.
 *     @type string $suggest REST endpoint for suggestions.
 *     @type string $base    Calendar page URL, sent with suggestion requests to build links.
 *     @type array  $filters Calendar filters suggestions must respect ( category, virtual ).
 * }
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evvnt_id = wp_unique_id( 'evvnt-cal-search-' );
?>
<form class="evvnt-cal__search" role="search" method="get" action="<?php echo esc_url( $args['action'] ); ?>" data-suggest="<?php echo esc_url( $args['suggest'] ); ?>" data-base="<?php echo esc_url( $args['base'] ); ?>"<?php foreach ( isset( $args['filters'] ) ? $args['filters'] : array() as $evvnt_key => $evvnt_val ) : ?> data-<?php echo esc_attr( $evvnt_key ); ?>="<?php echo esc_attr( $evvnt_val ); ?>"<?php endforeach; ?>>
	<?php foreach ( $args['hidden'] as $evvnt_name => $evvnt_value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $evvnt_name ); ?>" value="<?php echo esc_attr( $evvnt_value ); ?>">
	<?php endforeach; ?>
	<label class="screen-reader-text" for="<?php echo esc_attr( $evvnt_id ); ?>"><?php esc_html_e( 'Search events and categories', 'evvnt-calendar' ); ?></label>
	<div class="evvnt-cal__search-field">
		<?php echo Evvnt_Calendar_Renderer::icon( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
		<input type="search" id="<?php echo esc_attr( $evvnt_id ); ?>" name="searchTerm" value="<?php echo esc_attr( $args['value'] ); ?>"
			placeholder="<?php esc_attr_e( 'Search events and categories', 'evvnt-calendar' ); ?>"
			autocomplete="off" maxlength="100" role="combobox" aria-expanded="false" aria-autocomplete="list"
			aria-controls="<?php echo esc_attr( $evvnt_id ); ?>-list">
		<button type="submit" class="evvnt-cal__button"><?php esc_html_e( 'Search', 'evvnt-calendar' ); ?></button>
	</div>
	<ul class="evvnt-cal__suggest" id="<?php echo esc_attr( $evvnt_id ); ?>-list" role="listbox" aria-label="<?php esc_attr_e( 'Suggestions', 'evvnt-calendar' ); ?>" hidden></ul>
	<p class="evvnt-cal__suggest-status screen-reader-text" aria-live="polite"></p>
</form>
