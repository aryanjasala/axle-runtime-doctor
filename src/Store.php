<?php
/**
 * Where the plugin keeps its options.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's options.
 *
 * The runtime (PHP, OPcache, the object cache) is shared by every site of a
 * network, so settings, snapshots and samples are network options on
 * multisite. On a single site the network option functions use plain options,
 * saved with autoload off.
 */
final class Store {

	/**
	 * One option.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $fallback Value when it is not set.
	 * @return mixed
	 */
	public static function get( string $name, $fallback = false ) {
		return get_site_option( $name, $fallback );
	}

	/**
	 * Saves an option, with autoload off.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	public static function set( string $name, $value ): void {
		update_site_option( $name, $value );
	}

	/**
	 * Deletes an option.
	 *
	 * @param string $name Option name.
	 */
	public static function delete( string $name ): void {
		delete_site_option( $name );
	}
}
