<?php
/**
 * Event detail body shared by both detail layouts: "About This Event" (description,
 * featuring, organizer, clickable categories) beside a right-hand column with share
 * buttons, the "Where It's Happening" panel and upcoming dates, then Recommended Events.
 *
 * Override by copying to {your-theme}/evvnt-calendar/event-body.php.
 *
 * @var array $args {
 *     @type Evvnt_Calendar_Event    $event
 *     @type string                  $url           Canonical URL of this event page.
 *     @type string                  $base          Calendar page URL.
 *     @type Evvnt_Calendar_Event[]  $recommended
 *     @type string                  $submit_url
 *     @type string                  $submit_label
 *     @type bool                    $summary_shown Whether the summary is already shown above.
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
$evvnt_link    = $evvnt_event->cta_url();
$evvnt_rel     = $evvnt_event->is_affiliate_link() ? 'sponsored noopener' : 'noopener';
$evvnt_cta     = $evvnt_event->tickets_link() ? __( 'Buy Tickets', 'evvnt-calendar' ) : __( 'More Information', 'evvnt-calendar' );
$evvnt_html    = $evvnt_event->description_html();
$evvnt_text    = $evvnt_event->description();
$evvnt_dates   = $evvnt_event->upcoming_dates();
$evvnt_address = $evvnt_event->address_lines();
$evvnt_map     = $evvnt_event->is_virtual() ? '' : $evvnt_event->map_url();
$evvnt_static  = $evvnt_event->is_virtual() ? '' : $evvnt_event->static_map_url();
$evvnt_expired = $evvnt_event->is_expired();
$evvnt_share   = rawurlencode( $args['url'] );
$evvnt_title   = rawurlencode( $evvnt_event->title() );
$evvnt_summary = empty( $args['summary_shown'] );

// Every category links to the calendar: its own page, or its parent's filtered to it.
$evvnt_cat_links = $args['renderer']->category_links( $evvnt_event, $args['base'] );
?>
<div class="evvnt-cal__body">
	<article class="evvnt-cal__about">
		<h2><?php esc_html_e( 'About This Event', 'evvnt-calendar' ); ?></h2>

		<?php if ( $evvnt_summary && $evvnt_event->summary_adds_information() && ( '' !== $evvnt_text || '' !== trim( wp_strip_all_tags( $evvnt_html ) ) ) ) : ?>
			<p class="evvnt-cal__summary"><?php echo esc_html( $evvnt_event->summary() ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== trim( wp_strip_all_tags( $evvnt_html ) ) ) : ?>
			<div class="evvnt-cal__description"><?php echo wp_kses_post( $evvnt_html ); ?></div>
		<?php elseif ( '' !== $evvnt_text ) : ?>
			<div class="evvnt-cal__description"><?php echo wp_kses_post( wpautop( esc_html( $evvnt_text ) ) ); ?></div>
		<?php elseif ( $evvnt_summary && $evvnt_event->summary() ) : ?>
			<p class="evvnt-cal__summary"><?php echo esc_html( $evvnt_event->summary() ); ?></p>
		<?php endif; ?>

		<?php if ( $evvnt_event->artists() || $evvnt_event->organiser_name() || $evvnt_cat_links || $evvnt_event->category_names() ) : ?>
			<dl class="evvnt-cal__details">
				<?php if ( $evvnt_event->artists() ) : ?>
					<dt><?php esc_html_e( 'Featuring', 'evvnt-calendar' ); ?></dt>
					<dd><?php echo esc_html( implode( ', ', $evvnt_event->artists() ) ); ?></dd>
				<?php endif; ?>
				<?php if ( $evvnt_event->organiser_name() ) : ?>
					<dt><?php esc_html_e( 'Organizer', 'evvnt-calendar' ); ?></dt>
					<dd><?php echo esc_html( $evvnt_event->organiser_name() ); ?></dd>
				<?php endif; ?>
				<?php if ( $evvnt_cat_links ) : ?>
					<dt><?php esc_html_e( 'Category', 'evvnt-calendar' ); ?></dt>
					<dd class="evvnt-cal__categories">
						<?php foreach ( $evvnt_cat_links as $evvnt_cat ) : ?>
							<a href="<?php echo esc_url( $evvnt_cat['url'] ); ?>"><?php echo esc_html( $evvnt_cat['name'] ); ?></a>
						<?php endforeach; ?>
					</dd>
				<?php elseif ( $evvnt_event->category_names() ) : ?>
					<dt><?php esc_html_e( 'Category', 'evvnt-calendar' ); ?></dt>
					<dd><?php echo esc_html( implode( ', ', $evvnt_event->category_names() ) ); ?></dd>
				<?php endif; ?>
			</dl>
		<?php endif; ?>
	</article>

	<aside class="evvnt-cal__side">
		<div class="evvnt-cal__share-row">
			<span><?php esc_html_e( 'Share this event', 'evvnt-calendar' ); ?></span>
			<a href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . $evvnt_share ); ?>" target="_blank" rel="noopener nofollow" aria-label="<?php esc_attr_e( 'Share on Facebook', 'evvnt-calendar' ); ?>"><svg class="evvnt-cal__icon" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M14 8V6.5c0-.8.2-1.5 1.5-1.5H17V2h-2.6C11.6 2 11 3.8 11 6v2H9v3h2v11h3V11h2.6l.4-3z"/></svg></a>
			<a href="<?php echo esc_url( 'https://x.com/intent/post?url=' . $evvnt_share . '&text=' . $evvnt_title ); ?>" target="_blank" rel="noopener nofollow" aria-label="<?php esc_attr_e( 'Share on X', 'evvnt-calendar' ); ?>"><svg class="evvnt-cal__icon" viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true" focusable="false"><path d="M17.8 2h3.4l-7.4 8.5L22.5 22h-6.8l-5.3-7-6.1 7H.9l7.9-9.1L.5 2h7l4.8 6.4L17.8 2zm-1.2 18h1.9L6.5 3.9H4.5L16.6 20z"/></svg></a>
			<a href="<?php echo esc_url( 'mailto:?subject=' . $evvnt_title . '&body=' . $evvnt_share ); ?>" aria-label="<?php esc_attr_e( 'Share by email', 'evvnt-calendar' ); ?>"><?php echo $evvnt_icon( 'mail', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
		</div>

		<div class="evvnt-cal__panel evvnt-cal__panel--where">
			<h2><?php esc_html_e( 'Where It\'s Happening', 'evvnt-calendar' ); ?></h2>
			<?php if ( $evvnt_static ) : ?>
				<a class="evvnt-cal__panel-map" href="<?php echo esc_url( $evvnt_map ); ?>" target="_blank" rel="noopener nofollow">
					<?php // esc_url() strips the %0A padding Google signed; static_map_url() only returns https://maps.googleapis.com/ URLs. ?>
					<img src="<?php echo esc_attr( $evvnt_static ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: venue name. */ __( 'Map showing %s', 'evvnt-calendar' ), $evvnt_event->venue_name() ) ); ?>" loading="lazy" decoding="async">
				</a>
			<?php endif; ?>
			<div class="evvnt-cal__fact evvnt-cal__fact--place">
				<?php echo $evvnt_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				<span>
					<strong><?php echo esc_html( $evvnt_event->venue_name() ? $evvnt_event->venue_name() : __( 'Venue to be announced', 'evvnt-calendar' ) ); ?></strong>
					<?php foreach ( $evvnt_address as $evvnt_line ) : ?>
						<span class="evvnt-cal__muted"><?php echo esc_html( $evvnt_line ); ?></span>
					<?php endforeach; ?>
				</span>
			</div>
			<?php if ( $evvnt_map ) : ?>
				<a class="evvnt-cal__button evvnt-cal__button--outline" href="<?php echo esc_url( $evvnt_map ); ?>" rel="nofollow noopener" target="_blank"><?php echo $evvnt_icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'View on Google Maps', 'evvnt-calendar' ); ?></a>
			<?php endif; ?>
			<?php if ( $evvnt_link && ! $evvnt_expired ) : ?>
				<a class="evvnt-cal__button evvnt-cal__button--outline" href="<?php echo esc_url( $evvnt_link ); ?>" rel="<?php echo esc_attr( $evvnt_rel ); ?>" target="_blank"><?php echo $evvnt_icon( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( $evvnt_cta ); ?></a>
			<?php endif; ?>
		</div>

		<?php if ( count( $evvnt_dates ) > 1 ) : ?>
			<div class="evvnt-cal__panel evvnt-cal__dates">
				<h2><?php esc_html_e( 'Upcoming Dates', 'evvnt-calendar' ); ?></h2>
				<ul>
					<?php foreach ( $evvnt_dates as $evvnt_date ) : ?>
						<li><time datetime="<?php echo esc_attr( $evvnt_date['start']->format( DATE_ATOM ) ); ?>"><?php echo esc_html( $evvnt_event->format( $evvnt_date['start'], 'D, M j, Y · g:i A' ) ); ?></time></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ( $args['submit_url'] ) : ?>
			<p class="evvnt-cal__promote"><a href="<?php echo esc_url( $args['submit_url'] ); ?>" rel="nofollow noopener" target="_blank"><?php echo esc_html( $args['submit_label'] ); ?> →</a></p>
		<?php endif; ?>
	</aside>
</div>

<?php if ( ! empty( $args['recommended'] ) ) : ?>
	<section class="evvnt-cal__recommended" aria-labelledby="evvnt-cal-recommended-title">
		<h2 id="evvnt-cal-recommended-title"><?php esc_html_e( 'Recommended Events', 'evvnt-calendar' ); ?></h2>
		<ul class="evvnt-cal__recommended-grid">
			<?php foreach ( $args['recommended'] as $evvnt_other ) : ?>
				<li><?php echo $args['renderer']->render_card( $evvnt_other, $args['base'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?></li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>
