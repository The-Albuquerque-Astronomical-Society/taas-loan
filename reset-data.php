<?php
/**
 * Delete all loan program data.
 *
 * Removes every item, loan record and category, and optionally the pictures
 * attached to the items. Settings are kept. Vik Rent Items is not touched.
 *
 * Admin: bottom of Settings > TAAS Loan Program
 * WP-CLI: wp taas-loan reset [--keep-pictures] [--yes]
 *
 * @package TAAS_Loan_Program
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes all TAAS Loan Program data.
 */
class TAAS_Loan_Reset {

	const ACTION    = 'taas_loan_reset';
	const POST_TYPE = 'shared_item';
	const TAXONOMY  = 'shared_item_category';
	const CONFIRM   = 'DELETE';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'scwp_settings_page_after', array( __CLASS__, 'render_section' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'taas-loan reset', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * IDs of all items, in any status (including trash and drafts).
	 *
	 * @return int[]
	 */
	private static function item_ids() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", self::POST_TYPE ) ) );
	}

	/**
	 * IDs of all loan records.
	 *
	 * @return int[]
	 */
	private static function lending_ids() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_type = %s", 'lending' ) ) );
	}

	/**
	 * IDs of pictures attached to the given items.
	 *
	 * Pictures picked from the Media Library but uploaded elsewhere are not
	 * attached to an item, so they are left alone.
	 *
	 * @param int[] $item_ids Item IDs.
	 * @return int[]
	 */
	private static function picture_ids( array $item_ids ) {
		if ( ! $item_ids ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'       => 'attachment',
					'post_status'     => 'any',
					'post_parent__in' => $item_ids,
					'posts_per_page'  => -1,
					'fields'          => 'ids',
					'no_found_rows'   => true,
				)
			)
		);
	}

	/**
	 * IDs of all categories.
	 *
	 * @return int[]
	 */
	private static function category_ids() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		return is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
	}

	/**
	 * What would be deleted.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		$items = self::item_ids();
		return array(
			'items'      => count( $items ),
			'lendings'   => count( self::lending_ids() ),
			'categories' => count( self::category_ids() ),
			'pictures'   => count( self::picture_ids( $items ) ),
		);
	}

	/**
	 * Delete everything.
	 *
	 * @param bool $delete_pictures Also delete pictures attached to items.
	 * @return array<string,int> What was deleted.
	 */
	public static function reset( $delete_pictures = true ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$done  = array(
			'items'      => 0,
			'lendings'   => 0,
			'categories' => 0,
			'pictures'   => 0,
		);
		$items = self::item_ids();

		// Pictures first, while we still know which items they belong to.
		if ( $delete_pictures ) {
			foreach ( self::picture_ids( $items ) as $id ) {
				if ( wp_delete_attachment( $id, true ) ) {
					++$done['pictures'];
				}
			}
		}

		foreach ( self::lending_ids() as $id ) {
			if ( wp_delete_comment( $id, true ) ) {
				++$done['lendings'];
			}
		}

		foreach ( $items as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				++$done['items'];
			}
		}

		foreach ( self::category_ids() as $id ) {
			if ( true === wp_delete_term( $id, self::TAXONOMY ) ) {
				++$done['categories'];
			}
		}

		return $done;
	}

	/**
	 * Settings page section.
	 */
	public static function render_section() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$c = self::counts();
		?>
		<hr style="margin:2em 0" />
		<h2><?php esc_html_e( 'Delete all data', 'sharing-club' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: items, 2: loan records, 3: categories, 4: pictures. */
				esc_html__( 'Permanently deletes %1$d items, %2$d loan records, %3$d categories and %4$d pictures attached to items. Use it to start over, for example before importing again.', 'sharing-club' ),
				(int) $c['items'],
				(int) $c['lendings'],
				(int) $c['categories'],
				(int) $c['pictures']
			);
			?>
		</p>
		<p><?php esc_html_e( 'Your settings are kept. Vik Rent Items is not changed. This cannot be undone, so back up the site first.', 'sharing-club' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION ); ?>
			<p>
				<label>
					<input type="checkbox" name="delete_pictures" value="1" checked="checked" />
					<?php esc_html_e( 'Also delete the pictures attached to items', 'sharing-club' ); ?>
				</label>
			</p>
			<p>
				<label for="taas-loan-reset-confirm">
					<?php
					printf(
						/* translators: %s: the word to type. */
						esc_html__( 'Type %s to confirm:', 'sharing-club' ),
						'<code>' . esc_html( self::CONFIRM ) . '</code>'
					);
					?>
				</label>
				<input type="text" id="taas-loan-reset-confirm" name="confirm" autocomplete="off" />
			</p>
			<?php submit_button( __( 'Delete all data', 'sharing-club' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Handle the Delete button.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'sharing-club' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$back = admin_url( 'options-general.php?page=theme-settings' );

		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
		if ( self::CONFIRM !== $confirm ) {
			wp_safe_redirect( add_query_arg( 'taas_reset', 'unconfirmed', $back ) );
			exit;
		}

		$done = self::reset( ! empty( $_POST['delete_pictures'] ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'taas_reset' => 'done',
					'items'      => $done['items'],
					'lendings'   => $done['lendings'],
					'categories' => $done['categories'],
					'pictures'   => $done['pictures'],
				),
				$back
			)
		);
		exit;
	}

	/**
	 * Result message on the settings page.
	 */
	public static function notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only shows a message.
		if ( ! isset( $_GET['taas_reset'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = sanitize_key( wp_unslash( $_GET['taas_reset'] ) );
		if ( 'unconfirmed' === $state ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: the word to type. */
				esc_html__( 'Nothing was deleted. Type %s in the box to confirm.', 'sharing-club' ),
				'<code>' . esc_html( self::CONFIRM ) . '</code>'
			);
			echo '</p></div>';
		} elseif ( 'done' === $state ) {
			$n = static function ( $key ) {
				return isset( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : 0;
			};
			echo '<div class="notice notice-success"><p>';
			printf(
				/* translators: 1: items, 2: loan records, 3: categories, 4: pictures. */
				esc_html__( 'Deleted %1$d items, %2$d loan records, %3$d categories and %4$d pictures.', 'sharing-club' ),
				(int) $n( 'items' ),
				(int) $n( 'lendings' ),
				(int) $n( 'categories' ),
				(int) $n( 'pictures' )
			);
			echo '</p></div>';
		}
		// phpcs:enable
	}

	/**
	 * Delete all TAAS Loan Program data.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-pictures]
	 * : Keep the pictures attached to items.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taas-loan reset
	 *     wp taas-loan reset --keep-pictures --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public static function cli( $args, $assoc_args ) {
		$c = self::counts();
		WP_CLI::confirm(
			sprintf(
				'Permanently delete %d items, %d loan records, %d categories%s?',
				$c['items'],
				$c['lendings'],
				$c['categories'],
				empty( $assoc_args['keep-pictures'] ) ? sprintf( ' and %d pictures', $c['pictures'] ) : ''
			),
			$assoc_args
		);
		$done = self::reset( empty( $assoc_args['keep-pictures'] ) );
		WP_CLI::success(
			sprintf(
				'Deleted %d items, %d loan records, %d categories and %d pictures.',
				$done['items'],
				$done['lendings'],
				$done['categories'],
				$done['pictures']
			)
		);
	}
}

TAAS_Loan_Reset::init();
