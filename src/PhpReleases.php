<?php
/**
 * The latest PHP releases, from php.net.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Asks php.net for the release list of the running PHP major version, at
 * most once a week, and only when the patch-level check is on (it is off by
 * default). Nothing about the site is sent.
 */
final class PhpReleases {

	public const OPTION = 'axle_runtime_doctor_php_releases';
	public const URL    = 'https://www.php.net/releases/index.php';

	/**
	 * Latest and security releases per branch.
	 *
	 * @return array branches => branch => latest, security; or error.
	 */
	public function get(): array {
		$cached = Store::get( self::OPTION );
		$major  = (int) PHP_MAJOR_VERSION;
		if ( is_array( $cached ) && ( $cached['major'] ?? 0 ) === $major && (int) ( $cached['expires'] ?? 0 ) > time() ) {
			return (array) $cached['data'];
		}

		$data = $this->fetch( $major );
		Store::set(
			self::OPTION,
			array(
				'major'   => $major,
				'expires' => time() + ( isset( $data['error'] ) ? HOUR_IN_SECONDS : WEEK_IN_SECONDS ),
				'data'    => $data,
			)
		);

		return $data;
	}

	/**
	 * Fetches the release list.
	 *
	 * @param int $major PHP major version.
	 * @return array
	 */
	private function fetch( int $major ): array {
		$url = add_query_arg(
			array(
				'json'    => 1,
				'max'     => 200,
				'version' => $major,
			),
			self::URL
		);
		$get = function_exists( 'vip_safe_wp_remote_get' ) ? 'vip_safe_wp_remote_get' : 'wp_remote_get';
		// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Once a week at most, from the checks, never on a page view.
		$response = $get( $url, array( 'timeout' => 5 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		$releases = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $releases ) ) {
			return array( 'error' => __( 'php.net sent something that is not a release list.', 'axle-runtime-doctor' ) );
		}

		return self::parse( $releases );
	}

	/**
	 * Turns php.net's list (version => release) into branches.
	 *
	 * @param array $releases Release list.
	 * @return array branches => branch => latest, security.
	 */
	public static function parse( array $releases ): array {
		$branches = array();
		foreach ( $releases as $version => $release ) {
			$version = (string) $version;
			if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
				continue;
			}
			$branch = implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
			if ( ! isset( $branches[ $branch ] ) ) {
				$branches[ $branch ] = array(
					'latest'   => $version,
					'security' => array(),
				);
			}
			if ( version_compare( $version, $branches[ $branch ]['latest'], '>' ) ) {
				$branches[ $branch ]['latest'] = $version;
			}
			if ( is_array( $release ) && in_array( 'security', (array) ( $release['tags'] ?? array() ), true ) ) {
				$branches[ $branch ]['security'][] = $version;
			}
		}
		foreach ( $branches as &$branch ) {
			usort( $branch['security'], 'version_compare' );
		}

		return array( 'branches' => $branches );
	}
}
