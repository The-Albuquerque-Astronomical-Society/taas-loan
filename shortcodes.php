<?php
/**
 * Shortcodes.
 *
 * [taas_loan_items]
 *     Shows the equipment, grouped by category, with a picture and an
 *     In / Out / Requested status for each item.
 *
 *     category="telescopes,accessories"  Only these categories (slugs), in this order.
 *     status="no"                         Hide the In / Out status.
 *
 * @package TAAS_Loan_Program
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the [taas_loan_items] shortcode.
 */
class TAAS_Loan_Shortcodes {

	const POST_TYPE = 'shared_item';
	const TAXONOMY  = 'shared_item_category';

	/**
	 * Register shortcodes.
	 */
	public static function init() {
		add_shortcode( 'taas_loan_items', array( __CLASS__, 'items' ) );
	}

	/**
	 * Latest loan status for every item, in one query.
	 *
	 * @return array<int,string> Post ID => 'in', 'out' or 'requested'.
	 */
	public static function statuses() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT c.comment_post_ID AS post_id, c.comment_date AS lent, c.comment_date_gmt AS due
			FROM {$wpdb->comments} c
			INNER JOIN (
				SELECT comment_post_ID, MAX(comment_ID) AS last_id
				FROM {$wpdb->comments}
				WHERE comment_type = 'lending'
				GROUP BY comment_post_ID
			) latest ON c.comment_ID = latest.last_id",
			ARRAY_A
		);

		$now = current_time( 'mysql' );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$lent = (string) $row['lent'];
			$due  = (string) $row['due'];
			if ( self::is_empty_date( $lent ) ) {
				$status = 'requested';
			} elseif ( self::is_empty_date( $due ) || $due > $now ) {
				$status = 'out';
			} else {
				$status = 'in';
			}
			$out[ (int) $row['post_id'] ] = $status;
		}
		return $out;
	}

	/**
	 * Whether a stored date means "not set".
	 *
	 * @param string $date MySQL date.
	 * @return bool
	 */
	private static function is_empty_date( $date ) {
		return '' === $date || 0 === strpos( $date, '0000-00-00' );
	}

	/**
	 * Label for a status.
	 *
	 * @param string $status 'in', 'out' or 'requested'.
	 * @return string
	 */
	private static function status_label( $status ) {
		switch ( $status ) {
			case 'out':
				return __( 'Out', 'sharing-club' );
			case 'requested':
				return __( 'Requested', 'sharing-club' );
			default:
				return __( 'In', 'sharing-club' );
		}
	}

	/**
	 * [taas_loan_items] output.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function items( $atts ) {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'status'   => 'yes',
			),
			$atts,
			'taas_loan_items'
		);

		wp_enqueue_style( 'scwp-public', plugin_dir_url( __FILE__ ) . 'css/lending-library-public.css', array(), '1.3.1' );

		if ( ! is_user_logged_in() && ! scwp_get_option( 'public' ) ) {
			return '<p class="taas-loan-login">' . sprintf(
				/* translators: %s: login URL. */
				wp_kses_post( __( 'Please <a href="%s">log in</a> to see the equipment.', 'sharing-club' ) ),
				esc_url( wp_login_url( get_permalink() ) )
			) . '</p>';
		}

		$show_status = ! in_array( strtolower( $atts['status'] ), array( 'no', 'false', '0', 'off' ), true );

		// Which categories, in which order.
		$slugs = array_filter( array_map( 'sanitize_title', explode( ',', $atts['category'] ) ) );
		if ( $slugs ) {
			$terms = array();
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, self::TAXONOMY );
				if ( $term ) {
					$terms[] = $term;
				}
			}
		} else {
			$terms = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => true,
					'parent'     => 0,
					'orderby'    => 'term_id',
				)
			);
			if ( is_wp_error( $terms ) ) {
				$terms = array();
			}
		}

		$statuses = $show_status ? self::statuses() : array();
		$html     = '';

		foreach ( $terms as $term ) {
			$html .= self::render_group(
				$term->name,
				array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
				$statuses,
				$show_status
			);
		}

		// Items with no category, only when showing everything.
		if ( ! $slugs ) {
			$html .= self::render_group(
				__( 'Other', 'sharing-club' ),
				array(
					array(
						'taxonomy' => self::TAXONOMY,
						'operator' => 'NOT EXISTS',
					),
				),
				$statuses,
				$show_status
			);
		}

		if ( '' === $html ) {
			return '<p class="taas-loan-empty">' . esc_html__( 'No equipment to show yet.', 'sharing-club' ) . '</p>';
		}

		return '<div class="taas-loan-items">' . $html . '</div>';
	}

	/**
	 * One category heading and its items.
	 *
	 * @param string $title       Heading.
	 * @param array  $tax_query   Which items.
	 * @param array  $statuses    Post ID => status.
	 * @param bool   $show_status Show the status badge.
	 * @return string
	 */
	private static function render_group( $title, $tax_query, $statuses, $show_status ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'tax_query'      => $tax_query,
			)
		);
		if ( ! $posts ) {
			return '';
		}

		$html = '<h3 class="taas-loan-category">' . esc_html( $title ) . '</h3><ul class="taas-loan-grid">';
		foreach ( $posts as $post ) {
			$status = isset( $statuses[ $post->ID ] ) ? $statuses[ $post->ID ] : 'in';
			$link   = get_permalink( $post );

			$html .= '<li class="taas-loan-item taas-loan-' . esc_attr( $status ) . '">';
			$html .= '<a class="taas-loan-link" href="' . esc_url( $link ) . '">';
			$html .= '<span class="taas-loan-thumb">' . get_the_post_thumbnail( $post, 'medium', array( 'loading' => 'lazy' ) ) . '</span>';
			$html .= '<span class="taas-loan-name">' . esc_html( get_the_title( $post ) ) . '</span>';
			$html .= '</a>';
			if ( $show_status ) {
				$html .= '<span class="taas-loan-status">' . esc_html( self::status_label( $status ) ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';

		return $html;
	}
}

TAAS_Loan_Shortcodes::init();
