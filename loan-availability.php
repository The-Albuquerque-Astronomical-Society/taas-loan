<?php
/**
 * "Availability" dropdown on TAAS Loan Program > All objects, with the same
 * choices as the Lendings page: Checked Out, Returned, Damaged needs repair.
 *
 * Changing it updates the item's latest lending. "Checked Out" needs a member
 * and dates, so it opens the Check out page with the item picked.
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
			'<select class="taas-availability" data-item="%1$d" data-current="%2$s" aria-label="%3$s">',
			(int) $item_id,
			esc_attr( $current ),
			esc_attr__( 'Availability', 'sharing-club' )
		);
		foreach ( $options as $key => $label ) {
			// A request can only be checked out (on the Check out page).
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
			// A checkout needs a member and dates.
			wp_send_json_success(
				array(
					'redirect' => TAAS_Loan_Checkout::page_url( $item_id, 'requested' === $loan['status'] ? $loan['user_id'] : 0 ),
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
	 * Save the dropdown as soon as it changes.
	 */
	public static function script() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-shared_item' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<script>
		( function () {
			var url = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::ACTION ) ); ?>;
			var text = <?php echo wp_json_encode( array( 'saving' => __( 'Saving…', 'sharing-club' ), 'saved' => __( 'Saved', 'sharing-club' ), 'failed' => __( 'Could not save. Please try again.', 'sharing-club' ) ) ); ?>;
			document.addEventListener( 'change', function ( e ) {
				var select = e.target;
				if ( ! select.classList || ! select.classList.contains( 'taas-availability' ) ) {
					return;
				}
				var msg = select.nextElementSibling;
				var body = new FormData();
				body.append( 'action', <?php echo wp_json_encode( self::ACTION ); ?> );
				body.append( '_ajax_nonce', nonce );
				body.append( 'item', select.getAttribute( 'data-item' ) );
				body.append( 'state', select.value );
				select.disabled = true;
				msg.textContent = text.saving;
				fetch( url, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success && res.data && res.data.redirect ) {
							window.location.href = res.data.redirect;
							return;
						}
						if ( res && res.success ) {
							select.setAttribute( 'data-current', select.value );
							msg.textContent = text.saved;
						} else {
							select.value = select.getAttribute( 'data-current' );
							msg.textContent = ( res && typeof res.data === 'string' ) ? res.data : text.failed;
						}
						select.disabled = false;
					} )
					.catch( function () {
						select.value = select.getAttribute( 'data-current' );
						msg.textContent = text.failed;
						select.disabled = false;
					} );
			} );
		}() );
		</script>
		<?php
	}
}

TAAS_Loan_Availability::init();
