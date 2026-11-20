<?php
/**
 * "Confirm it": load a page once without the plugin that was just updated.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Timing;

use AryanX\Axle\RuntimeDoctor\Probe;
use AryanX\Axle\RuntimeDoctor\Settings;
use AryanX\Axle\RuntimeDoctor\Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Loads one page twice from the server: once as usual and once without the
 * plugin, in the same way and back to back, and compares the server times.
 * The second request is the only one that leaves a plugin out, and only
 * because it carries a token that the loader accepts once (see Loader).
 */
final class Confirm {

	public const OPTION = 'axle_runtime_doctor_confirmations';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the confirmation.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The capability needed, on top of the screen's own.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to load a page without a plugin.
		 *
		 * @param string $capability Default activate_plugins.
		 */
		$cap = (string) apply_filters( 'axle_runtime_doctor_confirm_capability', 'activate_plugins' );

		return '' !== $cap ? $cap : 'activate_plugins';
	}

	/**
	 * Whether the current user may use it.
	 *
	 * @return bool
	 */
	public static function can(): bool {
		return current_user_can( Settings::capability() ) && current_user_can( self::capability() );
	}

	/**
	 * Whether an update can be confirmed: a plugin that is still active.
	 *
	 * @param array $event A logged update.
	 * @return bool
	 */
	public static function possible( array $event ): bool {
		$file = (string) ( $event['file'] ?? '' );
		if ( 'plugin' !== ( $event['type'] ?? '' ) || '' === $file || ! Loader::skippable( $file ) ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		/**
		 * Filters the plugins that can never be left out of a request.
		 *
		 * @param string[] $files Plugin files. Default none; this plugin is always refused.
		 */
		$blocked = (array) apply_filters( 'axle_runtime_doctor_confirm_blocked', array() );

		return ! in_array( $file, $blocked, true ) && ( is_plugin_active( $file ) || is_plugin_active_for_network( $file ) );
	}

	/**
	 * Runs it.
	 *
	 * @param string $event_id An update's ID.
	 * @param string $url      A page of this site. Empty: the page that got slowest, or the home page.
	 * @return array|WP_Error with_ms, without_ms, saved_ms, verdict, message, status.
	 */
	public function run( string $event_id, string $url = '' ) {
		if ( ! Loader::ready( $this->settings ) ) {
			return new WP_Error( 'axle_runtime_doctor_confirm_off', __( 'Turn on "Confirm it" in the settings first. It adds a small must-use file that leaves one plugin out of one tokenized request.', 'axle-runtime-doctor' ) );
		}
		$event = Updates::find( $event_id );
		if ( ! $event || ! self::possible( $event ) ) {
			return new WP_Error( 'axle_runtime_doctor_confirm_unknown', __( 'That update is not a plugin that is active now, so it cannot be left out for a request.', 'axle-runtime-doctor' ) );
		}

		$file = (string) $event['file'];
		$page = $this->page( $event, $url );
		if ( ! $page ) {
			return new WP_Error( 'axle_runtime_doctor_confirm_url', __( 'That page is not a page of this site.', 'axle-runtime-doctor' ) );
		}

		/**
		 * Fires before a page is loaded without a plugin.
		 *
		 * @param array  $event The update.
		 * @param string $url   The page.
		 */
		do_action( 'axle_runtime_doctor_confirm_started', $event, $page['url'] );

		$with = Probe::time_page( $page['url'] );
		if ( is_wp_error( $with ) ) {
			return $with;
		}
		$without = Probe::time_page( $page['url'], $file );
		if ( is_wp_error( $without ) ) {
			return $without;
		}

		$verdict = self::verdict( (float) $with['ms'], (float) $without['ms'], (int) $with['status'], (int) $without['status'], $file === $without['skipped'], (float) $page['delta'] );
		$result  = array(
			'event'      => (string) $event['id'],
			'time'       => time(),
			'plugin'     => (string) $event['name'],
			'file'       => $file,
			'url'        => $page['url'],
			'label'      => Timings::label( $page['url'] ),
			'with_ms'    => (float) $with['ms'],
			'without_ms' => (float) $without['ms'],
			'saved_ms'   => round( (float) $with['ms'] - (float) $without['ms'], 1 ),
			'status'     => (int) $without['status'],
			'verdict'    => $verdict['verdict'],
			'message'    => self::message( $verdict['verdict'], (string) $event['name'], Timings::label( $page['url'] ), (float) $with['ms'], (float) $without['ms'], (int) $without['status'] ),
		);

		$kept = self::all();
		unset( $kept[ $result['event'] ] );
		$kept[ $result['event'] ] = $result;
		Store::set( self::OPTION, array_slice( $kept, -50, null, true ) );

		/**
		 * Fires after a page was loaded without a plugin.
		 *
		 * @param array $result with_ms, without_ms, saved_ms, verdict, message and what it was about.
		 * @param array $event  The update.
		 */
		do_action( 'axle_runtime_doctor_confirmed', $result, $event );

		return $result;
	}

	/**
	 * The page to load: the one asked for, or the one that got slowest.
	 *
	 * @param array  $event An update.
	 * @param string $url   Asked for, or empty.
	 * @return array{url: string, delta: float}|null
	 */
	private function page( array $event, string $url ): ?array {
		$home  = untrailingslashit( home_url() );
		$delta = 0.0;
		$run   = Timings::latest();
		$best  = '';
		foreach ( (array) ( $run['regressions'] ?? array() ) as $row ) {
			if ( in_array( (string) $event['id'], (array) $row['updates'], true ) && (float) $row['delta'] > $delta ) {
				$delta = (float) $row['delta'];
				$best  = (string) $row['key'];
			}
		}
		if ( '' === $url ) {
			$url = $home . ( '' !== $best ? $best : '/' );
		}
		if ( 0 !== strpos( $url, $home ) ) {
			return null;
		}

		return array(
			'url'   => $url,
			'delta' => $delta,
		);
	}

	/**
	 * Judges the two timings.
	 *
	 * @param float $with        Server time with the plugin.
	 * @param float $without     Server time without it.
	 * @param int   $with_status HTTP status with it.
	 * @param int   $out_status  HTTP status without it.
	 * @param bool  $left_out    Whether the plugin really was left out.
	 * @param float $delta       How much slower the page got (0 when unknown).
	 * @return array{verdict: string} confirmed, partly, not or inconclusive.
	 */
	public static function verdict( float $with, float $without, int $with_status, int $out_status, bool $left_out, float $delta ): array {
		if ( ! $left_out || $out_status >= 500 || $out_status !== $with_status ) {
			return array( 'verdict' => 'inconclusive' );
		}

		/**
		 * Filters how the two timings are judged.
		 *
		 * @param array $limits confirm (share of the slowdown the plugin must explain), clear (below this share it is not the cause) and min_ms (least saved time that counts).
		 */
		$limits  = (array) apply_filters(
			'axle_runtime_doctor_confirm_limits',
			array(
				'confirm' => 0.5,
				'clear'   => 0.2,
				'min_ms'  => 30,
			)
		);
		$confirm = max( 0.05, min( 1.0, (float) ( $limits['confirm'] ?? 0.5 ) ) );
		$clear   = max( 0.0, min( $confirm, (float) ( $limits['clear'] ?? 0.2 ) ) );
		$min     = max( 1.0, (float) ( $limits['min_ms'] ?? 30 ) );
		$saved   = $with - $without;
		$basis   = $delta > 0 ? $delta : $with * 0.3;

		if ( $saved >= $min && $saved >= $basis * $confirm ) {
			return array( 'verdict' => 'confirmed' );
		}
		if ( $saved < $min || $saved < $basis * $clear ) {
			return array( 'verdict' => 'not' );
		}

		return array( 'verdict' => 'partly' );
	}

	/**
	 * One sentence for a result.
	 *
	 * @param string $verdict confirmed, partly, not or inconclusive.
	 * @param string $plugin  Plugin name.
	 * @param string $label   Page name.
	 * @param float  $with    Milliseconds with it.
	 * @param float  $without Milliseconds without it.
	 * @param int    $status  HTTP status without it.
	 * @return string
	 */
	public static function message( string $verdict, string $plugin, string $label, float $with, float $without, int $status ): string {
		$with_ms    = number_format_i18n( round( $with ) );
		$without_ms = number_format_i18n( round( $without ) );
		$saved_ms   = number_format_i18n( round( $with - $without ) );
		switch ( $verdict ) {
			case 'confirmed':
				/* translators: 1: page, 2: ms with the plugin, 3: ms without it, 4: plugin, 5: ms saved. */
				return sprintf( __( '%1$s took %2$s ms. Without %4$s it took %3$s ms, so %4$s adds about %5$s ms.', 'axle-runtime-doctor' ), $label, $with_ms, $without_ms, $plugin, $saved_ms );
			case 'partly':
				/* translators: 1: page, 2: ms with the plugin, 3: ms without it, 4: plugin, 5: ms saved. */
				return sprintf( __( '%1$s took %2$s ms. Without %4$s it took %3$s ms. %4$s explains part of it (about %5$s ms), not all.', 'axle-runtime-doctor' ), $label, $with_ms, $without_ms, $plugin, $saved_ms );
			case 'not':
				/* translators: 1: page, 2: ms with the plugin, 3: ms without it, 4: plugin. */
				return sprintf( __( '%1$s took %2$s ms. Without %4$s it took %3$s ms. %4$s is not the cause.', 'axle-runtime-doctor' ), $label, $with_ms, $without_ms, $plugin );
		}
		if ( $status >= 500 ) {
			/* translators: 1: page, 2: plugin, 3: HTTP status. */
			return sprintf( __( '%1$s answered HTTP %3$d without %2$s, so the two times cannot be compared.', 'axle-runtime-doctor' ), $label, $plugin, $status );
		}

		/* translators: 1: page, 2: plugin. */
		return sprintf( __( '%1$s could not be loaded without %2$s, so nothing was confirmed. Nothing was changed on the site.', 'axle-runtime-doctor' ), $label, $plugin );
	}

	/**
	 * Results kept, by update ID.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		$all = Store::get( self::OPTION, array() );

		return is_array( $all ) ? array_filter( $all, 'is_array' ) : array();
	}

	/**
	 * The result for one update.
	 *
	 * @param string $event_id Update ID.
	 * @return array|null
	 */
	public static function for_event( string $event_id ): ?array {
		return self::all()[ $event_id ] ?? null;
	}
}
