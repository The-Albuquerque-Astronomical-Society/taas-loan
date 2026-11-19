<?php
/**
 * Shortcodes and shared display helpers.
 *
 * [taas_loan_items]
 *     Category cards (picture + name). Clicking one shows that category's
 *     items as a list on the same page.
 *
 *     view="list"                        All items as a list (no category cards).
 *     view="grid"                        All items as picture cards, grouped by category.
 *     category="telescopes,accessories"  Only these categories (slugs), in this order.
 *     status="no"                        Hide the In / Out status.
 *
 * @package TAAS_Loan_Program
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the [taas_loan_items] shortcode and the pieces the item page reuses.
 */
class TAAS_Loan_Shortcodes {

	const POST_TYPE = 'shared_item';
	const TAXONOMY  = 'shared_item_category';

	/** Query string key for the category shown on the loan program page. */
	const CAT_PARAM = 'loan_cat';

	/** Option that remembers which page holds the shortcode. */
	const PAGE_OPTION = 'taas_loan_page_id';

	/** Term meta with a category's picture (attachment ID). */
	const TERM_IMAGE = 'taas_image_id';

	/** Post meta with an item's short description for lists. */
	const SHORT_DESC = '_taas_short_description';

	/** Pictures narrower than this are shown whole instead of stretched to fill a card. */
	const CARD_IMAGE_MIN_WIDTH = 400;

	/**
	 * Register shortcodes and hooks.
	 */
	public static function init() {
		add_shortcode( 'taas_loan_items', array( __CLASS__, 'items' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_archives' ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** Comment meta key holding a lending's status. */
	const STATE_META = 'taas_loan_status';

	/**
	 * Lending statuses the Loan Director can pick.
	 *
	 * @return array<string,string> Key => label.
	 */
	public static function states() {
		return array(
			'out'      => __( 'Checked Out', 'sharing-club' ),
			'returned' => __( 'Returned', 'sharing-club' ),
			'damaged'  => __( 'Damaged needs repair', 'sharing-club' ),
		);
	}

	/**
	 * Save a lending's status.
	 *
	 * @param int    $lending_id Lending (comment) ID.
	 * @param string $state      Key from states().
	 */
	public static function set_state( $lending_id, $state ) {
		if ( isset( self::states()[ $state ] ) ) {
			update_comment_meta( (int) $lending_id, self::STATE_META, $state );
		}
	}

	/**
	 * Work out a lending's status from its dates and saved status.
	 *
	 * @param string $lent  MySQL lending date.
	 * @param string $due   MySQL return date.
	 * @param string $state Saved status ('' for older lendings).
	 * @return string 'requested', 'out', 'returned' or 'damaged'.
	 */
	public static function lending_state( $lent, $due, $state ) {
		if ( self::is_empty_date( (string) $lent ) ) {
			return 'requested';
		}
		if ( isset( self::states()[ $state ] ) ) {
			return $state;
		}
		// Older lendings with no saved status: go by the return date.
		$due = (string) $due;
		return ( self::is_empty_date( $due ) || $due > current_time( 'mysql' ) ) ? 'out' : 'returned';
	}

	/**
	 * Latest loan for every item, in one query.
	 *
	 * @return array<int,array{status:string,user_id:int,id:int}> Post ID => status ('in', 'out', 'requested' or 'repair'), borrower and lending ID.
	 */
	public static function loans() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.comment_ID AS id, c.comment_post_ID AS post_id, c.user_id, c.comment_date AS lent, c.comment_date_gmt AS due, m.meta_value AS state
				FROM {$wpdb->comments} c
				INNER JOIN (
					SELECT comment_post_ID, MAX(comment_ID) AS last_id
					FROM {$wpdb->comments}
					WHERE comment_type = 'lending'
					GROUP BY comment_post_ID
				) latest ON c.comment_ID = latest.last_id
				LEFT JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = %s",
				self::STATE_META
			),
			ARRAY_A
		);

		$map = array(
			'requested' => 'requested',
			'out'       => 'out',
			'returned'  => 'in',
			'damaged'   => 'repair',
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$state                        = self::lending_state( $row['lent'], $row['due'], (string) $row['state'] );
			$out[ (int) $row['post_id'] ] = array(
				'status'  => $map[ $state ],
				'user_id' => (int) $row['user_id'],
				'id'      => (int) $row['id'],
			);
		}
		return $out;
	}

	/**
	 * Latest loan status for every item.
	 *
	 * @return array<int,string> Post ID => 'in', 'out', 'requested' or 'repair'.
	 */
	public static function statuses() {
		return wp_list_pluck( self::loans(), 'status' );
	}

	/**
	 * Status and borrower for one item.
	 *
	 * @param int $post_id Item ID.
	 * @return array{status:string,user_id:int}
	 */
	public static function loan( $post_id ) {
		$loans = self::loans();
		return isset( $loans[ $post_id ] ) ? $loans[ $post_id ] : array(
			'status'  => 'in',
			'user_id' => 0,
		);
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
	 * @param string $status 'in', 'out', 'requested' or 'repair'.
	 * @return string
	 */
	public static function status_label( $status ) {
		switch ( $status ) {
			case 'out':
				return __( 'Out', 'sharing-club' );
			case 'requested':
				return __( 'Requested', 'sharing-club' );
			case 'repair':
				return __( 'Being repaired', 'sharing-club' );
			default:
				return __( 'In', 'sharing-club' );
		}
	}

	/**
	 * Status badge HTML.
	 *
	 * @param string $status 'in', 'out', 'requested' or 'repair'.
	 * @return string
	 */
	public static function status_badge( $status ) {
		return '<span class="taas-loan-badge taas-loan-badge-' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';
	}

	/**
	 * URL of the loan program page (the page with the shortcode), optionally for one category.
	 *
	 * Falls back to the plain item archive if no page has the shortcode yet.
	 *
	 * @param string $category_slug Category to open, or ''.
	 * @return string
	 */
	public static function loan_page_url( $category_slug = '' ) {
		$page_id = (int) get_option( self::PAGE_OPTION );
		$url     = ( $page_id && 'publish' === get_post_status( $page_id ) ) ? get_permalink( $page_id ) : get_post_type_archive_link( self::POST_TYPE );
		if ( $category_slug ) {
			$url = add_query_arg( self::CAT_PARAM, $category_slug, $url );
		}
		/**
		 * Change the loan program page link.
		 *
		 * @param string $url           Link.
		 * @param string $category_slug Category, or ''.
		 */
		return apply_filters( 'taas_loan_page_url', $url, $category_slug );
	}

	/**
	 * Where members log in.
	 *
	 * Uses the "Login page" setting, then a page with the address /login/, then WordPress's own login.
	 *
	 * @param string $redirect Where to come back to afterwards.
	 * @return string
	 */
	public static function login_url( $redirect = '' ) {
		$url = (string) scwp_get_option( 'login_url' );
		if ( ! $url ) {
			$page = get_page_by_path( 'login' );
			$url  = $page ? get_permalink( $page ) : '';
		}
		if ( ! $url ) {
			return wp_login_url( $redirect );
		}
		return $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url ) : $url;
	}

	/**
	 * Where people join TAAS, or '' if unknown.
	 *
	 * Uses the "Join page" setting, then a page with the address /join-taas/.
	 *
	 * @return string
	 */
	public static function join_url() {
		$url = (string) scwp_get_option( 'join_url' );
		if ( ! $url ) {
			$page = get_page_by_path( 'join-taas' );
			$url  = $page ? get_permalink( $page ) : '';
		}
		return $url;
	}

	/**
	 * An item's description as HTML.
	 *
	 * Runs WordPress's usual formatting (paragraphs, shortcodes such as the
	 * picture gallery) but not other plugins' content filters, so a site-wide
	 * "members only" notice doesn't replace it: anyone may look at equipment.
	 *
	 * @param WP_Post $post Item.
	 * @return string
	 */
	public static function description_html( $post ) {
		/**
		 * Return true to show item descriptions through the normal the_content filters instead.
		 *
		 * @param bool    $use  Default false.
		 * @param WP_Post $post Item.
		 */
		if ( apply_filters( 'taas_loan_use_the_content', false, $post ) ) {
			return apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}
		$content = (string) $post->post_content;
		if ( function_exists( 'do_blocks' ) && has_blocks( $content ) ) {
			$content = do_blocks( $content );
		}
		return do_shortcode( shortcode_unautop( wpautop( wptexturize( $content ) ) ) );
	}

	/**
	 * An item's short description for lists.
	 *
	 * @param WP_Post $post Item.
	 * @return string HTML.
	 */
	public static function short_description_html( $post ) {
		$short = (string) get_post_meta( $post->ID, self::SHORT_DESC, true );
		if ( '' !== trim( wp_strip_all_tags( $short ) ) ) {
			return wpautop( wp_kses_post( $short ) );
		}
		$text = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30 );
		return '' === $text ? '' : '<p>' . esc_html( $text ) . '</p>';
	}

	/**
	 * Main category of an item.
	 *
	 * @param int $post_id Item ID.
	 * @return WP_Term|null
	 */
	public static function primary_category( $post_id ) {
		$terms = get_the_terms( $post_id, self::TAXONOMY );
		return ( $terms && ! is_wp_error( $terms ) ) ? reset( $terms ) : null;
	}

	/**
	 * Load the front-end styles. The version changes whenever the file does, so caches refresh.
	 */
	public static function enqueue_style() {
		$file = plugin_dir_path( __FILE__ ) . 'css/lending-library-public.css';
		wp_enqueue_style( 'scwp-public', plugin_dir_url( __FILE__ ) . 'css/lending-library-public.css', array(), file_exists( $file ) ? (string) filemtime( $file ) : '1' );
	}

	/**
	 * Send the plain item archive and category archives to the loan program page.
	 */
	public static function redirect_archives() {
		$page_id = (int) get_option( self::PAGE_OPTION );
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return;
		}
		if ( is_post_type_archive( self::POST_TYPE ) ) {
			wp_safe_redirect( self::loan_page_url() );
			exit;
		}
		if ( is_tax( self::TAXONOMY ) ) {
			$term = get_queried_object();
			wp_safe_redirect( self::loan_page_url( $term ? $term->slug : '' ) );
			exit;
		}
	}

	/* ------------------------------------------------------------------
	 * [taas_loan_items]
	 * ------------------------------------------------------------------ */

	/**
	 * [taas_loan_items] output.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function items( $atts ) {
		$atts = shortcode_atts(
			array(
				'view'     => 'categories',
				'category' => '',
				'status'   => 'yes',
			),
			$atts,
			'taas_loan_items'
		);

		self::enqueue_style();

		// Remember this page so item pages can link back to it.
		if ( is_singular() && get_the_ID() && (int) get_option( self::PAGE_OPTION ) !== (int) get_the_ID() ) {
			update_option( self::PAGE_OPTION, (int) get_the_ID(), false );
		}

		if ( ! is_user_logged_in() && ! scwp_get_option( 'public' ) ) {
			return '<p class="taas-loan-login">' . sprintf(
				/* translators: %s: login URL. */
				wp_kses_post( __( 'Please <a href="%s">log in</a> to see the equipment.', 'sharing-club' ) ),
				esc_url( self::login_url( get_permalink() ) )
			) . '</p>';
		}

		$show_status = ! in_array( strtolower( $atts['status'] ), array( 'no', 'false', '0', 'off' ), true );
		$terms       = self::terms( $atts['category'] );
		$view        = strtolower( $atts['view'] );

		// A category picked from the cards.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$picked = isset( $_GET[ self::CAT_PARAM ] ) ? sanitize_title( wp_unslash( $_GET[ self::CAT_PARAM ] ) ) : '';
		if ( 'categories' === $view && $picked ) {
			$term = get_term_by( 'slug', $picked, self::TAXONOMY );
			if ( $term ) {
				return '<div class="taas-loan">' . self::render_category_list( $term, $show_status, true ) . '</div>';
			}
		}

		if ( 'categories' === $view ) {
			$html = self::render_category_cards( $terms );
		} else {
			$statuses = $show_status ? self::statuses() : array();
			$html     = '';
			foreach ( $terms as $term ) {
				$html .= 'grid' === $view
					? self::render_grid_group( $term->name, self::posts_in( $term ), $statuses, $show_status )
					: self::render_category_list( $term, $show_status, false );
			}
			if ( '' === $atts['category'] ) {
				$others = self::posts_in( null );
				if ( $others ) {
					$html .= 'grid' === $view
						? self::render_grid_group( __( 'Other', 'sharing-club' ), $others, $statuses, $show_status )
						: self::render_list( __( 'Other', 'sharing-club' ), '', $others, $statuses, $show_status );
				}
			}
		}

		if ( '' === $html ) {
			return '<p class="taas-loan-empty">' . esc_html__( 'No equipment to show yet.', 'sharing-club' ) . '</p>';
		}
		return '<div class="taas-loan">' . $html . '</div>';
	}

	/**
	 * Categories to show, in order.
	 *
	 * @param string $slugs Comma-separated slugs, or '' for all.
	 * @return WP_Term[]
	 */
	private static function terms( $slugs ) {
		$slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) $slugs ) ) );
		if ( $slugs ) {
			$terms = array();
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, self::TAXONOMY );
				if ( $term ) {
					$terms[] = $term;
				}
			}
			return $terms;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => true,
				'parent'     => 0,
				'orderby'    => 'term_id',
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Published items in a category, or with no category when $term is null.
	 *
	 * @param WP_Term|null $term Category.
	 * @return WP_Post[]
	 */
	private static function posts_in( $term ) {
		$tax_query = $term
			? array(
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				),
			)
			: array(
				array(
					'taxonomy' => self::TAXONOMY,
					'operator' => 'NOT EXISTS',
				),
			);
		return get_posts(
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
	}

	/**
	 * Picture for a category card: its own picture, or its first item's.
	 *
	 * @param WP_Term $term Category.
	 * @return string HTML.
	 */
	private static function category_image( $term ) {
		$image_id = (int) get_term_meta( $term->term_id, self::TERM_IMAGE, true );
		if ( $image_id ) {
			$html = self::card_image( $image_id );
			if ( $html ) {
				return $html;
			}
		}
		// Use the sharpest item picture, so small photos are not blown up.
		$best_id    = 0;
		$best_width = 0;
		foreach ( self::posts_in( $term ) as $post ) {
			$thumb_id = (int) get_post_thumbnail_id( $post );
			if ( ! $thumb_id ) {
				continue;
			}
			$src = wp_get_attachment_image_src( $thumb_id, 'medium_large' );
			if ( $src && (int) $src[1] > $best_width ) {
				$best_id    = $thumb_id;
				$best_width = (int) $src[1];
			}
		}
		if ( ! $best_id ) {
			return '';
		}
		return self::card_image( $best_id );
	}

	/**
	 * Card picture HTML, flagged when too small to fill the card sharply.
	 *
	 * @param int $image_id Attachment ID.
	 * @return string HTML, or '' if missing.
	 */
	private static function card_image( $image_id ) {
		$src = wp_get_attachment_image_src( $image_id, 'medium_large' );
		if ( ! $src ) {
			return '';
		}
		$attr = array(
			'loading' => 'lazy',
			'class'   => 'attachment-medium_large size-medium_large',
		);
		if ( (int) $src[1] < self::CARD_IMAGE_MIN_WIDTH ) {
			$attr['class'] .= ' taas-loan-img-small';
		}
		return wp_get_attachment_image( $image_id, 'medium_large', false, $attr );
	}

	/**
	 * Category cards.
	 *
	 * @param WP_Term[] $terms Categories.
	 * @return string
	 */
	private static function render_category_cards( array $terms ) {
		if ( ! $terms ) {
			return '';
		}
		$html = '<ul class="taas-loan-cats">';
		foreach ( $terms as $term ) {
			$html .= '<li class="taas-loan-cat"><a href="' . esc_url( add_query_arg( self::CAT_PARAM, $term->slug ) ) . '">';
			$html .= '<span class="taas-loan-cat-image">' . self::category_image( $term ) . '</span>';
			$html .= '<span class="taas-loan-cat-name">' . esc_html( $term->name ) . '</span>';
			$html .= '</a></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * One category as a list, with its heading and description.
	 *
	 * @param WP_Term $term        Category.
	 * @param bool    $show_status Show status badges.
	 * @param bool    $back_link   Show a link back to all categories.
	 * @return string
	 */
	private static function render_category_list( $term, $show_status, $back_link ) {
		$posts = self::posts_in( $term );
		$html  = '';
		if ( $back_link ) {
			$html .= '<p class="taas-loan-back"><a href="' . esc_url( remove_query_arg( self::CAT_PARAM ) ) . '">&larr; ' . esc_html__( 'All equipment', 'sharing-club' ) . '</a></p>';
		}
		$html .= self::render_list( $term->name, $term->description, $posts, $show_status ? self::statuses() : array(), $show_status );
		if ( ! $posts ) {
			$html .= '<p class="taas-loan-empty">' . esc_html__( 'Nothing in this category yet.', 'sharing-club' ) . '</p>';
		}
		return $html;
	}

	/**
	 * Items as a list: picture, name, category, short description, status, details button.
	 *
	 * @param string    $title       Heading.
	 * @param string    $description Category description (may contain basic HTML).
	 * @param WP_Post[] $posts       Items.
	 * @param array     $statuses    Post ID => status.
	 * @param bool      $show_status Show status badges.
	 * @return string
	 */
	private static function render_list( $title, $description, array $posts, array $statuses, $show_status ) {
		$html = '<h2 class="taas-loan-heading">' . esc_html( $title ) . '</h2>';
		if ( '' !== trim( (string) $description ) ) {
			$html .= '<div class="taas-loan-intro">' . wpautop( wp_kses_post( $description ) ) . '</div>';
		}
		if ( ! $posts ) {
			return $html;
		}
		$html .= '<ul class="taas-loan-list">';
		foreach ( $posts as $post ) {
			$link   = get_permalink( $post );
			$status = isset( $statuses[ $post->ID ] ) ? $statuses[ $post->ID ] : 'in';
			$cat    = self::primary_category( $post->ID );

			$html .= '<li class="taas-loan-row">';
			$html .= '<a class="taas-loan-row-image" href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">' . get_the_post_thumbnail( $post, 'medium', array( 'loading' => 'lazy' ) ) . '</a>';
			$html .= '<div class="taas-loan-row-body">';
			$html .= '<h3 class="taas-loan-row-title"><a href="' . esc_url( $link ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h3>';
			$html .= '<p class="taas-loan-row-meta">';
			if ( $cat ) {
				$html .= '<span class="taas-loan-row-cat">' . esc_html( $cat->name ) . '</span>';
			}
			if ( $show_status ) {
				$html .= self::status_badge( $status );
			}
			$html .= '</p>';
			$html .= '<div class="taas-loan-row-desc">' . self::short_description_html( $post ) . '</div>';
			$html .= '</div>';
			$html .= '<div class="taas-loan-row-action"><a class="taas-loan-button" href="' . esc_url( $link ) . '">' . esc_html__( 'View details', 'sharing-club' ) . '</a></div>';
			$html .= '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * One category heading and its items as picture cards.
	 *
	 * @param string    $title       Heading.
	 * @param WP_Post[] $posts       Items.
	 * @param array     $statuses    Post ID => status.
	 * @param bool      $show_status Show the status badge.
	 * @return string
	 */
	private static function render_grid_group( $title, array $posts, array $statuses, $show_status ) {
		if ( ! $posts ) {
			return '';
		}
		$html = '<h3 class="taas-loan-category">' . esc_html( $title ) . '</h3><ul class="taas-loan-grid">';
		foreach ( $posts as $post ) {
			$status = isset( $statuses[ $post->ID ] ) ? $statuses[ $post->ID ] : 'in';
			$html  .= '<li class="taas-loan-item taas-loan-' . esc_attr( $status ) . '">';
			$html  .= '<a class="taas-loan-link" href="' . esc_url( get_permalink( $post ) ) . '">';
			$html  .= '<span class="taas-loan-thumb">' . get_the_post_thumbnail( $post, 'medium', array( 'loading' => 'lazy' ) ) . '</span>';
			$html  .= '<span class="taas-loan-name">' . esc_html( get_the_title( $post ) ) . '</span>';
			$html  .= '</a>';
			if ( $show_status ) {
				$html .= '<span class="taas-loan-status">' . esc_html( self::status_label( $status ) ) . '</span>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}
}

TAAS_Loan_Shortcodes::init();
