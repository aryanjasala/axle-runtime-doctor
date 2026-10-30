<?php
/**
 * The regression notice in wp-admin.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Admin;

use AryanX\Axle\RuntimeDoctor\Alerts;
use AryanX\Axle\RuntimeDoctor\Result;
use AryanX\Axle\RuntimeDoctor\Service;
use AryanX\Axle\RuntimeDoctor\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the checks that got worse to everyone who may open the screen, until
 * one of them dismisses it.
 */
final class Notice implements Service {

	/**
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Sets up the notice.
	 *
	 * @param Alerts $alerts Alerts.
	 */
	public function __construct( Alerts $alerts ) {
		$this->alerts = $alerts;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( is_multisite() ? 'network_admin_notices' : 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Prints the notice.
	 */
	public function render(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}
		$notice = $this->alerts->notice();
		if ( ! $notice ) {
			return;
		}

		echo '<div class="notice notice-error axle-runtime-doctor-notice"><p><strong>' . esc_html__( 'Runtime Doctor: some checks got worse', 'axle-runtime-doctor' ) . '</strong></p><ul>';
		foreach ( (array) $notice['regressions'] as $regression ) {
			printf(
				'<li>%1$s: %2$s</li>',
				esc_html( (string) $regression['label'] ),
				esc_html(
					sprintf(
						/* translators: 1: status before, 2: status now, 3: summary. */
						__( '%1$s to %2$s. %3$s', 'axle-runtime-doctor' ),
						Result::status_label( (string) $regression['before'] ),
						Result::status_label( (string) $regression['after'] ),
						(string) $regression['summary']
					)
				)
			);
		}
		echo '</ul><p><a class="button button-primary" href="' . esc_url( Page::url() ) . '">' . esc_html__( 'See the checks', 'axle-runtime-doctor' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=axle_runtime_doctor_dismiss' ), 'axle_runtime_doctor_dismiss' ) ) . '">' . esc_html__( 'Dismiss', 'axle-runtime-doctor' ) . '</a></p></div>';
	}
}
