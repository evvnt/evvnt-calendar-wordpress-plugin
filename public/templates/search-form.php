<?php
/**
 * Calendar search box. Pressing Enter submits to the results page (?searchTerm=…); with
 * JavaScript it also shows live suggestions (matching categories and events) as an
 * accessible combobox.
 *
 * Override by copying to {your-theme}/evvnt-calendar/search-form.php (keep the
 * data-suggest / data-base attributes and the listbox for suggestions to work).
 *
 * @var array $args {
 *     @type string $action  Form target (calendar page URL without its query string).
 *     @type array  $hidden  Query arguments the action needs (e.g. page_id on plain permalinks).
 *     @type string $value   Current search text.
 *     @type string $suggest REST endpoint for suggestions ('' for a plain search box).
 *     @type string $base    Calendar page URL, sent with suggestion requests to build links.
 *     @type array  $filters Calendar filters suggestions must respect ( category, virtual ).
 *     @type string $placeholder Placeholder text.
 *     @type string $variant     Look modifier ('widget' for the compact widget), or ''.
 * }
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evvnt_id = wp_unique_id( 'evvnt-cal-search-' );
?>
<form class="evvnt-cal__search<?php echo ! empty( $args['variant'] ) ? ' evvnt-cal__search--' . esc_attr( $args['variant'] ) : ''; ?>" role="search" method="get" action="<?php echo esc_url( $args['action'] ); ?>" data-suggest="<?php echo esc_url( $args['suggest'] ); ?>" data-base="<?php echo esc_url( $args['base'] ); ?>"<?php foreach ( isset( $args['filters'] ) ? $args['filters'] : array() as $evvnt_key => $evvnt_val ) : ?> data-<?php echo esc_attr( $evvnt_key ); ?>="<?php echo esc_attr( $evvnt_val ); ?>"<?php endforeach; ?>>
	<?php foreach ( $args['hidden'] as $evvnt_name => $evvnt_value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $evvnt_name ); ?>" value="<?php echo esc_attr( $evvnt_value ); ?>">
	<?php endforeach; ?>
	<?php $evvnt_placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : __( 'Search events and categories', 'evvnt-calendar' ); ?>
	<label class="screen-reader-text" for="<?php echo esc_attr( $evvnt_id ); ?>"><?php echo esc_html( $evvnt_placeholder ); ?></label>
	<div class="evvnt-cal__search-field">
		<?php echo Evvnt_Calendar_Renderer::icon( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
		<input type="search" id="<?php echo esc_attr( $evvnt_id ); ?>" name="searchTerm" value="<?php echo esc_attr( $args['value'] ); ?>"
			placeholder="<?php echo esc_attr( $evvnt_placeholder ); ?>"
			maxlength="100"
			<?php if ( ! empty( $args['suggest'] ) ) : ?>
				autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="<?php echo esc_attr( $evvnt_id ); ?>-list"
			<?php endif; ?>>
	</div>
	<?php if ( ! empty( $args['suggest'] ) ) : ?>
		<ul class="evvnt-cal__suggest" id="<?php echo esc_attr( $evvnt_id ); ?>-list" role="listbox" aria-label="<?php esc_attr_e( 'Suggestions', 'evvnt-calendar' ); ?>" hidden></ul>
		<p class="evvnt-cal__suggest-status screen-reader-text" aria-live="polite"></p>
	<?php endif; ?>
</form>
