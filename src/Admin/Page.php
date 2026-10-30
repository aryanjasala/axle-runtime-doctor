<?php
/**
 * Tools > Runtime Doctor.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Admin;

use AryanX\Axle\RuntimeDoctor\Alerts;
use AryanX\Axle\RuntimeDoctor\Autoload;
use AryanX\Axle\RuntimeDoctor\Checks\Registry;
use AryanX\Axle\RuntimeDoctor\Fixes;
use AryanX\Axle\RuntimeDoctor\History;
use AryanX\Axle\RuntimeDoctor\Hosts;
use AryanX\Axle\RuntimeDoctor\Result;
use AryanX\Axle\RuntimeDoctor\Runner;
use AryanX\Axle\RuntimeDoctor\Service;
use AryanX\Axle\RuntimeDoctor\Settings;
use AryanX\Axle\RuntimeDoctor\Store;

use const AryanX\Axle\RuntimeDoctor\PLUGIN_FILE;
use const AryanX\Axle\RuntimeDoctor\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The screen: Checks, History, Slow queries and Settings tabs.
 *
 * On multisite the runtime is shared, so the full screen lives in Network
 * Admin (Settings > Runtime Doctor), and each site's Tools > Runtime Doctor
 * shows the checks about that site (autoloaded options, cron, uploads).
 * Forms post to admin-post.php and come back with a message.
 */
final class Page implements Service {

	public const SLUG = 'axle-runtime-doctor';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Runs the checks.
	 *
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Snapshots.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Fix data.
	 *
	 * @var Fixes
	 */
	private Fixes $fixes;

	/**
	 * Host detection.
	 *
	 * @var Hosts
	 */
	private Hosts $hosts;

	/**
	 * Autoloaded options.
	 *
	 * @var Autoload
	 */
	private Autoload $autoload;

	/**
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * The checks.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Sets up the screen.
	 *
	 * @param Settings $settings Settings.
	 * @param Runner   $runner   Runs the checks.
	 * @param History  $history  Snapshots.
	 * @param Fixes    $fixes    Fix data.
	 * @param Hosts    $hosts    Host detection.
	 * @param Autoload $autoload Autoloaded options.
	 * @param Alerts   $alerts   Alerts.
	 * @param Registry $registry The checks.
	 */
	public function __construct( Settings $settings, Runner $runner, History $history, Fixes $fixes, Hosts $hosts, Autoload $autoload, Alerts $alerts, Registry $registry ) {
		$this->settings = $settings;
		$this->runner   = $runner;
		$this->history  = $history;
		$this->fixes    = $fixes;
		$this->hosts    = $hosts;
		$this->autoload = $autoload;
		$this->alerts   = $alerts;
		$this->registry = $registry;
	}

	/**
	 * Adds the menus and form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'network_admin_menu', array( $this, 'add_network_page' ) );
		add_action( 'admin_post_axle_runtime_doctor_run', array( $this, 'handle_run' ) );
		add_action( 'admin_post_axle_runtime_doctor_settings', array( $this, 'handle_settings' ) );
		add_action( 'admin_post_axle_runtime_doctor_autoload', array( $this, 'handle_autoload' ) );
		add_action( 'admin_post_axle_runtime_doctor_dismiss', array( $this, 'handle_dismiss' ) );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  checks, history, slow-queries or settings.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( string $tab = 'checks', array $args = array() ): string {
		$query = array_merge(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			$args
		);

		return is_multisite()
			? add_query_arg( $query, network_admin_url( 'settings.php' ) )
			: add_query_arg( $query, admin_url( 'tools.php' ) );
	}

	/**
	 * Whether this screen shows the shared runtime, not just one site.
	 *
	 * @return bool
	 */
	private static function full(): bool {
		return ! is_multisite() || is_network_admin();
	}

	/**
	 * Adds Tools > Runtime Doctor.
	 */
	public function add_page(): void {
		$hook = add_management_page( __( 'Runtime Doctor', 'axle-runtime-doctor' ), __( 'Runtime Doctor', 'axle-runtime-doctor' ), Settings::capability(), self::SLUG, array( $this, 'render' ) );
		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue' ) );
		}
	}

	/**
	 * Adds Settings > Runtime Doctor in Network Admin.
	 */
	public function add_network_page(): void {
		$hook = add_submenu_page( 'settings.php', __( 'Runtime Doctor', 'axle-runtime-doctor' ), __( 'Runtime Doctor', 'axle-runtime-doctor' ), Settings::capability(), self::SLUG, array( $this, 'render' ) );
		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue' ) );
		}
	}

	/**
	 * The stylesheet and the script.
	 */
	public function enqueue(): void {
		wp_enqueue_style( 'axle-runtime-doctor-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'axle-runtime-doctor-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks', 'wp-api-fetch', 'wp-i18n' ), VERSION, true );
		wp_set_script_translations( 'axle-runtime-doctor-admin', 'axle-runtime-doctor' );
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	private static function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'checks';

		return in_array( $tab, array( 'checks', 'history', 'slow-queries', 'settings' ), true ) ? $tab : 'checks';
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view this page.', 'axle-runtime-doctor' ), 403 );
		}

		$tab  = self::full() ? self::tab() : 'checks';
		$tabs = array(
			'checks'       => __( 'Checks', 'axle-runtime-doctor' ),
			'history'      => __( 'History', 'axle-runtime-doctor' ),
			'slow-queries' => __( 'Slow queries', 'axle-runtime-doctor' ),
			'settings'     => __( 'Settings', 'axle-runtime-doctor' ),
		);

		echo '<div class="wrap ard-wrap">';
		echo '<h1>' . esc_html__( 'Runtime Doctor', 'axle-runtime-doctor' ) . '</h1>';
		$this->message();

		if ( self::full() ) {
			echo '<nav class="nav-tab-wrapper ard-tabs" aria-label="' . esc_attr__( 'Runtime Doctor sections', 'axle-runtime-doctor' ) . '">';
			foreach ( $tabs as $id => $label ) {
				printf(
					'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
					esc_url( self::url( $id ) ),
					$id === $tab ? ' nav-tab-active' : '',
					$id === $tab ? ' aria-current="page"' : '',
					esc_html( $label )
				);
			}
			echo '</nav>';
		}

		switch ( $tab ) {
			case 'history':
				$this->render_history();
				break;
			case 'slow-queries':
				$this->render_slow_queries();
				break;
			case 'settings':
				$this->render_settings();
				break;
			default:
				self::full() ? $this->render_checks() : $this->render_site_checks();
		}

		echo '<div class="ard-live screen-reader-text" role="status" aria-live="polite"></div>';
		echo '</div>';
	}

	/**
	 * The message after a form.
	 */
	private function message(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$message = isset( $_GET['ard-message'] ) ? sanitize_key( wp_unslash( $_GET['ard-message'] ) ) : '';
		$option  = isset( $_GET['ard-option'] ) ? sanitize_text_field( wp_unslash( $_GET['ard-option'] ) ) : '';
		// phpcs:enable
		$messages = array(
			'ran'       => array( 'success', __( 'The checks ran.', 'axle-runtime-doctor' ) ),
			'saved'     => array( 'success', __( 'Settings saved.', 'axle-runtime-doctor' ) ),
			/* translators: %s: option name. */
			'autoload'  => array( 'success', sprintf( __( 'Autoload is off for %s.', 'axle-runtime-doctor' ), $option ) ),
			/* translators: %s: option name. */
			'unchanged' => array( 'warning', sprintf( __( 'Nothing changed for %s: autoload was already off, or the option is gone.', 'axle-runtime-doctor' ), $option ) ),
			'dismissed' => array( 'success', __( 'Notice dismissed.', 'axle-runtime-doctor' ) ),
		);
		if ( isset( $messages[ $message ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $message ][0] ), esc_html( $messages[ $message ][1] ) );
		}
	}

	/**
	 * The "Run checks again" form.
	 *
	 * @param string $label Button label.
	 */
	private function run_form( string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ard-run-form" data-ard-run>';
		wp_nonce_field( 'axle_runtime_doctor_run' );
		echo '<input type="hidden" name="action" value="axle_runtime_doctor_run">';
		echo '<button type="submit" class="button button-primary ard-run">' . esc_html( $label ) . '</button>';
		echo ' <span class="ard-running" hidden><span class="spinner is-active"></span>' . esc_html__( 'Running the checks. This takes a few seconds.', 'axle-runtime-doctor' ) . '</span>';
		echo '</form>';
	}

	/**
	 * The Checks tab.
	 */
	private function render_checks(): void {
		$report = $this->history->latest();
		if ( ! $report ) {
			echo '<div class="ard-empty" data-ard-autorun>';
			echo '<p>' . esc_html__( 'No checks have run yet. They run inside a web request, so the values are the ones your visitors get.', 'axle-runtime-doctor' ) . '</p>';
			$this->run_form( __( 'Run the checks', 'axle-runtime-doctor' ) );
			echo '</div>';

			return;
		}

		$this->summary( $report );
		$this->groups( (array) $report['results'] );
	}

	/**
	 * Tools > Runtime Doctor on a site of a network: the checks about this site, run live.
	 */
	private function render_site_checks(): void {
		echo '<p>' . esc_html__( 'PHP, OPcache and the object cache are shared by every site of this network. The network admin sees them under Network Admin > Settings > Runtime Doctor. These checks are about this site.', 'axle-runtime-doctor' ) . '</p>';
		$report = $this->runner->run(
			array(
				'per_site' => true,
				'source'   => 'screen',
			)
		);
		$this->groups( (array) $report['results'] );
	}

	/**
	 * The summary bar.
	 *
	 * @param array $report The report.
	 */
	private function summary( array $report ): void {
		$counts = (array) $report['counts'];
		// The host from the settings now, so a changed override shows at once.
		$host = $this->hosts->current();

		echo '<div class="ard-summary">';
		echo '<ul class="ard-counts">';
		foreach ( array( Result::CRITICAL, Result::RECOMMENDED, Result::GOOD ) as $status ) {
			printf(
				'<li class="ard-count ard-count--%1$s"><span class="ard-count-number">%2$d</span> %3$s</li>',
				esc_attr( $status ),
				(int) ( $counts[ $status ] ?? 0 ),
				esc_html( Result::status_label( $status ) )
			);
		}
		echo '</ul>';

		echo '<div class="ard-host">';
		printf(
			/* translators: %s: host name. */
			esc_html__( 'Host: %s', 'axle-runtime-doctor' ),
			'<strong class="ard-host-name">' . esc_html( $host['label'] ) . '</strong>'
		);
		echo ' <a href="' . esc_url( self::url( 'settings' ) . '#ard-host' ) . '">' . esc_html__( 'change', 'axle-runtime-doctor' ) . '<span class="screen-reader-text"> ' . esc_html__( 'the host', 'axle-runtime-doctor' ) . '</span></a>';
		echo '<details class="ard-evidence"><summary>' . esc_html__( 'How it was detected', 'axle-runtime-doctor' ) . '</summary><ul>';
		foreach ( $host['evidence'] as $line ) {
			echo '<li>' . esc_html( (string) $line ) . '</li>';
		}
		echo '</ul></details>';
		echo '</div>';

		echo '<div class="ard-last">';
		printf(
			/* translators: 1: time ago, 2: web or WP-CLI. */
			esc_html__( 'Last run %1$s ago, in %2$s.', 'axle-runtime-doctor' ),
			esc_html( human_time_diff( (int) $report['time'] ) ),
			'cli' === ( $report['sapi'] ?? '' ) ? esc_html__( 'WP-CLI (the probe failed)', 'axle-runtime-doctor' ) : esc_html__( 'the web server', 'axle-runtime-doctor' )
		);
		echo '</div>';
		$this->run_form( __( 'Run checks again', 'axle-runtime-doctor' ) );
		echo '</div>';
	}

	/**
	 * The results, by group.
	 *
	 * @param array $results Results.
	 */
	private function groups( array $results ): void {
		$host = $this->hosts->current();
		foreach ( Registry::groups() as $group => $label ) {
			$rows = array_filter(
				$results,
				static function ( $result ) use ( $group ) {
					return ( $result['group'] ?? '' ) === $group;
				}
			);
			if ( ! $rows ) {
				continue;
			}
			uasort(
				$rows,
				static function ( $a, $b ) {
					return Result::rank( (string) $b['status'] ) <=> Result::rank( (string) $a['status'] );
				}
			);

			echo '<section class="ard-group" id="ard-group-' . esc_attr( $group ) . '" aria-labelledby="ard-group-' . esc_attr( $group ) . '-title">';
			echo '<h2 id="ard-group-' . esc_attr( $group ) . '-title">' . esc_html( $label ) . '</h2>';
			foreach ( $rows as $result ) {
				$this->row( $result, $host );
			}
			echo '</section>';
		}
	}

	/**
	 * One result.
	 *
	 * @param array $result The result.
	 * @param array $host   The host.
	 */
	private function row( array $result, array $host ): void {
		$id     = (string) $result['id'];
		$status = (string) $result['status'];

		echo '<div class="ard-check ard-check--' . esc_attr( $status ) . '" id="ard-check-' . esc_attr( $id ) . '" data-check="' . esc_attr( $id ) . '">';
		echo '<div class="ard-check-head">';
		echo '<span class="ard-badge ard-badge--' . esc_attr( $status ) . '">' . esc_html( Result::status_label( $status ) ) . '</span>';
		echo '<h3 class="ard-check-title">' . esc_html( (string) $result['label'] ) . '</h3>';
		echo '<p class="ard-check-summary">' . esc_html( (string) $result['summary'] );
		if ( ! empty( $result['inferred'] ) ) {
			echo ' <span class="ard-inferred">' . esc_html__( '(inferred)', 'axle-runtime-doctor' ) . '</span>';
		}
		echo '</p></div>';

		if ( '' !== (string) $result['value'] || '' !== (string) $result['expected'] ) {
			echo '<dl class="ard-values">';
			if ( '' !== (string) $result['value'] ) {
				echo '<div><dt>' . esc_html__( 'Value', 'axle-runtime-doctor' ) . '</dt><dd class="ard-value">' . esc_html( (string) $result['value'] ) . '</dd></div>';
			}
			if ( '' !== (string) $result['expected'] ) {
				echo '<div><dt>' . esc_html__( 'Expected', 'axle-runtime-doctor' ) . '</dt><dd class="ard-expected">' . esc_html( (string) $result['expected'] ) . '</dd></div>';
			}
			echo '</dl>';
		}

		echo '<details class="ard-why"><summary>' . esc_html__( 'Why it matters', 'axle-runtime-doctor' ) . '</summary>';
		echo '<p>' . esc_html( (string) $result['why'] ) . '</p>';
		if ( 'db_autoload' !== $id && ! empty( $result['details'] ) ) {
			echo '<ul class="ard-details">';
			foreach ( (array) $result['details'] as $line ) {
				echo '<li>' . esc_html( (string) $line ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</details>';

		if ( 'db_autoload' === $id ) {
			$this->autoload_table( $result );
		}

		if ( in_array( $status, array( Result::CRITICAL, Result::RECOMMENDED ), true ) ) {
			$this->fix( $result, $host );
		}
		echo '</div>';
	}

	/**
	 * "How to fix on <host>", with the support ticket.
	 *
	 * @param array $result The result.
	 * @param array $host   The host.
	 */
	private function fix( array $result, array $host ): void {
		$fix = $this->fixes->get( (string) $result['id'], $host );
		if ( ! $fix ) {
			return;
		}
		$ticket = $this->fixes->ticket( $result, $host );
		$id     = 'ard-ticket-' . (string) $result['id'];

		echo '<details class="ard-fix"><summary>';
		/* translators: %s: host name. */
		echo esc_html( sprintf( __( 'How to fix on %s', 'axle-runtime-doctor' ), (string) ( $host['label'] ?? '' ) ) );
		echo '</summary>';
		echo '<dl class="ard-values">';
		echo '<div><dt>' . esc_html__( 'Setting', 'axle-runtime-doctor' ) . '</dt><dd>' . esc_html( $fix['setting'] ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Where', 'axle-runtime-doctor' ) . '</dt><dd>' . esc_html( $fix['where'] ) . '</dd></div>';
		echo '</dl>';
		if ( $fix['steps'] ) {
			echo '<ol class="ard-steps">';
			foreach ( $fix['steps'] as $step ) {
				echo '<li>' . esc_html( str_replace( '{site}', home_url( '/' ), $step ) ) . '</li>';
			}
			echo '</ol>';
		}
		if ( '' !== $ticket ) {
			echo '<label for="' . esc_attr( $id ) . '" class="ard-ticket-label">' . esc_html__( 'Support ticket', 'axle-runtime-doctor' ) . '</label>';
			echo '<textarea id="' . esc_attr( $id ) . '" class="ard-ticket large-text" rows="9" readonly>' . esc_textarea( $ticket ) . '</textarea>';
			echo '<p><button type="button" class="button ard-copy" data-ard-copy="' . esc_attr( $id ) . '" data-check="' . esc_attr( (string) $result['id'] ) . '" data-host="' . esc_attr( (string) ( $host['id'] ?? '' ) ) . '">' . esc_html__( 'Copy support ticket', 'axle-runtime-doctor' ) . '</button></p>';
		}
		echo '</details>';
	}

	/**
	 * The biggest autoloaded options, with "Turn autoload off".
	 *
	 * @param array $result The db_autoload result.
	 */
	private function autoload_table( array $result ): void {
		$top = (array) ( $result['data']['top'] ?? array() );
		if ( ! $top ) {
			return;
		}
		$large      = (array) ( $result['data']['large'] ?? array() );
		$transients = (array) ( $result['data']['transients'] ?? array() );
		$inactive   = (array) ( $result['data']['inactive'] ?? array() );

		echo '<table class="widefat striped ard-autoload"><caption>' . esc_html__( 'Biggest autoloaded options', 'axle-runtime-doctor' ) . '</caption><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Option', 'axle-runtime-doctor' ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html__( 'Size', 'axle-runtime-doctor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Owner', 'axle-runtime-doctor' ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'axle-runtime-doctor' ) . '</span></th>';
		echo '</tr></thead><tbody>';
		foreach ( $top as $option ) {
			$name  = (string) $option['name'];
			$state = (string) ( $option['owner_status'] ?? '' );
			$flags = array();
			if ( in_array( $name, $large, true ) ) {
				$flags[] = __( 'over the size limit', 'axle-runtime-doctor' );
			}
			if ( 'inactive' === $state ) {
				$flags[] = __( 'plugin inactive', 'axle-runtime-doctor' );
			}
			echo '<tr data-option="' . esc_attr( $name ) . '"><td><code>' . esc_html( $name ) . '</code>';
			if ( $flags ) {
				echo ' <span class="ard-flag">' . esc_html( implode( ', ', $flags ) ) . '</span>';
			}
			echo '</td><td class="num">' . esc_html( size_format( (int) $option['size'], 1 ) ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $option['owner'] ? (string) $option['owner'] : __( 'Unknown', 'axle-runtime-doctor' ) ) . '</td><td>';
			if ( 'core' !== $state ) {
				$this->autoload_button( $name );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		foreach (
			array(
				array( $transients, __( 'Autoloaded transients', 'axle-runtime-doctor' ) ),
				array( array_diff( $inactive, array_column( $top, 'name' ) ), __( 'Autoloaded options of inactive plugins', 'axle-runtime-doctor' ) ),
			) as $list
		) {
			if ( $list[0] ) {
				echo '<h4>' . esc_html( $list[1] ) . '</h4><ul class="ard-option-list">';
				foreach ( $list[0] as $name ) {
					echo '<li><code>' . esc_html( (string) $name ) . '</code> ';
					$this->autoload_button( (string) $name );
					echo '</li>';
				}
				echo '</ul>';
			}
		}
	}

	/**
	 * A "Turn autoload off" button for one option.
	 *
	 * @param string $name Option name.
	 */
	private function autoload_button( string $name ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ard-inline-form">';
		wp_nonce_field( 'axle_runtime_doctor_autoload', '_wpnonce', true, true );
		echo '<input type="hidden" name="action" value="axle_runtime_doctor_autoload">';
		echo '<input type="hidden" name="option" value="' . esc_attr( $name ) . '">';
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Turn autoload off', 'axle-runtime-doctor' ) . '<span class="screen-reader-text"> ' . esc_html( $name ) . '</span></button>';
		echo '</form>';
	}

	/**
	 * The History tab.
	 */
	private function render_history(): void {
		$snapshots = $this->history->all();
		echo '<p>' . esc_html__( 'A snapshot is stored after each full run, and the checks run once a day on their own. A check that got worse is marked as a regression and emailed to the alert recipients.', 'axle-runtime-doctor' ) . '</p>';
		if ( ! $snapshots ) {
			echo '<p class="ard-empty">' . esc_html__( 'No snapshots yet.', 'axle-runtime-doctor' ) . '</p>';

			return;
		}

		$latest = end( $snapshots );
		$labels = array();
		foreach ( $this->registry->all() as $id => $check ) {
			$labels[ $id ] = $check->label();
		}

		echo '<h2>' . esc_html__( 'Each check over time', 'axle-runtime-doctor' ) . '</h2>';
		echo '<table class="widefat striped ard-history-checks"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Check', 'axle-runtime-doctor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status, oldest first', 'axle-runtime-doctor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Now', 'axle-runtime-doctor' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( (array) $latest['results'] as $id => $now ) {
			$regressed = in_array( $id, (array) ( $latest['regressions'] ?? array() ), true );
			echo '<tr class="' . ( $regressed ? 'ard-regressed' : '' ) . '" data-check="' . esc_attr( (string) $id ) . '"><th scope="row">' . esc_html( $labels[ $id ] ?? (string) $id );
			if ( $regressed ) {
				echo ' <span class="ard-flag">' . esc_html__( 'regression', 'axle-runtime-doctor' ) . '</span>';
			}
			echo '</th><td><ol class="ard-spark">';
			foreach ( $snapshots as $snapshot ) {
				$status = (string) ( $snapshot['results'][ $id ]['status'] ?? '' );
				$when   = wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) $snapshot['time'] );
				$label  = '' !== $status ? Result::status_label( $status ) : __( 'Not run', 'axle-runtime-doctor' );
				echo '<li class="ard-spark-dot ard-spark-dot--' . esc_attr( '' !== $status ? $status : 'none' ) . '" title="' . esc_attr( $when . ': ' . $label ) . '"><span class="screen-reader-text">' . esc_html( $when . ': ' . $label ) . '</span></li>';
			}
			echo '</ol></td>';
			echo '<td><span class="ard-badge ard-badge--' . esc_attr( (string) $now['status'] ) . '">' . esc_html( Result::status_label( (string) $now['status'] ) ) . '</span></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Snapshots', 'axle-runtime-doctor' ) . '</h2>';
		echo '<table class="widefat striped ard-snapshots"><thead><tr>';
		foreach ( array( __( 'Time', 'axle-runtime-doctor' ), __( 'Host', 'axle-runtime-doctor' ), __( 'Critical', 'axle-runtime-doctor' ), __( 'Recommended', 'axle-runtime-doctor' ), __( 'Good', 'axle-runtime-doctor' ), __( 'Regressions', 'axle-runtime-doctor' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$hosts = Hosts::labels();
		foreach ( array_reverse( $snapshots ) as $snapshot ) {
			$counts      = Runner::counts( (array) $snapshot['results'] );
			$regressions = array_map(
				static function ( $id ) use ( $labels ) {
					return $labels[ $id ] ?? $id;
				},
				(array) ( $snapshot['regressions'] ?? array() )
			);
			echo '<tr' . ( $regressions ? ' class="ard-regressed"' : '' ) . '>';
			echo '<td>' . esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) $snapshot['time'] ) ) . '</td>';
			echo '<td>' . esc_html( $hosts[ $snapshot['host'] ] ?? (string) $snapshot['host'] ) . '</td>';
			echo '<td>' . (int) $counts[ Result::CRITICAL ] . '</td><td>' . (int) $counts[ Result::RECOMMENDED ] . '</td><td>' . (int) $counts[ Result::GOOD ] . '</td>';
			echo '<td>' . esc_html( $regressions ? implode( ', ', $regressions ) : '-' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The Slow queries tab.
	 */
	private function render_slow_queries(): void {
		$report = $this->history->latest();
		$slow   = is_array( $report ) && isset( $report['slow_queries'] ) ? (array) $report['slow_queries'] : array();
		echo '<p>' . esc_html__( 'Each run loads the key pages of the site with query logging on and groups the queries by shape, with numbers and strings taken out. Slowest total time first.', 'axle-runtime-doctor' ) . '</p>';
		if ( ! $slow ) {
			echo '<p class="ard-empty">' . esc_html__( 'No queries measured yet. Run the checks first.', 'axle-runtime-doctor' ) . '</p>';

			return;
		}

		$limit = (float) ( $slow['threshold_ms'] ?? Settings::slow_query_ms() );
		echo '<h2>' . esc_html__( 'Pages loaded', 'axle-runtime-doctor' ) . '</h2><ul class="ard-pages">';
		foreach ( (array) ( $slow['probed'] ?? array() ) as $url ) {
			echo '<li><a href="' . esc_url( (string) $url ) . '">' . esc_html( (string) $url ) . '</a></li>';
		}
		foreach ( (array) ( $slow['failed'] ?? array() ) as $url ) {
			/* translators: %s: URL and error. */
			echo '<li>' . esc_html( sprintf( __( 'Failed: %s', 'axle-runtime-doctor' ), (string) $url ) ) . '</li>';
		}
		echo '</ul>';

		echo '<table class="widefat striped ard-slow"><caption>' . esc_html__( 'Queries by total time', 'axle-runtime-doctor' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Query', 'axle-runtime-doctor' ), __( 'Component', 'axle-runtime-doctor' ), __( 'Calls', 'axle-runtime-doctor' ), __( 'Total ms', 'axle-runtime-doctor' ), __( 'Average ms', 'axle-runtime-doctor' ), __( 'Slowest ms', 'axle-runtime-doctor' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array_slice( (array) ( $slow['digests'] ?? array() ), 0, 50 ) as $digest ) {
			$calls = max( 1, (int) $digest['calls'] );
			$over  = (float) $digest['max_ms'] >= $limit;
			echo '<tr' . ( $over ? ' class="ard-regressed"' : '' ) . '><td><code class="ard-sql">' . esc_html( (string) $digest['sql'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $digest['component'] ) . '</td>';
			echo '<td class="num">' . (int) $digest['calls'] . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( (float) $digest['total_ms'], 2 ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( (float) $digest['total_ms'] / $calls, 2 ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( (float) $digest['max_ms'], 2 ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The Settings tab.
	 */
	private function render_settings(): void {
		$settings   = $this->settings->all();
		$thresholds = $this->settings->thresholds();
		$detected   = $this->hosts->current();
		$labels     = array(
			'opcache_memory_pct'  => __( 'OPcache memory used (%)', 'axle-runtime-doctor' ),
			'opcache_strings_pct' => __( 'Interned strings buffer used (%)', 'axle-runtime-doctor' ),
			'opcache_keys_pct'    => __( 'OPcache file slots used (%)', 'axle-runtime-doctor' ),
			'opcache_hit_rate'    => __( 'Lowest OPcache hit rate (%)', 'axle-runtime-doctor' ),
			'object_hit_rate'     => __( 'Lowest object cache hit rate (%)', 'axle-runtime-doctor' ),
			'autoload_single_kb'  => __( 'Largest single autoloaded option (KB)', 'axle-runtime-doctor' ),
			'cron_late_minutes'   => __( 'Cron events late by (minutes)', 'axle-runtime-doctor' ),
			'loopback_ms'         => __( 'Slowest loopback (ms)', 'axle-runtime-doctor' ),
			'disk_free_mb'        => __( 'Least free disk space (MB)', 'axle-runtime-doctor' ),
			'eol_warning_days'    => __( 'Warn before end of support (days)', 'axle-runtime-doctor' ),
			'bootstrap_ms'        => __( 'Slowest plugin load when OPcache is hidden (ms)', 'axle-runtime-doctor' ),
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ard-settings">';
		wp_nonce_field( 'axle_runtime_doctor_settings' );
		echo '<input type="hidden" name="action" value="axle_runtime_doctor_settings">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="ard-host">' . esc_html__( 'Host', 'axle-runtime-doctor' ) . '</label></th><td>';
		echo '<select id="ard-host" name="host" aria-describedby="ard-host-help">';
		/* translators: %s: detected host name. */
		echo '<option value="auto"' . selected( 'auto', $settings['host'], false ) . '>' . esc_html( sprintf( __( 'Detect it (%s)', 'axle-runtime-doctor' ), $detected['overridden'] ? $detected['detected'] : $detected['label'] ) ) . '</option>';
		foreach ( Hosts::labels() as $id => $label ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $id, $settings['host'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description" id="ard-host-help">' . esc_html__( 'Picks which fixes and support tickets you see. Evidence:', 'axle-runtime-doctor' ) . ' ' . esc_html( implode( '; ', (array) $detected['evidence'] ) ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Front-end sampling', 'axle-runtime-doctor' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Front-end sampling', 'axle-runtime-doctor' ) . '</legend>';
		echo '<input type="hidden" name="sampling" value="0"><label><input type="checkbox" name="sampling" value="1"' . checked( ! empty( $settings['sampling'] ), true, false ) . '> ' . esc_html__( 'Sample front-end requests for the object cache hit rate and peak memory', 'axle-runtime-doctor' ) . '</label><br>';
		echo '<label for="ard-sample-rate">' . esc_html__( 'One request in', 'axle-runtime-doctor' ) . '</label> <input type="number" min="1" max="10000" class="small-text" id="ard-sample-rate" name="sample_rate" value="' . esc_attr( (string) $settings['sample_rate'] ) . '">';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'PHP patch level', 'axle-runtime-doctor' ) . '</th><td>';
		echo '<input type="hidden" name="patch_check" value="0"><label><input type="checkbox" name="patch_check" value="1" aria-describedby="ard-patch-help"' . checked( ! empty( $settings['patch_check'] ), true, false ) . '> ' . esc_html__( 'Compare PHP with the latest releases on php.net', 'axle-runtime-doctor' ) . '</label>';
		echo '<p class="description" id="ard-patch-help">' . esc_html__( 'Fetches the public release list from php.net once a week. Nothing about this site is sent. Off by default.', 'axle-runtime-doctor' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="ard-recipients">' . esc_html__( 'Alert recipients', 'axle-runtime-doctor' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="ard-recipients" name="recipients" aria-describedby="ard-recipients-help" value="' . esc_attr( (string) $settings['recipients'] ) . '">';
		echo '<p class="description" id="ard-recipients-help">' . esc_html__( 'Email addresses, separated by commas, for when a check gets worse. Empty: the site admin email.', 'axle-runtime-doctor' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Thresholds', 'axle-runtime-doctor' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		foreach ( $labels as $key => $label ) {
			echo '<tr><th scope="row"><label for="ard-t-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="number" step="any" min="0" class="small-text" id="ard-t-' . esc_attr( $key ) . '" name="thresholds[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $thresholds[ $key ] ) . '">';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'axle-runtime-doctor' ) );
		echo '</form>';
	}

	/**
	 * Checks the capability and nonce of a form.
	 *
	 * @param string $action Nonce action.
	 */
	private static function verify( string $action ): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'axle-runtime-doctor' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the screen with a message.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Query arguments.
	 */
	private static function back( string $tab, array $args ): void {
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/**
	 * Runs the checks from the form.
	 */
	public function handle_run(): void {
		self::verify( 'axle_runtime_doctor_run' );
		$this->runner->run( array( 'source' => 'screen' ) );
		self::back( 'checks', array( 'ard-message' => 'ran' ) );
	}

	/**
	 * Saves the settings.
	 */
	public function handle_settings(): void {
		self::verify( 'axle_runtime_doctor_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in self::verify().
		$input = array();
		foreach ( array( 'host', 'sampling', 'sample_rate', 'patch_check', 'recipients' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		if ( isset( $_POST['thresholds'] ) && is_array( $_POST['thresholds'] ) ) {
			$input['thresholds'] = array_map( 'sanitize_text_field', wp_unslash( $_POST['thresholds'] ) );
		}
		// phpcs:enable
		$this->settings->save( $input );
		self::back( 'settings', array( 'ard-message' => 'saved' ) );
	}

	/**
	 * Turns autoload off for one option, then checks autoload again.
	 */
	public function handle_autoload(): void {
		self::verify( 'axle_runtime_doctor_autoload' );
		$option = isset( $_POST['option'] ) ? sanitize_text_field( wp_unslash( $_POST['option'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in self::verify().
		$done   = $this->autoload->disable( array( $option ) );
		$this->refresh( 'db_autoload' );
		self::back(
			'checks',
			array(
				'ard-message' => $done['changed'] ? 'autoload' : 'unchanged',
				'ard-option'  => rawurlencode( $option ),
			)
		);
	}

	/**
	 * Dismisses the regression notice.
	 */
	public function handle_dismiss(): void {
		self::verify( 'axle_runtime_doctor_dismiss' );
		$this->alerts->dismiss();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url() );
		exit;
	}

	/**
	 * Runs one check again and updates it in the stored report.
	 *
	 * @param string $id Check ID.
	 */
	public function refresh( string $id ): void {
		$report = $this->history->latest();
		if ( ! $report ) {
			return;
		}
		$fresh = $this->runner->run_local(
			array(
				'checks' => array( $id ),
				'save'   => false,
			)
		);
		if ( isset( $fresh['results'][ $id ] ) ) {
			$report['results'][ $id ] = $fresh['results'][ $id ];
			$report['counts']         = Runner::counts( (array) $report['results'] );
			Store::set( History::REPORT, $report );
		}
	}
}
