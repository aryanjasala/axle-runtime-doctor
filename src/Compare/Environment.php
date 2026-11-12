<?php
/**
 * The runtime settings a report carries, so two sites can be compared.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Compare;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the PHP, OPcache and WordPress settings that decide how fast a site
 * runs. Run it inside a web request: the command line has other values.
 *
 * Only plain values are read: no paths, no hostnames, no credentials.
 */
final class Environment {

	/**
	 * The ini settings, with the group each one belongs to.
	 *
	 * @return array<string, string> ini name => group.
	 */
	private static function ini_keys(): array {
		return array(
			'memory_limit'                    => 'php',
			'max_execution_time'              => 'php',
			'max_input_vars'                  => 'php',
			'upload_max_filesize'             => 'php',
			'post_max_size'                   => 'php',
			'display_errors'                  => 'php',
			'realpath_cache_size'             => 'php',
			'realpath_cache_ttl'              => 'php',
			'opcache.enable'                  => 'opcache',
			'opcache.memory_consumption'      => 'opcache',
			'opcache.interned_strings_buffer' => 'opcache',
			'opcache.max_accelerated_files'   => 'opcache',
			'opcache.validate_timestamps'     => 'opcache',
			'opcache.revalidate_freq'         => 'opcache',
			'opcache.save_comments'           => 'opcache',
			'opcache.jit'                     => 'opcache',
			'opcache.jit_buffer_size'         => 'opcache',
			'opcache.file_cache_only'         => 'opcache',
		);
	}

	/**
	 * Reads the settings of this request.
	 *
	 * @return array<string, array{label: string, group: string, value: string}> Setting key => label, group, value.
	 */
	public static function collect(): array {
		global $wpdb;
		$found = array();

		$found['php_version'] = array(
			'label' => __( 'PHP version', 'axle-runtime-doctor' ),
			'group' => 'php',
			'value' => PHP_VERSION,
		);
		$found['php_sapi']    = array(
			'label' => __( 'How PHP runs', 'axle-runtime-doctor' ),
			'group' => 'php',
			'value' => PHP_SAPI,
		);
		foreach ( self::ini_keys() as $name => $group ) {
			$value = ini_get( $name );
			if ( false === $value ) {
				continue;
			}
			$found[ $name ] = array(
				/* translators: %s: a PHP setting name such as opcache.memory_consumption. */
				'label' => sprintf( __( 'PHP setting %s', 'axle-runtime-doctor' ), $name ),
				'group' => $group,
				'value' => (string) $value,
			);
		}

		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		if ( '' !== $software ) {
			$found['server_software'] = array(
				'label' => __( 'Web server', 'axle-runtime-doctor' ),
				'group' => 'server',
				'value' => trim( (string) strtok( $software, '/ ' ) ),
			);
		}

		$found['wp_version']      = array(
			'label' => __( 'WordPress version', 'axle-runtime-doctor' ),
			'group' => 'wordpress',
			'value' => (string) get_bloginfo( 'version' ),
		);
		$found['multisite']       = array(
			'label' => __( 'Multisite', 'axle-runtime-doctor' ),
			'group' => 'wordpress',
			'value' => is_multisite() ? 'on' : 'off',
		);
		$found['object_cache']    = array(
			'label' => __( 'Persistent object cache', 'axle-runtime-doctor' ),
			'group' => 'object_cache',
			'value' => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ? 'drop-in' : 'none',
		);
		$found['wp_debug']        = array(
			'label' => __( 'WP_DEBUG', 'axle-runtime-doctor' ),
			'group' => 'wordpress',
			'value' => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'on' : 'off',
		);
		$found['disable_wp_cron'] = array(
			'label' => __( 'DISABLE_WP_CRON', 'axle-runtime-doctor' ),
			'group' => 'cron',
			'value' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'on' : 'off',
		);
		foreach ( array(
			'WP_MEMORY_LIMIT'     => __( 'WP_MEMORY_LIMIT', 'axle-runtime-doctor' ),
			'WP_MAX_MEMORY_LIMIT' => __( 'WP_MAX_MEMORY_LIMIT', 'axle-runtime-doctor' ),
		) as $constant => $label ) {
			if ( defined( $constant ) ) {
				$found[ strtolower( $constant ) ] = array(
					'label' => $label,
					'group' => 'wordpress',
					'value' => (string) constant( $constant ),
				);
			}
		}

		$active                  = (array) get_option( 'active_plugins', array() );
		$network                 = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$found['active_plugins'] = array(
			'label' => __( 'Active plugins', 'axle-runtime-doctor' ),
			'group' => 'wordpress',
			'value' => (string) count( array_unique( array_merge( $active, $network ) ) ),
		);

		$info = is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		if ( preg_match( '/(\d+\.\d+(?:\.\d+)?)/', $info, $match ) ) {
			$found['db_server'] = array(
				'label' => __( 'Database server', 'axle-runtime-doctor' ),
				'group' => 'database',
				'value' => ( false !== stripos( $info, 'mariadb' ) ? 'MariaDB ' : 'MySQL ' ) . $match[1],
			);
		}

		/**
		 * Filters the runtime settings a report carries for comparing two sites.
		 *
		 * Keep to plain values: nothing secret, no paths, no hostnames.
		 *
		 * @param array $settings Setting key => label, group, value.
		 */
		return self::clean( (array) apply_filters( 'axle_runtime_doctor_environment', $found ) );
	}

	/**
	 * Keeps well-formed settings only.
	 *
	 * @param array $settings Setting key => label, group, value.
	 * @return array
	 */
	public static function clean( array $settings ): array {
		$clean = array();
		foreach ( $settings as $key => $setting ) {
			if ( count( $clean ) >= 150 ) {
				break;
			}
			$key = is_string( $key ) ? strtolower( $key ) : '';
			if ( ! preg_match( '/^[a-z0-9_.]{1,60}$/', $key ) || ! is_array( $setting ) || ! isset( $setting['value'] ) || ! is_scalar( $setting['value'] ) ) {
				continue;
			}
			$clean[ $key ] = array(
				'label' => substr( trim( wp_strip_all_tags( (string) ( $setting['label'] ?? $key ) ) ), 0, 80 ),
				'group' => substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $setting['group'] ?? 'php' ) ) ), 0, 30 ),
				'value' => substr( trim( wp_strip_all_tags( (string) $setting['value'] ) ), 0, 120 ),
			);
		}

		return $clean;
	}
}
