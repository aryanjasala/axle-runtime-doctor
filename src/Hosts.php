<?php
/**
 * Guesses the host, with the evidence.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the host from constants, environment variables, paths and the web
 * server, and always says why. The guess can be overridden in the settings.
 */
final class Hosts {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * What the server looks like. Null reads the real one.
	 *
	 * @var array|null
	 */
	private ?array $environment;

	/**
	 * Detected host, cached for the request.
	 *
	 * @var array|null
	 */
	private ?array $cache = null;

	/**
	 * Sets up detection.
	 *
	 * @param Settings   $settings    Settings, for the override.
	 * @param array|null $environment For tests: constants, env, abspath, software, sapi, files.
	 */
	public function __construct( Settings $settings, ?array $environment = null ) {
		$this->settings    = $settings;
		$this->environment = $environment;
	}

	/**
	 * Every host the plugin knows, in the order they are tried.
	 *
	 * Each has a label, a type (managed, panel, server, local), and the signs
	 * that give it away: constants, environment variables, path patterns,
	 * a web server pattern and files.
	 *
	 * @return array<string, array>
	 */
	public static function detectors(): array {
		$detectors = array(
			'wpengine'   => array(
				'label'     => 'WP Engine',
				'type'      => 'managed',
				'constants' => array( 'WPE_APIKEY', 'PWP_NAME' ),
				'env'       => array( 'IS_WPE' ),
			),
			'pressable'  => array(
				'label'     => 'Pressable',
				'type'      => 'managed',
				'constants' => array( 'IS_PRESSABLE' ),
			),
			'kinsta'     => array(
				'label'     => 'Kinsta',
				'type'      => 'managed',
				'constants' => array( 'KINSTAMU_VERSION' ),
				'env'       => array( 'KINSTA_CACHE_ZONE' ),
			),
			'wpcom'      => array(
				'label'     => 'WordPress.com',
				'type'      => 'managed',
				'constants' => array( 'ATOMIC_SITE_ID', 'WPCOMSH_VERSION' ),
			),
			'vip'        => array(
				'label'     => 'WordPress VIP',
				'type'      => 'managed',
				'constants' => array( 'VIP_GO_APP_ENVIRONMENT', 'VIP_GO_ENV' ),
			),
			'pantheon'   => array(
				'label'     => 'Pantheon',
				'type'      => 'managed',
				'constants' => array( 'PANTHEON_ENVIRONMENT' ),
				'env'       => array( 'PANTHEON_ENVIRONMENT' ),
			),
			'godaddy'    => array(
				'label'     => 'GoDaddy Managed WordPress',
				'type'      => 'managed',
				'constants' => array( 'GD_SYSTEM_PLUGIN_DIR', 'WPAAS_ACCOUNT_ID' ),
			),
			'flywheel'   => array(
				'label'     => 'Flywheel',
				'type'      => 'managed',
				'constants' => array( 'FLYWHEEL_CONFIG_DIR', 'FLYWHEEL_PLUGIN_DIR' ),
			),
			'siteground' => array(
				'label' => 'SiteGround',
				'type'  => 'managed',
				'paths' => array( '#^/home/customer/www/#' ),
			),
			'cloudways'  => array(
				'label' => 'Cloudways',
				'type'  => 'managed',
				'paths' => array( '#^/home/[^/]+\.cloudwaysapps\.com/#', '#^/home/master/applications/#' ),
			),
			'hostinger'  => array(
				'label' => 'Hostinger',
				'type'  => 'panel',
				'paths' => array( '#^/home/u\d+/domains/#' ),
			),
			'cpanel'     => array(
				'label' => 'cPanel',
				'type'  => 'panel',
				'paths' => array( '#^/home\d*/[^/]+/public_html/#' ),
				'files' => array( '/usr/local/cpanel/version' ),
			),
			'plesk'      => array(
				'label' => 'Plesk',
				'type'  => 'panel',
				'paths' => array( '#^/var/www/vhosts/#' ),
			),
			'ddev'       => array(
				'label' => 'DDEV',
				'type'  => 'local',
				'env'   => array( 'IS_DDEV_PROJECT' ),
			),
			'local'      => array(
				'label' => 'Local',
				'type'  => 'local',
				'paths' => array( '#/Local Sites/#' ),
			),
			'docker'     => array(
				'label' => 'Docker',
				'type'  => 'local',
				'files' => array( '/.dockerenv' ),
			),
			'litespeed'  => array(
				'label'    => 'LiteSpeed server',
				'type'     => 'server',
				'software' => '#litespeed#i',
			),
			'nginx'      => array(
				'label'    => 'nginx and PHP-FPM',
				'type'     => 'server',
				'software' => '#nginx#i',
			),
			'apache'     => array(
				'label'    => 'Apache',
				'type'     => 'server',
				'software' => '#apache#i',
			),
		);

		/**
		 * Filters the host detectors, tried in order. Each: label, type
		 * (managed, panel, server, local) and any of constants, env, paths
		 * (regular expressions on ABSPATH), software (regular expression on
		 * SERVER_SOFTWARE) and files.
		 *
		 * @param array $detectors Host ID => detector.
		 */
		$filtered = (array) apply_filters( 'axle_runtime_doctor_hosts', $detectors );

		return array_filter( $filtered, 'is_array' );
	}

	/**
	 * Host labels for the settings list.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		$labels = array();
		foreach ( self::detectors() as $id => $detector ) {
			$labels[ (string) $id ] = (string) ( $detector['label'] ?? $id );
		}

		return $labels;
	}

	/**
	 * The host: detected, or overridden in the settings.
	 *
	 * @return array{id: string, label: string, type: string, server: string, evidence: string[], overridden: bool, detected: string}
	 */
	public function current(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$env      = $this->environment();
		$server   = self::server( (string) $env['software'], (string) $env['sapi'] );
		$found    = $this->detect( $env );
		$override = (string) $this->settings->get( 'host' );
		$labels   = self::labels();
		$host     = $found + array(
			'server'     => $server,
			'overridden' => false,
			'detected'   => $found['id'],
		);

		if ( 'auto' !== $override && '' !== $override && isset( $labels[ $override ] ) ) {
			$detectors        = self::detectors();
			$host['id']       = $override;
			$host['label']    = $labels[ $override ];
			$host['type']     = (string) ( $detectors[ $override ]['type'] ?? 'server' );
			$host['evidence'] = array_merge(
				/* translators: %s: host name. */
				array( sprintf( __( 'Set in the settings. Detected: %s', 'axle-runtime-doctor' ), $found['label'] ) ),
				$found['evidence']
			);
			$host['overridden'] = true;
		}

		/**
		 * Filters the host, after detection and the override.
		 *
		 * @param array $host id, label, type, server (apache, nginx, litespeed or empty), evidence, overridden, detected.
		 */
		$filtered = (array) apply_filters( 'axle_runtime_doctor_host', $host );
		if ( isset( $filtered['id'], $filtered['label'] ) ) {
			$host = array_merge( $host, $filtered );
		}
		$host['evidence'] = array_values( array_map( 'strval', (array) $host['evidence'] ) );
		$this->cache      = $host;

		return $host;
	}

	/**
	 * The first detector whose signs match.
	 *
	 * @param array $env What the server looks like.
	 * @return array{id: string, label: string, type: string, evidence: string[]}
	 */
	private function detect( array $env ): array {
		foreach ( self::detectors() as $id => $detector ) {
			$evidence = array();
			foreach ( (array) ( $detector['constants'] ?? array() ) as $constant ) {
				if ( in_array( $constant, $env['constants'], true ) ) {
					/* translators: %s: constant name. */
					$evidence[] = sprintf( __( 'The constant %s is defined', 'axle-runtime-doctor' ), $constant );
				}
			}
			foreach ( (array) ( $detector['env'] ?? array() ) as $name ) {
				if ( in_array( $name, $env['env'], true ) ) {
					/* translators: %s: environment variable. */
					$evidence[] = sprintf( __( 'The environment variable %s is set', 'axle-runtime-doctor' ), $name );
				}
			}
			foreach ( (array) ( $detector['paths'] ?? array() ) as $pattern ) {
				if ( @preg_match( (string) $pattern, (string) $env['abspath'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A filtered pattern may be invalid.
					/* translators: %s: path. */
					$evidence[] = sprintf( __( 'WordPress lives in %s', 'axle-runtime-doctor' ), $env['abspath'] );
				}
			}
			if ( ! empty( $detector['software'] ) && '' !== $env['software'] && @preg_match( (string) $detector['software'], (string) $env['software'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A filtered pattern may be invalid.
				/* translators: %s: server software. */
				$evidence[] = sprintf( __( 'The web server says %s', 'axle-runtime-doctor' ), $env['software'] );
			}
			foreach ( (array) ( $detector['files'] ?? array() ) as $file ) {
				if ( in_array( $file, $env['files'], true ) ) {
					/* translators: %s: file path. */
					$evidence[] = sprintf( __( '%s exists', 'axle-runtime-doctor' ), $file );
				}
			}

			if ( $evidence ) {
				return array(
					'id'       => (string) $id,
					'label'    => (string) ( $detector['label'] ?? $id ),
					'type'     => (string) ( $detector['type'] ?? 'server' ),
					'evidence' => $evidence,
				);
			}
		}

		return array(
			'id'       => 'generic',
			'label'    => __( 'Unknown host', 'axle-runtime-doctor' ),
			'type'     => 'server',
			'evidence' => array( __( 'No sign of a known host', 'axle-runtime-doctor' ) ),
		);
	}

	/**
	 * The web server family, for fallback fixes.
	 *
	 * @param string $software SERVER_SOFTWARE.
	 * @param string $sapi     PHP_SAPI.
	 * @return string apache, nginx, litespeed or empty.
	 */
	public static function server( string $software, string $sapi ): string {
		if ( false !== stripos( $software, 'litespeed' ) || 'litespeed' === $sapi ) {
			return 'litespeed';
		}
		if ( false !== stripos( $software, 'nginx' ) || 'fpm-fcgi' === $sapi ) {
			return 'nginx';
		}
		if ( false !== stripos( $software, 'apache' ) || 'apache2handler' === $sapi ) {
			return 'apache';
		}

		return '';
	}

	/**
	 * What the server looks like: which known constants and environment
	 * variables are set, the WordPress path, the web server and known files.
	 *
	 * @return array{constants: string[], env: string[], abspath: string, software: string, sapi: string, files: string[]}
	 */
	private function environment(): array {
		if ( null !== $this->environment ) {
			return $this->environment + array(
				'constants' => array(),
				'env'       => array(),
				'abspath'   => '',
				'software'  => '',
				'sapi'      => '',
				'files'     => array(),
			);
		}

		$constants = array();
		$env       = array();
		$files     = array();
		foreach ( self::detectors() as $detector ) {
			foreach ( (array) ( $detector['constants'] ?? array() ) as $constant ) {
				if ( defined( (string) $constant ) ) {
					$constants[] = (string) $constant;
				}
			}
			foreach ( (array) ( $detector['env'] ?? array() ) as $name ) {
				if ( false !== getenv( (string) $name ) || isset( $_SERVER[ $name ] ) ) {
					$env[] = (string) $name;
				}
			}
			foreach ( (array) ( $detector['files'] ?? array() ) as $file ) {
				if ( @file_exists( (string) $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir may forbid the path.
					$files[] = (string) $file;
				}
			}
		}

		return array(
			'constants' => $constants,
			'env'       => $env,
			'abspath'   => ABSPATH,
			'software'  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'sapi'      => PHP_SAPI,
			'files'     => $files,
		);
	}
}
