<?php
/**
 * WP-CLI commands.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Cli;

use AryanX\Axle\RuntimeDoctor\Abilities;
use AryanX\Axle\RuntimeDoctor\Autoload;
use AryanX\Axle\RuntimeDoctor\Fixes;
use AryanX\Axle\RuntimeDoctor\History;
use AryanX\Axle\RuntimeDoctor\Hosts;
use AryanX\Axle\RuntimeDoctor\Result;
use AryanX\Axle\RuntimeDoctor\Runner;
use AryanX\Axle\RuntimeDoctor\Settings;
use AryanX\Axle\RuntimeDoctor\Checks\Registry;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Check the server WordPress runs on, from inside a real web request.
 *
 * WP-CLI runs in a different PHP than visitors get, so the checks run in the
 * web server through a loopback probe. If the site cannot reach itself, the
 * command says so and shows what WP-CLI can see.
 *
 * ## EXAMPLES
 *
 *     wp axle-runtime-doctor check
 *     wp axle-runtime-doctor check opcache_coverage --format=json
 *     wp axle-runtime-doctor check --group=opcache --status=critical,recommended
 *     wp axle-runtime-doctor fix opcache_coverage --host=pressable
 *     wp axle-runtime-doctor autoload off my_big_option
 */
final class Command {

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
	 * The checks.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Sets up the command.
	 *
	 * @param Settings $settings Settings.
	 * @param Runner   $runner   Runs the checks.
	 * @param History  $history  Snapshots.
	 * @param Fixes    $fixes    Fix data.
	 * @param Hosts    $hosts    Host detection.
	 * @param Autoload $autoload Autoloaded options.
	 * @param Registry $registry The checks.
	 */
	public function __construct( Settings $settings, Runner $runner, History $history, Fixes $fixes, Hosts $hosts, Autoload $autoload, Registry $registry ) {
		$this->settings = $settings;
		$this->runner   = $runner;
		$this->history  = $history;
		$this->fixes    = $fixes;
		$this->hosts    = $hosts;
		$this->autoload = $autoload;
		$this->registry = $registry;
	}

	/**
	 * Runs the checks in the web server and lists the results. Exits with 1 when one is critical.
	 *
	 * A full run (no check IDs, no group) is stored as a snapshot, like the daily run.
	 *
	 * ## OPTIONS
	 *
	 * [<check-id>...]
	 * : Only these checks.
	 *
	 * [--group=<group>]
	 * : Only this group.
	 * ---
	 * options:
	 *   - opcache
	 *   - object_cache
	 *   - php
	 *   - database
	 *   - cron
	 *   - filesystem
	 * ---
	 *
	 * [--status=<statuses>]
	 * : Only results with these statuses, comma separated: critical, recommended, good, info.
	 *
	 * [--fields=<fields>]
	 * : Fields to show. Default: id,status,summary,value.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args       Check IDs.
	 * @param array $assoc_args Options.
	 */
	public function check( $args, $assoc_args ): void {
		$report = $this->runner->run(
			array(
				'checks' => array_map( 'strval', (array) $args ),
				'group'  => (string) ( $assoc_args['group'] ?? '' ),
				'source' => 'cli',
			)
		);
		$this->where( $report, (string) ( $assoc_args['format'] ?? 'table' ) );

		$rows = self::rows( (array) $report['results'], (string) ( $assoc_args['status'] ?? '' ) );
		Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, self::fields( $assoc_args, 'id,status,summary,value' ) );

		if ( (int) ( $report['counts'][ Result::CRITICAL ] ?? 0 ) > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Shows the latest stored report.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<statuses>]
	 * : Only results with these statuses, comma separated.
	 *
	 * [--fields=<fields>]
	 * : Fields to show. Default: id,status,summary,value.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function report( $args, $assoc_args ): void {
		unset( $args );
		$report = $this->history->latest();
		if ( ! $report ) {
			WP_CLI::error( 'The checks have not run yet. Run: wp axle-runtime-doctor check' );
		}
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'Last run: %s UTC, host: %s', gmdate( 'Y-m-d H:i', (int) $report['time'] ), (string) $report['host']['label'] ) );
		}
		Utils\format_items( $format, self::rows( (array) $report['results'], (string) ( $assoc_args['status'] ?? '' ) ), self::fields( $assoc_args, 'id,status,summary,value' ) );
	}

	/**
	 * Lists the stored snapshots, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function history( $args, $assoc_args ): void {
		unset( $args );
		$rows = array();
		foreach ( array_reverse( $this->history->all() ) as $snapshot ) {
			$counts = Runner::counts( (array) $snapshot['results'] );
			$rows[] = array(
				'time'        => gmdate( 'Y-m-d H:i:s', (int) $snapshot['time'] ),
				'host'        => (string) $snapshot['host'],
				'sapi'        => (string) $snapshot['sapi'],
				'critical'    => $counts[ Result::CRITICAL ],
				'recommended' => $counts[ Result::RECOMMENDED ],
				'good'        => $counts[ Result::GOOD ],
				'regressions' => implode( ',', (array) ( $snapshot['regressions'] ?? array() ) ),
			);
		}
		Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'time', 'host', 'sapi', 'critical', 'recommended', 'good', 'regressions' ) );
	}

	/**
	 * Lists the queries measured on the key pages in the latest run, slowest total first.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Most rows. Default 20.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @subcommand slow-queries
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function slow_queries( $args, $assoc_args ): void {
		unset( $args );
		$report = $this->history->latest();
		$slow   = is_array( $report ) ? (array) ( $report['slow_queries'] ?? array() ) : array();
		$rows   = array();
		foreach ( array_slice( (array) ( $slow['digests'] ?? array() ), 0, max( 1, (int) ( $assoc_args['limit'] ?? 20 ) ) ) as $digest ) {
			$rows[] = array(
				'total_ms'  => (float) $digest['total_ms'],
				'calls'     => (int) $digest['calls'],
				'max_ms'    => (float) $digest['max_ms'],
				'component' => (string) $digest['component'],
				'sql'       => (string) $digest['sql'],
			);
		}
		Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'total_ms', 'calls', 'max_ms', 'component', 'sql' ) );
	}

	/**
	 * Prints how to fix a check, with the support ticket.
	 *
	 * ## OPTIONS
	 *
	 * <check-id>
	 * : The check, such as opcache_coverage.
	 *
	 * [--host=<host>]
	 * : The host, such as pressable. Default: the detected host. See wp axle-runtime-doctor hosts --all.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * @param array $args       Check ID.
	 * @param array $assoc_args Options.
	 */
	public function fix( $args, $assoc_args ): void {
		$abilities = new Abilities( $this->runner, $this->history, $this->fixes, $this->hosts, $this->autoload, $this->registry );
		$fix       = $abilities->get_fix(
			array(
				'check' => (string) $args[0],
				'host'  => (string) ( $assoc_args['host'] ?? '' ),
			)
		);
		if ( is_wp_error( $fix ) ) {
			WP_CLI::error( $fix->get_error_message() );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			WP_CLI::line( (string) wp_json_encode( $fix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			return;
		}

		WP_CLI::line( sprintf( 'How to fix %s on %s', $fix['check'], $fix['host'] ) );
		WP_CLI::line( 'Setting: ' . $fix['setting'] );
		WP_CLI::line( 'Where:   ' . $fix['where'] );
		foreach ( $fix['steps'] as $number => $step ) {
			WP_CLI::line( sprintf( '%d. %s', $number + 1, $step ) );
		}
		if ( '' !== $fix['ticket'] ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Support ticket:' );
			WP_CLI::line( $fix['ticket'] );
		}
	}

	/**
	 * Turns autoload off for options.
	 *
	 * ## OPTIONS
	 *
	 * <off>
	 * : The word "off".
	 *
	 * <option>...
	 * : Option names.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-runtime-doctor autoload off my_plugin_cache my_plugin_log
	 *
	 * @param array $args       "off" and option names.
	 * @param array $assoc_args Unused.
	 */
	public function autoload( $args, $assoc_args ): void {
		unset( $assoc_args );
		if ( 'off' !== ( $args[0] ?? '' ) || count( $args ) < 2 ) {
			WP_CLI::error( 'Usage: wp axle-runtime-doctor autoload off <option>...' );
		}
		$done = $this->autoload->disable( array_slice( $args, 1 ) );
		foreach ( $done['changed'] as $name ) {
			WP_CLI::log( sprintf( 'Autoload off: %s', $name ) );
		}
		foreach ( $done['skipped'] as $name => $reason ) {
			WP_CLI::warning( sprintf( '%s: %s', $name, $reason ) );
		}
		if ( $done['changed'] ) {
			WP_CLI::success( sprintf( 'Turned autoload off for %d option(s).', count( $done['changed'] ) ) );
		}
	}

	/**
	 * Shows the detected host and the evidence, or every known host.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : List every host the plugin knows.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function hosts( $args, $assoc_args ): void {
		unset( $args );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( ! empty( $assoc_args['all'] ) ) {
			$rows = array();
			foreach ( Hosts::detectors() as $id => $detector ) {
				$rows[] = array(
					'id'    => (string) $id,
					'label' => (string) ( $detector['label'] ?? $id ),
					'type'  => (string) ( $detector['type'] ?? '' ),
				);
			}
			Utils\format_items( $format, $rows, array( 'id', 'label', 'type' ) );

			return;
		}

		// The web server's view: WP-CLI has no SERVER_SOFTWARE and may see other constants.
		$report = $this->runner->run(
			array(
				'checks' => array( '-' ),
				'source' => 'cli',
			)
		);
		if ( ! empty( $report['probe_error'] ) ) {
			WP_CLI::warning( sprintf( 'Could not reach the site through the loopback probe (%s). This is what WP-CLI sees.', (string) $report['probe_error'] ) );
		}
		$host = (array) $report['host'];
		if ( in_array( $format, array( 'json', 'yaml' ), true ) ) {
			Utils\format_items( $format, array( $host ), array_keys( $host ) );

			return;
		}
		$rows = array();
		foreach ( $host['evidence'] as $line ) {
			$rows[] = array(
				'host'     => $host['label'] . ( $host['overridden'] ? ' (set in the settings)' : '' ),
				'server'   => $host['server'],
				'evidence' => $line,
			);
		}
		Utils\format_items( $format, $rows, array( 'host', 'server', 'evidence' ) );
	}

	/**
	 * Shows or changes the settings.
	 *
	 * ## OPTIONS
	 *
	 * [--host=<host>]
	 * : Host ID, or auto to detect it.
	 *
	 * [--sampling=<on|off>]
	 * : Sample front-end requests.
	 *
	 * [--sample-rate=<n>]
	 * : Sample 1 in N front-end requests.
	 *
	 * [--patch-check=<on|off>]
	 * : Compare PHP with php.net's latest releases.
	 *
	 * [--recipients=<emails>]
	 * : Alert recipients, comma separated. Empty for the admin email.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function settings( $args, $assoc_args ): void {
		unset( $args );
		$input = array();
		if ( isset( $assoc_args['host'] ) ) {
			$host = sanitize_key( (string) $assoc_args['host'] );
			if ( 'auto' !== $host && ! isset( Hosts::labels()[ $host ] ) ) {
				WP_CLI::error( sprintf( 'Unknown host "%s". See: wp axle-runtime-doctor hosts --all', $host ) );
			}
			$input['host'] = $host;
		}
		foreach ( array(
			'sampling'    => 'sampling',
			'patch-check' => 'patch_check',
		) as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $key ] = in_array( strtolower( (string) $assoc_args[ $flag ] ), array( 'on', '1', 'yes', 'true' ), true );
			}
		}
		if ( isset( $assoc_args['sample-rate'] ) ) {
			$input['sample_rate'] = (int) $assoc_args['sample-rate'];
		}
		if ( isset( $assoc_args['recipients'] ) ) {
			$input['recipients'] = (string) $assoc_args['recipients'];
		}

		$settings = $input ? $this->settings->save( $input ) : $this->settings->all();
		if ( $input ) {
			WP_CLI::success( 'Settings saved.' );
		}
		$rows = array();
		foreach ( $settings as $key => $value ) {
			$rows[] = array(
				'setting' => $key,
				'value'   => is_scalar( $value ) ? ( is_bool( $value ) ? ( $value ? 'on' : 'off' ) : (string) $value ) : (string) wp_json_encode( $value ),
			);
		}
		Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'setting', 'value' ) );
	}

	/**
	 * Says where the checks ran.
	 *
	 * @param array  $report The report.
	 * @param string $format Output format.
	 */
	private function where( array $report, string $format ): void {
		if ( ! empty( $report['probe_error'] ) ) {
			WP_CLI::warning( sprintf( 'Could not reach the site through the loopback probe (%s). These are WP-CLI values, not the web server\'s; checks that need the web server were skipped.', (string) $report['probe_error'] ) );
		} elseif ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'Ran in the web server (PHP %s) through the loopback probe. Host: %s.', (string) ( $report['php'] ?? '' ), (string) ( $report['host']['label'] ?? '' ) ) );
		}
	}

	/**
	 * Results as rows, filtered by status.
	 *
	 * @param array  $results  Results.
	 * @param string $statuses Comma-separated statuses. Empty for all.
	 * @return array[]
	 */
	private static function rows( array $results, string $statuses ): array {
		$keep = array_filter( array_map( 'trim', explode( ',', $statuses ) ) );
		$rows = array();
		foreach ( $results as $result ) {
			if ( $keep && ! in_array( $result['status'], $keep, true ) ) {
				continue;
			}
			$rows[] = array(
				'id'       => (string) $result['id'],
				'group'    => (string) $result['group'],
				'status'   => (string) $result['status'],
				'summary'  => (string) $result['summary'],
				'value'    => (string) $result['value'],
				'expected' => (string) $result['expected'],
				'details'  => implode( ' | ', (array) $result['details'] ),
				'inferred' => ! empty( $result['inferred'] ) ? 'yes' : 'no',
			);
		}

		return $rows;
	}

	/**
	 * Fields to show.
	 *
	 * @param array  $assoc_args Options.
	 * @param string $fallback   Default fields.
	 * @return string[]
	 */
	private static function fields( array $assoc_args, string $fallback ): array {
		return array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['fields'] ?? $fallback ) ) ) );
	}
}
