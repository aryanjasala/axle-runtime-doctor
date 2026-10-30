<?php
/**
 * Must-use plugin for the end-to-end tests only.
 *
 * - Loopback requests go to the wp service of the Docker network, since
 *   localhost:<port> inside the containers is not the web server. The option
 *   ard_e2e_loopback = off sends them nowhere, to test a blocked loopback.
 * - Emails are captured into the ard_e2e_mail option instead of being sent.
 * - Application passwords work over plain HTTP.
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
