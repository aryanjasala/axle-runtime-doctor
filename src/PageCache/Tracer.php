<?php
/**
 * Finds out, inside a real request, who made the page uncacheable.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\PageCache;

use AryanX\Axle\RuntimeDoctor\Probe;

defined( 'ABSPATH' ) || exit;

/**
 * Runs only in a front-end request that carries a single-use token made by the
 * page cache test, and costs nothing in any other request.
 *
 * PHP has no hook for setcookie(), header() or define(). So the tracer takes
 * a snapshot of the response so far (cookies, cache headers, DONOTCACHEPAGE,
 * a started session) before and after each WordPress hook, and blames what
 * changed on the callbacks of the hook in between. A nocache_headers() call is
 * the one thing it sees directly: WordPress filters it, so the caller is in
 * the backtrace. Everything else is a window, not a name, and is labelled so.
 */
final class Tracer {

	private const HOOKS   = array( 'plugins_loaded', 'init', 'wp_loaded', 'send_headers', 'wp', 'template_redirect', 'wp_head', 'wp_footer', 'shutdown' );
	private const HEADERS = array( 'cache-control', 'x-litespeed-cache-control', 'pragma', 'expires', 'vary' );

	/**
	 * Where the trace is saved for the caller.
	 *
	 * @var string
	 */
	private static string $out = '';

	/**
	 * What this request has done so far.
	 *
	 * @var array[]
	 */
	private static array $signals = array();

	/**
	 * The snapshot at the last checkpoint.
	 *
	 * @var array|null
	 */
	private static ?array $previous = null;

	/**
	 * The name of the last checkpoint.
	 *
	 * @var string
	 */
	private static string $label = 'load';

	/**
	 * Whether this request is traced.
	 *
	 * @var bool
	 */
	private static bool $active = false;

	/**
	 * Starts tracing when the request carries a valid token. Runs on plugins_loaded.
	 */
	public static function maybe_start(): void {
		$token = isset( $_SERVER['HTTP_X_AXLE_RUNTIME_DOCTOR_PROBE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AXLE_RUNTIME_DOCTOR_PROBE'] ) ) : '';
		if ( '' === $token || ! Probe::consume( $token, 'cache-trace' ) ) {
			return;
		}

		self::$active   = true;
		self::$out      = Probe::OUT . Probe::hash( $token );
		self::$signals  = array();
		self::$previous = null;
		self::$label    = 'load';

		// The test may reach the site through a different address than the canonical one.
		remove_action( 'template_redirect', 'redirect_canonical' );
		add_filter( 'nocache_headers', array( self::class, 'on_nocache_headers' ), 10, 1 );

		self::checkpoint( 'load' );
		foreach ( self::HOOKS as $hook ) {
			if ( 'plugins_loaded' !== $hook ) {
				add_action(
					$hook,
					static function () use ( $hook ) {
						Tracer::checkpoint( $hook . ':start' );
					},
					PHP_INT_MIN
				);
			}
			add_action(
				$hook,
				static function () use ( $hook ) {
					Tracer::checkpoint( $hook . ':end' );
					if ( 'shutdown' === $hook ) {
						Tracer::save();
					}
				},
				PHP_INT_MAX
			);
		}
	}

	/**
	 * Whether this request is traced.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return self::$active;
	}

	/**
	 * Notes who called nocache_headers().
	 *
	 * @param mixed $headers The headers WordPress is about to send.
	 * @return mixed Unchanged.
	 */
	public static function on_nocache_headers( $headers ) {
		$component = '';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Only in the token-authenticated test request, to name the caller.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			$component = Probe::component( (string) ( $frame['file'] ?? '' ) );
			if ( '' !== $component ) {
				break;
			}
		}

		self::$signals[] = array(
			'type'     => 'nocache_headers',
			'name'     => 'nocache_headers()',
			'where'    => (string) self::$label,
			'suspects' => '' !== $component ? array( $component ) : array(),
			'certain'  => '' !== $component,
		);

		return $headers;
	}

	/**
	 * What the response looks like right now.
	 *
	 * @return array cookies, headers, donotcache, session.
	 */
	public static function snapshot(): array {
		$cookies = array();
		$headers = array();
		foreach ( headers_list() as $line ) {
			$parts = explode( ':', (string) $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$name = strtolower( trim( $parts[0] ) );
			if ( 'set-cookie' === $name ) {
				if ( preg_match( '/^\s*([^=;\s]+)\s*=/', $parts[1], $match ) ) {
					$cookies[] = $match[1];
				}
			} elseif ( in_array( $name, self::HEADERS, true ) ) {
				$headers[ $name ] = trim( $parts[1] );
			}
		}

		return array(
			'cookies'    => array_values( array_unique( $cookies ) ),
			'headers'    => $headers,
			'donotcache' => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
			'session'    => function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status(), // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.session_session_status -- Only reads whether another plugin started a session, in the token-authenticated test request.
		);
	}

	/**
	 * Compares two snapshots.
	 *
	 * @param array|null $before Snapshot before. Null for the start of the request.
	 * @param array      $after  Snapshot after.
	 * @return array[] New signals: type, name.
	 */
	public static function changes( ?array $before, array $after ): array {
		$before  = $before ?? array(
			'cookies'    => array(),
			'headers'    => array(),
			'donotcache' => false,
			'session'    => false,
		);
		$changes = array();
		foreach ( array_diff( (array) $after['cookies'], (array) $before['cookies'] ) as $name ) {
			$changes[] = array(
				'type' => 'cookie',
				'name' => (string) $name,
			);
		}
		foreach ( (array) $after['headers'] as $name => $value ) {
			if ( ( $before['headers'][ $name ] ?? null ) !== $value ) {
				$changes[] = array(
					'type' => 'header',
					'name' => (string) $name,
				);
			}
		}
		if ( ! empty( $after['donotcache'] ) && empty( $before['donotcache'] ) ) {
			$changes[] = array(
				'type' => 'donotcachepage',
				'name' => 'DONOTCACHEPAGE',
			);
		}
		if ( ! empty( $after['session'] ) && empty( $before['session'] ) ) {
			$changes[] = array(
				'type' => 'session',
				'name' => 'session_start()',
			);
		}

		return $changes;
	}

	/**
	 * Takes a snapshot and blames what changed on the code that ran since the last one.
	 *
	 * @param string $label Checkpoint: load, or <hook>:start or <hook>:end.
	 */
	public static function checkpoint( string $label ): void {
		if ( ! self::$active ) {
			return;
		}
		$now      = self::snapshot();
		$changes  = self::changes( self::$previous, $now );
		$earlier  = self::$label;
		$suspects = array();
		$where    = '';
		if ( 'load' === $label ) {
			$where = 'load';
		} elseif ( 'plugins_loaded:end' === $label && 'load' === $earlier ) {
			$where    = 'plugins_loaded';
			$suspects = self::hook_components( 'plugins_loaded' );
		} elseif ( substr( $label, -4 ) === ':end' && substr( $earlier, -6 ) === ':start' && substr( $label, 0, -4 ) === substr( $earlier, 0, -6 ) ) {
			$where    = substr( $label, 0, -4 );
			$suspects = self::hook_components( $where );
		}

		foreach ( $changes as $change ) {
			if ( count( self::$signals ) >= 40 ) {
				break;
			}
			self::$signals[] = $change + array(
				'where'    => $where,
				'suspects' => $suspects,
				'certain'  => false,
			);
		}

		self::$previous = $now;
		self::$label    = $label;
	}

	/**
	 * The components that have callbacks on a hook, other than core and this plugin.
	 *
	 * @param string $hook Hook name.
	 * @return string[] plugin:slug, theme:slug or mu-plugin:file.
	 */
	public static function hook_components( string $hook ): array {
		global $wp_filter;
		if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
			return array();
		}

		$found = array();
		foreach ( (array) $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$component = Probe::component( self::file_of( $callback['function'] ?? null ) );
				if ( '' !== $component ) {
					$found[ $component ] = true;
				}
			}
		}

		return array_slice( array_keys( $found ), 0, 12 );
	}

	/**
	 * The file a callback is defined in.
	 *
	 * @param mixed $callback A hook callback.
	 * @return string Empty when it cannot be found.
	 */
	public static function file_of( $callback ): string {
		try {
			if ( $callback instanceof \Closure ) {
				$reflection = new \ReflectionFunction( $callback );
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$reflection = new \ReflectionMethod( $callback[0], (string) $callback[1] );
			} elseif ( is_object( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
			} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$reflection = new \ReflectionMethod( $callback );
			} elseif ( is_string( $callback ) && function_exists( $callback ) ) {
				$reflection = new \ReflectionFunction( $callback );
			} else {
				return '';
			}

			return (string) $reflection->getFileName();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Saves what the trace found, for the request that asked for it.
	 */
	public static function save(): void {
		if ( ! self::$active || '' === self::$out ) {
			return;
		}
		add_option(
			self::$out,
			array(
				'signals' => self::$signals,
				'final'   => self::snapshot(),
			),
			'',
			false
		);
	}
}
