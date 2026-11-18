<?php
/**
 * Single item page.
 *
 * Copy this file into your theme to change it.
 *
 * Anyone can see the item. Logged-in members can request it; visitors are
 * asked to log in or join.
 *
 * @package TAAS_Loan_Program
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

global $wpdb;

$taas_post     = get_queried_object();
$taas_user_id  = get_current_user_id();
$taas_loan     = TAAS_Loan_Shortcodes::loan( $taas_post->ID );
$taas_status   = $taas_loan['status'];
$taas_is_mine  = $taas_user_id && $taas_loan['user_id'] === $taas_user_id;
$taas_category = TAAS_Loan_Shortcodes::primary_category( $taas_post->ID );
$taas_back_url = TAAS_Loan_Shortcodes::loan_page_url( $taas_category ? $taas_category->slug : '' );
$taas_reviews  = ! scwp_get_option( 'hide_comments' );
?>
<div class="taas-loan-page">
	<div class="taas-loan">

	<?php if ( ! is_user_logged_in() && ! scwp_get_option( 'public' ) ) : ?>

		<div class="taas-loan-callout">
			<p><strong><?php esc_html_e( 'The equipment list is for TAAS members.', 'sharing-club' ); ?></strong></p>
			<p class="taas-loan-callout-actions">
				<a class="taas-loan-button" href="<?php echo esc_url( TAAS_Loan_Shortcodes::login_url( get_permalink( $taas_post ) ) ); ?>"><?php esc_html_e( 'Log in', 'sharing-club' ); ?></a>
				<?php if ( TAAS_Loan_Shortcodes::join_url() ) : ?>
					<a class="taas-loan-button taas-loan-button-outline" href="<?php echo esc_url( TAAS_Loan_Shortcodes::join_url() ); ?>"><?php esc_html_e( 'Join TAAS', 'sharing-club' ); ?></a>
				<?php endif; ?>
			</p>
		</div>

	<?php else : ?>

		<p class="taas-loan-back">
			<a href="<?php echo esc_url( $taas_back_url ); ?>">&larr;
				<?php
				if ( $taas_category ) {
					/* translators: %s: category name, e.g. Telescopes. */
					echo esc_html( sprintf( __( 'Back to %s', 'sharing-club' ), $taas_category->name ) );
				} else {
					esc_html_e( 'All equipment', 'sharing-club' );
				}
				?>
			</a>
		</p>

		<article class="taas-loan-detail">
			<div class="taas-loan-detail-image">
				<?php echo get_the_post_thumbnail( $taas_post, 'large' ); ?>
			</div>

			<div class="taas-loan-detail-body">
				<?php if ( $taas_category ) : ?>
					<p class="taas-loan-detail-cat"><?php echo esc_html( $taas_category->name ); ?></p>
				<?php endif; ?>

				<h1 class="taas-loan-detail-title"><?php echo esc_html( get_the_title( $taas_post ) ); ?></h1>

				<p class="taas-loan-detail-status"><?php echo TAAS_Loan_Shortcodes::status_badge( $taas_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_html/esc_attr. ?></p>

				<?php
				if ( $taas_reviews ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$taas_avg = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ROUND(AVG(comment_karma), 0) FROM {$wpdb->comments} WHERE comment_type = 'lending' AND comment_karma > 0 AND comment_post_ID = %d", $taas_post->ID ) );
					if ( $taas_avg ) {
						echo '<p class="taas-loan-stars" aria-label="' . esc_attr( sprintf( /* translators: %d: rating 1-5. */ __( 'Rated %d out of 5', 'sharing-club' ), $taas_avg ) ) . '">';
						for ( $taas_i = 1; $taas_i <= 5; $taas_i++ ) {
							echo '<span class="' . ( $taas_i <= $taas_avg ? 'is-on' : '' ) . '" aria-hidden="true">★</span>';
						}
						echo '</p>';
					}
				}
				?>

				<div class="taas-loan-detail-desc">
					<?php echo TAAS_Loan_Shortcodes::description_html( $taas_post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- post content, formatted like the_content(). ?>
				</div>

				<div class="taas-loan-request">
					<?php if ( ! is_user_logged_in() ) : ?>

						<div class="taas-loan-callout">
							<p><strong><?php esc_html_e( 'TAAS members can borrow this for free.', 'sharing-club' ); ?></strong></p>
							<p class="taas-loan-callout-actions">
								<a class="taas-loan-button" href="<?php echo esc_url( TAAS_Loan_Shortcodes::login_url( get_permalink( $taas_post ) ) ); ?>"><?php esc_html_e( 'Log in to request', 'sharing-club' ); ?></a>
								<?php if ( TAAS_Loan_Shortcodes::join_url() ) : ?>
									<a class="taas-loan-button taas-loan-button-outline" href="<?php echo esc_url( TAAS_Loan_Shortcodes::join_url() ); ?>"><?php esc_html_e( 'Join TAAS', 'sharing-club' ); ?></a>
								<?php endif; ?>
							</p>
						</div>

					<?php elseif ( $taas_is_mine && 'requested' === $taas_status ) : ?>

						<p class="taas-loan-note"><?php esc_html_e( 'You have requested this item. The Loan Program Manager will be in touch to arrange pickup.', 'sharing-club' ); ?></p>

					<?php elseif ( $taas_is_mine && 'out' === $taas_status ) : ?>

						<p class="taas-loan-note"><?php esc_html_e( 'You have this item. Contact the Loan Program Manager to arrange a return.', 'sharing-club' ); ?></p>

					<?php elseif ( 'in' !== $taas_status ) : ?>

						<p class="taas-loan-note"><?php esc_html_e( 'This item is currently on loan to another member.', 'sharing-club' ); ?></p>

					<?php else : ?>

						<form method="post" action="<?php echo esc_url( get_permalink( $taas_post ) ); ?>">
							<?php wp_nonce_field( 'booking', 'booking_nonce' ); ?>
							<input type="hidden" name="iwantit" value="1" />
							<button type="submit" class="taas-loan-button"><?php esc_html_e( 'Request this item', 'sharing-club' ); ?></button>
						</form>

					<?php endif; ?>
				</div>
			</div>
		</article>

		<?php
		if ( $taas_reviews ) :
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$taas_review_rows = $wpdb->get_col( $wpdb->prepare( "SELECT comment_content FROM {$wpdb->comments} WHERE comment_type = 'lending' AND comment_post_ID = %d AND comment_content != '' ORDER BY comment_ID DESC", $taas_post->ID ) );

			// The member's own latest loan of this item that still has no review or rating.
			$taas_past = $taas_user_id ? $wpdb->get_row( $wpdb->prepare( "SELECT comment_ID, comment_karma AS rating, comment_content FROM {$wpdb->comments} WHERE comment_type = 'lending' AND user_id = %d AND comment_post_ID = %d AND comment_date != '0000-00-00 00:00:00' ORDER BY comment_ID DESC LIMIT 1", $taas_user_id, $taas_post->ID ) ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			?>

			<?php if ( $taas_review_rows ) : ?>
				<section class="taas-loan-reviews">
					<h2><?php esc_html_e( 'Reviews', 'sharing-club' ); ?></h2>
					<?php foreach ( $taas_review_rows as $taas_review ) : ?>
						<blockquote><?php echo wp_kses_post( wpautop( stripslashes( $taas_review ) ) ); ?></blockquote>
					<?php endforeach; ?>
				</section>
			<?php endif; ?>

			<?php if ( $taas_past && ( 0 === (int) $taas_past->rating || '' === $taas_past->comment_content ) ) : ?>
				<section class="taas-loan-reviews">
					<form method="post" action="<?php echo esc_url( get_permalink( $taas_post ) ); ?>">
						<h2><?php esc_html_e( 'Your review', 'sharing-club' ); ?></h2>
						<?php wp_nonce_field( 'review', 'review_nonce' ); ?>
						<?php if ( '' === $taas_past->comment_content ) : ?>
							<p><textarea name="comment_content" rows="4"></textarea></p>
						<?php endif; ?>
						<?php if ( 0 === (int) $taas_past->rating ) : ?>
							<fieldset class="rating">
								<legend><?php esc_html_e( 'Your rating', 'sharing-club' ); ?></legend>
								<?php for ( $taas_i = 5; $taas_i >= 1; $taas_i-- ) : ?>
									<input type="radio" id="star<?php echo (int) $taas_i; ?>" name="comment_karma" value="<?php echo (int) $taas_i; ?>" /><label for="star<?php echo (int) $taas_i; ?>">
										<?php
										/* translators: %d: number of stars. */
										echo esc_html( sprintf( _n( '%d star', '%d stars', $taas_i, 'sharing-club' ), $taas_i ) );
										?>
									</label>
								<?php endfor; ?>
							</fieldset>
						<?php endif; ?>
						<input type="hidden" name="comment_ID" value="<?php echo (int) $taas_past->comment_ID; ?>" />
						<p class="clear"><button type="submit" class="taas-loan-button"><?php esc_html_e( 'Send', 'sharing-club' ); ?></button></p>
					</form>
				</section>
			<?php endif; ?>

		<?php endif; ?>

	<?php endif; ?>

	</div>
</div>
<?php
get_footer();
