<?php
/**
 * The opt-in must-use loader behind "Confirm it".
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Timing;

use AryanX\Axle\RuntimeDoctor\Service;
use AryanX\Axle\RuntimeDoctor\Settings;
use AryanX\Axle\RuntimeDoctor\Store;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions -- The opt-in loader is one small file of our own in the must-use folder. Where that folder is not writable (hosted platforms) it is never written and Confirm it stays off.

/**
 * "Confirm it" loads one page once without one plugin, to see whether that
 * plugin is the slow part. Plugins load before this plugin does, so leaving
 * one out needs a must-use file. It is opt-in, written when the setting is
 * turned on, and removed when the setting is turned off, when the plugin is
 * deactivated and when it is deleted.
 *
 * The file does nothing for visitors. It acts only on a request that carries
 * a token this plugin issued a moment ago: the token row must exist, must be
 * unused and must not have expired, and it is deleted as the request starts,
 * so a token works once. Anything else (no token, a forged token, a used
 * token, a read error) changes nothing and every plugin loads.
 */
final class Loader implements Service {

	public const FILE   = 'axle-runtime-doctor-confirm.php';
	public const MARKER = 'AXLE_RUNTIME_DOCTOR_CONFIRM_LOADER 1';
	public const PREFIX = 'axle_runtime_doctor_probe_mu_';
	private const TTL   = 60;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the loader.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Keeps the file in step with the setting.
	 */
	public function register(): void {
		add_action( 'axle_runtime_doctor_settings_saved', array( $this, 'sync' ) );
	}

	/**
	 * Writes or removes the file to match the saved setting. When the file
	 * cannot be written the setting goes back to off.
	 *
	 * @param mixed $settings The saved settings.
	 */
	public function sync( $settings = array() ): void {
		$settings = is_array( $settings ) ? $settings : array();
		$wanted   = ! empty( $settings['confirm'] );

		if ( $wanted && ! self::installed() && ! self::install() ) {
			$this->settings->save( array( 'confirm' => false ) );

			return;
		}
		if ( ! $wanted && self::installed() ) {
			self::remove();
		}
	}

	/**
	 * Where the file goes.
	 *
	 * @return string
	 */
	public static function path(): string {
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

		return rtrim( wp_normalize_path( $dir ), '/' ) . '/' . self::FILE;
	}

	/**
	 * Whether our file is in place.
	 *
	 * @return bool
	 */
	public static function installed(): bool {
		$path = self::path();

		return is_readable( $path ) && false !== strpos( (string) file_get_contents( $path, false, null, 0, 2048 ), self::MARKER );
	}

	/**
	 * Whether "Confirm it" can run: the setting is on and the file is in place.
	 *
	 * @param Settings $settings Settings.
	 * @return bool
	 */
	public static function ready( Settings $settings ): bool {
		return ! empty( $settings->get( 'confirm' ) ) && self::installed();
	}

	/**
	 * Writes the file.
	 *
	 * @return bool
	 */
	public static function install(): bool {
		$path = self::path();
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! is_writable( $dir ) || ( file_exists( $path ) && ! self::installed() ) ) {
			// Never overwrite a file that is not ours.
			return false;
		}

		$written = false !== file_put_contents( $path, self::source() );
		if ( $written ) {
			do_action( 'axle_runtime_doctor_confirm_loader_installed', $path );
		}

		return $written;
	}

	/**
	 * Removes the file on deactivation.
	 */
	public static function on_deactivate(): void {
		self::remove();

		// Turning the plugin back on does not turn Confirm it back on: it is opt-in each time.
		$saved = Store::get( Settings::OPTION, array() );
		if ( is_array( $saved ) && ! empty( $saved['confirm'] ) ) {
			$saved['confirm'] = false;
			Store::set( Settings::OPTION, $saved );
		}
	}

	/**
	 * Removes the file, when it is ours.
	 *
	 * @return bool True when no file of ours is left.
	 */
	public static function remove(): bool {
		if ( ! self::installed() ) {
			return true;
		}
		$removed = wp_delete_file( self::path() ) || ! file_exists( self::path() );
		if ( $removed ) {
			do_action( 'axle_runtime_doctor_confirm_loader_removed' );
		}

		return $removed;
	}

	/**
	 * The name of the row the loader looks for.
	 *
	 * @param string $token Probe token.
	 * @return string
	 */
	public static function row_name( string $token ): string {
		return self::PREFIX . substr( hash( 'sha256', 'axle-runtime-doctor-mu|' . $token ), 0, 40 );
	}

	/**
	 * Lets one request, the one that carries this token, load without a plugin.
	 * Does nothing when the loader is not in place, so the request is a plain
	 * timing request.
	 *
	 * @param string $token   Probe token.
	 * @param string $without Plugin file, folder/file.php.
	 * @return bool Whether the row was written.
	 */
	public static function allow( string $token, string $without ): bool {
		if ( ! self::installed() || ! self::skippable( $without ) ) {
			return false;
		}

		return add_option(
			self::row_name( $token ),
			array(
				'without' => $without,
				'digest'  => hash( 'sha256', $token ),
				'expires' => time() + self::TTL,
			),
			'',
			false
		);
	}

	/**
	 * Drops the row, used or not.
	 *
	 * @param string $token Probe token.
	 */
	public static function forget( string $token ): void {
		delete_option( self::row_name( $token ) );
	}

	/**
	 * Whether a plugin file may be left out: well formed, and not this plugin.
	 *
	 * @param string $without Plugin file.
	 * @return bool
	 */
	public static function skippable( string $without ): bool {
		if ( 1 !== preg_match( '#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)?\.php$#', $without ) ) {
			return false;
		}

		return 0 !== strpos( $without, 'axle-runtime-doctor/' );
	}

	/**
	 * The file's code.
	 *
	 * @return string
	 */
	public static function source(): string {
		return <<<'LOADER'
<?php
/**
 * Axle Runtime Doctor: loader for "Confirm it".
 * AXLE_RUNTIME_DOCTOR_CONFIRM_LOADER 1
 *
 * Written by the plugin when you turn "Confirm it" on, and removed when you
 * turn it off, deactivate the plugin or delete it. It changes nothing unless
 * a request carries a single-use token the plugin issued less than a minute
 * ago. Every other request, visitors included, loads every plugin.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

( static function () {
	$token = isset( $_SERVER['HTTP_X_AXLE_RUNTIME_DOCTOR_PROBE'] ) ? (string) $_SERVER['HTTP_X_AXLE_RUNTIME_DOCTOR_PROBE'] : '';
	if ( 1 !== preg_match( '/^[A-Za-z0-9]{40}$/', $token ) || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}
	if ( defined( 'WP_ADMIN' ) || defined( 'WP_INSTALLING' ) || ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) {
		return;
	}
	// The plugin is gone: do nothing.
	if ( ! is_readable( WP_PLUGIN_DIR . '/axle-runtime-doctor/axle-runtime-doctor.php' ) ) {
		return;
	}

	$name = 'axle_runtime_doctor_probe_mu_' . substr( hash( 'sha256', 'axle-runtime-doctor-mu|' . $token ), 0, 40 );
	$row  = get_option( $name );
	if ( ! is_array( $row ) || ! isset( $row['without'], $row['digest'], $row['expires'] ) || ! hash_equals( (string) $row['digest'], hash( 'sha256', $token ) ) ) {
		return;
	}
	// A token works once: only the request whose delete succeeds goes on.
	if ( ! delete_option( $name ) || (int) $row['expires'] < time() ) {
		return;
	}

	$without = (string) $row['without'];
	if ( 1 !== preg_match( '#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)?\.php$#', $without ) || 0 === strpos( $without, 'axle-runtime-doctor/' ) ) {
		return;
	}

	define( 'AXLE_RUNTIME_DOCTOR_PROBE_SKIPPED', $without );
	add_filter(
		'option_active_plugins',
		static function ( $plugins ) use ( $without ) {
			return is_array( $plugins ) ? array_values( array_diff( $plugins, array( $without ) ) ) : $plugins;
		}
	);
	add_filter(
		'site_option_active_sitewide_plugins',
		static function ( $plugins ) use ( $without ) {
			if ( is_array( $plugins ) ) {
				unset( $plugins[ $without ] );
			}

			return $plugins;
		}
	);
} )();
LOADER;
	}
}
