<?php
/**
 * "Availability" dropdown on TAAS Loan Program > All objects, with the same
 * choices as the Lendings page: Checked Out, Returned, Damaged needs repair.
 *
 * Changing it updates the item's latest lending. "Checked Out" opens a small
 * box to pick the member and dates (with a calendar); saving it records the
 * loan on the Lendings page and sends the same emails as the Check out page.
 *
 * @package TAAS_Loan_Program
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Availability column.
 */
class TAAS_Loan_Availability {

	/** AJAX action and nonce name. */
	const ACTION = 'taas_loan_availability';

	/** Latest loan per item, loaded once per page. @var array|null */
	private static $loans = null;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'manage_shared_item_posts_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_shared_item_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'admin_footer-edit.php', array( __CLASS__, 'script' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
	}

	/**
	 * Add the column after the title.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $columns;
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['taas_availability'] = __( 'Availability', 'sharing-club' );
			}
		}
		if ( ! isset( $out['taas_availability'] ) ) {
			$out['taas_availability'] = __( 'Availability', 'sharing-club' );
		}
		return $out;
	}

	/**
	 * The item's latest loan, or a blank one.
	 *
	 * @param int $item_id Item ID.
	 * @return array{status:string,user_id:int,id:int}
	 */
	private static function loan( $item_id ) {
		if ( null === self::$loans ) {
			self::$loans = TAAS_Loan_Shortcodes::loans();
		}
		return isset( self::$loans[ $item_id ] ) ? self::$loans[ $item_id ] : array(
			'status'  => 'in',
			'user_id' => 0,
			'id'      => 0,
		);
	}

	/**
	 * Print the dropdown.
	 *
	 * @param string $column  Column key.
	 * @param int    $item_id Item ID.
	 */
	public static function render_column( $column, $item_id ) {
		if ( 'taas_availability' !== $column ) {
			return;
		}
		$loan      = self::loan( $item_id );
		$requested = 'requested' === $loan['status'];
		$current   = array(
			'out'       => 'out',
			'in'        => 'returned',
			'repair'    => 'damaged',
			'requested' => 'requested',
		)[ $loan['status'] ];

		$options = array();
		if ( $requested ) {
			$options['requested'] = __( 'Requested', 'sharing-club' );
		}
		$options += TAAS_Loan_Shortcodes::states();
		if ( ! $loan['id'] ) {
			// Never lent out yet.
			$options['returned'] = __( 'Available', 'sharing-club' );
		}

		printf(
			'<select class="taas-availability" data-item="%1$d" data-current="%2$s" data-member="%3$d" aria-label="%4$s">',
			(int) $item_id,
			esc_attr( $current ),
			$requested ? (int) $loan['user_id'] : 0,
			esc_attr__( 'Availability', 'sharing-club' )
		);
		foreach ( $options as $key => $label ) {
			// A request can only be checked out.
			$off = $requested && 'out' !== $key;
			printf(
				'<option value="%1$s"%2$s%3$s>%4$s</option>',
				esc_attr( $key ),
				selected( $current, $key, false ),
				$off ? ' disabled' : '',
				esc_html( $label )
			);
		}
		echo '</select> <span class="taas-availability-msg" aria-live="polite"></span>';
		echo '<div class="taas-availability-box"></div>';
	}

	/**
	 * Save a change from the dropdown.
	 */
	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Sorry, you are not allowed to do that.', 'sharing-club' ), 403 );
		}
		check_ajax_referer( self::ACTION );

		$item_id = isset( $_POST['item'] ) ? absint( $_POST['item'] ) : 0;
		$state   = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		$post    = get_post( $item_id );
		if ( ! $post || 'shared_item' !== $post->post_type || ! isset( TAAS_Loan_Shortcodes::states()[ $state ] ) ) {
			wp_send_json_error( __( 'Something went wrong.', 'sharing-club' ) );
		}

		$loan = self::loan( $item_id );

		if ( 'out' === $state ) {
			if ( 'out' === $loan['status'] ) {
				wp_send_json_success();
			}
			if ( 'repair' === $loan['status'] ) {
				wp_send_json_error( __( 'Mark it as Returned first.', 'sharing-club' ) );
			}
			$user_id = isset( $_POST['member'] ) ? absint( $_POST['member'] ) : 0;
			$result  = TAAS_Loan_Checkout::save(
				$item_id,
				$user_id,
				isset( $_POST['lent'] ) ? sanitize_text_field( wp_unslash( $_POST['lent'] ) ) : '',
				isset( $_POST['due'] ) ? sanitize_text_field( wp_unslash( $_POST['due'] ) ) : '',
				isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '',
				'out',
				! empty( $_POST['notify'] )
			);
			if ( isset( $result['error'] ) ) {
				wp_send_json_error( TAAS_Loan_Checkout::error_message( $result['error'] ) );
			}
			$user = get_userdata( $user_id );
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: member name. */
						__( 'Checked out to %s.', 'sharing-club' ),
						$user->display_name
					) . ' ' . TAAS_Loan_Checkout::email_message(
						isset( $result['sent_team'] ) ? $result['sent_team'] : null,
						isset( $result['sent_member'] ) ? $result['sent_member'] : null
					),
				)
			);
		}

		if ( 'requested' === $loan['status'] ) {
			wp_send_json_error( __( 'This item is requested. Check it out or update it on the Lendings page.', 'sharing-club' ) );
		}

		if ( $loan['id'] ) {
			TAAS_Loan_Shortcodes::set_state( $loan['id'], $state );
			wp_send_json_success();
		}

		// Never lent out: "Available" is already true.
		if ( 'returned' === $state ) {
			wp_send_json_success();
		}

		// Never lent out but damaged: add a lending so it shows on the Lendings page.
		global $wpdb;
		$user  = wp_get_current_user();
		$today = current_datetime();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$saved = $wpdb->insert(
			$wpdb->comments,
			array(
				'comment_post_ID'      => $item_id,
				'user_id'              => $user->ID,
				'comment_type'         => 'lending',
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_content'      => '',
				'comment_date'         => $today->format( 'Y-m-d 00:00:00' ),
				'comment_date_gmt'     => $today->format( 'Y-m-d 23:59:59' ),
				'comment_agent'        => __( 'Marked damaged on All objects', 'sharing-club' ),
			)
		);
		if ( ! $saved ) {
			wp_send_json_error( __( 'Could not save. Please try again.', 'sharing-club' ) );
		}
		TAAS_Loan_Shortcodes::set_state( (int) $wpdb->insert_id, $state );
		wp_send_json_success();
	}

	/**
	 * The checkout box (moved into a row when "Checked Out" is picked) and
	 * the script that saves the dropdown.
	 */
	public static function script() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-shared_item' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$users = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
			)
		);
		$days  = TAAS_Loan_Checkout::DEFAULT_DAYS;
		$today = current_datetime();
		?>
		<style>
			.taas-availability-box .taas-checkout-box { margin-top: 8px; padding: 10px 12px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; max-width: 320px; }
			.taas-checkout-box p { margin: 0 0 8px; }
			.taas-checkout-box label { display: block; font-weight: 600; }
			.taas-checkout-box label.taas-inline { font-weight: normal; }
			.taas-checkout-box select, .taas-checkout-box input[type="text"], .taas-checkout-box input[type="date"] { width: 100%; max-width: 100%; }
		</style>
		<div id="taas-checkout-box-template" hidden>
			<div class="taas-checkout-box">
				<p><label><?php esc_html_e( 'Member', 'sharing-club' ); ?>
					<select data-field="member">
						<option value=""><?php esc_html_e( 'Choose a member…', 'sharing-club' ); ?></option>
						<?php foreach ( $users as $user ) : ?>
							<option value="<?php echo esc_attr( $user->ID ); ?>"><?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label></p>
				<p><label><?php esc_html_e( 'Pickup date', 'sharing-club' ); ?>
					<input type="date" data-field="lent">
				</label></p>
				<p><label><?php esc_html_e( 'Return date', 'sharing-club' ); ?>
					<input type="date" data-field="due">
				</label></p>
				<p><label><?php esc_html_e( 'Notes', 'sharing-club' ); ?>
					<input type="text" data-field="notes">
				</label></p>
				<p><label class="taas-inline"><input type="checkbox" data-field="notify" value="1" checked> <?php esc_html_e( 'Email the member and the loan team', 'sharing-club' ); ?></label></p>
				<p>
					<button type="button" class="button button-primary" data-do="save"><?php esc_html_e( 'Check out', 'sharing-club' ); ?></button>
					<button type="button" class="button" data-do="cancel"><?php esc_html_e( 'Cancel', 'sharing-club' ); ?></button>
				</p>
			</div>
		</div>
		<script>
		( function () {
			var url = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::ACTION ) ); ?>;
			var days = <?php echo (int) $days; ?>;
			var today = <?php echo wp_json_encode( $today->format( 'Y-m-d' ) ); ?>;
			var text = <?php echo wp_json_encode( array( 'saving' => __( 'Saving…', 'sharing-club' ), 'saved' => __( 'Saved', 'sharing-club' ), 'failed' => __( 'Could not save. Please try again.', 'sharing-club' ), 'repair' => __( 'Mark it as Returned first.', 'sharing-club' ) ) ); ?>;
			var template = document.getElementById( 'taas-checkout-box-template' );
			var open = null; // The select whose box is showing.

			function plusDays( ymd ) {
				var p = ymd.split( '-' );
				if ( 3 !== p.length ) {
					return '';
				}
				return new Date( Date.UTC( +p[0], p[1] - 1, +p[2] + days ) ).toISOString().slice( 0, 10 );
			}

			function msgFor( select ) {
				return select.parentNode.querySelector( '.taas-availability-msg' );
			}

			function closeBox( revert ) {
				if ( ! open ) {
					return;
				}
				if ( revert ) {
					open.value = open.getAttribute( 'data-current' );
				}
				open.parentNode.querySelector( '.taas-availability-box' ).innerHTML = '';
				open = null;
			}

			function send( select, extra, done ) {
				var body = new FormData();
				body.append( 'action', <?php echo wp_json_encode( self::ACTION ); ?> );
				body.append( '_ajax_nonce', nonce );
				body.append( 'item', select.getAttribute( 'data-item' ) );
				body.append( 'state', select.value );
				Object.keys( extra ).forEach( function ( k ) {
					body.append( k, extra[ k ] );
				} );
				fetch( url, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( done )
					.catch( function () { done( null ); } );
			}

			function saved( select, message ) {
				select.setAttribute( 'data-current', select.value );
				// Once checked out, a request is no longer a request.
				Array.prototype.forEach.call( select.options, function ( o ) {
					if ( 'requested' === o.value ) {
						o.remove();
					} else {
						o.disabled = false;
					}
				} );
				msgFor( select ).textContent = message || text.saved;
			}

			function openBox( select ) {
				closeBox( true );
				var holder = select.parentNode.querySelector( '.taas-availability-box' );
				holder.innerHTML = template.innerHTML;
				var box = holder.firstElementChild;
				var lent = box.querySelector( '[data-field="lent"]' );
				var due = box.querySelector( '[data-field="due"]' );
				var member = box.querySelector( '[data-field="member"]' );
				var who = select.getAttribute( 'data-member' );
				if ( who && '0' !== who ) {
					member.value = who;
				}
				lent.value = today;
				due.value = plusDays( today );
				lent.addEventListener( 'change', function () {
					var d = plusDays( lent.value );
					if ( d ) {
						due.value = d;
					}
				} );
				open = select;
				msgFor( select ).textContent = '';
				member.focus();
			}

			document.addEventListener( 'click', function ( e ) {
				var button = e.target.closest ? e.target.closest( '.taas-checkout-box [data-do]' ) : null;
				if ( ! button || ! open ) {
					return;
				}
				if ( 'cancel' === button.getAttribute( 'data-do' ) ) {
					closeBox( true );
					return;
				}
				var select = open;
				var box = button.closest( '.taas-checkout-box' );
				var field = function ( n ) { return box.querySelector( '[data-field="' + n + '"]' ); };
				var msg = msgFor( select );
				button.disabled = true;
				msg.textContent = text.saving;
				send( select, {
					member: field( 'member' ).value,
					lent: field( 'lent' ).value,
					due: field( 'due' ).value,
					notes: field( 'notes' ).value,
					notify: field( 'notify' ).checked ? '1' : ''
				}, function ( res ) {
					if ( res && res.success ) {
						closeBox( false );
						saved( select, res.data && res.data.message );
					} else {
						button.disabled = false;
						msg.textContent = ( res && typeof res.data === 'string' ) ? res.data : text.failed;
					}
				} );
			} );

			// Enter in the box checks out, instead of submitting the list's form.
			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key && e.target.closest && e.target.closest( '.taas-checkout-box' ) && 'BUTTON' !== e.target.tagName ) {
					e.preventDefault();
					e.target.closest( '.taas-checkout-box' ).querySelector( '[data-do="save"]' ).click();
				}
			} );

			document.addEventListener( 'change', function ( e ) {
				var select = e.target;
				if ( ! select.classList || ! select.classList.contains( 'taas-availability' ) ) {
					return;
				}
				var current = select.getAttribute( 'data-current' );
				if ( 'out' === select.value ) {
					if ( 'out' === current ) {
						closeBox( false );
						return;
					}
					if ( 'damaged' === current ) {
						select.value = current;
						msgFor( select ).textContent = text.repair;
						return;
					}
					openBox( select );
					return;
				}
				if ( open === select ) {
					closeBox( false );
				}
				var msg = msgFor( select );
				select.disabled = true;
				msg.textContent = text.saving;
				send( select, {}, function ( res ) {
					select.disabled = false;
					if ( res && res.success ) {
						saved( select );
					} else {
						select.value = current;
						msg.textContent = ( res && typeof res.data === 'string' ) ? res.data : text.failed;
					}
				} );
			} );
		}() );
		</script>
		<?php
	}
}

TAAS_Loan_Availability::init();
