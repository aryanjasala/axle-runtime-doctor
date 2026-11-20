<?php
/**
 * The log of updates: plugins, themes, core and deploys.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Timing;

use AryanX\Axle\RuntimeDoctor\Service;
use AryanX\Axle\RuntimeDoctor\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers what was updated and when, so a page that got slower can be
 * matched to the updates made since the last time it was timed. A plugin
 * update is logged with the version before and after.
 */
final class Updates implements Service {

	public const OPTION = 'axle_runtime_doctor_updates';

	/**
	 * Versions seen just before an upgrade, by "type:slug".
	 *
	 * @var array<string, array{version: string, name: string}>
	 */
	private static array $before = array();

	/**
	 * Runs the timing after an update.
	 *
	 * @var Timings
	 */
	private Timings $timings;

	/**
	 * Sets up the log.
	 *
	 * @param Timings $timings Runs the timing.
	 */
	public function __construct( Timings $timings ) {
		$this->timings = $timings;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'upgrader_pre_install', array( $this, 'remember_versions' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
		add_action( 'axle_smoke_test_deploy_detected', array( $this, 'on_deploy' ), 10, 3 );
		add_action( 'axle_smoke_test_run_finished', array( $this, 'on_smoke_test' ), 10, 2 );
	}

	/**
	 * Notes the version of what is about to be replaced.
	 *
	 * @param bool|\WP_Error $response   Install response, passed on.
	 * @param mixed          $hook_extra What is being upgraded.
	 * @return bool|\WP_Error
	 */
	public function remember_versions( $response, $hook_extra = array() ) {
		$hook_extra = is_array( $hook_extra ) ? $hook_extra : array();
		if ( ! empty( $hook_extra['plugin'] ) ) {
			$info = self::plugin_info( (string) $hook_extra['plugin'] );
			if ( $info ) {
				self::$before[ 'plugin:' . (string) $hook_extra['plugin'] ] = $info;
			}
		} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$info = self::theme_info( (string) $hook_extra['theme'] );
			if ( $info ) {
				self::$before[ 'theme:' . (string) $hook_extra['theme'] ] = $info;
			}
		} elseif ( isset( $hook_extra['type'] ) && 'core' === $hook_extra['type'] ) {
			self::$before['core:wordpress'] = array(
				'version' => (string) get_bloginfo( 'version' ),
				'name'    => 'WordPress',
			);
		}

		return $response;
	}

	/**
	 * Logs what an upgrade replaced, then asks for a timing run.
	 *
	 * @param mixed $upgrader   The upgrader.
	 * @param mixed $hook_extra action, type and what was updated.
	 */
	public function on_upgrade( $upgrader, $hook_extra = array() ): void {
		unset( $upgrader );
		$hook_extra = is_array( $hook_extra ) ? $hook_extra : array();
		if ( 'update' !== ( $hook_extra['action'] ?? '' ) ) {
			return;
		}

		$type  = (string) ( $hook_extra['type'] ?? '' );
		$added = 0;
		if ( 'plugin' === $type ) {
			$files = isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : ( isset( $hook_extra['plugin'] ) ? array( $hook_extra['plugin'] ) : array() );
			foreach ( $files as $file ) {
				$file   = (string) $file;
				$old    = self::$before[ 'plugin:' . $file ] ?? array();
				$new    = self::plugin_info( $file );
				$added += $this->record(
					array(
						'type'   => 'plugin',
						'slug'   => self::slug_of( $file ),
						'file'   => $file,
						'name'   => (string) ( $new['name'] ?? $old['name'] ?? self::slug_of( $file ) ),
						'from'   => (string) ( $old['version'] ?? '' ),
						'to'     => (string) ( $new['version'] ?? '' ),
						'source' => 'upgrader',
					)
				) ? 1 : 0;
			}
		} elseif ( 'theme' === $type ) {
			$slugs = isset( $hook_extra['themes'] ) ? (array) $hook_extra['themes'] : ( isset( $hook_extra['theme'] ) ? array( $hook_extra['theme'] ) : array() );
			foreach ( $slugs as $slug ) {
				$slug   = (string) $slug;
				$old    = self::$before[ 'theme:' . $slug ] ?? array();
				$new    = self::theme_info( $slug );
				$added += $this->record(
					array(
						'type'   => 'theme',
						'slug'   => $slug,
						'file'   => '',
						'name'   => (string) ( $new['name'] ?? $old['name'] ?? $slug ),
						'from'   => (string) ( $old['version'] ?? '' ),
						'to'     => (string) ( $new['version'] ?? '' ),
						'source' => 'upgrader',
					)
				) ? 1 : 0;
			}
		} elseif ( 'core' === $type ) {
			$old    = self::$before['core:wordpress'] ?? array();
			$added += $this->record(
				array(
					'type'   => 'core',
					'slug'   => 'wordpress',
					'file'   => '',
					'name'   => 'WordPress',
					'from'   => (string) ( $old['version'] ?? '' ),
					'to'     => self::core_version(),
					'source' => 'upgrader',
				)
			) ? 1 : 0;
		}

		if ( $added ) {
			$this->timings->queue( 'update' );
		}
	}

	/**
	 * Logs a deploy that Smoke Test noticed, and asks for a timing run.
	 *
	 * @param mixed ...$args What Smoke Test passes about the deploy.
	 */
	public function on_deploy( ...$args ): void {
		unset( $args );
		if ( $this->record(
			array(
				'type'   => 'deploy',
				'slug'   => 'deploy',
				'file'   => '',
				'name'   => __( 'A deploy', 'axle-runtime-doctor' ),
				'from'   => '',
				'to'     => '',
				'source' => 'smoke-test',
			)
		) ) {
			$this->timings->queue( 'deploy' );
		}
	}

	/**
	 * Smoke Test finished a run: time the pages, once its last result is in.
	 * It fires more than once for a run, so only the final one counts.
	 *
	 * @param mixed $run     id, verdict, source, finished and final.
	 * @param mixed $results Smoke Test's results. Not used.
	 */
	public function on_smoke_test( $run = array(), $results = array() ): void {
		unset( $results );
		$run = is_array( $run ) ? $run : array();
		if ( ! ( $run['final'] ?? true ) ) {
			return;
		}

		$this->timings->queue( 'smoke-test' );
	}

	/**
	 * Adds an update to the log.
	 *
	 * @param array $event type, slug, file, name, from, to, source.
	 * @return bool Whether it was logged.
	 */
	public function record( array $event ): bool {
		$event        += array(
			'type'   => '',
			'slug'   => '',
			'file'   => '',
			'name'   => '',
			'from'   => '',
			'to'     => '',
			'source' => '',
		);
		$event['time'] = time();
		$event['id']   = substr( md5( $event['type'] . '|' . $event['slug'] . '|' . $event['to'] . '|' . microtime( true ) ), 0, 12 );

		/**
		 * Filters an update before it is logged. Return an empty value to leave it out.
		 *
		 * @param array $event id, time, type (plugin, theme, core or deploy), slug, file, name, from, to, source.
		 */
		$event = (array) apply_filters( 'axle_runtime_doctor_update_event', $event );
		if ( empty( $event['type'] ) ) {
			return false;
		}

		$all   = self::all();
		$all[] = $event;
		Store::set( self::OPTION, array_slice( $all, -self::keep() ) );

		/**
		 * Fires after an update was logged.
		 *
		 * @param array $event The logged update.
		 */
		do_action( 'axle_runtime_doctor_update_recorded', $event );

		return true;
	}

	/**
	 * Every logged update, oldest first.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		$all = Store::get( self::OPTION, array() );

		return is_array( $all ) ? array_values( array_filter( $all, 'is_array' ) ) : array();
	}

	/**
	 * One update.
	 *
	 * @param string $id Update ID.
	 * @return array|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $event ) {
			if ( (string) ( $event['id'] ?? '' ) === $id ) {
				return $event;
			}
		}

		return null;
	}

	/**
	 * Updates logged after one time and up to another.
	 *
	 * @param int $after Exclusive.
	 * @param int $until Inclusive.
	 * @return array[]
	 */
	public static function between( int $after, int $until ): array {
		return array_values(
			array_filter(
				self::all(),
				static function ( $event ) use ( $after, $until ) {
					$time = (int) ( $event['time'] ?? 0 );

					return $time > $after && $time <= $until;
				}
			)
		);
	}

	/**
	 * How many updates are kept.
	 *
	 * @return int
	 */
	public static function keep(): int {
		/**
		 * Filters how many updates the log keeps.
		 *
		 * @param int $keep Default 100. 10 to 500.
		 */
		return max( 10, min( 500, (int) apply_filters( 'axle_runtime_doctor_updates_to_keep', 100 ) ) );
	}

	/**
	 * A sentence for one update: "Elementor 3.25 to 3.26".
	 *
	 * @param array $event An update.
	 * @return string
	 */
	public static function describe( array $event ): string {
		$name = (string) ( $event['name'] ?? '' );
		$from = (string) ( $event['from'] ?? '' );
		$to   = (string) ( $event['to'] ?? '' );
		if ( 'deploy' === ( $event['type'] ?? '' ) ) {
			return $name;
		}
		if ( '' !== $from && '' !== $to && $from !== $to ) {
			/* translators: 1: plugin, theme or core name, 2: version before, 3: version after. */
			return sprintf( __( '%1$s %2$s to %3$s', 'axle-runtime-doctor' ), $name, $from, $to );
		}
		if ( '' !== $to ) {
			/* translators: 1: plugin, theme or core name, 2: version. */
			return sprintf( __( '%1$s updated to %2$s', 'axle-runtime-doctor' ), $name, $to );
		}

		/* translators: %s: plugin, theme or core name. */
		return sprintf( __( '%s updated', 'axle-runtime-doctor' ), $name );
	}

	/**
	 * A plugin's name and version, from its header.
	 *
	 * @param string $file Plugin file, folder/file.php.
	 * @return array{name: string, version: string}|null
	 */
	private static function plugin_info( string $file ): ?array {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! is_readable( $path ) ) {
			return null;
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $path, false, false );

		return array(
			'name'    => '' !== (string) $data['Name'] ? (string) $data['Name'] : self::slug_of( $file ),
			'version' => (string) $data['Version'],
		);
	}

	/**
	 * A theme's name and version.
	 *
	 * @param string $slug Theme folder.
	 * @return array{name: string, version: string}|null
	 */
	private static function theme_info( string $slug ): ?array {
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return null;
		}

		return array(
			'name'    => (string) $theme->get( 'Name' ),
			'version' => (string) $theme->get( 'Version' ),
		);
	}

	/**
	 * The folder of a plugin file.
	 *
	 * @param string $file Plugin file.
	 * @return string
	 */
	public static function slug_of( string $file ): string {
		return false !== strpos( $file, '/' ) ? (string) strtok( $file, '/' ) : (string) basename( $file, '.php' );
	}

	/**
	 * The WordPress version on disk, after a core update replaced the files.
	 *
	 * @return string
	 */
	private static function core_version(): string {
		$file = ABSPATH . 'wp-includes/version.php';
		if ( is_readable( $file ) ) {
			$source = file_get_contents( $file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads core's own version file.
			if ( is_string( $source ) && preg_match( "/\\\$wp_version\\s*=\\s*'([^']+)'/", $source, $match ) ) {
				return $match[1];
			}
		}

		return (string) get_bloginfo( 'version' );
	}
}
