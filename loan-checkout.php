<?php
/**
 * "Check out" page: the Loan Director checks an item out to a member, and
 * the loan team and the member get an email with the return date.
 *
 * @package TAAS_Loan_Program
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual checkout.
 */
class TAAS_Loan_Checkout {

	/** Admin page slug. */
	const PAGE = 'taas_loan_checkout';

	/** Admin-post action that saves a checkout. */
	const ACTION = 'taas_loan_checkout';

	/** Default loan length, in days, for the return date field. */
	const DEFAULT_DAYS = 30;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Add TAAS Loan Program > Check out.
	 */
	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=shared_item',
			__( 'Check out an item', 'sharing-club' ),
			__( 'Check out', 'sharing-club' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Link to the checkout page, optionally with the item and member picked.
	 *
	 * @param int $item_id Item to pick.
	 * @param int $user_id Member to pick.
	 * @return string
	 */
	public static function page_url( $item_id = 0, $user_id = 0 ) {
		$args = array(
			'post_type' => 'shared_item',
			'page'      => self::PAGE,
		);
		if ( $item_id ) {
			$args['item'] = (int) $item_id;
		}
		if ( $user_id ) {
			$args['member'] = (int) $user_id;
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Read a YYYY-MM-DD date in the site's time zone.
	 *
	 * @param string $value Date from the form.
	 * @return DateTimeImmutable|null
	 */
	private static function parse_date( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value, wp_timezone() );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	/**
	 * Save a checkout, email everyone, and go back to the form.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sharing-club' ) );
		}
		check_admin_referer( self::ACTION );

		$item_id = isset( $_POST['item'] ) ? absint( $_POST['item'] ) : 0;
		$user_id = isset( $_POST['member'] ) ? absint( $_POST['member'] ) : 0;
		$lent    = self::parse_date( isset( $_POST['lent'] ) ? sanitize_text_field( wp_unslash( $_POST['lent'] ) ) : '' );
		$due     = self::parse_date( isset( $_POST['due'] ) ? sanitize_text_field( wp_unslash( $_POST['due'] ) ) : '' );
		$notes   = isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';
		$state   = isset( $_POST['state'] ) ? sanitize_key( $_POST['state'] ) : 'out';
		$notify  = ! empty( $_POST['notify'] );

		$post = get_post( $item_id );
		$user = get_userdata( $user_id );

		$error = '';
		if ( ! $post || 'shared_item' !== $post->post_type ) {
			$error = 'item';
		} elseif ( ! $user ) {
			$error = 'member';
		} elseif ( ! $lent || ! $due ) {
			$error = 'dates';
		} elseif ( $due < $lent ) {
			$error = 'order';
		} elseif ( ! isset( TAAS_Loan_Shortcodes::states()[ $state ] ) ) {
			$error = 'state';
		} elseif ( in_array( TAAS_Loan_Shortcodes::loan( $item_id )['status'], array( 'out', 'repair' ), true ) ) {
			$error = 'out';
		}
		if ( $error ) {
			self::back( array( 'error' => $error ) + self::picked( $item_id, $user_id ) );
		}

		global $wpdb;
		$fields = array(
			'comment_date'     => $lent->format( 'Y-m-d 00:00:00' ),
			// End of the day, so the item shows as out through the return date.
			'comment_date_gmt' => $due->format( 'Y-m-d 23:59:59' ),
		);
		if ( '' !== $notes ) {
			$fields['comment_agent'] = $notes;
		}

		// Turn the member's open request for this item into the loan, if there is one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$request_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments}
				WHERE comment_type = 'lending' AND comment_post_ID = %d AND user_id = %d AND comment_date LIKE %s
				ORDER BY comment_ID DESC LIMIT 1",
				$item_id,
				$user_id,
				'0000-00-00%'
			)
		);
		if ( $request_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$saved = false !== $wpdb->update( $wpdb->comments, $fields, array( 'comment_ID' => $request_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$saved = false !== $wpdb->insert(
				$wpdb->comments,
				$fields + array(
					'comment_post_ID'      => $item_id,
					'user_id'              => $user_id,
					'comment_type'         => 'lending',
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_content'      => '',
				)
			);
		}
		if ( ! $saved ) {
			self::back( array( 'error' => 'save' ) + self::picked( $item_id, $user_id ) );
		}
		TAAS_Loan_Shortcodes::set_state( $request_id ? $request_id : (int) $wpdb->insert_id, $state );

		$result = array(
			'done'  => $item_id,
			'who'   => $user_id,
			'state' => $state,
		);
		// The checkout email only makes sense when the member is taking the item.
		if ( $notify && 'out' === $state ) {
			$sent             = TAAS_Loan_Notify::send_checkout( $post, $user, $lent->getTimestamp(), $due->getTimestamp() );
			$result['sent_team']   = $sent['team'] ? 1 : 0;
			$result['sent_member'] = $sent['member'] ? 1 : 0;
		}
		self::back( $result );
	}

	/**
	 * Keep the picked item and member when going back after an error.
	 *
	 * @param int $item_id Item.
	 * @param int $user_id Member.
	 * @return array<string,int>
	 */
	private static function picked( $item_id, $user_id ) {
		return array(
			'item'   => $item_id,
			'member' => $user_id,
		);
	}

	/**
	 * Go back to the form with a result.
	 *
	 * @param array $args Query args.
	 */
	private static function back( array $args ) {
		wp_safe_redirect( add_query_arg( $args, self::page_url() ) );
		exit;
	}

	/**
	 * Result message after saving.
	 */
	private static function notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only picks a message to show.
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		$done  = isset( $_GET['done'] ) ? absint( $_GET['done'] ) : 0;
		$who   = isset( $_GET['who'] ) ? absint( $_GET['who'] ) : 0;
		$team  = isset( $_GET['sent_team'] ) ? absint( $_GET['sent_team'] ) : null;
		$memb  = isset( $_GET['sent_member'] ) ? absint( $_GET['sent_member'] ) : null;
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : 'out';
		// phpcs:enable

		if ( $error ) {
			$messages = array(
				'item'   => __( 'Pick an item.', 'sharing-club' ),
				'member' => __( 'Pick a member.', 'sharing-club' ),
				'dates'  => __( 'Enter both a pickup date and a return date.', 'sharing-club' ),
				'order'  => __( 'The return date can\'t be before the pickup date.', 'sharing-club' ),
				'state'  => __( 'Pick a status.', 'sharing-club' ),
				'out'    => __( 'That item is checked out or needs repair. Mark it as returned on the Lendings page first.', 'sharing-club' ),
				'save'   => __( 'The checkout could not be saved. Please try again.', 'sharing-club' ),
			);
			$text     = isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'Something went wrong.', 'sharing-club' );
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $text ) );
			return;
		}
		if ( ! $done ) {
			return;
		}

		$user   = get_userdata( $who );
		$states = TAAS_Loan_Shortcodes::states();
		if ( 'out' === $state || ! isset( $states[ $state ] ) ) {
			$text = sprintf(
				/* translators: 1: item name, 2: member name. */
				__( 'Checked out %1$s to %2$s.', 'sharing-club' ),
				get_the_title( $done ),
				$user ? $user->display_name : ''
			);
		} else {
			$text = sprintf(
				/* translators: 1: item name, 2: member name, 3: status, e.g. "Returned". */
				__( 'Saved %1$s for %2$s as "%3$s".', 'sharing-club' ),
				get_the_title( $done ),
				$user ? $user->display_name : '',
				$states[ $state ]
			);
		}
		$type = 'success';
		if ( null === $team ) {
			$text .= ' ' . __( 'No emails were sent.', 'sharing-club' );
		} elseif ( $team && $memb ) {
			$text .= ' ' . __( 'Emails sent to the loan team and the member.', 'sharing-club' );
		} else {
			$type   = 'warning';
			$missed = array();
			if ( ! $team ) {
				$missed[] = __( 'the loan team', 'sharing-club' );
			}
			if ( ! $memb ) {
				$missed[] = __( 'the member', 'sharing-club' );
			}
			/* translators: %s: who didn't get the email, e.g. "the member". */
			$text .= ' ' . sprintf( __( 'The email to %s could not be sent.', 'sharing-club' ), implode( ' / ', $missed ) );
		}
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	/**
	 * The checkout form.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only pre-fills the form.
		$pick_item   = isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0;
		$pick_member = isset( $_GET['member'] ) ? absint( $_GET['member'] ) : 0;
		// phpcs:enable

		$loans = TAAS_Loan_Shortcodes::loans();
		$items = get_posts(
			array(
				'post_type'      => 'shared_item',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$users = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
			)
		);
		$names = wp_list_pluck( $users, 'display_name', 'ID' );

		$groups = array(
			'requested' => array(),
			'in'        => array(),
			'out'       => array(),
			'repair'    => array(),
		);
		foreach ( $items as $item ) {
			$loan                        = isset( $loans[ $item->ID ] ) ? $loans[ $item->ID ] : array(
				'status'  => 'in',
				'user_id' => 0,
			);
			$groups[ $loan['status'] ][] = array( $item, $loan );
		}
		$labels = array(
			'requested' => __( 'Requested', 'sharing-club' ),
			'in'        => __( 'Available', 'sharing-club' ),
			'out'       => __( 'Checked out (mark as returned first)', 'sharing-club' ),
			'repair'    => __( 'Damaged needs repair (mark as returned first)', 'sharing-club' ),
		);

		$today = current_datetime();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Check out an item', 'sharing-club' ); ?></h1>
			<?php self::notice(); ?>
			<p><?php esc_html_e( 'Record that a member has taken an item. If you tick the box below, the loan team and the member each get an email with the return date.', 'sharing-club' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="taas-checkout-item"><?php esc_html_e( 'Item', 'sharing-club' ); ?></label></th>
						<td>
							<select id="taas-checkout-item" name="item" required>
								<option value=""><?php esc_html_e( 'Choose an item…', 'sharing-club' ); ?></option>
								<?php foreach ( $groups as $status => $rows ) : ?>
									<?php
									if ( ! $rows ) {
										continue;
									}
									?>
									<optgroup label="<?php echo esc_attr( $labels[ $status ] ); ?>">
										<?php
										foreach ( $rows as $row ) :
											list( $item, $loan ) = $row;
											$who                 = isset( $names[ $loan['user_id'] ] ) ? $names[ $loan['user_id'] ] : '';
											$text                = get_the_title( $item );
											if ( 'requested' === $status && $who ) {
												/* translators: 1: item name, 2: member name. */
												$text = sprintf( __( '%1$s (requested by %2$s)', 'sharing-club' ), $text, $who );
											} elseif ( 'out' === $status && $who ) {
												/* translators: 1: item name, 2: member name. */
												$text = sprintf( __( '%1$s (out with %2$s)', 'sharing-club' ), $text, $who );
											}
											?>
											<option value="<?php echo esc_attr( $item->ID ); ?>"
												<?php echo 'requested' === $status ? 'data-member="' . esc_attr( $loan['user_id'] ) . '"' : ''; ?>
												<?php selected( $pick_item, $item->ID ); ?>
												<?php disabled( in_array( $status, array( 'out', 'repair' ), true ) ); ?>><?php echo esc_html( $text ); ?></option>
										<?php endforeach; ?>
									</optgroup>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Picking a requested item also picks the member who asked for it.', 'sharing-club' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="taas-checkout-member"><?php esc_html_e( 'Member', 'sharing-club' ); ?></label></th>
						<td>
							<select id="taas-checkout-member" name="member" required>
								<option value=""><?php esc_html_e( 'Choose a member…', 'sharing-club' ); ?></option>
								<?php foreach ( $users as $user ) : ?>
									<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $pick_member, (int) $user->ID ); ?>><?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Tip: click the list and start typing a name to jump to it.', 'sharing-club' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="taas-checkout-lent"><?php esc_html_e( 'Pickup date', 'sharing-club' ); ?></label></th>
						<td><input type="date" id="taas-checkout-lent" name="lent" value="<?php echo esc_attr( $today->format( 'Y-m-d' ) ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="taas-checkout-due"><?php esc_html_e( 'Return date', 'sharing-club' ); ?></label></th>
						<td>
							<input type="date" id="taas-checkout-due" name="due" value="<?php echo esc_attr( $today->modify( '+' . self::DEFAULT_DAYS . ' days' )->format( 'Y-m-d' ) ); ?>" required>
							<p class="description">
								<?php
								/* translators: %d: number of days. */
								echo esc_html( sprintf( __( 'Set to %d days after the pickup date. Change it if needed.', 'sharing-club' ), self::DEFAULT_DAYS ) );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="taas-checkout-state"><?php esc_html_e( 'Status', 'sharing-club' ); ?></label></th>
						<td>
							<select id="taas-checkout-state" name="state">
								<?php foreach ( TAAS_Loan_Shortcodes::states() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( 'out', $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Shown as Availability on the Lendings page. Emails are only sent for "Checked Out".', 'sharing-club' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="taas-checkout-notes"><?php esc_html_e( 'Notes', 'sharing-club' ); ?></label></th>
						<td>
							<input type="text" id="taas-checkout-notes" name="notes" class="regular-text">
							<p class="description"><?php esc_html_e( 'Private, for the loan team only (e.g. "includes red dot finder").', 'sharing-club' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Emails', 'sharing-club' ); ?></th>
						<td>
							<label><input type="checkbox" name="notify" value="1" checked>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: loan team email address(es). */
									__( 'Email the member and the loan team (%s)', 'sharing-club' ),
									implode( ', ', TAAS_Loan_Notify::manager_emails() )
								)
							);
							?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Check out', 'sharing-club' ) ); ?>
			</form>
		</div>
		<script>
		( function () {
			var item = document.getElementById( 'taas-checkout-item' );
			var member = document.getElementById( 'taas-checkout-member' );
			item.addEventListener( 'change', function () {
				var who = item.options[ item.selectedIndex ].getAttribute( 'data-member' );
				if ( who ) {
					member.value = who;
				}
			} );
		}() );
		</script>
		<?php
		self::due_date_script( 'taas-checkout-lent', 'taas-checkout-due' );
	}

	/**
	 * When the pickup date changes, set the return date 30 days later.
	 *
	 * @param string $lent_id ID of the pickup date field.
	 * @param string $due_id  ID of the return date field.
	 */
	public static function due_date_script( $lent_id, $due_id ) {
		?>
		<script>
		( function () {
			var lent = document.getElementById( <?php echo wp_json_encode( $lent_id ); ?> );
			var due = document.getElementById( <?php echo wp_json_encode( $due_id ); ?> );
			if ( ! lent || ! due ) {
				return;
			}
			lent.addEventListener( 'change', function () {
				var parts = lent.value.split( '-' );
				if ( 3 !== parts.length ) {
					return;
				}
				var d = new Date( Date.UTC( +parts[0], parts[1] - 1, +parts[2] + <?php echo (int) self::DEFAULT_DAYS; ?> ) );
				due.value = d.toISOString().slice( 0, 10 );
			} );
		}() );
		</script>
		<?php
	}
}

TAAS_Loan_Checkout::init();
