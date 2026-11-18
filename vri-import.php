<?php
/**
 * Import equipment from the Vik Rent Items plugin.
 *
 * Copies each Vik Rent Items item into a TAAS Loan Program item:
 * name, description, categories, main picture and extra pictures.
 * Safe to run more than once: items that were already imported are skipped.
 *
 * Admin page: TAAS Loan Program > Import
 * WP-CLI:     wp taas-loan import-vri [--dry-run]
 *
 * @package TAAS_Loan_Program
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports items from Vik Rent Items.
 */
class TAAS_VRI_Importer {

	/** Post meta that remembers which Vik Rent Items item a post came from. */
	const META_KEY = '_taas_vri_item_id';

	/** Post meta with the unit number, for items that have more than one unit. */
	const UNIT_KEY = '_taas_vri_unit';

	/** Admin page slug. */
	const PAGE = 'taas-vri-import';

	/** admin-post.php action name. */
	const ACTION = 'taas_vri_import';

	/** Post type and taxonomy used by this plugin. */
	const POST_TYPE = 'shared_item';
	const TAXONOMY  = 'shared_item_category';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_import' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'taas-loan import-vri', array( __CLASS__, 'cli' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Reading Vik Rent Items data
	 * ------------------------------------------------------------------ */

	/**
	 * Vik Rent Items table name.
	 *
	 * @param string $name Table name without prefix, e.g. 'items'.
	 * @return string
	 */
	private static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'vikrentitems_' . $name;
	}

	/**
	 * Whether the Vik Rent Items tables exist on this site.
	 *
	 * @return bool
	 */
	public static function source_available() {
		global $wpdb;
		$table = self::table( 'items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return $found === $table;
	}

	/**
	 * Folder where Vik Rent Items keeps its pictures.
	 *
	 * @return string Path with trailing slash.
	 */
	public static function images_dir() {
		$dir = WP_PLUGIN_DIR . '/vikrentitems/admin/resources/';
		/**
		 * Change where the importer looks for Vik Rent Items pictures.
		 *
		 * @param string $dir Folder path with trailing slash.
		 */
		return trailingslashit( apply_filters( 'taas_vri_images_dir', $dir ) );
	}

	/**
	 * Vik Rent Items categories.
	 *
	 * @return array<int,string> Category ID => name.
	 */
	public static function get_categories() {
		global $wpdb;
		$table = self::table( 'categories' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		$rows = $wpdb->get_results( "SELECT id, name FROM `{$table}` ORDER BY ordering, id", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = $row['name'];
		}
		return $out;
	}

	/**
	 * Vik Rent Items items.
	 *
	 * @return array[]
	 */
	public static function get_items() {
		global $wpdb;
		$table = self::table( 'items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		$rows = $wpdb->get_results( "SELECT id, name, img, idcat, info, shortdesc, avail, units, moreimgs FROM `{$table}` ORDER BY id", ARRAY_A );
		return (array) $rows;
	}

	/**
	 * Category IDs from a Vik Rent Items "idcat" value like "1;3;".
	 *
	 * @param string|null $idcat Raw value.
	 * @return int[]
	 */
	private static function parse_category_ids( $idcat ) {
		return array_values( array_filter( array_map( 'intval', explode( ';', (string) $idcat ) ) ) );
	}

	/**
	 * Extra picture file names from a "moreimgs" value like "a.jpg;;b.jpg;;".
	 *
	 * @param string|null $moreimgs Raw value.
	 * @return string[]
	 */
	private static function parse_more_images( $moreimgs ) {
		return array_values( array_filter( array_map( 'trim', explode( ';;', (string) $moreimgs ) ) ) );
	}

	/**
	 * Full path of a picture, or '' if it isn't there.
	 *
	 * Only looks inside the Vik Rent Items picture folder.
	 *
	 * @param string   $file     File name from the database.
	 * @param string[] $prefixes File name prefixes to try first (e.g. 'big_').
	 * @return string
	 */
	private static function image_path( $file, $prefixes = array() ) {
		$file = basename( (string) $file );
		if ( '' === $file ) {
			return '';
		}
		$dir = self::images_dir();
		foreach ( array_merge( $prefixes, array( '' ) ) as $prefix ) {
			$path = $dir . $prefix . $file;
			if ( is_file( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Posts already imported from this Vik Rent Items item, by unit number.
	 *
	 * Posts imported before unit numbers were stored count as unit 1.
	 *
	 * @param int $vri_id Vik Rent Items item ID.
	 * @return array<int,int> Unit number => post ID.
	 */
	private static function find_units( $vri_id ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'   => self::META_KEY,
						'value' => (int) $vri_id,
					),
				),
			)
		);
		$units = array();
		foreach ( $posts as $post_id ) {
			$unit = (int) get_post_meta( $post_id, self::UNIT_KEY, true );
			$unit = $unit > 0 ? $unit : 1;
			if ( ! isset( $units[ $unit ] ) ) {
				$units[ $unit ] = (int) $post_id;
			}
		}
		ksort( $units );
		return $units;
	}

	/**
	 * Title for one unit: "Name" for single items, "Name #2" when there are several.
	 *
	 * @param string $name  Item name.
	 * @param int    $unit  Unit number.
	 * @param int    $units Total units.
	 * @return string
	 */
	private static function unit_title( $name, $unit, $units ) {
		return $units > 1 ? $name . ' #' . $unit : $name;
	}

	/* ------------------------------------------------------------------
	 * Importing
	 * ------------------------------------------------------------------ */

	/**
	 * Run the import.
	 *
	 * Items with more than one unit get one entry per unit ("Name #1", "Name #2"),
	 * because each physical unit is lent out separately. The copies share the
	 * same pictures.
	 *
	 * @param bool $dry_run Only report what would happen.
	 * @return array Report.
	 */
	public static function import( $dry_run = false ) {
		$report = array(
			'dry_run'        => (bool) $dry_run,
			'created'        => array(),
			'renamed'        => array(),
			'skipped'        => array(),
			'missing_images' => array(),
			'errors'         => array(),
			'images'         => 0,
		);

		if ( ! self::source_available() ) {
			$report['errors'][] = __( 'Vik Rent Items tables were not found on this site.', 'sharing-club' );
			return $report;
		}

		if ( ! $dry_run ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 0 );
			}
		}

		$categories = self::get_categories();
		$term_cache = array();

		// Create the categories up front, in Vik Rent Items order, so lists show them in that order.
		if ( ! $dry_run ) {
			foreach ( $categories as $category_name ) {
				self::get_term_id( $category_name, $term_cache );
			}
		}

		foreach ( self::get_items() as $item ) {
			$vri_id   = (int) $item['id'];
			$name     = wp_strip_all_tags( (string) $item['name'] );
			$units    = max( 1, (int) $item['units'] );
			$existing = self::find_units( $vri_id );
			$missing  = array_values( array_diff( range( 1, $units ), array_keys( $existing ) ) );

			// An item imported as a single entry that now has several units: call it "#1".
			if ( $units > 1 && isset( $existing[1] ) && get_post_field( 'post_title', $existing[1], 'raw' ) === $name ) {
				$new_title = self::unit_title( $name, 1, $units );
				if ( ! $dry_run ) {
					wp_update_post(
						array(
							'ID'         => $existing[1],
							'post_title' => $new_title,
						)
					);
				}
				$report['renamed'][] = $name . ' → ' . $new_title;
			}

			if ( ! $missing ) {
				$report['skipped'][] = self::unit_title( $name, 1, $units ) . ( $units > 1 ? ' … #' . $units : '' );
				continue;
			}

			// Pictures are only needed when no copy of this item exists yet.
			$main_image  = '';
			$more_images = array();
			if ( ! $existing ) {
				$main_image = self::image_path( $item['img'] );
				foreach ( self::parse_more_images( $item['moreimgs'] ) as $file ) {
					$path = self::image_path( $file, array( 'big_' ) );
					if ( $path ) {
						$more_images[] = $path;
					} else {
						$report['missing_images'][] = $name . ': ' . basename( $file );
					}
				}
				if ( ! empty( $item['img'] ) && ! $main_image ) {
					$report['missing_images'][] = $name . ': ' . basename( $item['img'] );
				}
			}

			if ( $dry_run ) {
				foreach ( $missing as $unit ) {
					$report['created'][] = self::unit_title( $name, $unit, $units );
				}
				$report['images'] += ( $main_image ? 1 : 0 ) + count( $more_images );
				continue;
			}

			// Copy from an existing unit if there is one.
			$template = null;
			if ( $existing ) {
				$source   = reset( $existing );
				$template = array(
					'content' => get_post_field( 'post_content', $source, 'raw' ),
					'thumb'   => (int) get_post_thumbnail_id( $source ),
					'terms'   => wp_get_object_terms( $source, self::TAXONOMY, array( 'fields' => 'ids' ) ),
				);
				if ( is_wp_error( $template['terms'] ) ) {
					$template['terms'] = array();
				}
			}

			foreach ( $missing as $unit ) {
				$title = self::unit_title( $name, $unit, $units );

				if ( $template ) {
					$post_id = self::create_post( $title, $template['content'], $item, $unit );
					if ( is_wp_error( $post_id ) ) {
						$report['errors'][] = $title . ': ' . $post_id->get_error_message();
						continue;
					}
					if ( $template['terms'] ) {
						wp_set_object_terms( $post_id, array_map( 'intval', $template['terms'] ), self::TAXONOMY );
					}
					if ( $template['thumb'] ) {
						set_post_thumbnail( $post_id, $template['thumb'] );
					}
					$report['created'][] = $title;
					continue;
				}

				// First copy: full import with pictures.
				$content = trim( (string) $item['info'] );
				if ( '' === $content ) {
					$content = trim( (string) $item['shortdesc'] );
				}
				$content = wp_kses_post( $content );

				$post_id = self::create_post( $title, $content, $item, $unit );
				if ( is_wp_error( $post_id ) ) {
					$report['errors'][] = $title . ': ' . $post_id->get_error_message();
					continue;
				}

				// Categories.
				$term_ids = array();
				foreach ( self::parse_category_ids( $item['idcat'] ) as $cat_id ) {
					if ( ! isset( $categories[ $cat_id ] ) ) {
						continue;
					}
					$term_id = self::get_term_id( $categories[ $cat_id ], $term_cache );
					if ( $term_id ) {
						$term_ids[] = $term_id;
					}
				}
				if ( $term_ids ) {
					wp_set_object_terms( $post_id, $term_ids, self::TAXONOMY );
				}

				// Main picture.
				$thumb_id = 0;
				if ( $main_image ) {
					$attachment_id = self::attach_image( $main_image, $post_id, $name );
					if ( is_wp_error( $attachment_id ) ) {
						$report['errors'][] = $name . ': ' . $attachment_id->get_error_message();
					} else {
						set_post_thumbnail( $post_id, $attachment_id );
						$thumb_id = (int) $attachment_id;
						++$report['images'];
					}
				}

				// Extra pictures, shown as a gallery under the description.
				$gallery = array();
				foreach ( $more_images as $path ) {
					$attachment_id = self::attach_image( $path, $post_id, $name );
					if ( is_wp_error( $attachment_id ) ) {
						$report['errors'][] = $name . ': ' . $attachment_id->get_error_message();
					} else {
						$gallery[] = (int) $attachment_id;
						++$report['images'];
					}
				}
				if ( $gallery ) {
					$content .= "\n\n" . '[gallery ids="' . implode( ',', $gallery ) . '"]';
					wp_update_post(
						array(
							'ID'           => $post_id,
							'post_content' => $content,
						)
					);
				}

				$template = array(
					'content' => $content,
					'thumb'   => $thumb_id,
					'terms'   => $term_ids,
				);

				$report['created'][] = $title;
			}
		}

		return $report;
	}

	/**
	 * Create one item post and remember where it came from.
	 *
	 * @param string $title   Post title.
	 * @param string $content Post content (already cleaned).
	 * @param array  $item    Vik Rent Items row.
	 * @param int    $unit    Unit number.
	 * @return int|WP_Error Post ID.
	 */
	private static function create_post( $title, $content, array $item, $unit ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => (int) $item['avail'] ? 'publish' : 'draft',
			),
			true
		);
		if ( ! is_wp_error( $post_id ) ) {
			update_post_meta( $post_id, self::META_KEY, (int) $item['id'] );
			update_post_meta( $post_id, self::UNIT_KEY, (int) $unit );
		}
		return $post_id;
	}

	/**
	 * Find or create a category by name.
	 *
	 * @param string $name  Category name.
	 * @param array  $cache Name => term ID cache.
	 * @return int Term ID or 0.
	 */
	private static function get_term_id( $name, array &$cache ) {
		if ( isset( $cache[ $name ] ) ) {
			return $cache[ $name ];
		}
		$term = term_exists( $name, self::TAXONOMY );
		if ( ! $term ) {
			$term = wp_insert_term( $name, self::TAXONOMY );
		}
		$cache[ $name ] = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
		return $cache[ $name ];
	}

	/**
	 * Copy a picture into the Media Library and attach it to a post.
	 *
	 * The original file is left where it is.
	 *
	 * @param string $path    Full path of the picture.
	 * @param int    $post_id Post to attach it to.
	 * @param string $title   Picture title.
	 * @return int|WP_Error Attachment ID.
	 */
	private static function attach_image( $path, $post_id, $title ) {
		$type = wp_check_filetype( basename( $path ) );
		if ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) ) {
			/* translators: %s: file name. */
			return new WP_Error( 'taas_vri_not_image', sprintf( __( '%s is not a picture.', 'sharing-club' ), basename( $path ) ) );
		}

		$tmp = wp_tempnam( basename( $path ) );
		if ( ! $tmp || ! copy( $path, $tmp ) ) {
			/* translators: %s: file name. */
			return new WP_Error( 'taas_vri_copy_failed', sprintf( __( 'Could not copy %s.', 'sharing-club' ), basename( $path ) ) );
		}

		$file = array(
			'name'     => basename( $path ),
			'tmp_name' => $tmp,
		);
		$id   = media_handle_sideload( $file, $post_id, $title );
		if ( is_wp_error( $id ) && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		return $id;
	}

	/* ------------------------------------------------------------------
	 * Admin page
	 * ------------------------------------------------------------------ */

	/**
	 * Add TAAS Loan Program > Import.
	 */
	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__( 'Import from Vik Rent Items', 'sharing-club' ),
			__( 'Import', 'sharing-club' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handle the Import button.
	 */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'sharing-club' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$report = self::import( false );
		set_transient( 'taas_vri_report_' . get_current_user_id(), $report, HOUR_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => self::POST_TYPE,
					'page'      => self::PAGE,
					'done'      => 1,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Show the Import page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Import from Vik Rent Items', 'sharing-club' ) . '</h1>';

		// Result of the last import.
		$key    = 'taas_vri_report_' . get_current_user_id();
		$report = get_transient( $key );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides whether to show a stored report.
		if ( $report && isset( $_GET['done'] ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-success"><p>';
			printf(
				/* translators: 1: number of items, 2: number of pictures. */
				esc_html__( 'Imported %1$d items and %2$d pictures.', 'sharing-club' ),
				count( $report['created'] ),
				(int) $report['images']
			);
			echo '</p></div>';
			self::render_report( $report );
		}

		if ( ! self::source_available() ) {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e( 'Vik Rent Items was not found on this site. Install this plugin on the same WordPress site that runs Vik Rent Items, then come back here.', 'sharing-club' );
			echo '</p></div></div>';
			return;
		}

		// Preview.
		$preview = self::import( true );
		echo '<p>' . esc_html__( 'This copies every Vik Rent Items item into the TAAS Loan Program: name, description, categories and pictures. Vik Rent Items itself is not changed. Items that were already imported are skipped, so it is safe to run again.', 'sharing-club' ) . '</p>';

		echo '<h2>' . esc_html__( 'Preview', 'sharing-club' ) . '</h2>';
		self::render_report( $preview );

		if ( $preview['created'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
			wp_nonce_field( self::ACTION );
			submit_button(
				sprintf(
					/* translators: %d: number of items. */
					__( 'Import %d items', 'sharing-club' ),
					count( $preview['created'] )
				)
			);
			echo '</form>';
		} else {
			echo '<p><strong>' . esc_html__( 'Nothing new to import.', 'sharing-club' ) . '</strong></p>';
		}

		echo '</div>';
	}

	/**
	 * Print a report as lists.
	 *
	 * @param array $report Report from import().
	 */
	private static function render_report( array $report ) {
		$sections = array(
			'created'        => $report['dry_run'] ? __( 'Will be imported', 'sharing-club' ) : __( 'Imported', 'sharing-club' ),
			'renamed'        => $report['dry_run'] ? __( 'Will be renamed (now has more than one unit)', 'sharing-club' ) : __( 'Renamed (now has more than one unit)', 'sharing-club' ),
			'skipped'        => __( 'Already imported (skipped)', 'sharing-club' ),
			'missing_images' => __( 'Pictures not found', 'sharing-club' ),
			'errors'         => __( 'Errors', 'sharing-club' ),
		);
		foreach ( $sections as $key => $label ) {
			if ( empty( $report[ $key ] ) ) {
				continue;
			}
			echo '<h3>' . esc_html( $label ) . ' (' . count( $report[ $key ] ) . ')</h3><ul style="list-style:disc;margin-left:2em">';
			foreach ( $report[ $key ] as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/* ------------------------------------------------------------------
	 * WP-CLI
	 * ------------------------------------------------------------------ */

	/**
	 * Import equipment from Vik Rent Items.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be imported without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taas-loan import-vri --dry-run
	 *     wp taas-loan import-vri
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public static function cli( $args, $assoc_args ) {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$report  = self::import( $dry_run );

		foreach ( array( 'created', 'renamed', 'skipped', 'missing_images' ) as $key ) {
			foreach ( $report[ $key ] as $line ) {
				WP_CLI::log( sprintf( '[%s] %s', $key, $line ) );
			}
		}
		foreach ( $report['errors'] as $line ) {
			WP_CLI::warning( $line );
		}

		$summary = sprintf(
			'%s %d items, %d pictures. Skipped %d already imported.',
			$dry_run ? 'Would import' : 'Imported',
			count( $report['created'] ),
			$report['images'],
			count( $report['skipped'] )
		);
		if ( $report['errors'] && ! $report['created'] ) {
			WP_CLI::error( $summary );
		}
		WP_CLI::success( $summary );
	}
}

TAAS_VRI_Importer::init();
