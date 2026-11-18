<?php
/**
 * Email the Loan Manager when a member requests an item.
 *
 * The member's phone numbers come from Participants Database, matched on the
 * email address of their WordPress account.
 *
 * @package TAAS_Loan_Program
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loan request emails and their settings.
 */
class TAAS_Loan_Notify {

	/** Admin-post action for the "Send test email" button. */
	const TEST_ACTION = 'taas_loan_test_email';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::TEST_ACTION, array( __CLASS__, 'handle_test_email' ) );
		add_action( 'admin_notices', array( __CLASS__, 'test_email_notice' ) );
	}

	/**
	 * Email addresses that get loan requests. Falls back to the site admin.
	 *
	 * @return string[]
	 */
	public static function manager_emails() {
		$emails = array_filter( array_map( 'trim', explode( ',', (string) scwp_get_option( 'manager_email' ) ) ), 'is_email' );
		return $emails ? array_values( $emails ) : array( get_option( 'admin_email' ) );
	}

	/**
	 * Where the Loan Manager handles requests.
	 *
	 * @return string
	 */
	public static function portal_url() {
		return admin_url( 'edit.php?post_type=shared_item&page=display_lending_table' );
	}

	/**
	 * Send the "new loan request" email.
	 *
	 * @param int $post_id Requested item.
	 * @param int $user_id Member who asked for it.
	 * @return bool Whether the email was handed to the mail server.
	 */
	public static function send_request( $post_id, $user_id ) {
		$user = get_userdata( $user_id );
		$post = get_post( $post_id );
		if ( ! $user || ! $post ) {
			return false;
		}
		return self::send( $post, $user, self::manager_emails() );
	}

	/**
	 * Build and send the email.
	 *
	 * @param WP_Post  $post Item.
	 * @param WP_User  $user Member.
	 * @param string[] $to   Recipients.
	 * @param bool     $test Mark the subject as a test.
	 * @return bool
	 */
	private static function send( $post, $user, array $to, $test = false ) {
		$member = self::member_contact( $user );
		$item   = get_the_title( $post );

		/* translators: 1: item name, 2: member name. */
		$subject = sprintf( __( 'Loan request: %1$s from %2$s', 'sharing-club' ), $item, $member['name'] );
		if ( $test ) {
			/* translators: %s: email subject. */
			$subject = sprintf( __( '[Test] %s', 'sharing-club' ), $subject );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $member['email'] ) ) {
			// Replying goes straight to the member.
			$headers[] = 'Reply-To: ' . self::header_safe( $member['name'] ) . ' <' . $member['email'] . '>';
		}

		return wp_mail( $to, wp_specialchars_decode( $subject, ENT_QUOTES ), self::email_html( $post, $member ), $headers );
	}

	/**
	 * Strip characters that could break an email header.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function header_safe( $text ) {
		return trim( str_replace( array( "\r", "\n", '<', '>', '"', ',' ), ' ', $text ) );
	}

	/**
	 * The member's name, email and phone numbers.
	 *
	 * Uses their Participants Database record when one matches their account
	 * email, otherwise their WordPress profile.
	 *
	 * @param WP_User $user Member.
	 * @return array{name:string,email:string,mobile:string,home:string,username:string,found:bool}
	 */
	public static function member_contact( $user ) {
		$record = self::pdb_record( $user->user_email );
		$fields = self::phone_fields();

		$name = '';
		if ( $record ) {
			$name = trim( ( $record['first_name'] ?? '' ) . ' ' . ( $record['last_name'] ?? '' ) );
		}
		if ( '' === $name ) {
			$name = trim( $user->first_name . ' ' . $user->last_name );
		}
		if ( '' === $name ) {
			$name = $user->display_name;
		}

		return array(
			'name'     => $name,
			'email'    => $user->user_email,
			'mobile'   => $record && $fields['mobile'] ? trim( (string) ( $record[ $fields['mobile'] ] ?? '' ) ) : '',
			'home'     => $record && $fields['home'] ? trim( (string) ( $record[ $fields['home'] ] ?? '' ) ) : '',
			'username' => $user->user_login,
			'found'    => (bool) $record,
		);
	}

	/**
	 * Participants Database table name, or '' if the plugin isn't installed.
	 *
	 * @param string $which 'records' or 'fields'.
	 * @return string
	 */
	private static function pdb_table( $which ) {
		global $wpdb;
		if ( class_exists( 'Participants_Db' ) ) {
			$table = 'fields' === $which ? Participants_Db::$fields_table : Participants_Db::$participants_table;
		} else {
			$table = $wpdb->prefix . ( 'fields' === $which ? 'participants_database_fields' : 'participants_database' );
		}
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return $exists === $table ? $table : '';
	}

	/**
	 * Is Participants Database available?
	 *
	 * @return bool
	 */
	public static function pdb_available() {
		return '' !== self::pdb_table( 'records' );
	}

	/**
	 * The newest Participants Database record with this email.
	 *
	 * @param string $email Email address.
	 * @return array<string,mixed>|null
	 */
	private static function pdb_record( $email ) {
		global $wpdb;
		$table = self::pdb_table( 'records' );
		if ( ! $table || ! is_email( $email ) ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id DESC LIMIT 1', $table, $email ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Participants Database fields, for the settings page.
	 *
	 * @return array<string,string> Field name => label.
	 */
	public static function pdb_fields() {
		global $wpdb;
		$table = self::pdb_table( 'fields' );
		if ( ! $table ) {
			return array();
		}
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT name, title FROM %i', $table ), ARRAY_A );
		$fields = array();
		foreach ( (array) $rows as $row ) {
			$fields[ $row['name'] ] = wp_strip_all_tags( $row['title'] ? $row['title'] : $row['name'] );
		}
		asort( $fields, SORT_NATURAL | SORT_FLAG_CASE );
		return $fields;
	}

	/**
	 * Which Participants Database fields hold the mobile and home numbers.
	 *
	 * Uses the settings, or guesses from field names like "Mobile" and "Home".
	 *
	 * @return array{mobile:string,home:string}
	 */
	public static function phone_fields() {
		$chosen = array(
			'mobile' => (string) scwp_get_option( 'pdb_mobile_field' ),
			'home'   => (string) scwp_get_option( 'pdb_home_field' ),
		);
		if ( $chosen['mobile'] && $chosen['home'] ) {
			return $chosen;
		}
		$guess = array(
			'mobile' => '',
			'home'   => '',
		);
		foreach ( self::pdb_fields() as $name => $title ) {
			$text = strtolower( $name . ' ' . $title );
			if ( ! $guess['mobile'] && preg_match( '/mobile|cell/', $text ) ) {
				$guess['mobile'] = $name;
			} elseif ( ! $guess['home'] && false !== strpos( $text, 'home' ) && ! preg_match( '/address|page|town|city/', $text ) ) {
				$guess['home'] = $name;
			}
		}
		return array(
			'mobile' => $chosen['mobile'] ? $chosen['mobile'] : $guess['mobile'],
			'home'   => $chosen['home'] ? $chosen['home'] : $guess['home'],
		);
	}

	/**
	 * The email body.
	 *
	 * @param WP_Post $post   Item.
	 * @param array   $member From member_contact().
	 * @return string HTML.
	 */
	private static function email_html( $post, array $member ) {
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$item     = get_the_title( $post );
		$category = TAAS_Loan_Shortcodes::primary_category( $post->ID );
		$when     = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$first    = strtok( $member['name'], ' ' );
		$missing  = '<span style="color:#8a8f98;">' . esc_html__( 'Not on file', 'sharing-club' ) . '</span>';

		$rows = array(
			__( 'Name', 'sharing-club' )     => esc_html( $member['name'] ),
			__( 'Email', 'sharing-club' )    => '<a href="' . esc_url( 'mailto:' . $member['email'] ) . '" style="color:#1d4ed8;text-decoration:none;">' . esc_html( $member['email'] ) . '</a>',
			__( 'Mobile', 'sharing-club' )   => self::phone_html( $member['mobile'], $missing ),
			__( 'Home', 'sharing-club' )     => self::phone_html( $member['home'], $missing ),
			__( 'Username', 'sharing-club' ) => esc_html( $member['username'] ),
		);

		$member_rows = '';
		foreach ( $rows as $label => $value ) {
			$member_rows .= '<tr><td style="padding:6px 0;width:110px;color:#5b6270;font-size:14px;vertical-align:top;">' . esc_html( $label ) . '</td>'
				. '<td style="padding:6px 0;color:#111827;font-size:15px;">' . $value . '</td></tr>';
		}

		$note = '';
		if ( ! $member['found'] ) {
			$note = '<p style="margin:12px 0 0;color:#8a5a00;font-size:13px;">'
				. esc_html__( 'No Participants Database record matches this member\'s email, so phone numbers are missing.', 'sharing-club' )
				. '</p>';
		}

		$item_meta = $category ? esc_html( $category->name ) . ' &middot; ' : '';

		ob_start();
		?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;">
	<tr><td style="background:#111827;padding:20px 28px;color:#ffffff;font-size:18px;font-weight:600;">
		<?php echo esc_html( $site ); ?> &middot; <?php esc_html_e( 'Loan Program', 'sharing-club' ); ?>
	</td></tr>
	<tr><td style="padding:28px 28px 8px;">
		<h1 style="margin:0 0 8px;font-size:22px;color:#111827;"><?php esc_html_e( 'New loan request', 'sharing-club' ); ?></h1>
		<p style="margin:0;color:#374151;font-size:15px;line-height:1.5;">
			<?php
			/* translators: 1: member name, 2: item name. */
			echo wp_kses_post( sprintf( __( '%1$s would like to borrow %2$s.', 'sharing-club' ), '<strong>' . esc_html( $member['name'] ) . '</strong>', '<strong>' . esc_html( $item ) . '</strong>' ) );
			?>
		</p>
	</td></tr>
	<tr><td style="padding:16px 28px 0;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
			<tr><td style="padding:16px 20px;">
				<div style="color:#5b6270;font-size:12px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;"><?php esc_html_e( 'Item', 'sharing-club' ); ?></div>
				<a href="<?php echo esc_url( get_permalink( $post ) ); ?>" style="color:#111827;font-size:16px;font-weight:600;text-decoration:none;"><?php echo esc_html( $item ); ?></a>
				<div style="color:#5b6270;font-size:13px;margin-top:4px;"><?php echo $item_meta . esc_html( $when ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $item_meta is escaped above. ?></div>
			</td></tr>
		</table>
	</td></tr>
	<tr><td style="padding:16px 28px 0;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;">
			<tr><td style="padding:16px 20px;">
				<div style="color:#5b6270;font-size:12px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;"><?php esc_html_e( 'Member', 'sharing-club' ); ?></div>
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><?php echo $member_rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?></table>
				<?php echo $note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
			</td></tr>
		</table>
	</td></tr>
	<tr><td align="center" style="padding:28px;">
		<a href="<?php echo esc_url( self::portal_url() ); ?>" style="display:inline-block;background:#1d4ed8;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;padding:12px 26px;border-radius:6px;"><?php esc_html_e( 'Open the Loan Portal', 'sharing-club' ); ?></a>
	</td></tr>
	<tr><td style="padding:16px 28px 24px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;line-height:1.5;">
		<?php
		/* translators: %s: member's first name. */
		echo esc_html( sprintf( __( 'Reply to this email to contact %s directly.', 'sharing-club' ), $first ) );
		?>
	</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A tap-to-call phone number.
	 *
	 * @param string $number  Phone number as typed.
	 * @param string $missing HTML to show when empty.
	 * @return string HTML.
	 */
	private static function phone_html( $number, $missing ) {
		if ( '' === $number ) {
			return $missing;
		}
		$dial = preg_replace( '/[^0-9+]/', '', $number );
		return '<a href="' . esc_url( 'tel:' . $dial, array( 'tel' ) ) . '" style="color:#1d4ed8;text-decoration:none;">' . esc_html( $number ) . '</a>';
	}

	/**
	 * Settings rows for the Loan Manager email and phone fields.
	 */
	public static function render_settings_rows() {
		$fields = self::pdb_fields();
		$guess  = self::phone_fields();
		?>
		<tr>
			<th scope="row"><label for="scwp-manager-email"><?php esc_html_e( 'Loan Manager email', 'sharing-club' ); ?></label></th>
			<td>
				<input type="text" id="scwp-manager-email" class="regular-text" name="scwp_options[manager_email]" value="<?php echo esc_attr( scwp_get_option( 'manager_email' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
				<p class="description"><?php esc_html_e( 'Who gets an email when a member requests an item. Separate several addresses with commas. Leave empty to use the site admin email.', 'sharing-club' ); ?></p>
			</td>
		</tr>
		<?php
		foreach ( array(
			'mobile' => __( 'Mobile phone field', 'sharing-club' ),
			'home'   => __( 'Home phone field', 'sharing-club' ),
		) as $key => $label ) :
			$option = 'pdb_' . $key . '_field';
			$value  = scwp_get_option( $option );
			?>
		<tr>
			<th scope="row"><label for="scwp-<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( $fields ) : ?>
					<select id="scwp-<?php echo esc_attr( $option ); ?>" name="scwp_options[<?php echo esc_attr( $option ); ?>]">
						<option value="">
							<?php
							echo esc_html(
								$guess[ $key ] && isset( $fields[ $guess[ $key ] ] )
									/* translators: %s: field label the plugin picked. */
									? sprintf( __( 'Automatic (%s)', 'sharing-club' ), $fields[ $guess[ $key ] ] )
									: __( 'None', 'sharing-club' )
							);
							?>
						</option>
						<?php foreach ( $fields as $name => $title ) : ?>
							<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $value, $name ); ?>><?php echo esc_html( $title . ' (' . $name . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The Participants Database field shown in the request email. Members are matched by their WordPress account email.', 'sharing-club' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Participants Database is not installed, so phone numbers can\'t be added to request emails.', 'sharing-club' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
			<?php
		endforeach;
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Test the request email', 'sharing-club' ); ?></th>
			<td>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::TEST_ACTION ), self::TEST_ACTION ) ); ?>"><?php esc_html_e( 'Send test email', 'sharing-club' ); ?></a>
				<p class="description"><?php esc_html_e( 'Sends a sample request, using your own account as the member, to the Loan Manager email. Save your changes first.', 'sharing-club' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Clean the email and phone field settings.
	 *
	 * @param array $options Submitted settings.
	 * @return array
	 */
	public static function sanitize( array $options ) {
		$emails = array();
		foreach ( explode( ',', (string) ( $options['manager_email'] ?? '' ) ) as $email ) {
			$email = sanitize_email( trim( $email ) );
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}
		if ( $emails ) {
			$options['manager_email'] = implode( ', ', array_unique( $emails ) );
		} else {
			unset( $options['manager_email'] );
		}

		$fields = self::pdb_fields();
		foreach ( array( 'pdb_mobile_field', 'pdb_home_field' ) as $key ) {
			$value = sanitize_key( $options[ $key ] ?? '' );
			if ( $value && isset( $fields[ $value ] ) ) {
				$options[ $key ] = $value;
			} else {
				unset( $options[ $key ] );
			}
		}
		return $options;
	}

	/**
	 * Send a sample request email to the Loan Manager.
	 */
	public static function handle_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sharing-club' ) );
		}
		check_admin_referer( self::TEST_ACTION );

		$items = get_posts(
			array(
				'post_type'      => 'shared_item',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);
		$sent  = $items ? self::send( $items[0], wp_get_current_user(), self::manager_emails(), true ) : false;

		wp_safe_redirect(
			add_query_arg(
				'taas_test_email',
				$sent ? 'sent' : ( $items ? 'failed' : 'noitems' ),
				admin_url( 'options-general.php?page=theme-settings' )
			)
		);
		exit;
	}

	/**
	 * Say whether the test email went out.
	 */
	public static function test_email_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks a message to show.
		$result = isset( $_GET['taas_test_email'] ) ? sanitize_key( wp_unslash( $_GET['taas_test_email'] ) ) : '';
		if ( ! $result ) {
			return;
		}
		$messages = array(
			/* translators: %s: email address(es). */
			'sent'    => array( 'success', sprintf( __( 'Test email sent to %s.', 'sharing-club' ), implode( ', ', self::manager_emails() ) ) ),
			'failed'  => array( 'error', __( 'The test email could not be sent. Check that this site can send email (an SMTP plugin may be needed).', 'sharing-club' ) ),
			'noitems' => array( 'warning', __( 'Add at least one item before sending a test email.', 'sharing-club' ) ),
		);
		if ( isset( $messages[ $result ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $result ][0] ), esc_html( $messages[ $result ][1] ) );
		}
	}
}

TAAS_Loan_Notify::init();
