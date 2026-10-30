<?php
/**
 * Autoloaded options: the biggest, who owns them, and turning autoload off.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Reads autoloaded options and turns autoload off for chosen ones.
 */
final class Autoload {

	/**
	 * Option names WordPress itself creates.
	 */
	private const CORE = array( 'siteurl', 'home', 'blogname', 'blogdescription', 'users_can_register', 'admin_email', 'start_of_week', 'use_smilies', 'require_name_email', 'comments_notify', 'posts_per_rss', 'rss_use_excerpt', 'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port', 'default_category', 'default_comment_status', 'default_ping_status', 'default_pingback_flag', 'posts_per_page', 'date_format', 'time_format', 'links_updated_date_format', 'comment_moderation', 'moderation_notify', 'permalink_structure', 'rewrite_rules', 'hack_file', 'blog_charset', 'moderation_keys', 'active_plugins', 'category_base', 'ping_sites', 'comment_max_links', 'gmt_offset', 'default_email_category', 'recently_edited', 'template', 'stylesheet', 'comment_registration', 'html_type', 'use_trackback', 'default_role', 'db_version', 'uploads_use_yearmonth_folders', 'upload_path', 'blog_public', 'default_link_category', 'show_on_front', 'tag_base', 'show_avatars', 'avatar_rating', 'upload_url_path', 'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop', 'medium_size_w', 'medium_size_h', 'avatar_default', 'large_size_w', 'large_size_h', 'image_default_link_type', 'image_default_size', 'image_default_align', 'close_comments_for_old_posts', 'close_comments_days_old', 'thread_comments', 'thread_comments_depth', 'page_comments', 'comments_per_page', 'default_comments_page', 'comment_order', 'sticky_posts', 'widget_categories', 'widget_text', 'widget_rss', 'uninstall_plugins', 'timezone_string', 'page_for_posts', 'page_on_front', 'default_post_format', 'link_manager_enabled', 'finished_splitting_shared_terms', 'site_icon', 'medium_large_size_w', 'medium_large_size_h', 'wp_page_for_privacy_policy', 'show_comments_cookies_opt_in', 'admin_email_lifespan', 'disallowed_keys', 'comment_previously_approved', 'auto_plugin_theme_update_emails', 'auto_update_core_dev', 'auto_update_core_minor', 'auto_update_core_major', 'wp_force_deactivated_plugins', 'wp_attachment_pages_enabled', 'wp_notes_notify', 'cron', 'sidebars_widgets', 'initial_db_version', 'fresh_site', 'WPLANG', 'can_compress_scripts', 'recovery_keys', 'recovery_mode_email_last_sent', 'nav_menu_options', 'current_theme', 'theme_switched', 'site_logo', 'db_upgraded', 'https_detection_errors', 'user_count', 'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt', 'wp_calendar_block_has_published_posts', 'dashboard_widget_options', 'active_sitewide_plugins', 'theme_mods_' );

	/**
	 * The autoload column values that mean "loaded on every request".
	 *
	 * @return string[]
	 */
	public static function on_values(): array {
		return function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes', 'on', 'auto-on', 'auto' );
	}

	/**
	 * Total, top 20 by size with owners, autoloaded transients, and options of inactive plugins.
	 *
	 * @return array total, count, top, transients, inactive.
	 */
	public function report(): array {
		global $wpdb;
		$values = self::on_values();
		$in     = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Sizes of autoloaded options, read live; $in is a list of %s placeholders.
		$totals = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE( SUM( LENGTH( option_value ) ), 0 ) AS bytes FROM {$wpdb->options} WHERE autoload IN ( $in )", $values ), ARRAY_A );
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name AS name, LENGTH( option_value ) AS size FROM {$wpdb->options} WHERE autoload IN ( $in ) ORDER BY size DESC LIMIT 300", $values ), ARRAY_A );
		$trans  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE autoload IN ( $in ) AND ( option_name LIKE %s OR option_name LIKE %s ) LIMIT 50", array_merge( $values, array( $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' ) ) ) );
		// phpcs:enable

		$top      = array();
		$inactive = array();
		foreach ( $rows as $index => $row ) {
			$owner = $this->owner( (string) $row['name'] );
			if ( $index < self::top_count() ) {
				$top[] = array(
					'name'         => (string) $row['name'],
					'size'         => (int) $row['size'],
					'owner'        => $owner['label'],
					'owner_status' => $owner['status'],
				);
			} elseif ( 'inactive' === $owner['status'] ) {
				$inactive[] = (string) $row['name'];
			}
		}

		return array(
			'total'      => (int) ( $totals['bytes'] ?? 0 ),
			'count'      => (int) ( $totals['n'] ?? 0 ),
			'top'        => $top,
			'transients' => array_map( 'strval', $trans ),
			'inactive'   => array_slice( $inactive, 0, 50 ),
		);
	}

	/**
	 * How many of the biggest options the report lists.
	 *
	 * @return int
	 */
	public static function top_count(): int {
		/**
		 * Filters how many of the biggest autoloaded options are listed.
		 *
		 * @param int $count Default 20. 5 to 100.
		 */
		return max( 5, min( 100, (int) apply_filters( 'axle_runtime_doctor_autoload_top', 20 ) ) );
	}

	/**
	 * Who owns an option: WordPress, a plugin or theme, or unknown.
	 *
	 * @param string $name Option name.
	 * @return array{label: string, status: string} status is core, active, inactive, theme or unknown.
	 */
	public function owner( string $name ): array {
		$owner = array(
			'label'  => '',
			'status' => 'unknown',
		);

		if ( in_array( $name, self::CORE, true ) || 0 === strpos( $name, '_transient_' ) || 0 === strpos( $name, '_site_transient_' ) || 0 === strpos( $name, 'widget_' ) || preg_match( '/^wp_\d*_?user_roles$|user_roles$/', $name ) ) {
			$owner = array(
				'label'  => __( 'WordPress', 'axle-runtime-doctor' ),
				'status' => 'core',
			);
		} elseif ( 0 === strpos( $name, 'theme_mods_' ) ) {
			$owner = array(
				'label'  => substr( $name, 11 ),
				'status' => 'theme',
			);
		} elseif ( function_exists( 'axle_leftovers_owner_of' ) ) {
			$found = axle_leftovers_owner_of( 'option', $name );
			if ( is_array( $found ) && ! empty( $found['name'] ) ) {
				$owner = array(
					'label'  => (string) $found['name'],
					'status' => ! empty( $found['active'] ) ? 'active' : 'inactive',
				);
			} elseif ( is_string( $found ) && '' !== $found ) {
				$owner = array(
					'label'  => $found,
					'status' => 'unknown',
				);
			}
		}

		if ( 'unknown' === $owner['status'] && '' === $owner['label'] ) {
			$owner = $this->plugin_owner( $name );
		}

		/**
		 * Filters the owner of an autoloaded option.
		 *
		 * @param array  $owner label, status (core, active, inactive, theme, unknown).
		 * @param string $name  Option name.
		 */
		$filtered = (array) apply_filters( 'axle_runtime_doctor_option_owner', $owner, $name );

		return isset( $filtered['label'], $filtered['status'] )
			? array(
				'label'  => (string) $filtered['label'],
				'status' => (string) $filtered['status'],
			)
			: $owner;
	}

	/**
	 * Guesses the plugin by matching the option name against plugin folder names.
	 *
	 * @param string $name Option name.
	 * @return array{label: string, status: string}
	 */
	private function plugin_owner( string $name ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$best  = array(
			'label'  => '',
			'status' => 'unknown',
		);
		$score = 0;
		$lower = strtolower( $name );
		foreach ( get_plugins() as $file => $data ) {
			$slug = strtolower( false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' ) );
			foreach ( array_unique( array( $slug, str_replace( '-', '_', $slug ) ) ) as $prefix ) {
				if ( strlen( $prefix ) > $score && strlen( $prefix ) >= 3 && 0 === strpos( $lower, $prefix ) ) {
					$score = strlen( $prefix );
					$best  = array(
						'label'  => (string) $data['Name'],
						'status' => is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ? 'active' : 'inactive',
					);
				}
			}
		}

		return $best;
	}

	/**
	 * The autoload value of options, read from the database.
	 *
	 * @param string[] $names Option names.
	 * @return array<string, string> Name => autoload value, for the options that exist.
	 */
	public function values( array $names ): array {
		global $wpdb;
		if ( ! $names ) {
			return array();
		}
		$in = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The stored value, read live; $in is a list of %s placeholders.
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name IN ( $in )", $names ), ARRAY_A );
		$values = array();
		foreach ( $rows as $row ) {
			$values[ (string) $row['option_name'] ] = (string) $row['autoload'];
		}

		return $values;
	}

	/**
	 * Turns autoload off for options.
	 *
	 * @param string[] $names Option names.
	 * @return array{changed: string[], skipped: array<string, string>} Skipped name => reason.
	 */
	public function disable( array $names ): array {
		$names   = array_values( array_unique( array_filter( array_map( 'strval', $names ) ) ) );
		$before  = $this->values( $names );
		$on      = self::on_values();
		$targets = array();
		$skipped = array();
		foreach ( $names as $name ) {
			if ( ! isset( $before[ $name ] ) ) {
				$skipped[ $name ] = __( 'No such option', 'axle-runtime-doctor' );
			} elseif ( ! in_array( $before[ $name ], $on, true ) ) {
				$skipped[ $name ] = __( 'Autoload is already off', 'axle-runtime-doctor' );
			} else {
				$targets[] = $name;
			}
		}

		if ( $targets ) {
			if ( function_exists( 'wp_set_option_autoload_values' ) ) {
				wp_set_option_autoload_values( array_fill_keys( $targets, false ) );
			} else {
				foreach ( $targets as $name ) {
					$value = get_option( $name );
					delete_option( $name );
					add_option( $name, $value, '', false );
				}
			}

			$after = $this->values( $targets );

			/**
			 * Fires after autoload was turned off for options, so an activity
			 * log can record it and undo it.
			 *
			 * @param string[] $options Option names.
			 * @param array    $before  Name => autoload value before.
			 * @param array    $after   Name => autoload value after.
			 */
			do_action( 'axle_runtime_doctor_autoload_changed', $targets, array_intersect_key( $before, array_flip( $targets ) ), $after );
		}

		return array(
			'changed' => $targets,
			'skipped' => $skipped,
		);
	}
}
