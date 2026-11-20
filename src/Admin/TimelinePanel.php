<?php
/**
 * Tools > Runtime Doctor > Compare > With another day: which update made the site slower.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Admin;

use AryanX\Axle\RuntimeDoctor\Service;
use AryanX\Axle\RuntimeDoctor\Settings;
use AryanX\Axle\RuntimeDoctor\Timing\Confirm;
use AryanX\Axle\RuntimeDoctor\Timing\Loader;
use AryanX\Axle\RuntimeDoctor\Timing\Timings;
use AryanX\Axle\RuntimeDoctor\Timing\Updates;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The timeline of updates and page timings, and the two forms that act on it:
 * time the pages now, and "Confirm it" for one update.
 */
final class TimelinePanel implements Service {

	public const FLASH = 'axle_runtime_doctor_timeline_flash';

	/**
	 * Page timings.
	 *
	 * @var Timings
	 */
	private Timings $timings;

	/**
	 * Leaves a plugin out of one request.
	 *
	 * @var Confirm
	 */
	private Confirm $confirm;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the panel.
	 *
	 * @param Timings  $timings  Page timings.
	 * @param Confirm  $confirm  Leaves a plugin out of one request.
	 * @param Settings $settings Settings.
	 */
	public function __construct( Timings $timings, Confirm $confirm, Settings $settings ) {
		$this->timings  = $timings;
		$this->confirm  = $confirm;
		$this->settings = $settings;
	}

	/**
	 * Adds the form handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_axle_runtime_doctor_measure', array( $this, 'handle_measure' ) );
		add_action( 'admin_post_axle_runtime_doctor_confirm', array( $this, 'handle_confirm' ) );
	}

	/**
	 * URL of the view.
	 *
	 * @return string
	 */
	private static function url(): string {
		return Page::url(
			'compare',
			array(
				'view' => 'day',
			)
		) . '#ard-timeline';
	}

	/**
	 * Keeps a notice for the next page view.
	 *
	 * @param string $type success, warning or error.
	 * @param string $text The message.
	 */
	private static function flash( string $type, string $text ): void {
		update_user_meta(
			get_current_user_id(),
			self::FLASH,
			array(
				'type' => $type,
				'text' => $text,
			)
		);
	}

	/**
	 * Times the pages from the form.
	 */
	public function handle_measure(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'axle-runtime-doctor' ), 403 );
		}
		check_admin_referer( 'axle_runtime_doctor_measure' );

		$run = $this->timings->run( 'screen' );
		if ( is_wp_error( $run ) ) {
			self::flash( 'error', $run->get_error_message() );
		} elseif ( ! empty( $run['regressions'] ) ) {
			self::flash( 'warning', (string) $run['regressions'][0]['summary'] );
		} else {
			self::flash( 'success', __( 'The key pages were timed.', 'axle-runtime-doctor' ) );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Loads a page once without one plugin, from the form.
	 */
	public function handle_confirm(): void {
		if ( ! Confirm::can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'axle-runtime-doctor' ), 403 );
		}
		check_admin_referer( 'axle_runtime_doctor_confirm' );

		$event  = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		$result = $this->confirm->run( $event );
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
		} else {
			self::flash( 'confirmed' === $result['verdict'] ? 'warning' : 'success', (string) $result['message'] );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Prints the notice kept by the last action.
	 */
	private function print_flash(): void {
		$flash = get_user_meta( get_current_user_id(), self::FLASH, true );
		if ( ! is_array( $flash ) || empty( $flash['text'] ) ) {
			return;
		}
		delete_user_meta( get_current_user_id(), self::FLASH );

		$type = in_array( $flash['type'] ?? '', array( 'success', 'warning', 'error' ), true ) ? (string) $flash['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible ard-notice" role="status"><p>' . esc_html( (string) $flash['text'] ) . '</p></div>';
	}

	/**
	 * Prints the card.
	 */
	public function render(): void {
		$this->print_flash();
		$run = Timings::latest();

		echo '<section class="axle-card" id="ard-timeline" aria-labelledby="ard-timeline-title"><h2 class="axle-card__title" id="ard-timeline-title">' . esc_html__( 'Which update made the site slower', 'axle-runtime-doctor' ) . '</h2>';
		echo '<p class="axle-intro">' . esc_html__( 'The key pages are timed from the server once a day and after each update, with page caches left out, and matched to what was updated in between.', 'axle-runtime-doctor' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="axle-toolbar ard-measure">';
		wp_nonce_field( 'axle_runtime_doctor_measure' );
		echo '<input type="hidden" name="action" value="axle_runtime_doctor_measure">';
		echo '<button type="submit" class="button' . ( $run ? '' : ' button-primary' ) . '">' . esc_html__( 'Time the key pages now', 'axle-runtime-doctor' ) . '</button>';
		echo '<span class="axle-muted">' . esc_html__( 'Takes about half a minute.', 'axle-runtime-doctor' ) . '</span></form>';

		if ( ! $run ) {
			echo '<div class="axle-empty ard-empty"><p>' . esc_html__( 'No page has been timed yet. Time them now to get a first reading. After that, a page that gets slower is matched to the updates made since the last reading.', 'axle-runtime-doctor' ) . '</p></div></section>';

			return;
		}

		$this->slowdowns( $run );
		$this->pages( $run );
		$this->timeline();
		echo '</section>';
	}

	/**
	 * What got slower in the latest run, and the updates to suspect.
	 *
	 * @param array $run The latest run.
	 */
	private function slowdowns( array $run ): void {
		$rows = (array) ( $run['regressions'] ?? array() );
		if ( ! $rows ) {
			echo '<p class="ard-steady"><span class="axle-badge axle-badge--good">' . esc_html__( 'Steady', 'axle-runtime-doctor' ) . '</span> ';
			echo esc_html__( 'No page got noticeably slower in the latest timing.', 'axle-runtime-doctor' ) . '</p>';

			return;
		}

		foreach ( $rows as $row ) {
			echo '<div class="ard-slowdown" role="group" aria-label="' . esc_attr( (string) $row['label'] ) . '">';
			echo '<p><span class="axle-badge axle-badge--warning">' . esc_html__( 'Slower', 'axle-runtime-doctor' ) . '</span> <strong>' . esc_html( (string) $row['summary'] ) . '</strong></p>';
			$events = array();
			foreach ( (array) $row['updates'] as $id ) {
				$event = Updates::find( (string) $id );
				if ( $event ) {
					$events[] = $event;
				}
			}
			if ( count( $events ) > 1 ) {
				echo '<p class="description">' . esc_html__( 'More than one update came in since the last timing. "Confirm it" loads the page once without a plugin to show which one adds the time.', 'axle-runtime-doctor' ) . '</p>';
				echo '<ul class="ard-candidates">';
				foreach ( $events as $event ) {
					echo '<li>' . esc_html( Updates::describe( $event ) ) . ' ';
					$this->confirm_control( $event );
					echo '</li>';
				}
				echo '</ul>';
			} elseif ( 1 === count( $events ) ) {
				echo '<p>';
				$this->confirm_control( $events[0] );
				echo '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * The "Confirm it" button, its result, or why it is not there.
	 *
	 * @param array $event An update.
	 */
	private function confirm_control( array $event ): void {
		$id     = (string) $event['id'];
		$result = Confirm::for_event( $id );
		if ( $result ) {
			echo '<span class="ard-confirm-result ard-confirm-result--' . esc_attr( (string) $result['verdict'] ) . '">' . esc_html( (string) $result['message'] ) . '</span> ';
		}
		if ( ! Confirm::possible( $event ) || ! Confirm::can() ) {
			return;
		}
		if ( ! Loader::ready( $this->settings ) ) {
			echo '<a href="' . esc_url( Page::url( 'settings' ) . '#ard-confirm' ) . '">' . esc_html__( 'Turn on Confirm it', 'axle-runtime-doctor' ) . '</a>';

			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ard-inline-form">';
		wp_nonce_field( 'axle_runtime_doctor_confirm' );
		echo '<input type="hidden" name="action" value="axle_runtime_doctor_confirm"><input type="hidden" name="event" value="' . esc_attr( $id ) . '">';
		echo '<button type="submit" class="button button-small">' . esc_html( $result ? __( 'Confirm it again', 'axle-runtime-doctor' ) : __( 'Confirm it', 'axle-runtime-doctor' ) ) . '<span class="screen-reader-text"> ' . esc_html( Updates::describe( $event ) ) . '</span></button></form>';
	}

	/**
	 * The latest reading of each page.
	 *
	 * @param array $run The latest run.
	 */
	private function pages( array $run ): void {
		$all      = Timings::all();
		$previous = count( $all ) > 1 ? (array) $all[ count( $all ) - 2 ] : array();

		echo '<h3>' . esc_html__( 'Server time of the key pages', 'axle-runtime-doctor' ) . '</h3>';
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Server time of the key pages', 'axle-runtime-doctor' ) . '"><table class="widefat striped ard-timings"><thead><tr>';
		foreach ( array( __( 'Page', 'axle-runtime-doctor' ), __( 'Now', 'axle-runtime-doctor' ), __( 'Before', 'axle-runtime-doctor' ), __( 'Change', 'axle-runtime-doctor' ), __( 'Note', 'axle-runtime-doctor' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( (array) $run['pages'] as $path => $page ) {
			$old = $previous['pages'][ $path ] ?? null;
			echo '<tr data-page="' . esc_attr( (string) $path ) . '"><th scope="row">' . esc_html( (string) $page['label'] ) . '</th>';
			if ( ! empty( $page['error'] ) ) {
				echo '<td colspan="4"><span class="axle-badge axle-badge--info">' . esc_html__( 'Not timed', 'axle-runtime-doctor' ) . '</span> ' . esc_html( (string) $page['error'] ) . '</td></tr>';
				continue;
			}
			echo '<td class="num">' . esc_html( self::ms( (float) $page['median'] ) ) . '</td>';
			if ( is_array( $old ) && empty( $old['error'] ) ) {
				$delta = (float) $page['median'] - (float) $old['median'];
				echo '<td class="num">' . esc_html( self::ms( (float) $old['median'] ) ) . '</td><td class="num">' . esc_html( ( $delta >= 0 ? '+' : '-' ) . self::ms( abs( $delta ) ) ) . '</td>';
			} else {
				echo '<td class="num">-</td><td class="num">-</td>';
			}
			echo '<td>';
			if ( ! empty( $page['cached'] ) ) {
				echo '<span class="axle-badge axle-badge--warning">' . esc_html__( 'A cache answered', 'axle-runtime-doctor' ) . '</span> ' . esc_html__( 'This is the cache time, not the site time.', 'axle-runtime-doctor' );
			} elseif ( (int) $page['status'] >= 400 ) {
				/* translators: %d: HTTP status. */
				echo esc_html( sprintf( __( 'HTTP %d', 'axle-runtime-doctor' ), (int) $page['status'] ) );
			} else {
				echo '<span class="axle-muted">' . esc_html__( 'Median of the timed requests', 'axle-runtime-doctor' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * The timeline of updates and timing runs.
	 */
	private function timeline(): void {
		echo '<h3>' . esc_html__( 'Timeline', 'axle-runtime-doctor' ) . '</h3><ol class="ard-timeline">';
		foreach ( Timings::timeline() as $row ) {
			echo '<li class="ard-tl ard-tl--' . esc_attr( (string) $row['kind'] ) . '"><span class="ard-tl-time">' . AxleUi::relative_time( (int) $row['time'] ) . '</span> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time().
			if ( 'update' === $row['kind'] ) {
				$event = (array) $row['update'];
				echo '<span class="axle-badge axle-badge--info">' . esc_html( self::kind_label( (string) $event['type'] ) ) . '</span> ' . esc_html( Updates::describe( $event ) ) . ' ';
				$this->confirm_control( $event );
			} else {
				$run   = (array) $row['run'];
				$parts = array();
				foreach ( (array) $run['pages'] as $page ) {
					$parts[] = empty( $page['error'] ) ? $page['label'] . ' ' . self::ms( (float) $page['median'] ) : $page['label'] . ' ' . __( 'not timed', 'axle-runtime-doctor' );
				}
				/* translators: %s: what started the run: cron, update, screen. */
				echo '<span class="axle-badge axle-badge--info">' . esc_html( sprintf( __( 'Timing, %s', 'axle-runtime-doctor' ), self::source_label( (string) $run['source'] ) ) ) . '</span> ' . esc_html( implode( ', ', $parts ) );
				if ( ! empty( $run['regressions'] ) ) {
					echo ' <span class="axle-badge axle-badge--warning">' . esc_html__( 'Slower', 'axle-runtime-doctor' ) . '</span>';
				}
			}
			echo '</li>';
		}
		echo '</ol>';
	}

	/**
	 * Milliseconds, as text.
	 *
	 * @param float $value Milliseconds.
	 * @return string
	 */
	private static function ms( float $value ): string {
		/* translators: %s: a number of milliseconds. */
		return sprintf( __( '%s ms', 'axle-runtime-doctor' ), number_format_i18n( round( $value ) ) );
	}

	/**
	 * What kind of update it was.
	 *
	 * @param string $type plugin, theme, core or deploy.
	 * @return string
	 */
	private static function kind_label( string $type ): string {
		$labels = array(
			'plugin' => __( 'Plugin', 'axle-runtime-doctor' ),
			'theme'  => __( 'Theme', 'axle-runtime-doctor' ),
			'core'   => __( 'WordPress', 'axle-runtime-doctor' ),
			'deploy' => __( 'Deploy', 'axle-runtime-doctor' ),
		);

		return $labels[ $type ] ?? $type;
	}

	/**
	 * What started a run.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	private static function source_label( string $source ): string {
		$labels = array(
			'cron'       => __( 'daily', 'axle-runtime-doctor' ),
			'update'     => __( 'after an update', 'axle-runtime-doctor' ),
			'deploy'     => __( 'after a deploy', 'axle-runtime-doctor' ),
			'smoke-test' => __( 'after Smoke Test', 'axle-runtime-doctor' ),
			'screen'     => __( 'by hand', 'axle-runtime-doctor' ),
			'cli'        => __( 'by WP-CLI', 'axle-runtime-doctor' ),
			'ability'    => __( 'by an ability', 'axle-runtime-doctor' ),
		);

		return $labels[ $source ] ?? $source;
	}
}
