<?php
/**
 * Upcoming dates of a recurring event series, as the discovery plugin shows them: up to 3
 * dates as chips; up to 18 as a list; more as a list of the first 12 with "Show all N dates",
 * or a month calendar when the dates are dense. Each date links to its own event page.
 *
 * Override by copying to {your-theme}/evvnt-calendar/event-dates.php.
 *
 * @var array $args {
 *     @type array $dates {
 *         @type string  $id           Anchor id of the section.
 *         @type string  $view         'chips', 'list' or 'calendar'.
 *         @type string  $caption
 *         @type array[] $toggle       List/Calendar switch: { label, pressed, url } (empty if none).
 *         @type array[] $items        Chips/list dates: { tone ('viewed'|'next'|'upcoming'), day, time, datetime, url ('' when not a link) }.
 *         @type int     $total        Number of upcoming dates.
 *         @type string  $show_all_url "Show all" link of a windowed list, or ''.
 *         @type array[] $months       Calendar months: { key, label, current, weeks: [ [ { day, date, label (full date), scheduled, next, viewed, url } | null ] ] }.
 *     }
 *     @type Evvnt_Calendar_Renderer $renderer
 * }
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evvnt_dates  = $args['dates'];
$evvnt_icon   = array( 'Evvnt_Calendar_Renderer', 'icon' );
$evvnt_badges = array(
	'viewed' => __( 'Viewing', 'evvnt-calendar' ),
	'next'   => __( 'Next', 'evvnt-calendar' ),
);
?>
<section class="evvnt-cal__series evvnt-cal__series--<?php echo esc_attr( $evvnt_dates['view'] ); ?>" id="<?php echo esc_attr( $evvnt_dates['id'] ); ?>" aria-labelledby="<?php echo esc_attr( $evvnt_dates['id'] ); ?>-title">
	<header class="evvnt-cal__series-head">
		<div>
			<h2 class="evvnt-cal__series-title" id="<?php echo esc_attr( $evvnt_dates['id'] ); ?>-title"><?php esc_html_e( 'Upcoming dates', 'evvnt-calendar' ); ?></h2>
			<p class="evvnt-cal__series-caption"><?php echo esc_html( $evvnt_dates['caption'] ); ?></p>
		</div>
		<?php if ( $evvnt_dates['toggle'] ) : ?>
			<nav class="evvnt-cal__series-toggle" aria-label="<?php esc_attr_e( 'Show dates as', 'evvnt-calendar' ); ?>">
				<?php foreach ( $evvnt_dates['toggle'] as $evvnt_option ) : ?>
					<a href="<?php echo esc_url( $evvnt_option['url'] ); ?>" rel="nofollow"<?php echo $evvnt_option['pressed'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $evvnt_option['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	</header>

	<div class="evvnt-cal__series-body">
		<?php if ( 'calendar' === $evvnt_dates['view'] ) : ?>
			<?php Evvnt_Calendar_Renderer::enqueue_dates_script(); ?>
			<div class="evvnt-cal__datecal">
				<div class="evvnt-cal__datecal-nav" hidden>
					<button type="button" data-dir="-1" aria-label="<?php esc_attr_e( 'Previous month', 'evvnt-calendar' ); ?>"><?php echo $evvnt_icon( 'prev', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
					<span class="evvnt-cal__datecal-label" aria-live="polite"></span>
					<button type="button" data-dir="1" aria-label="<?php esc_attr_e( 'Next month', 'evvnt-calendar' ); ?>"><?php echo $evvnt_icon( 'next', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
				</div>
				<?php foreach ( $evvnt_dates['months'] as $evvnt_month ) : ?>
					<div class="evvnt-cal__datecal-month" data-label="<?php echo esc_attr( $evvnt_month['label'] ); ?>"<?php echo $evvnt_month['current'] ? ' data-current' : ''; ?>>
						<h3 class="evvnt-cal__datecal-caption" id="<?php echo esc_attr( $evvnt_dates['id'] . '-' . $evvnt_month['key'] ); ?>"><?php echo esc_html( $evvnt_month['label'] ); ?></h3>
						<table aria-labelledby="<?php echo esc_attr( $evvnt_dates['id'] . '-' . $evvnt_month['key'] ); ?>">
							<thead>
								<tr>
									<?php foreach ( Evvnt_Calendar_Renderer::calendar_weekdays() as $evvnt_weekday ) : ?>
										<th scope="col"><abbr title="<?php echo esc_attr( $evvnt_weekday['full'] ); ?>"><?php echo esc_html( $evvnt_weekday['short'] ); ?></abbr></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $evvnt_month['weeks'] as $evvnt_week ) : ?>
									<tr>
										<?php foreach ( $evvnt_week as $evvnt_cell ) : ?>
											<?php if ( ! $evvnt_cell ) : ?>
												<td></td>
												<?php continue; ?>
											<?php endif; ?>
											<?php
											$evvnt_classes = array_keys( array_filter( array( 'is-scheduled' => $evvnt_cell['scheduled'], 'is-next' => $evvnt_cell['next'], 'is-viewed' => $evvnt_cell['viewed'] ) ) );
											// Name each date in full; the next and viewed dates say so, not by colour alone.
											$evvnt_name = $evvnt_cell['label'];
											if ( $evvnt_cell['viewed'] ) {
												/* translators: %s: full date. */
												$evvnt_name = sprintf( __( '%s, viewing', 'evvnt-calendar' ), $evvnt_name );
											} elseif ( $evvnt_cell['next'] ) {
												/* translators: %s: full date. */
												$evvnt_name = sprintf( __( '%s, next date', 'evvnt-calendar' ), $evvnt_name );
											}
											?>
											<td class="<?php echo esc_attr( implode( ' ', $evvnt_classes ) ); ?>">
												<?php if ( $evvnt_cell['url'] ) : ?>
													<a href="<?php echo esc_url( $evvnt_cell['url'] ); ?>" aria-label="<?php echo esc_attr( $evvnt_name ); ?>"><time datetime="<?php echo esc_attr( $evvnt_cell['date'] ); ?>"><?php echo (int) $evvnt_cell['day']; ?></time></a>
												<?php else : ?>
													<span<?php echo $evvnt_cell['viewed'] ? ' aria-current="date"' : ''; ?><?php echo $evvnt_cell['scheduled'] ? ' role="img" aria-label="' . esc_attr( $evvnt_name ) . '"' : ''; ?>><time datetime="<?php echo esc_attr( $evvnt_cell['date'] ); ?>"><?php echo (int) $evvnt_cell['day']; ?></time></span>
												<?php endif; ?>
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<ul class="evvnt-cal__series-dates evvnt-cal__series-dates--<?php echo esc_attr( $evvnt_dates['view'] ); ?>">
				<?php foreach ( $evvnt_dates['items'] as $evvnt_item ) : ?>
					<?php
					$evvnt_badge = ( 'list' === $evvnt_dates['view'] && isset( $evvnt_badges[ $evvnt_item['tone'] ] ) ) ? $evvnt_badges[ $evvnt_item['tone'] ] : '';
					$evvnt_tag   = $evvnt_item['url'] ? 'a' : 'span';
					?>
					<li>
						<<?php echo $evvnt_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- "a" or "span". ?> class="evvnt-cal__series-date is-<?php echo esc_attr( $evvnt_item['tone'] ); ?>"<?php echo $evvnt_item['url'] ? ' href="' . esc_url( $evvnt_item['url'] ) . '"' : ''; ?><?php echo 'viewed' === $evvnt_item['tone'] ? ' aria-current="page"' : ''; ?>>
							<?php if ( $evvnt_badge ) : ?>
								<span class="evvnt-cal__series-badge"><?php echo esc_html( $evvnt_badge ); ?></span>
							<?php endif; ?>
							<time datetime="<?php echo esc_attr( $evvnt_item['datetime'] ); ?>">
								<span class="evvnt-cal__series-day"><?php echo esc_html( $evvnt_item['day'] ); ?></span>
								<span class="evvnt-cal__series-time"><?php echo esc_html( $evvnt_item['time'] ); ?></span>
							</time>
						</<?php echo $evvnt_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- "a" or "span". ?>>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $evvnt_dates['show_all_url'] ) : ?>
				<p class="evvnt-cal__series-more">
					<a href="<?php echo esc_url( $evvnt_dates['show_all_url'] ); ?>" rel="nofollow">
						<?php
						/* translators: %s: number of upcoming dates. */
						echo esc_html( sprintf( __( 'Show all %s dates', 'evvnt-calendar' ), number_format_i18n( $evvnt_dates['total'] ) ) );
						?>
					</a>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</section>
