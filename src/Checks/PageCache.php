<?php
/**
 * Whether a page cache answers visitors, and what stops it.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\PageCache\Analyzer;
use AryanX\Axle\RuntimeDoctor\PageCache\Blame;
use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the key pages twice as a logged-out visitor and reads the headers: did a
 * cache answer the second request, and if not, which cookie, header or call
 * stops one. Names a plugin or theme only when the trace could.
 */
final class PageCache extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'page_cache';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'page_cache';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Page cache', 'axle-runtime-doctor' );
	}

	/**
	 * The test sends its own requests to the site, so WP-CLI can run it.
	 *
	 * @return bool
	 */
	public function needs_web(): bool {
		return false;
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'A page cache answers a visitor without running PHP or the database. It only stores a page that is the same for everyone. One cookie, a no-store header or a session on a page makes the cache skip it for every visitor, and the site works as if no cache were there. The test asks the key pages twice as a visitor and shows what it found.', 'axle-runtime-doctor' );
	}

	/**
	 * A page address as a short path.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function path( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' === $path ? '/' : $path;
	}

	/**
	 * The result for a stored scan, without sending any request.
	 *
	 * @param array $scan A scan from Scanner.
	 * @return Result|null
	 */
	public static function from_scan( array $scan ): ?Result {
		return ( new self() )->run( new Context( array( 'page_cache' => $scan ) ) );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$scan = $context->get( 'page_cache' );
		if ( ! is_array( $scan ) ) {
			return null;
		}

		$pages   = array_values( array_filter( (array) ( $scan['pages'] ?? array() ), 'is_array' ) );
		$tested  = array();
		$details = array();
		foreach ( $pages as $page ) {
			if ( '' !== (string) ( $page['error'] ?? '' ) || (int) ( $page['status'] ?? 0 ) <= 0 ) {
				/* translators: 1: page path, 2: reason. */
				$details[] = sprintf( __( 'Could not test %1$s: %2$s', 'axle-runtime-doctor' ), self::path( (string) ( $page['url'] ?? '' ) ), (string) ( $page['error'] ?? '' ) );
				continue;
			}
			$tested[] = $page;
		}

		if ( ! $tested ) {
			return $this->result(
				Result::INFO,
				__( 'No page could be loaded to test the page cache', 'axle-runtime-doctor' ),
				array(
					'value'   => __( 'No page answered', 'axle-runtime-doctor' ),
					'details' => $details,
					'data'    => array(
						'hits'  => 0,
						'pages' => 0,
					),
				)
			);
		}

		$hits       = 0;
		$blocked    = 0;
		$cache_seen = false;
		$missed     = false;
		$found      = array();
		$culprits   = array();
		$traced     = array();
		foreach ( $tested as $page ) {
			$hit        = Analyzer::HIT === ( $page['answered'] ?? '' );
			$stops      = array_values(
				array_filter(
					(array) ( $page['blockers'] ?? array() ),
					static function ( $blocker ) {
						return is_array( $blocker ) && 'blocks' === ( $blocker['severity'] ?? '' );
					}
				)
			);
			$cache_seen = $cache_seen || Analyzer::NONE !== ( $page['answered'] ?? Analyzer::NONE ) || ! empty( $page['signals'] );
			$missed     = $missed || Analyzer::MISS === ( $page['answered'] ?? '' );
			$path       = self::path( (string) ( $page['url'] ?? '' ) );

			if ( $hit ) {
				++$hits;
			} elseif ( $stops ) {
				++$blocked;
			}
			if ( ! empty( $page['dynamic'] ) && ! $hit ) {
				/* translators: %s: page path. */
				$details[] = sprintf( __( '%s: Cloudflare answered DYNAMIC. It does not cache HTML unless a Cache Everything rule or APO is on.', 'axle-runtime-doctor' ), $path );
			}

			foreach ( (array) ( $page['blockers'] ?? array() ) as $blocker ) {
				if ( ! is_array( $blocker ) ) {
					continue;
				}
				/* translators: 1: page path, 2: what was found. */
				$line = sprintf( __( '%1$s: %2$s', 'axle-runtime-doctor' ), $path, (string) ( $blocker['detail'] ?? '' ) );
				if ( $hit ) {
					/* translators: %s: what was found. */
					$line = sprintf( __( '%s A cache still answered, so it is not stopping it here.', 'axle-runtime-doctor' ), $line );
				}
				$details[] = $line;
				$found[]   = (string) ( $blocker['type'] ?? '' ) . ': ' . implode( ', ', array_map( 'strval', (array) ( $blocker['names'] ?? array() ) ) );
			}

			$trace = isset( $page['trace'] ) && is_array( $page['trace'] ) ? $page['trace'] : null;
			if ( $trace && $stops ) {
				if ( empty( $trace['reached'] ) ) {
					$details[] = $path . ': ' . ( ! empty( $trace['ran'] )
						? __( 'The tracing request did not reach PHP (a cache answered it), so no plugin could be named.', 'axle-runtime-doctor' )
						: __( 'The tracing request failed, so no plugin could be named.', 'axle-runtime-doctor' ) );
				}
				if ( ! empty( $trace['reached'] ) && empty( $trace['signals'] ) ) {
					$details[] = $path . ': ' . __( 'The tracing request saw no cookie or header being set. The cause may depend on the exact address, or on a cache or CDN in front of the site.', 'axle-runtime-doctor' );
				}
				foreach ( Blame::describe_all( (array) ( $trace['signals'] ?? array() ) ) as $text ) {
					if ( isset( $traced[ $text ] ) ) {
						continue;
					}
					$traced[ $text ] = true;
					$details[]       = $path . ': ' . $text;
				}
				foreach ( (array) ( $trace['signals'] ?? array() ) as $signal ) {
					if ( is_array( $signal ) && ( ! empty( $signal['certain'] ) || 1 === count( (array) ( $signal['suspects'] ?? array() ) ) ) ) {
						foreach ( (array) ( $signal['suspects'] ?? array() ) as $component ) {
							$culprits[ (string) $component ] = true;
						}
					}
				}
			}
			if ( $stops && ( ! $trace || empty( $trace['signals'] ) ) ) {
				foreach ( (array) ( $page['cookies'] ?? array() ) as $cookie ) {
					$hint = Blame::hint( (string) $cookie );
					if ( '' !== $hint ) {
						/* translators: 1: cookie name, 2: who sets it. */
						$line = sprintf( __( 'The cookie %1$s points at: %2$s. This comes from its name, not from a trace.', 'axle-runtime-doctor' ), (string) $cookie, $hint );
						if ( ! in_array( $line, $details, true ) ) {
							$details[] = $line;
						}
					}
				}
			}
		}

		$count  = count( $tested );
		$none   = $count - $hits;
		$fields = array(
			/* translators: 1: pages answered from a cache, 2: pages tested. */
			'value'    => sprintf( __( '%1$d of %2$d pages answered from a cache', 'axle-runtime-doctor' ), $hits, $count ),
			'expected' => __( 'Every page is answered from a cache on the second request', 'axle-runtime-doctor' ),
			'details'  => $details,
			'data'     => array(
				'hits'       => $hits,
				'pages'      => $count,
				'blocked'    => $blocked,
				'cache_seen' => $cache_seen ? 'yes' : 'no',
				'found'      => implode( '; ', array_slice( array_values( array_unique( $found ) ), 0, 8 ) ),
				'culprits'   => implode( ', ', array_keys( $culprits ) ),
			),
		);

		if ( 0 === $none ) {
			return $this->result( Result::GOOD, __( 'A cache answered every page we tested', 'axle-runtime-doctor' ), $fields );
		}
		if ( $blocked === $none ) {
			if ( 0 === $hits && $cache_seen ) {
				return $this->result( Result::CRITICAL, __( 'A cache is in place, but something on every page makes it skip the page', 'axle-runtime-doctor' ), $fields );
			}
			if ( $hits > 0 ) {
				return $this->result( Result::RECOMMENDED, __( 'Some pages skip the page cache', 'axle-runtime-doctor' ), $fields );
			}

			return $this->result( Result::RECOMMENDED, __( 'Pages send cookies or headers that stop page caching', 'axle-runtime-doctor' ), $fields + array( 'inferred' => true ) );
		}
		if ( $blocked > 0 ) {
			return $this->result( Result::RECOMMENDED, __( 'Some pages skip the page cache', 'axle-runtime-doctor' ), $fields );
		}
		if ( $missed ) {
			return $this->result( Result::RECOMMENDED, __( 'A cache is in place, but it did not answer the second request', 'axle-runtime-doctor' ), $fields );
		}

		return $this->result( Result::INFO, __( 'No page cache was detected', 'axle-runtime-doctor' ), $fields + array( 'inferred' => true ) );
	}
}
