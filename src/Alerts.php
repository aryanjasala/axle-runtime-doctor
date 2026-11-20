<?php
/**
 * Emails and the admin notice when a check gets worse.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the regression email and keeps the notice until someone dismisses it.
 */
final class Alerts {

	public const NOTICE = 'axle_runtime_doctor_notice';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up alerts.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Raises the notice and emails the recipients.
	 *
	 * @param array[] $regressions id, label, before, after, summary.
	 * @param array   $report      The new report.
	 */
	public function send( array $regressions, array $report ): void {
		Store::set(
			self::NOTICE,
			array(
				'time'        => (int) ( $report['time'] ?? time() ),
				'regressions' => $regressions,
			)
		);

		$recipients = $this->settings->recipients();
		if ( ! $recipients ) {
			return;
		}

		$site  = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$lines = array();
		foreach ( $regressions as $regression ) {
			/* translators: 1: check name, 2: status before, 3: status now, 4: summary. */
			$lines[] = sprintf( __( '- %1$s: %2$s to %3$s. %4$s', 'axle-runtime-doctor' ), $regression['label'], Result::status_label( $regression['before'] ), Result::status_label( $regression['after'] ), $regression['summary'] );
		}
		$email = array(
			/* translators: 1: site name, 2: first regression. */
			'subject' => sprintf( __( '[%1$s] Runtime check got worse: %2$s', 'axle-runtime-doctor' ), $site, $regressions[0]['summary'] ),
			'message' => sprintf(
				/* translators: 1: site address, 2: list of changes, 3: screen address. */
				__( "The latest runtime check of %1\$s found checks that got worse since the last one:\n\n%2\$s\n\nSee the details and how to fix them:\n%3\$s\n", 'axle-runtime-doctor' ),
				home_url( '/' ),
				implode( "\n", $lines ),
				Admin\Page::url()
			),
		);

		/**
		 * Filters the regression email.
		 *
		 * @param array $email       subject, message.
		 * @param array $regressions What got worse.
		 * @param array $report      The new report.
		 */
		$filtered = (array) apply_filters( 'axle_runtime_doctor_alert_message', $email, $regressions, $report );
		if ( isset( $filtered['subject'], $filtered['message'] ) ) {
			$email = $filtered;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- A few admins, only when a check gets worse.
		$sent = wp_mail( $recipients, (string) $email['subject'], (string) $email['message'] );

		/**
		 * Fires after the regression email went out.
		 *
		 * @param string[] $recipients  Who got it.
		 * @param array    $regressions What got worse.
		 * @param bool     $sent        Whether wp_mail() succeeded.
		 */
		do_action( 'axle_runtime_doctor_alert_sent', $recipients, $regressions, (bool) $sent );
	}

	/**
	 * Raises the notice and emails the recipients when a page got slower, with
	 * the updates made since the last timing in the subject and the text.
	 *
	 * @param array[] $slowdowns key, label, before, after, delta, percent, updates, summary.
	 * @param array   $run       The timing run.
	 */
	public function send_slowdown( array $slowdowns, array $run ): void {
		if ( ! $slowdowns ) {
			return;
		}

		// Add to what is open, so a slowdown does not hide a check that got worse.
		$open = Store::get( self::NOTICE );
		$rows = is_array( $open ) && ! empty( $open['regressions'] ) ? (array) $open['regressions'] : array();
		foreach ( $slowdowns as $slowdown ) {
			$id     = 'timing:' . $slowdown['key'];
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $id ) {
						return ( $row['id'] ?? '' ) !== $id;
					}
				)
			);
			$rows[] = array(
				'id'      => $id,
				'label'   => (string) $slowdown['label'],
				'before'  => Result::GOOD,
				'after'   => (float) $slowdown['percent'] >= 100 ? Result::CRITICAL : Result::RECOMMENDED,
				'summary' => (string) $slowdown['summary'],
			);
		}
		Store::set(
			self::NOTICE,
			array(
				'time'        => (int) ( $run['time'] ?? time() ),
				'regressions' => $rows,
			)
		);

		$recipients = $this->settings->recipients();
		if ( ! $recipients ) {
			return;
		}

		$site  = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$lines = array();
		foreach ( $slowdowns as $slowdown ) {
			$lines[] = '- ' . $slowdown['summary'];
		}
		$email = array(
			/* translators: 1: site name, 2: what happened, naming the update. */
			'subject' => sprintf( __( '[%1$s] A page got slower: %2$s', 'axle-runtime-doctor' ), $site, $slowdowns[0]['summary'] ),
			'message' => sprintf(
				/* translators: 1: site address, 2: list of slowdowns, 3: screen address. */
				__( "The latest timing of %1\$s found pages that got slower:\n\n%2\$s\n\nSee the timeline, and load the page without a plugin to confirm which one it is:\n%3\$s\n", 'axle-runtime-doctor' ),
				home_url( '/' ),
				implode( "\n", $lines ),
				Admin\Page::url( 'compare' )
			),
		);

		/**
		 * Filters the email sent when a page got slower.
		 *
		 * @param array $email     subject, message.
		 * @param array $slowdowns What got slower, with the updates in between.
		 * @param array $run       The timing run.
		 */
		$filtered = (array) apply_filters( 'axle_runtime_doctor_slowdown_message', $email, $slowdowns, $run );
		if ( isset( $filtered['subject'], $filtered['message'] ) ) {
			$email = $filtered;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- A few admins, only when a page got slower.
		$sent = wp_mail( $recipients, (string) $email['subject'], (string) $email['message'] );

		/**
		 * Fires after the slowdown email went out.
		 *
		 * @param string[] $recipients Who got it.
		 * @param array    $slowdowns  What got slower.
		 * @param bool     $sent       Whether wp_mail() succeeded.
		 */
		do_action( 'axle_runtime_doctor_slowdown_alert_sent', $recipients, $slowdowns, (bool) $sent );
	}

	/**
	 * The open notice.
	 *
	 * @return array|null time, regressions.
	 */
	public function notice(): ?array {
		$notice = Store::get( self::NOTICE );

		return is_array( $notice ) && ! empty( $notice['regressions'] ) ? $notice : null;
	}

	/**
	 * Closes the notice.
	 */
	public function dismiss(): void {
		Store::delete( self::NOTICE );
	}
}
