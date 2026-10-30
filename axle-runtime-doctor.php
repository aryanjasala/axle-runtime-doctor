<?php
/**
 * Plugin Name:       Axle Runtime Doctor
 * Plugin URI:        https://wordpress.org/plugins/axle-runtime-doctor/
 * Description:       Checks the server WordPress runs on, from inside a real web request: OPcache coverage and memory, the object cache, the PHP branch and limits, autoloaded options, cron, loopback, files and the database. Each problem comes with the fix for your host and a support ticket to paste.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Aryan Jasala
 * Author URI:        https://profiles.wordpress.org/aryanjasala/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       axle-runtime-doctor
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

// PSR-4: AryanX\Axle\RuntimeDoctor\Foo\Bar lives in src/Foo/Bar.php.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
register_deactivation_hook( __FILE__, array( Scheduler::class, 'unschedule' ) );
