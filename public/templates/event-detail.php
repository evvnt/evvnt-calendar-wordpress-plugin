<?php
/**
 * Event detail view.
 *
 * Override by copying to {your-theme}/evvnt-calendar/event-detail.php.
 *
 * @var array $args {
 *     @type Evvnt_Calendar_Event $event
 *     @type string               $back_url
 *     @type string               $submit_url
 *     @type string               $submit_label
 * }
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evvnt_event   = $args['event'];
$evvnt_image   = $evvnt_event->hero_image_url();
$evvnt_link    = $evvnt_event->main_link();
$evvnt_rel     = $evvnt_event->is_affiliate_link() ? 'sponsored noopener' : 'noopener';
$evvnt_price   = $evvnt_event->price_range();
$evvnt_html    = $evvnt_event->description_html();
$evvnt_text    = $evvnt_event->description();
$evvnt_dates   = $evvnt_event->upcoming_dates();
$evvnt_address = $evvnt_event->address_lines();
$evvnt_map     = $evvnt_event->is_virtual() ? '' : $evvnt_event->map_url();
?>
<div class="evvnt-cal evvnt-cal--detail">
	<p class="evvnt-cal__back"><a href="<?php echo esc_url( $args['back_url'] ); ?>"><?php esc_html_e( '← All events', 'evvnt-calendar' ); ?></a></p>

	<article class="evvnt-cal__event">
		<?php if ( $evvnt_event->is_expired() ) : ?>
			<p class="evvnt-cal__notice"><?php esc_html_e( 'This event has ended.', 'evvnt-calendar' ); ?></p>
		<?php endif; ?>

		<?php if ( $evvnt_image ) : ?>
			<figure class="evvnt-cal__hero"><img src="<?php echo esc_url( $evvnt_image ); ?>" alt="<?php echo esc_attr( $evvnt_event->title() ); ?>" decoding="async"></figure>
		<?php endif; ?>

		<h2 class="evvnt-cal__title"><?php echo esc_html( $evvnt_event->title() ); ?></h2>

		<?php if ( $evvnt_event->summary() ) : ?>
			<p class="evvnt-cal__summary"><?php echo esc_html( $evvnt_event->summary() ); ?></p>
		<?php endif; ?>

		<dl class="evvnt-cal__facts">
			<?php if ( $evvnt_event->start() ) : ?>
				<dt><?php esc_html_e( 'When', 'evvnt-calendar' ); ?></dt>
				<dd><time datetime="<?php echo esc_attr( $evvnt_event->start()->format( DATE_ATOM ) ); ?>"><?php echo esc_html( $evvnt_event->display_date() ); ?></time></dd>
			<?php endif; ?>

			<?php if ( $evvnt_event->venue_name() || $evvnt_address ) : ?>
				<dt><?php esc_html_e( 'Where', 'evvnt-calendar' ); ?></dt>
				<dd>
					<?php if ( $evvnt_event->venue_name() ) : ?>
						<strong><?php echo esc_html( $evvnt_event->venue_name() ); ?></strong><br>
					<?php endif; ?>
					<?php if ( $evvnt_address ) : ?>
						<?php echo esc_html( implode( ', ', $evvnt_address ) ); ?><br>
					<?php endif; ?>
					<?php if ( $evvnt_map ) : ?>
						<a href="<?php echo esc_url( $evvnt_map ); ?>" rel="nofollow noopener" target="_blank"><?php esc_html_e( 'View map', 'evvnt-calendar' ); ?></a>
					<?php endif; ?>
				</dd>
			<?php endif; ?>

			<?php if ( $evvnt_price ) : ?>
				<dt><?php esc_html_e( 'Price', 'evvnt-calendar' ); ?></dt>
				<dd><?php echo esc_html( $evvnt_price ); ?></dd>
			<?php endif; ?>

			<?php if ( $evvnt_event->organiser_name() ) : ?>
				<dt><?php esc_html_e( 'Organizer', 'evvnt-calendar' ); ?></dt>
				<dd><?php echo esc_html( $evvnt_event->organiser_name() ); ?></dd>
			<?php endif; ?>

			<?php if ( $evvnt_event->artists() ) : ?>
				<dt><?php esc_html_e( 'Featuring', 'evvnt-calendar' ); ?></dt>
				<dd><?php echo esc_html( implode( ', ', $evvnt_event->artists() ) ); ?></dd>
			<?php endif; ?>

			<?php if ( $evvnt_event->category_names() ) : ?>
				<dt><?php esc_html_e( 'Category', 'evvnt-calendar' ); ?></dt>
				<dd><?php echo esc_html( implode( ', ', $evvnt_event->category_names() ) ); ?></dd>
			<?php endif; ?>
		</dl>

		<?php if ( $evvnt_link && ! $evvnt_event->is_expired() ) : ?>
			<p><a class="evvnt-cal__button evvnt-cal__cta" href="<?php echo esc_url( $evvnt_link ); ?>" rel="<?php echo esc_attr( $evvnt_rel ); ?>" target="_blank"><?php echo esc_html( $evvnt_event->tickets_link() ? __( 'Get tickets', 'evvnt-calendar' ) : __( 'More information', 'evvnt-calendar' ) ); ?></a></p>
		<?php endif; ?>

		<?php if ( '' !== trim( wp_strip_all_tags( $evvnt_html ) ) ) : ?>
			<div class="evvnt-cal__description"><?php echo wp_kses_post( $evvnt_html ); ?></div>
		<?php elseif ( '' !== $evvnt_text ) : ?>
			<div class="evvnt-cal__description"><?php echo wp_kses_post( wpautop( esc_html( $evvnt_text ) ) ); ?></div>
		<?php endif; ?>

		<?php if ( count( $evvnt_dates ) > 1 ) : ?>
			<section class="evvnt-cal__dates">
				<h3><?php esc_html_e( 'Upcoming dates', 'evvnt-calendar' ); ?></h3>
				<ul>
					<?php foreach ( $evvnt_dates as $evvnt_date ) : ?>
						<li><time datetime="<?php echo esc_attr( $evvnt_date['start']->format( DATE_ATOM ) ); ?>"><?php echo esc_html( $evvnt_event->format( $evvnt_date['start'], 'D, M j, Y g:i A' ) ); ?></time></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
	</article>

	<?php if ( $args['submit_url'] ) : ?>
		<p class="evvnt-cal__toolbar"><a class="evvnt-cal__button evvnt-cal__submit" href="<?php echo esc_url( $args['submit_url'] ); ?>" rel="nofollow noopener" target="_blank"><?php echo esc_html( $args['submit_label'] ); ?></a></p>
	<?php endif; ?>
</div>
