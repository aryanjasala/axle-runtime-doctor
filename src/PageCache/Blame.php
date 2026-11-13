<?php
/**
 * Says, in plain words, who made a page uncacheable and how sure we are.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\PageCache;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the tracer's signals into sentences. A name is only given as fact
 * when the code was seen (a nocache_headers() call). A window of hook
 * callbacks is "one of". A cookie name that matches a known plugin is "the
 * name suggests". Anything else says it could not be traced.
 */
final class Blame {

	/**
	 * Cookie names that give their owner away.
	 *
	 * @return array<string, string> Regular expression => what sets it.
	 */
	public static function cookie_hints(): array {
		$hints = array(
			'/^(wp_woocommerce_session_|woocommerce_items_in_cart|woocommerce_cart_hash|woocommerce_recently_viewed)/i' => 'plugin woocommerce',
			'/^(_icl_current_language|wpml_)/i'        => 'plugin sitepress-multilingual-cms (WPML)',
			'/^pll_language$/i'                        => 'plugin polylang',
			'/^edd_wp_session/i'                       => 'plugin easy-digital-downloads',
			'/^(tk_ai|jetpack_)/i'                     => 'plugin jetpack',
			'/^mailpoet_/i'                            => 'plugin mailpoet',
			'/^(sbjs_|_gcl_|_ga|_fbp)/i'               => 'a tracking script (cookie set by JavaScript, not by the page)',
			'/^(cookielawinfo|cmplz_|CookieLawInfo)/i' => 'a cookie consent plugin',
			'/^wpdiscuz_/i'                            => 'plugin wpdiscuz',
			'/^(PHPSESSID|wp_session|_wp_session)/i'   => 'PHP session_start()',
			'/^wordpress_test_cookie$/i'               => 'WordPress core, on the login page',
		);

		/**
		 * Filters the cookie names that tell which plugin set them.
		 *
		 * @param array<string, string> $hints Regular expression => who sets it.
		 */
		$filtered = array_filter( (array) apply_filters( 'axle_runtime_doctor_page_cache_cookie_hints', $hints ), 'is_scalar' );
		$clean    = array();
		foreach ( $filtered as $pattern => $who ) {
			if ( '' !== (string) $pattern && false !== @preg_match( (string) $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Tests a pattern from a filter.
				$clean[ $pattern ] = (string) $who;
			}
		}

		return $clean;
	}

	/**
	 * What a cookie name suggests.
	 *
	 * @param string $name Cookie name.
	 * @return string Empty when the name says nothing.
	 */
	public static function hint( string $name ): string {
		foreach ( self::cookie_hints() as $pattern => $who ) {
			if ( preg_match( $pattern, $name ) ) {
				return $who;
			}
		}

		return '';
	}

	/**
	 * A component as words.
	 *
	 * @param string $component plugin:slug, theme:slug or mu-plugin:file.
	 * @return string
	 */
	public static function component( string $component ): string {
		$parts = explode( ':', $component, 2 );
		if ( 2 !== count( $parts ) ) {
			return $component;
		}
		if ( 'mu-plugin' === $parts[0] ) {
			/* translators: %s: file name. */
			return sprintf( __( 'must-use plugin %s', 'axle-runtime-doctor' ), $parts[1] );
		}
		if ( 'theme' === $parts[0] ) {
			/* translators: %s: theme folder. */
			return sprintf( __( 'theme %s', 'axle-runtime-doctor' ), $parts[1] );
		}

		/* translators: %s: plugin folder. */
		return sprintf( __( 'plugin %s', 'axle-runtime-doctor' ), $parts[1] );
	}

	/**
	 * The names a signal points at, as words.
	 *
	 * @param array $signal A tracer signal.
	 * @return string[]
	 */
	public static function suspects( array $signal ): array {
		return array_map( array( self::class, 'component' ), array_map( 'strval', (array) ( $signal['suspects'] ?? array() ) ) );
	}

	/**
	 * What a signal is, as words that follow "appeared".
	 *
	 * @param array $signal A tracer signal.
	 * @return string
	 */
	private static function item( array $signal ): string {
		$name = (string) ( $signal['name'] ?? '' );
		switch ( (string) ( $signal['type'] ?? '' ) ) {
			case 'cookie':
				/* translators: %s: cookie name. */
				return sprintf( __( 'the cookie %s', 'axle-runtime-doctor' ), $name );
			case 'donotcachepage':
				return __( 'the constant DONOTCACHEPAGE', 'axle-runtime-doctor' );
			case 'session':
				return __( 'a PHP session', 'axle-runtime-doctor' );
		}

		/* translators: %s: header name. */
		return sprintf( __( 'the %s header', 'axle-runtime-doctor' ), $name );
	}

	/**
	 * Every signal of a trace as sentences: one per call that was seen, and one
	 * per hook window for everything else, so a session that sets a cookie and
	 * three headers is one sentence and not five.
	 *
	 * @param array $signals Tracer signals.
	 * @return string[]
	 */
	public static function describe_all( array $signals ): array {
		$lines  = array();
		$groups = array();
		foreach ( $signals as $signal ) {
			if ( ! is_array( $signal ) ) {
				continue;
			}
			if ( 'nocache_headers' === ( $signal['type'] ?? '' ) ) {
				$lines[ self::describe( $signal ) ] = true;
				continue;
			}
			$key = (string) ( $signal['where'] ?? '' ) . '|' . implode( ',', array_map( 'strval', (array) ( $signal['suspects'] ?? array() ) ) );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'signal' => $signal,
					'items'  => array(),
					'hints'  => array(),
				);
			}
			$groups[ $key ]['items'][] = self::item( $signal );
			if ( 'cookie' === ( $signal['type'] ?? '' ) && '' !== self::hint( (string) $signal['name'] ) ) {
				$groups[ $key ]['hints'][] = sprintf(
					/* translators: 1: cookie name, 2: who the cookie name points at. */
					__( 'The cookie %1$s points at: %2$s.', 'axle-runtime-doctor' ),
					(string) $signal['name'],
					self::hint( (string) $signal['name'] )
				);
			}
		}

		foreach ( $groups as $group ) {
			$lines[ self::sentence( $group['signal'], implode( ', ', $group['items'] ), $group['hints'] ) ] = true;
		}

		return array_keys( $lines );
	}

	/**
	 * One signal as a sentence.
	 *
	 * @param array $signal type, name, where, suspects, certain.
	 * @return string
	 */
	public static function describe( array $signal ): string {
		if ( 'nocache_headers' === (string) ( $signal['type'] ?? '' ) ) {
			$suspects = self::suspects( $signal );
			if ( ! empty( $signal['certain'] ) && $suspects ) {
				/* translators: %s: plugin or theme. */
				return sprintf( __( 'nocache_headers() was called by %s. This is seen, not guessed.', 'axle-runtime-doctor' ), $suspects[0] );
			}

			return __( 'nocache_headers() was called by WordPress itself or by code the trace could not place.', 'axle-runtime-doctor' );
		}

		$hint = 'cookie' === (string) ( $signal['type'] ?? '' ) ? self::hint( (string) ( $signal['name'] ?? '' ) ) : '';

		return self::sentence(
			$signal,
			self::item( $signal ),
			'' !== $hint ? array(
				sprintf(
					/* translators: %s: who the cookie name points at. */
					__( 'The name of the cookie points at: %s.', 'axle-runtime-doctor' ),
					$hint
				),
			) : array()
		);
	}

	/**
	 * A sentence about what appeared in one window.
	 *
	 * @param array    $signal What the window is: where and suspects.
	 * @param string   $items  What appeared, as words.
	 * @param string[] $hints  Extra sentences.
	 * @return string
	 */
	private static function sentence( array $signal, string $items, array $hints ): string {
		$where    = (string) ( $signal['where'] ?? '' );
		$suspects = self::suspects( $signal );

		if ( 'load' === $where ) {
			/* translators: %s: what appeared. */
			$text = sprintf( __( 'Seen: %s. It appeared while the plugin and theme files were loading, before any hook ran, so the trace cannot say which one.', 'axle-runtime-doctor' ), $items );
		} elseif ( '' === $where ) {
			/* translators: %s: what appeared. */
			$text = sprintf( __( 'Seen: %s. It appeared between two WordPress hooks, in core or in code that does not run on a hook, so the trace cannot say which plugin.', 'axle-runtime-doctor' ), $items );
		} elseif ( 1 === count( $suspects ) ) {
			/* translators: 1: what appeared, 2: hook name, 3: plugin or theme. */
			$text = sprintf( __( 'Seen: %1$s. It appeared during the %2$s hook, and only %3$s has callbacks on it. It is probably that one, but the trace did not see it.', 'axle-runtime-doctor' ), $items, $where, $suspects[0] );
		} elseif ( $suspects ) {
			/* translators: 1: what appeared, 2: hook name, 3: list of plugins and themes. */
			$text = sprintf( __( 'Seen: %1$s. It appeared during the %2$s hook. It is one of these, and the trace cannot tell which: %3$s.', 'axle-runtime-doctor' ), $items, $where, implode( ', ', $suspects ) );
		} else {
			/* translators: 1: what appeared, 2: hook name. */
			$text = sprintf( __( 'Seen: %1$s. It appeared during the %2$s hook, in core code. The trace cannot name a plugin.', 'axle-runtime-doctor' ), $items, $where );
		}

		return $text . ( $hints ? ' ' . implode( ' ', $hints ) : '' );
	}
}
