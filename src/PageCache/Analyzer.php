<?php
/**
 * Reads response headers: did a cache answer, and what stops one.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\PageCache;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions over response headers. They read no request and store
 * nothing, so the same code answers for a live page and for a test.
 *
 * Cookie values are never kept: only their names.
 */
final class Analyzer {

	public const HIT  = 'hit';
	public const MISS = 'miss';
	public const NONE = 'none';

	/**
	 * Response headers as lowercase name => list of values.
	 *
	 * @param array $headers Headers as WordPress returns them: name => string or list.
	 * @return array<string, string[]>
	 */
	public static function normalise( array $headers ): array {
		$clean = array();
		foreach ( $headers as $name => $value ) {
			$name = strtolower( trim( (string) $name ) );
			if ( '' === $name ) {
				continue;
			}
			$values = array();
			foreach ( (array) $value as $one ) {
				if ( is_scalar( $one ) ) {
					$values[] = trim( (string) $one );
				}
			}
			$clean[ $name ] = array_merge( $clean[ $name ] ?? array(), $values );
		}

		return $clean;
	}

	/**
	 * Headers the big hosts, CDNs and cache plugins use to say what they did.
	 *
	 * @return array<string, string> Lowercase header name => who sets it.
	 */
	public static function cache_headers(): array {
		$known = array(
			'cf-cache-status'             => 'Cloudflare',
			'x-cache'                     => 'A CDN or proxy cache (x-cache)',
			'x-cache-status'              => 'An nginx or host cache (x-cache-status)',
			'x-fastcgi-cache'             => 'nginx FastCGI cache',
			'x-proxy-cache'               => 'SiteGround cache',
			'x-kinsta-cache'              => 'Kinsta cache',
			'x-litespeed-cache'           => 'LiteSpeed cache',
			'x-hcdn-cache-status'         => 'Hostinger CDN',
			'x-ac'                        => 'Pressable or WordPress.com edge cache',
			'cdn-cache'                   => 'Bunny CDN',
			'x-varnish'                   => 'Varnish',
			'x-cache-group'               => 'WP Engine cache',
			'x-rocket-nginx-serving-time' => 'WP Rocket nginx cache',
			'x-edge-cache'                => 'An edge cache',
		);

		/**
		 * Filters the headers that tell whether a cache answered.
		 *
		 * @param array<string, string> $known Lowercase header name => who sets it.
		 */
		$filtered = array_filter( (array) apply_filters( 'axle_runtime_doctor_page_cache_headers', $known ), 'is_scalar' );
		$clean    = array();
		foreach ( $filtered as $name => $who ) {
			$name = strtolower( trim( (string) $name ) );
			if ( '' !== $name ) {
				$clean[ $name ] = (string) $who;
			}
		}

		return $clean;
	}

	/**
	 * What one cache header says.
	 *
	 * @param string $name  Lowercase header name.
	 * @param string $value Header value.
	 * @return string|null hit, miss, or null when it says nothing about this request.
	 */
	public static function state_of( string $name, string $value ): ?string {
		$value = strtolower( trim( $value ) );
		if ( 'x-rocket-nginx-serving-time' === $name ) {
			return self::HIT;
		}
		if ( 'x-varnish' === $name ) {
			// Two request IDs mean Varnish had the page. One means it fetched it.
			return preg_match( '/^\d+\s+\d+/', $value ) ? self::HIT : self::MISS;
		}
		if ( 'x-cache-group' === $name ) {
			return 'bypass' === $value ? self::MISS : null;
		}

		// A chain such as "MISS, HIT" lists the cache nearest the visitor last.
		$parts = array_filter(
			array_map( 'trim', explode( ',', $value ) ),
			static function ( $part ) {
				return '' !== $part;
			}
		);
		$last  = $parts ? (string) end( $parts ) : '';
		if ( preg_match( '/\b(miss|bypass|dynamic|expired|uncacheable|none|pass)\b/', $last ) ) {
			return self::MISS;
		}
		if ( preg_match( '/\b(hit|stale|revalidated|updating)\b/', $last ) ) {
			return self::HIT;
		}

		return null;
	}

	/**
	 * Whether a cache answered a response.
	 *
	 * @param array $headers Normalised headers.
	 * @return array{state: string, by: string[], signals: string[], dynamic: bool}
	 *         state: hit, miss or none. by: who. signals: the header lines read.
	 *         dynamic: Cloudflare said DYNAMIC, which is its answer for HTML it does not cache by default.
	 */
	public static function answer( array $headers ): array {
		$hits    = array();
		$misses  = array();
		$signals = array();
		$dynamic = false;
		foreach ( self::cache_headers() as $name => $who ) {
			if ( empty( $headers[ $name ] ) ) {
				continue;
			}
			$value     = implode( ', ', $headers[ $name ] );
			$signals[] = $name . ': ' . substr( $value, 0, 60 );
			$state     = self::state_of( $name, $value );
			if ( self::HIT === $state ) {
				$hits[] = $who;
			} elseif ( self::MISS === $state ) {
				$misses[] = $who;
				if ( 'cf-cache-status' === $name && false !== stripos( $value, 'dynamic' ) ) {
					$dynamic = true;
				}
			}
		}

		$age = isset( $headers['age'][0] ) ? (int) $headers['age'][0] : 0;
		if ( $age > 0 ) {
			$hits[]    = __( 'A shared cache (the Age header)', 'axle-runtime-doctor' );
			$signals[] = 'age: ' . $age;
		}

		if ( $hits ) {
			return array(
				'state'   => self::HIT,
				'by'      => array_values( array_unique( $hits ) ),
				'signals' => $signals,
				'dynamic' => false,
			);
		}

		return array(
			'state'   => $misses ? self::MISS : self::NONE,
			'by'      => array_values( array_unique( $misses ) ),
			'signals' => $signals,
			'dynamic' => $dynamic,
		);
	}

	/**
	 * Names of the cookies a response sets. Values are dropped.
	 *
	 * @param array $headers Normalised headers.
	 * @return string[]
	 */
	public static function cookie_names( array $headers ): array {
		$names = array();
		foreach ( (array) ( $headers['set-cookie'] ?? array() ) as $line ) {
			if ( preg_match( '/^\s*([^=;\s]+)\s*=/', (string) $line, $match ) ) {
				$names[] = $match[1];
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * The directives of Cache-Control, lowercase.
	 *
	 * @param array $headers Normalised headers.
	 * @return string[]
	 */
	public static function cache_control( array $headers ): array {
		$directives = array();
		foreach ( (array) ( $headers['cache-control'] ?? array() ) as $line ) {
			foreach ( explode( ',', strtolower( (string) $line ) ) as $part ) {
				$part = trim( $part );
				if ( '' !== $part ) {
					$directives[] = $part;
				}
			}
		}

		return array_values( array_unique( $directives ) );
	}

	/**
	 * What in a response keeps a cache from storing it.
	 *
	 * @param array $headers Normalised headers.
	 * @return array[] Each: type (cookie, session, cache-control, vary, expires), detail, severity (blocks or hint), names.
	 */
	public static function blockers( array $headers ): array {
		$found   = array();
		$cookies = self::cookie_names( $headers );

		$sessions = array_values( preg_grep( '/^(phpsessid|wp_session|_wp_session)/i', $cookies ) ?: array() ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- preg_grep returns false on a bad pattern only.
		if ( $sessions ) {
			$found[] = array(
				'type'     => 'session',
				'severity' => 'blocks',
				'names'    => $sessions,
				/* translators: %s: cookie name. */
				'detail'   => sprintf( __( 'A PHP session was started (cookie %s). Every visitor gets their own session, so a cache cannot share the page.', 'axle-runtime-doctor' ), $sessions[0] ),
			);
		}

		if ( ! $sessions && self::looks_like_session( $headers ) ) {
			$found[] = array(
				'type'     => 'session',
				'severity' => 'blocks',
				'names'    => array( 'session_start()' ),
				'detail'   => __( 'The headers match what PHP sends after session_start(): an Expires date in 1981 and no-store. Something started a PHP session on this page.', 'axle-runtime-doctor' ),
			);
		}

		$others = array_values( array_diff( $cookies, $sessions ) );
		if ( $others ) {
			$found[] = array(
				'type'     => 'cookie',
				'severity' => 'blocks',
				'names'    => array_slice( $others, 0, 10 ),
				/* translators: %s: cookie names. */
				'detail'   => sprintf( __( 'The page sets a cookie for a visitor who is not logged in: %s. Most caches do not store a response that sets a cookie.', 'axle-runtime-doctor' ), implode( ', ', array_slice( $others, 0, 10 ) ) ),
			);
		}

		$directives = self::cache_control( $headers );
		$stops      = array_values( array_intersect( array( 'no-store', 'private', 'no-cache', 'max-age=0', 's-maxage=0' ), $directives ) );
		if ( $stops ) {
			$found[] = array(
				'type'     => 'cache-control',
				'severity' => 'blocks',
				'names'    => $stops,
				/* translators: %s: Cache-Control directives. */
				'detail'   => sprintf( __( 'Cache-Control says: %s. That tells shared caches not to keep the page.', 'axle-runtime-doctor' ), implode( ', ', $stops ) ),
			);
		}

		$vary = array();
		foreach ( (array) ( $headers['vary'] ?? array() ) as $line ) {
			foreach ( explode( ',', strtolower( (string) $line ) ) as $part ) {
				$vary[] = trim( $part );
			}
		}
		if ( in_array( '*', $vary, true ) ) {
			$found[] = array(
				'type'     => 'vary',
				'severity' => 'blocks',
				'names'    => array( '*' ),
				'detail'   => __( 'Vary: * means no two requests are the same, so no cache stores the page.', 'axle-runtime-doctor' ),
			);
		} elseif ( in_array( 'cookie', $vary, true ) ) {
			$found[] = array(
				'type'     => 'vary',
				'severity' => 'blocks',
				'names'    => array( 'Cookie' ),
				'detail'   => __( 'Vary: Cookie makes a separate copy for every set of cookies, so visitors rarely share one.', 'axle-runtime-doctor' ),
			);
		}

		$expires = isset( $headers['expires'][0] ) ? strtotime( (string) $headers['expires'][0] ) : false;
		if ( false !== $expires && $expires < time() - 86400 && ! $stops ) {
			$found[] = array(
				'type'     => 'expires',
				'severity' => 'hint',
				'names'    => array( 'Expires' ),
				'detail'   => __( 'Expires is in the past, so the page is stale the moment it is sent.', 'axle-runtime-doctor' ),
			);
		}

		return $found;
	}

	/**
	 * Whether the headers carry what PHP's session_start() sends.
	 *
	 * @param array $headers Normalised headers.
	 * @return bool
	 */
	public static function looks_like_session( array $headers ): bool {
		$expires = isset( $headers['expires'][0] ) ? (string) $headers['expires'][0] : '';

		return false !== strpos( $expires, '1981' ) && in_array( 'no-store', self::cache_control( $headers ), true );
	}
}
