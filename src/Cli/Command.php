<?php
/**
 * WP-CLI commands.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Cli;

use AryanX\Axle\RuntimeDoctor\Abilities;
use AryanX\Axle\RuntimeDoctor\Autoload;
use AryanX\Axle\RuntimeDoctor\Compare\Comparer;
use AryanX\Axle\RuntimeDoctor\Compare\Direct;
use AryanX\Axle\RuntimeDoctor\Compare\Exporter;
use AryanX\Axle\RuntimeDoctor\Compare\Importer;
use AryanX\Axle\RuntimeDoctor\Compare\Library;
use AryanX\Axle\RuntimeDoctor\PageCache\Scanner;
use AryanX\Axle\RuntimeDoctor\Fixes;
use AryanX\Axle\RuntimeDoctor\History;
use AryanX\Axle\RuntimeDoctor\Hosts;
use AryanX\Axle\RuntimeDoctor\Result;
use AryanX\Axle\RuntimeDoctor\Runner;
use AryanX\Axle\RuntimeDoctor\Settings;
use AryanX\Axle\RuntimeDoctor\Checks\PageCache;
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
 *     wp axle-runtime-doctor page-cache
 *     wp axle-runtime-doctor export --file=production.json
 *     wp axle-runtime-doctor compare production.json
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
	 * Kept reports from other sites.
	 *
	 * @var Library
	 */
	private Library $library;

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
	 * @param Library  $library  Kept reports from other sites.
	 */
	public function __construct( Settings $settings, Runner $runner, History $history, Fixes $fixes, Hosts $hosts, Autoload $autoload, Registry $registry, Library $library ) {
		$this->settings = $settings;
		$this->runner   = $runner;
		$this->history  = $history;
		$this->fixes    = $fixes;
		$this->hosts    = $hosts;
		$this->autoload = $autoload;
		$this->registry = $registry;
		$this->library  = $library;
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
	 * Tests whether a page cache answers visitors, and what stops it.
	 *
	 * Asks the home page, the latest post and a page twice, with no cookies, and
	 * reads the response headers. Pages that send a cookie, no-store, Vary: Cookie
	 * or a PHP session are loaded once more, and the page traces who did it. A
	 * plugin is named only when the trace saw it or only one plugin could have.
	 *
	 * ## OPTIONS
	 *
	 * [--stored]
	 * : Show the last test instead of running a new one.
	 *
	 * [--fields=<fields>]
	 * : Fields to show. Default: url,status,answered,by,first_ms,second_ms,stops.
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
	 * ## EXAMPLES
	 *
	 *     wp axle-runtime-doctor page-cache
	 *     wp axle-runtime-doctor page-cache --stored --format=json
	 *
	 * @subcommand page-cache
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function page_cache( $args, $assoc_args ): void {
		unset( $args );
		$scan = Utils\get_flag_value( $assoc_args, 'stored', false ) ? Scanner::latest() : Scanner::scan();
		if ( ! $scan ) {
			WP_CLI::error( Utils\get_flag_value( $assoc_args, 'stored', false ) ? 'The page cache has not been tested yet. Run: wp axle-runtime-doctor page-cache' : 'The page cache test is switched off (filter axle_runtime_doctor_page_cache_enabled).' );
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' === $format ) {
			$result = PageCache::from_scan( $scan );
			if ( $result ) {
				WP_CLI::log( sprintf( '%s: %s (%s)', strtoupper( $result->status ), $result->summary, $result->value ) );
				foreach ( $result->details as $line ) {
					WP_CLI::log( '  - ' . $line );
				}
			}
		}
		Utils\format_items( $format, Scanner::rows( $scan ), self::fields( $assoc_args, 'url,status,answered,by,first_ms,second_ms,stops' ) );
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
		$abilities = new Abilities( $this->runner, $this->history, $this->fixes, $this->hosts, $this->autoload, $this->registry, $this->library );
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
	 * Turns autoload off for options, or back on to undo it.
	 *
	 * Options WordPress itself needs on every request are skipped.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : off or on.
	 *
	 * <option>...
	 * : Option names.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-runtime-doctor autoload off my_plugin_cache my_plugin_log
	 *     wp axle-runtime-doctor autoload on my_plugin_cache
	 *
	 * @param array $args       "off" or "on", and option names.
	 * @param array $assoc_args Unused.
	 */
	public function autoload( $args, $assoc_args ): void {
		unset( $assoc_args );
		$state = (string) ( $args[0] ?? '' );
		if ( ! in_array( $state, array( 'off', 'on' ), true ) || count( $args ) < 2 ) {
			WP_CLI::error( 'Usage: wp axle-runtime-doctor autoload <off|on> <option>...' );
		}
		$done = 'off' === $state ? $this->autoload->disable( array_slice( $args, 1 ) ) : $this->autoload->enable( array_slice( $args, 1 ) );
		foreach ( $done['changed'] as $name ) {
			WP_CLI::log( sprintf( 'Autoload %s: %s', $state, $name ) );
		}
		foreach ( $done['skipped'] as $name => $reason ) {
			WP_CLI::warning( sprintf( '%s: %s', $name, $reason ) );
		}
		if ( $done['changed'] ) {
			WP_CLI::success( sprintf( 'Turned autoload %s for %d option(s).', $state, count( $done['changed'] ) ) );
		}
	}

	/**
	 * Exports this site's runtime report as JSON, to compare with another site.
	 *
	 * Paths, hostnames, email addresses and IP addresses are replaced. The
	 * latest stored report is used. Without one, or with --fresh, the checks
	 * run first, in the web server, and are stored like any full run.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : Write the JSON to this file. Default: print it.
	 *
	 * [--fresh]
	 * : Run the checks first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-runtime-doctor export --file=production.json
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ): void {
		unset( $args );
		$report = $this->report_for_export( ! empty( $assoc_args['fresh'] ) );
		$json   = Exporter::to_json( Exporter::publish( $report, 'cli' ) );
		if ( ! empty( $assoc_args['file'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- WP-CLI writes a file the person named.
			if ( false === file_put_contents( (string) $assoc_args['file'], $json . "\n" ) ) {
				WP_CLI::error( 'Could not write ' . (string) $assoc_args['file'] );
			}
			WP_CLI::success( 'Wrote ' . (string) $assoc_args['file'] );

			return;
		}
		WP_CLI::line( $json );
	}

	/**
	 * Lists what differs between this site and another site's report.
	 *
	 * The other report is a file made by `export` on the other site, or the ID
	 * of a report kept with `import`. Differences come first, each with what it
	 * means. Exits with 1 when something differs, so a script can notice.
	 *
	 * ## OPTIONS
	 *
	 * <file-or-id>
	 * : The report file, or the ID of a kept report.
	 *
	 * [--all]
	 * : Also list the rows that are the same.
	 *
	 * [--fresh]
	 * : Run the checks on this site first.
	 *
	 * [--fields=<fields>]
	 * : Fields to show. Default: state,type,key,here,there,meaning.
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
	 * ## EXAMPLES
	 *
	 *     wp axle-runtime-doctor compare production.json
	 *     wp axle-runtime-doctor compare 4kd8sjw2ma --format=json
	 *
	 * @param array $args       File or ID.
	 * @param array $assoc_args Options.
	 */
	public function compare( $args, $assoc_args ): void {
		$other = $this->load_report( (string) $args[0] );
		$name  = $other['name'];
		$here  = Exporter::build( $this->report_for_export( ! empty( $assoc_args['fresh'] ) ) );

		$result = Comparer::compare( $here, $other['report'], 'This site', $name );
		$rows   = array();
		foreach ( $result['rows'] as $row ) {
			if ( empty( $assoc_args['all'] ) && Comparer::SAME === $row['state'] ) {
				continue;
			}
			$rows[] = array(
				'state'   => $row['state'],
				'type'    => $row['type'],
				'key'     => $row['key'],
				'here'    => self::side( $row['a'] ),
				'there'   => self::side( $row['b'] ),
				'meaning' => $row['meaning'],
			);
		}

		Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, self::fields( $assoc_args, 'state,type,key,here,there,meaning' ) );

		$differ = $result['counts'][ Comparer::DIFFERENT ] + $result['counts'][ Comparer::ONLY ];
		WP_CLI::debug( sprintf( '%d difference(s)', $differ ), 'axle-runtime-doctor' );
		if ( $differ ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Keeps a report from another site, for comparing later.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : A file made by `export` on the other site.
	 *
	 * [--name=<name>]
	 * : What to call it. Default: the file name.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-runtime-doctor import production.json --name="Before the migration"
	 *
	 * @param array $args       File.
	 * @param array $assoc_args Options.
	 */
	public function import( $args, $assoc_args ): void {
		$file = (string) $args[0];
		if ( ! is_readable( $file ) || filesize( $file ) > Importer::max_bytes() ) {
			WP_CLI::error( 'Cannot read that file, or it is larger than ' . size_format( Importer::max_bytes() ) . '.' );
		}
		$document = Importer::parse( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- WP-CLI reads a local file the person named, size-checked above.
		if ( is_wp_error( $document ) ) {
			WP_CLI::error( $document->get_error_message() );
		}
		$item = $this->library->add( $document, (string) ( $assoc_args['name'] ?? pathinfo( $file, PATHINFO_FILENAME ) ), 'file' );
		if ( is_wp_error( $item ) ) {
			WP_CLI::error( $item->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Kept "%s" as %s.', $item['name'], $item['id'] ) );
	}

	/**
	 * Fetches a report from another site that runs this plugin, with its one-time code.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : The code made on the other site, with `direct-code` or on the Compare tab.
	 *
	 * [--name=<name>]
	 * : What to call it. Default: the other site's address.
	 *
	 * @param array $args       Code.
	 * @param array $assoc_args Options.
	 */
	public function fetch( $args, $assoc_args ): void {
		$document = Direct::fetch( (string) $args[0] );
		if ( is_wp_error( $document ) ) {
			WP_CLI::error( $document->get_error_message() );
		}
		$item = $this->library->add( $document, (string) ( $assoc_args['name'] ?? Direct::site_name( (string) $args[0] ) ), 'direct' );
		if ( is_wp_error( $item ) ) {
			WP_CLI::error( $item->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Kept "%s" as %s.', $item['name'], $item['id'] ) );
	}

	/**
	 * Makes a one-time code that lets another site fetch this site's report.
	 *
	 * The code works once and stops after a few minutes.
	 *
	 * @subcommand direct-code
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Unused.
	 */
	public function direct_code( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		$made = Direct::create_code();
		if ( is_wp_error( $made ) ) {
			WP_CLI::error( $made->get_error_message() );
		}
		WP_CLI::line( $made['code'] );
		WP_CLI::log( sprintf( 'Works once, until %s.', gmdate( 'H:i', $made['expires'] ) . ' UTC' ) );
	}

	/**
	 * Lists the kept reports from other sites.
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
	 * ---
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function imports( $args, $assoc_args ): void {
		unset( $args );
		$rows = array();
		foreach ( $this->library->all() as $item ) {
			$rows[] = array(
				'id'     => $item['id'],
				'name'   => $item['name'],
				'added'  => gmdate( 'Y-m-d H:i', (int) $item['added'] ),
				'source' => $item['source'],
				'host'   => (string) ( $item['report']['host']['label'] ?? '' ),
			);
		}
		Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, 'id,name,added,source,host' );
	}

	/**
	 * Removes a kept report.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID, from `imports`.
	 *
	 * @subcommand remove-import
	 *
	 * @param array $args       ID.
	 * @param array $assoc_args Unused.
	 */
	public function remove_import( $args, $assoc_args ): void {
		unset( $assoc_args );
		if ( ! $this->library->remove( sanitize_key( (string) $args[0] ) ) ) {
			WP_CLI::error( 'There is no kept report with that ID.' );
		}
		WP_CLI::success( 'Removed.' );
	}

	/**
	 * The report to export or compare: the latest, or a fresh run.
	 *
	 * @param bool $fresh Run the checks first.
	 * @return array
	 */
	private function report_for_export( bool $fresh ): array {
		$report = $fresh ? null : $this->history->latest();
		if ( ! $report ) {
			$report = $this->runner->run( array( 'source' => 'cli' ) );
		}

		return $report;
	}

	/**
	 * A report from a file, or a kept one by ID.
	 *
	 * @param string $source File path or ID.
	 * @return array{name: string, report: array}
	 */
	private function load_report( string $source ): array {
		$item = $this->library->get( sanitize_key( $source ) );
		if ( $item ) {
			return array(
				'name'   => (string) $item['name'],
				'report' => (array) $item['report'],
			);
		}
		if ( ! is_readable( $source ) || filesize( $source ) > Importer::max_bytes() ) {
			WP_CLI::error( 'Cannot read that file, it is larger than ' . size_format( Importer::max_bytes() ) . ', and it is not the ID of a kept report.' );
		}
		$document = Importer::parse( (string) file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- WP-CLI reads a local file the person named, size-checked above.
		if ( is_wp_error( $document ) ) {
			WP_CLI::error( $document->get_error_message() );
		}

		return array(
			'name'   => basename( $source ),
			'report' => $document,
		);
	}

	/**
	 * One side of a compared row, as text.
	 *
	 * @param array|null $side Result or setting.
	 * @return string
	 */
	private static function side( ?array $side ): string {
		if ( null === $side ) {
			return '';
		}
		$value = (string) ( $side['value'] ?? '' );

		return isset( $side['status'] ) ? trim( (string) $side['status'] . ': ' . $value, ': ' ) : $value;
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
