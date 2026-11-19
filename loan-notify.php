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

	/** Admin-post action for the private test link (works without logging in). */
	const LINK_ACTION = 'taas_loan_test_link';

	/** Admin-post action that replaces the private test link. */
	const RESET_ACTION = 'taas_loan_test_link_reset';

	/** Option holding the private test link's secret key. */
	const LINK_KEY_OPTION = 'taas_loan_test_link_key';

	/**
	 * Why the last email failed, if it did.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::TEST_ACTION, array( __CLASS__, 'handle_test_email' ) );
		add_action( 'admin_notices', array( __CLASS__, 'test_email_notice' ) );
		add_action( 'admin_post_nopriv_' . self::LINK_ACTION, array( __CLASS__, 'handle_test_link' ) );
		add_action( 'admin_post_' . self::LINK_ACTION, array( __CLASS__, 'handle_test_link' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( __CLASS__, 'handle_reset_link' ) );
	}

	/**
	 * Address the emails come from.
	 *
	 * @return string
	 */
	public static function from_email() {
		$email = (string) scwp_get_option( 'from_email' );
		if ( is_email( $email ) ) {
			return $email;
		}
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return 'noreply@' . $host;
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
		$sent = self::send( $post, $user, self::manager_emails() );
		if ( self::confirm_members() && is_email( $user->user_email ) ) {
			self::send_confirmation( $post, $user, array( $user->user_email ) );
		}
		return $sent;
	}

	/**
	 * Should members get a "we received your request" email?
	 *
	 * @return bool
	 */
	public static function confirm_members() {
		return 'off' !== scwp_get_option( 'member_email' );
	}

	/**
	 * Tell the member their request arrived and the Loan Director will be in touch.
	 *
	 * @param WP_Post  $post Item.
	 * @param WP_User  $user Member.
	 * @param string[] $to   Recipients (the member, or the Loan Manager for a test).
	 * @param bool     $test Mark the subject as a test.
	 * @return bool
	 */
	private static function send_confirmation( $post, $user, array $to, $test = false ) {
		$member = self::member_contact( $user );
		$item   = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );

		/* translators: %s: item name. */
		$subject = sprintf( __( 'We received your request for %s', 'sharing-club' ), $item );
		if ( $test ) {
			/* translators: %s: email subject. */
			$subject = sprintf( __( '[Test] %s', 'sharing-club' ), $subject );
		}

		// Replying reaches the Loan Director.
		$reply = implode( ', ', self::manager_emails() );
		return self::deliver( $to, $subject, self::confirmation_html( $post, $member ), self::confirmation_text( $post, $member ), $reply );
	}

	/**
	 * Tell the loan team and the member that an item was checked out.
	 *
	 * @param WP_Post $post Item.
	 * @param WP_User $user Member who has it.
	 * @param int     $lent Checkout date (timestamp).
	 * @param int     $due  Return date (timestamp).
	 * @return array{team:bool,member:bool} Whether each email was handed off.
	 */
	public static function send_checkout( $post, $user, $lent, $due ) {
		$member  = self::member_contact( $user );
		$item    = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );
		$format  = get_option( 'date_format' );
		$details = array(
			__( 'Checked out', 'sharing-club' ) => wp_date( $format, $lent ),
			__( 'Return by', 'sharing-club' )   => wp_date( $format, $due ),
		);

		// To the loan team; replying reaches the member.
		/* translators: 1: item name, 2: member name, 3: return date. */
		$subject = sprintf( __( 'Checked out: %1$s to %2$s, due %3$s', 'sharing-club' ), $item, $member['name'], $details[ __( 'Return by', 'sharing-club' ) ] );
		$reply   = is_email( $member['email'] ) ? self::header_safe( $member['name'] ) . ' <' . $member['email'] . '>' : '';
		$team    = self::deliver( self::manager_emails(), $subject, self::checkout_team_html( $post, $member, $details ), self::checkout_text( $post, $member, $details, true ), $reply );

		// To the member; replying reaches the loan team.
		$sent_member = false;
		if ( is_email( $member['email'] ) ) {
			/* translators: 1: item name, 2: return date. */
			$subject     = sprintf( __( 'You\'ve checked out %1$s (return by %2$s)', 'sharing-club' ), $item, $details[ __( 'Return by', 'sharing-club' ) ] );
			$sent_member = self::deliver( array( $member['email'] ), $subject, self::checkout_member_html( $post, $member, $details ), self::checkout_text( $post, $member, $details, false ), implode( ', ', self::manager_emails() ) );
		}

		return array(
			'team'   => $team,
			'member' => $sent_member,
		);
	}

	/**
	 * Checkout email for the loan team.
	 *
	 * @param WP_Post              $post    Item.
	 * @param array                $member  From member_contact().
	 * @param array<string,string> $details Checkout and return dates.
	 * @return string HTML.
	 */
	private static function checkout_team_html( $post, array $member, array $details ) {
		ob_start();
		?>
	<tr><td style="padding:28px 28px 8px;">
		<h1 style="margin:0 0 8px;font-size:22px;color:#111827;"><?php esc_html_e( 'Item checked out', 'sharing-club' ); ?></h1>
		<p style="margin:0;color:#374151;font-size:15px;line-height:1.5;">
			<?php
			/* translators: 1: item name, 2: member name. */
			echo wp_kses_post( sprintf( __( '%1$s has been checked out to %2$s.', 'sharing-club' ), '<strong>' . esc_html( get_the_title( $post ) ) . '</strong>', '<strong>' . esc_html( $member['name'] ) . '</strong>' ) );
			?>
		</p>
	</td></tr>
	<tr><td style="padding:16px 28px 0;">
		<?php echo self::item_card_html( $post, $details ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
	</td></tr>
	<tr><td style="padding:16px 28px 0;">
		<?php echo self::member_card_html( $member ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
	</td></tr>
	<tr><td align="center" style="padding:24px 28px;">
		<?php echo self::button_html( self::portal_url(), __( 'Open the Loan Portal', 'sharing-club' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
	</td></tr>
	<tr><td style="padding:16px 28px 24px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;line-height:1.5;">
		<?php
		/* translators: %s: member's first name. */
		echo esc_html( sprintf( __( 'Reply to this email to contact %s directly.', 'sharing-club' ), strtok( $member['name'], ' ' ) ) );
		?>
	</td></tr>
		<?php
		return self::email_shell( (string) ob_get_clean() );
	}

	/**
	 * Checkout email for the member.
	 *
	 * @param WP_Post              $post    Item.
	 * @param array                $member  From member_contact().
	 * @param array<string,string> $details Checkout and return dates.
	 * @return string HTML.
	 */
	private static function checkout_member_html( $post, array $member, array $details ) {
		$due = $details[ __( 'Return by', 'sharing-club' ) ];
		ob_start();
		?>
	<tr><td style="padding:28px 28px 8px;">
		<h1 style="margin:0 0 8px;font-size:22px;color:#111827;"><?php esc_html_e( 'Your equipment is checked out', 'sharing-club' ); ?></h1>
		<p style="margin:0 0 12px;color:#374151;font-size:15px;line-height:1.5;">
			<?php
			/* translators: %s: member's first name. */
			echo esc_html( sprintf( __( 'Hi %s,', 'sharing-club' ), strtok( $member['name'], ' ' ) ) );
			?>
		</p>
		<p style="margin:0;color:#374151;font-size:15px;line-height:1.5;">
			<?php
			/* translators: 1: item name, 2: return date. */
			echo wp_kses_post( sprintf( __( 'You\'ve checked out %1$s from the TAAS Loan Program. Please return it by %2$s.', 'sharing-club' ), '<strong>' . esc_html( get_the_title( $post ) ) . '</strong>', '<strong>' . esc_html( $due ) . '</strong>' ) );
			?>
		</p>
	</td></tr>
	<tr><td style="padding:16px 28px 0;">
		<?php echo self::item_card_html( $post, $details ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
	</td></tr>
	<tr><td style="padding:20px 28px 0;color:#374151;font-size:15px;line-height:1.5;">
		<?php esc_html_e( 'Need more time, or have a question about the equipment? Just reply to this email to reach the Loan Director.', 'sharing-club' ); ?>
	</td></tr>
	<tr><td align="center" style="padding:24px 28px;">
		<?php echo self::button_html( get_permalink( $post ), __( 'View the item', 'sharing-club' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
	</td></tr>
	<tr><td style="padding:16px 28px 24px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;line-height:1.5;">
		<?php esc_html_e( 'Enjoy, and clear skies!', 'sharing-club' ); ?>
	</td></tr>
		<?php
		return self::email_shell( (string) ob_get_clean() );
	}

	/**
	 * Checkout email as plain text.
	 *
	 * @param WP_Post              $post    Item.
	 * @param array                $member  From member_contact().
	 * @param array<string,string> $details Checkout and return dates.
	 * @param bool                 $team    For the loan team (else the member).
	 * @return string
	 */
	private static function checkout_text( $post, array $member, array $details, $team ) {
		$item  = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );
		$lines = array();
		if ( $team ) {
			/* translators: 1: item name, 2: member name. */
			$lines[] = sprintf( __( '%1$s has been checked out to %2$s.', 'sharing-club' ), $item, $member['name'] );
		} else {
			/* translators: %s: member's first name. */
			$lines[] = sprintf( __( 'Hi %s,', 'sharing-club' ), strtok( $member['name'], ' ' ) );
			$lines[] = '';
			/* translators: 1: item name, 2: return date. */
			$lines[] = sprintf( __( 'You\'ve checked out %1$s from the TAAS Loan Program. Please return it by %2$s.', 'sharing-club' ), $item, $details[ __( 'Return by', 'sharing-club' ) ] );
		}
		$lines[] = '';
		foreach ( $details as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		$lines[] = __( 'Item', 'sharing-club' ) . ': ' . get_permalink( $post );
		$lines[] = '';
		if ( $team ) {
			$none    = __( 'Not on file', 'sharing-club' );
			$lines[] = __( 'Name', 'sharing-club' ) . ': ' . $member['name'];
			$lines[] = __( 'Email', 'sharing-club' ) . ': ' . $member['email'];
			$lines[] = __( 'Mobile', 'sharing-club' ) . ': ' . ( '' !== $member['mobile'] ? $member['mobile'] : $none );
			$lines[] = __( 'Home', 'sharing-club' ) . ': ' . ( '' !== $member['home'] ? $member['home'] : $none );
			$lines[] = '';
			$lines[] = __( 'Open the Loan Portal', 'sharing-club' ) . ': ' . self::portal_url();
		} else {
			$lines[] = __( 'Need more time, or have a question about the equipment? Just reply to this email to reach the Loan Director.', 'sharing-club' );
			$lines[] = '';
			$lines[] = __( 'Enjoy, and clear skies!', 'sharing-club' );
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Send an email with a TAAS Loan Program sender and a plain-text copy.
	 *
	 * @param string[] $to       Recipients.
	 * @param string   $subject  Subject.
	 * @param string   $html     HTML body, or '' to send plain text only.
	 * @param string   $text     Plain-text body.
	 * @param string   $reply_to Reply-To header value, or ''.
	 * @return bool
	 */
	private static function deliver( array $to, $subject, $html, $text, $reply_to = '' ) {
		$headers = array(
			'Content-Type: ' . ( '' === $html ? 'text/plain' : 'text/html' ) . '; charset=UTF-8',
			'From: ' . self::header_safe( __( 'TAAS Loan Program', 'sharing-club' ) ) . ' <' . self::from_email() . '>',
		);
		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . str_replace( array( "\r", "\n" ), '', $reply_to );
		}

		// Remember why it failed, if it does. The return address is left to the
		// host or a mail plugin such as WP Mail SMTP: setting it here got emails lost.
		$failed = static function ( $error ) {
			self::$last_error = $error->get_error_message();
		};
		// A plain-text copy alongside the HTML, which spam filters expect.
		$alt = static function ( $phpmailer ) use ( $text ) {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		};
		self::$last_error = '';
		add_action( 'wp_mail_failed', $failed );
		if ( '' !== $html ) {
			add_action( 'phpmailer_init', $alt );
		}
		$sent = wp_mail( $to, wp_specialchars_decode( $subject, ENT_QUOTES ), '' === $html ? $text : $html, $headers );
		remove_action( 'phpmailer_init', $alt );
		remove_action( 'wp_mail_failed', $failed );
		return $sent;
	}

	/**
	 * Build and send the email.
	 *
	 * @param WP_Post  $post Item.
	 * @param WP_User  $user Member.
	 * @param string[] $to   Recipients.
	 * @param bool     $test Mark the subject as a test.
	 * @param string   $kind 'full' (normal), 'text' (plain text only) or
	 *                       'html' (no Reply-To). The last two are for the email check.
	 * @param string   $tag  Extra words for the start of the subject.
	 * @return bool
	 */
	private static function send( $post, $user, array $to, $test = false, $kind = 'full', $tag = '' ) {
		$member = self::member_contact( $user );
		$item   = get_the_title( $post );

		/* translators: 1: item name, 2: member name. */
		$subject = sprintf( __( 'Loan request: %1$s from %2$s', 'sharing-club' ), $item, $member['name'] );
		if ( $test ) {
			/* translators: %s: email subject. */
			$subject = sprintf( __( '[Test] %s', 'sharing-club' ), $subject );
		}
		if ( $tag ) {
			$subject = $tag . ' ' . $subject;
		}

		// Replying goes straight to the member.
		$reply = '';
		if ( 'html' !== $kind && is_email( $member['email'] ) ) {
			$reply = self::header_safe( $member['name'] ) . ' <' . $member['email'] . '>';
		}
		$html = 'text' === $kind ? '' : self::email_html( $post, $member );
		return self::deliver( $to, $subject, $html, self::email_text( $post, $member ), $reply );
	}

	/**
	 * The email as plain text.
	 *
	 * @param WP_Post $post   Item.
	 * @param array   $member From member_contact().
	 * @return string
	 */
	private static function email_text( $post, array $member ) {
		$none  = __( 'Not on file', 'sharing-club' );
		$lines = array(
			/* translators: 1: member name, 2: item name. */
			sprintf( __( '%1$s would like to borrow %2$s.', 'sharing-club' ), $member['name'], wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ) ),
			'',
			__( 'Item', 'sharing-club' ) . ': ' . get_permalink( $post ),
			'',
			__( 'Name', 'sharing-club' ) . ': ' . $member['name'],
			__( 'Email', 'sharing-club' ) . ': ' . $member['email'],
			__( 'Mobile', 'sharing-club' ) . ': ' . ( '' !== $member['mobile'] ? $member['mobile'] : $none ),
			__( 'Home', 'sharing-club' ) . ': ' . ( '' !== $member['home'] ? $member['home'] : $none ),
			__( 'Username', 'sharing-club' ) . ': ' . $member['username'],
			'',
			__( 'Open the Loan Portal', 'sharing-club' ) . ': ' . self::portal_url(),
		);
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Send four versions of the test email, from as simple as WP Mail SMTP's
	 * own test up to the real thing, to see which ones arrive.
	 *
	 * @param WP_User $user Member to show in the email.
	 * @return array<string,bool> Subject label => whether it was handed off.
	 */
	private static function send_check( $user ) {
		$items = get_posts(
			array(
				'post_type'      => 'shared_item',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);
		if ( ! $items ) {
			return array();
		}
		$to      = self::manager_emails();
		$results = array();

		$label             = __( 'Check 1 of 4: basic', 'sharing-club' );
		$results[ $label ] = wp_mail( $to, $label, __( 'A basic test from the TAAS Loan Program. No formatting, links or reply address.', 'sharing-club' ) );

		$label             = __( 'Check 2 of 4: plain text', 'sharing-club' );
		$results[ $label ] = self::send( $items[0], $user, $to, true, 'text', $label . ' -' );

		$label             = __( 'Check 3 of 4: formatted, no reply address', 'sharing-club' );
		$results[ $label ] = self::send( $items[0], $user, $to, true, 'html', $label . ' -' );

		$label             = __( 'Check 4 of 4: the real email', 'sharing-club' );
		$results[ $label ] = self::send( $items[0], $user, $to, true, 'full', $label . ' -' );

		return $results;
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
	 * @return array{name:string,email:string,mobile:string,home:string,username:string,user_id:int,found:bool}
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
			'user_id'  => (int) $user->ID,
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
		$item  = get_the_title( $post );
		$first = strtok( $member['name'], ' ' );

		ob_start();
		?>
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
		<?php echo self::item_card_html( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
	</td></tr>
	<tr><td style="padding:16px 28px 0;">
		<?php echo self::member_card_html( $member ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
	</td></tr>
	<tr><td align="center" style="padding:24px 28px;">
		<?php
		$check_out = TAAS_Loan_Checkout::page_url( $post->ID, $member['user_id'] );
		echo self::button_html( $check_out, __( 'Check it out to them', 'sharing-club' ) ) . self::button_html( self::portal_url(), __( 'Open the Loan Portal', 'sharing-club' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		?>
	</td></tr>
	<tr><td style="padding:16px 28px 24px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;line-height:1.5;">
		<?php
		/* translators: %s: member's first name. */
		echo esc_html( sprintf( __( 'Reply to this email to contact %s directly.', 'sharing-club' ), $first ) );
		?>
	</td></tr>
		<?php
		return self::email_shell( (string) ob_get_clean() );
	}

	/**
	 * Wrap email content in the TAAS Loan Program layout.
	 *
	 * @param string $rows Table rows (HTML) for the white card.
	 * @return string HTML.
	 */
	private static function email_shell( $rows ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
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
	<?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by the caller. ?>
</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Item card for emails: name (linked), category, and either when it was
	 * requested or the given details (like checkout and return dates).
	 *
	 * @param WP_Post              $post    Item.
	 * @param array<string,string> $details Label => value (plain text). Empty shows the time now.
	 * @return string HTML.
	 */
	private static function item_card_html( $post, array $details = array() ) {
		$category = TAAS_Loan_Shortcodes::primary_category( $post->ID );
		$meta     = $category ? $category->name : '';
		if ( ! $details ) {
			$meta .= ( $meta ? ' · ' : '' ) . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		}
		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;"><tr><td style="padding:16px 20px;">'
			. '<div style="color:#5b6270;font-size:12px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">' . esc_html__( 'Item', 'sharing-club' ) . '</div>'
			. '<a href="' . esc_url( get_permalink( $post ) ) . '" style="color:#111827;font-size:16px;font-weight:600;text-decoration:none;">' . esc_html( get_the_title( $post ) ) . '</a>';
		if ( $meta ) {
			$html .= '<div style="color:#5b6270;font-size:13px;margin-top:4px;">' . esc_html( $meta ) . '</div>';
		}
		if ( $details ) {
			$html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:12px;">';
			foreach ( $details as $label => $value ) {
				$html .= '<tr><td style="padding:3px 16px 3px 0;color:#5b6270;font-size:14px;">' . esc_html( $label ) . '</td>'
					. '<td style="padding:3px 0;color:#111827;font-size:15px;font-weight:600;">' . esc_html( $value ) . '</td></tr>';
			}
			$html .= '</table>';
		}
		return $html . '</td></tr></table>';
	}

	/**
	 * Member card for emails: name, email, phones, username.
	 *
	 * @param array $member From member_contact().
	 * @return string HTML.
	 */
	private static function member_card_html( array $member ) {
		$missing = '<span style="color:#8a8f98;">' . esc_html__( 'Not on file', 'sharing-club' ) . '</span>';
		$rows    = array(
			__( 'Name', 'sharing-club' )     => esc_html( $member['name'] ),
			__( 'Email', 'sharing-club' )    => '<a href="' . esc_url( 'mailto:' . $member['email'] ) . '" style="color:#1d4ed8;text-decoration:none;">' . esc_html( $member['email'] ) . '</a>',
			__( 'Mobile', 'sharing-club' )   => self::phone_html( $member['mobile'], $missing ),
			__( 'Home', 'sharing-club' )     => self::phone_html( $member['home'], $missing ),
			__( 'Username', 'sharing-club' ) => esc_html( $member['username'] ),
		);

		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;"><tr><td style="padding:16px 20px;">'
			. '<div style="color:#5b6270;font-size:12px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">' . esc_html__( 'Member', 'sharing-club' ) . '</div>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><td style="padding:6px 0;width:110px;color:#5b6270;font-size:14px;vertical-align:top;">' . esc_html( $label ) . '</td>'
				. '<td style="padding:6px 0;color:#111827;font-size:15px;">' . $value . '</td></tr>';
		}
		$html .= '</table>';
		if ( ! $member['found'] ) {
			$html .= '<p style="margin:12px 0 0;color:#8a5a00;font-size:13px;">'
				. esc_html__( 'No Participants Database record matches this member\'s email, so phone numbers are missing.', 'sharing-club' )
				. '</p>';
		}
		return $html . '</td></tr></table>';
	}

	/**
	 * A big blue email button.
	 *
	 * @param string $url   Link.
	 * @param string $label Button text.
	 * @return string HTML.
	 */
	private static function button_html( $url, $label ) {
		return '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#1d4ed8;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;padding:12px 26px;border-radius:6px;margin:4px;">' . esc_html( $label ) . '</a>';
	}

	/**
	 * The member's confirmation email.
	 *
	 * @param WP_Post $post   Item.
	 * @param array   $member From member_contact().
	 * @return string HTML.
	 */
	private static function confirmation_html( $post, array $member ) {
		$first = strtok( $member['name'], ' ' );
		ob_start();
		?>
	<tr><td style="padding:28px 28px 8px;">
		<h1 style="margin:0 0 8px;font-size:22px;color:#111827;"><?php esc_html_e( 'We received your request', 'sharing-club' ); ?></h1>
		<p style="margin:0 0 12px;color:#374151;font-size:15px;line-height:1.5;">
			<?php
			/* translators: %s: member's first name. */
			echo esc_html( sprintf( __( 'Hi %s,', 'sharing-club' ), $first ) );
			?>
		</p>
		<p style="margin:0;color:#374151;font-size:15px;line-height:1.5;">
			<?php
			/* translators: %s: item name. */
			echo wp_kses_post( sprintf( __( 'Thank you for your request to borrow %s. The Loan Director will be in touch soon to arrange pickup.', 'sharing-club' ), '<strong>' . esc_html( get_the_title( $post ) ) . '</strong>' ) );
			?>
		</p>
	</td></tr>
	<tr><td style="padding:16px 28px 0;">
		<?php echo self::item_card_html( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
	</td></tr>
	<tr><td style="padding:20px 28px 0;">
		<div style="color:#5b6270;font-size:12px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;"><?php esc_html_e( 'What happens next', 'sharing-club' ); ?></div>
		<ol style="margin:0;padding-left:20px;color:#374151;font-size:15px;line-height:1.6;">
			<li><?php esc_html_e( 'The Loan Director will contact you by email or phone.', 'sharing-club' ); ?></li>
			<li><?php esc_html_e( 'Together you\'ll set a time to pick up the equipment.', 'sharing-club' ); ?></li>
		</ol>
	</td></tr>
	<tr><td align="center" style="padding:28px;">
		<a href="<?php echo esc_url( get_permalink( $post ) ); ?>" style="display:inline-block;background:#1d4ed8;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;padding:12px 26px;border-radius:6px;"><?php esc_html_e( 'View the item', 'sharing-club' ); ?></a>
	</td></tr>
	<tr><td style="padding:16px 28px 24px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;line-height:1.5;">
		<?php esc_html_e( 'Questions? Reply to this email to reach the Loan Director. Clear skies!', 'sharing-club' ); ?>
	</td></tr>
		<?php
		return self::email_shell( (string) ob_get_clean() );
	}

	/**
	 * The member's confirmation email as plain text.
	 *
	 * @param WP_Post $post   Item.
	 * @param array   $member From member_contact().
	 * @return string
	 */
	private static function confirmation_text( $post, array $member ) {
		$item  = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );
		$lines = array(
			/* translators: %s: member's first name. */
			sprintf( __( 'Hi %s,', 'sharing-club' ), strtok( $member['name'], ' ' ) ),
			'',
			/* translators: %s: item name. */
			sprintf( __( 'Thank you for your request to borrow %s. The Loan Director will be in touch soon to arrange pickup.', 'sharing-club' ), $item ),
			'',
			__( 'What happens next', 'sharing-club' ) . ':',
			'1. ' . __( 'The Loan Director will contact you by email or phone.', 'sharing-club' ),
			'2. ' . __( 'Together you\'ll set a time to pick up the equipment.', 'sharing-club' ),
			'',
			__( 'Item', 'sharing-club' ) . ': ' . get_permalink( $post ),
			'',
			__( 'Questions? Reply to this email to reach the Loan Director. Clear skies!', 'sharing-club' ),
		);
		return implode( "\n", $lines ) . "\n";
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
		<tr>
			<th scope="row"><?php esc_html_e( 'Confirm requests to members', 'sharing-club' ); ?></th>
			<td>
				<label><input type="checkbox" name="scwp_options[member_email]" value="on" <?php checked( self::confirm_members() ); ?>> <?php esc_html_e( 'Email members that their request arrived and the Loan Director will be in touch', 'sharing-club' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="scwp-from-email"><?php esc_html_e( 'Send emails from', 'sharing-club' ); ?></label></th>
			<td>
				<input type="email" id="scwp-from-email" class="regular-text" name="scwp_options[from_email]" value="<?php echo esc_attr( scwp_get_option( 'from_email' ) ); ?>" placeholder="<?php echo esc_attr( self::from_email() ); ?>">
				<p class="description"><?php esc_html_e( 'The address request emails come from. Use one at this site\'s own domain so Gmail doesn\'t treat them as spam. Leave empty for the one shown.', 'sharing-club' ); ?></p>
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
				<p class="description"><?php esc_html_e( 'Sends a sample request, using your own account as the member, to the Loan Manager email, plus a sample of the member\'s confirmation if that\'s turned on. Save your changes first.', 'sharing-club' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="scwp-test-link"><?php esc_html_e( 'Private test link', 'sharing-club' ); ?></label></th>
			<td>
				<input type="text" id="scwp-test-link" class="large-text code" readonly onfocus="this.select()" value="<?php echo esc_attr( self::test_link_url() ); ?>">
				<p class="description">
					<?php esc_html_e( 'Bookmark this to send a test email any time, without logging in. It works for anyone who has it, so keep it private.', 'sharing-club' ); ?>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::RESET_ACTION ), self::RESET_ACTION ) ); ?>"><?php esc_html_e( 'Make a new link', 'sharing-club' ); ?></a>
				</p>
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

		// Stored as 'off' when unticked, so it's on until someone turns it off.
		$options['member_email'] = empty( $options['member_email'] ) ? 'off' : 'on';

		$from = sanitize_email( trim( (string) ( $options['from_email'] ?? '' ) ) );
		if ( is_email( $from ) ) {
			$options['from_email'] = $from;
		} else {
			unset( $options['from_email'] );
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

		$result = self::send_test( wp_get_current_user() );
		wp_safe_redirect(
			add_query_arg(
				'taas_test_email',
				$result,
				admin_url( 'options-general.php?page=theme-settings' )
			)
		);
		exit;
	}

	/**
	 * Send a sample request email about the first item.
	 *
	 * @param WP_User|false $user Member to show in the email.
	 * @return string 'sent', 'failed', 'noitems' or 'nouser'.
	 */
	private static function send_test( $user ) {
		if ( ! $user || ! $user->exists() ) {
			return 'nouser';
		}
		$items = get_posts(
			array(
				'post_type'      => 'shared_item',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);
		if ( ! $items ) {
			return 'noitems';
		}
		$sent = self::send( $items[0], $user, self::manager_emails(), true );
		if ( $sent && self::confirm_members() ) {
			// A sample of what the member gets, also to the Loan Manager.
			self::send_confirmation( $items[0], $user, self::manager_emails(), true );
		}
		return $sent ? 'sent' : 'failed';
	}

	/**
	 * The private test link's secret key, made on first use.
	 *
	 * @return string
	 */
	private static function link_key() {
		$key = (string) get_option( self::LINK_KEY_OPTION );
		if ( strlen( $key ) < 20 ) {
			$key = wp_generate_password( 32, false );
			update_option( self::LINK_KEY_OPTION, $key, false );
		}
		return $key;
	}

	/**
	 * The private test link.
	 *
	 * @return string
	 */
	public static function test_link_url() {
		return add_query_arg(
			array(
				'action' => self::LINK_ACTION,
				'key'    => self::link_key(),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Send a test email from the private link, and say how it went.
	 */
	public static function handle_test_link() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the secret key is the check.
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( '' === $key || ! hash_equals( self::link_key(), $key ) ) {
			wp_die( esc_html__( 'This test link is not valid. Copy the current one from Settings > TAAS Loan Program.', 'sharing-club' ), esc_html__( 'Test email', 'sharing-club' ), array( 'response' => 403 ) );
		}
		if ( get_transient( 'taas_loan_test_link_sent' ) ) {
			wp_die( esc_html__( 'A test email was just sent. Wait a minute before sending another.', 'sharing-club' ), esc_html__( 'Test email', 'sharing-club' ), array( 'response' => 429 ) );
		}
		set_transient( 'taas_loan_test_link_sent', 1, MINUTE_IN_SECONDS );

		// Show the Loan Manager as the member, or the first site admin.
		$user = get_user_by( 'email', self::manager_emails()[0] );
		if ( ! $user ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
				)
			);
			$user   = $admins ? $admins[0] : false;
		}

		$to = implode( ', ', self::manager_emails() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the secret key is the check.
		if ( ! empty( $_GET['check'] ) && $user ) {
			$list = '';
			foreach ( self::send_check( $user ) as $label => $ok ) {
				$list .= '<li>' . esc_html( $label ) . ': ' . ( $ok ? esc_html__( 'handed off', 'sharing-club' ) : esc_html__( 'failed', 'sharing-club' ) ) . '</li>';
			}
			$intro = sprintf(
				/* translators: %s: recipient address(es). */
				__( 'Sent four test emails to %s. Wait a few minutes, check the inbox and spam, and note which ones arrived:', 'sharing-club' ),
				$to
			);
			wp_die( '<p>' . esc_html( $intro ) . '</p><ul>' . $list . '</ul>', esc_html__( 'Email check', 'sharing-club' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $list is escaped above.
		}

		$result = self::send_test( $user );
		if ( 'sent' === $result ) {
			/* translators: 1: recipient address(es), 2: sender address. */
			$message = sprintf( __( 'Test email(s) sent to %1$s from %2$s. If nothing arrives in a few minutes, check the spam folder. If it\'s not there either, the web host isn\'t delivering the site\'s email and an SMTP plugin is needed.', 'sharing-club' ), $to, self::from_email() );
		} elseif ( 'failed' === $result ) {
			/* translators: %s: error message from the mail system. */
			$message = sprintf( __( 'The email could not be sent. The mail system said: %s', 'sharing-club' ), self::$last_error ? self::$last_error : __( 'no reason given', 'sharing-club' ) );
		} elseif ( 'noitems' === $result ) {
			$message = __( 'Add at least one item before sending a test email.', 'sharing-club' );
		} else {
			$message = __( 'No user account found to show as the member.', 'sharing-club' );
		}
		$check = '';
		if ( 'sent' === $result ) {
			$check = '<p><a href="' . esc_url( add_query_arg( 'check', 1, self::test_link_url() ) ) . '">'
				. esc_html__( 'Email not arriving? Wait a minute, then run the email check (sends four simpler versions).', 'sharing-club' ) . '</a></p>';
		}
		wp_die( '<p>' . esc_html( $message ) . '</p>' . $check, esc_html__( 'Test email', 'sharing-club' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $check is escaped above.
	}

	/**
	 * Replace the private test link, so the old one stops working.
	 */
	public static function handle_reset_link() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sharing-club' ) );
		}
		check_admin_referer( self::RESET_ACTION );
		delete_option( self::LINK_KEY_OPTION );
		self::link_key();
		wp_safe_redirect( add_query_arg( 'taas_test_email', 'newlink', admin_url( 'options-general.php?page=theme-settings' ) ) );
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
			'sent'    => array( 'success', sprintf( __( 'Test email(s) sent to %s.', 'sharing-club' ), implode( ', ', self::manager_emails() ) ) ),
			'failed'  => array( 'error', __( 'The test email could not be sent. Check that this site can send email (an SMTP plugin may be needed).', 'sharing-club' ) ),
			'noitems' => array( 'warning', __( 'Add at least one item before sending a test email.', 'sharing-club' ) ),
			'newlink' => array( 'success', __( 'Made a new private test link. The old one no longer works.', 'sharing-club' ) ),
		);
		if ( isset( $messages[ $result ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $result ][0] ), esc_html( $messages[ $result ][1] ) );
		}
	}
}

TAAS_Loan_Notify::init();
