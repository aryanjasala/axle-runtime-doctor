<?php
/**
 * Must-use plugin for the end-to-end tests only.
 *
 * - Loopback requests go to the wp service of the Docker network, since
 *   localhost:<port> inside the containers is not the web server. The option
 *   ard_e2e_loopback = off sends them nowhere, to test a blocked loopback.
 * - Emails are captured into the ard_e2e_mail option instead of being sent.
 * - Application passwords work over plain HTTP.
 * - The option ard_e2e_direct = on lets direct mode call a site over http and
 *   through the Docker network, since localhost:<port> is not the web server there.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

add_filter(
	'axle_runtime_doctor_loopback_url',
	static function () {
		return 'off' === get_option( 'ard_e2e_loopback' ) ? 'http://127.0.0.1:9' : 'http://wp';
	}
);

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$mail   = (array) get_option( 'ard_e2e_mail', array() );
		$mail[] = array(
			'to'      => (array) $atts['to'],
			'subject' => (string) $atts['subject'],
			'message' => (string) $atts['message'],
		);
		update_option( 'ard_e2e_mail', $mail, false );
		return true;
	},
	10,
	2
);

add_filter( 'wp_is_application_passwords_available', '__return_true' );

if ( 'on' === get_option( 'ard_e2e_direct' ) ) {
	add_filter( 'axle_runtime_doctor_direct_allow_http', '__return_true' );
	add_filter( 'http_request_host_is_external', '__return_true' );
	add_filter(
		'axle_runtime_doctor_direct_request_url',
		static function ( $url, $origin ) {
			return str_replace( $origin, 'http://wp', $url );
		},
		10,
		2
	);
}

/*
 * Page cache fixture: the option ard_e2e_cache picks what the front end does.
 * cookie: sets a cookie, nocache: calls nocache_headers(), session: starts a
 * PHP session, donotcache: defines DONOTCACHEPAGE, hit: answers like a host
 * cache (a miss, then hits), anything else does nothing.
 */
add_action(
	'template_redirect',
	static function () {
		$mode = (string) get_option( 'ard_e2e_cache' );
		if ( 'cookie' === $mode ) {
			setcookie( 'ard_visitor', '1', 0, '/' ); // phpcs:ignore
		} elseif ( 'nocache' === $mode ) {
			nocache_headers();
		} elseif ( 'session' === $mode && ! headers_sent() ) {
			session_start(); // phpcs:ignore
		} elseif ( 'donotcache' === $mode ) {
			define( 'DONOTCACHEPAGE', true );
		} elseif ( 'hit' === $mode ) {
			$key = 'ard_e2e_seen_' . md5( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore
			header( 'X-Kinsta-Cache: ' . ( get_transient( $key ) ? 'HIT' : 'MISS' ) );
			set_transient( $key, 1, 60 );
		}
	}
);
