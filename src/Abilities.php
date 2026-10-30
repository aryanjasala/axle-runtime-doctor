<?php
/**
 * Abilities: run the checks and read the report from AI agents and the REST API.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

use AryanX\Axle\RuntimeDoctor\Checks\Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Runtime Doctor's abilities on WordPress 6.9 and later. On older
 * WordPress nothing here runs.
 *
 * The read-only ones only store the snapshot of a run. disable-autoload writes,
 * so Axle Read-Only Mode refuses it during a freeze.
 */
final class Abilities implements Service {

	public const CATEGORY = 'axle-runtime-doctor';

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
	 * Sets up the abilities.
	 *
	 * @param Runner   $runner   Runs the checks.
	 * @param History  $history  Snapshots.
	 * @param Fixes    $fixes    Fix data.
	 * @param Hosts    $hosts    Host detection.
	 * @param Autoload $autoload Autoloaded options.
	 * @param Registry $registry The checks.
	 */
	public function __construct( Runner $runner, History $history, Fixes $fixes, Hosts $hosts, Autoload $autoload, Registry $registry ) {
		$this->runner   = $runner;
		$this->history  = $history;
		$this->fixes    = $fixes;
		$this->hosts    = $hosts;
		$this->autoload = $autoload;
		$this->registry = $registry;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle Runtime Doctor', 'axle-runtime-doctor' ),
				'description' => __( 'Check the server the site runs on (OPcache, object cache, PHP, database, cron, files) and get the fix for each problem.', 'axle-runtime-doctor' ),
			)
		);
	}

	/**
	 * Whether the current user may use the abilities.
	 *
	 * @return bool
	 */
	public static function can(): bool {
		return current_user_can( Settings::capability() );
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$report   = $this->report_schema();
		$readonly = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);

		$this->ability(
			'run-checks',
			__( 'Run runtime checks', 'axle-runtime-doctor' ),
			__( 'Runs the runtime checks inside a web request and returns each result: status (good, recommended, critical, info), the measured value, what it should be and details. A full run is stored as a snapshot; nothing else changes.', 'axle-runtime-doctor' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'checks' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Only these check IDs, such as opcache_coverage. Empty for every check.', 'axle-runtime-doctor' ),
					),
					'group'  => array(
						'type'        => 'string',
						'enum'        => array_merge( array( '' ), array_keys( Registry::groups() ) ),
						'description' => __( 'Only this group.', 'axle-runtime-doctor' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			$report,
			array( $this, 'run_checks' ),
			$readonly
		);

		$this->ability(
			'get-report',
			__( 'Get the latest runtime report', 'axle-runtime-doctor' ),
			__( 'The latest stored report: when it ran, the host, counts per status and every result. Empty results when the checks have not run yet.', 'axle-runtime-doctor' ),
			array(),
			$report,
			array( $this, 'get_report' ),
			$readonly
		);

		$this->ability(
			'list-slow-queries',
			__( 'List slow queries', 'axle-runtime-doctor' ),
			__( 'Queries measured on the key pages in the latest run, grouped by shape with values taken out, slowest total first, with the component that sent them.', 'axle-runtime-doctor' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 10,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'threshold_ms' => array( 'type' => 'number' ),
					'pages'        => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'queries'      => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'sql'       => array( 'type' => 'string' ),
								'component' => array( 'type' => 'string' ),
								'calls'     => array( 'type' => 'integer' ),
								'total_ms'  => array( 'type' => 'number' ),
								'max_ms'    => array( 'type' => 'number' ),
							),
						),
					),
				),
			),
			array( $this, 'list_slow_queries' ),
			$readonly
		);

		$this->ability(
			'get-fix',
			__( 'Get the fix for a check', 'axle-runtime-doctor' ),
			__( 'How to fix a check on a host: the setting, where to change it, the steps, and a support ticket filled in with the latest measured values.', 'axle-runtime-doctor' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'check' => array(
						'type'        => 'string',
						'description' => __( 'Check ID, such as opcache_coverage.', 'axle-runtime-doctor' ),
					),
					'host'  => array(
						'type'        => 'string',
						'description' => __( 'Host ID, such as pressable. Default: the detected host.', 'axle-runtime-doctor' ),
					),
				),
				'required'             => array( 'check' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'check'   => array( 'type' => 'string' ),
					'host'    => array( 'type' => 'string' ),
					'setting' => array( 'type' => 'string' ),
					'where'   => array( 'type' => 'string' ),
					'steps'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'ticket'  => array( 'type' => 'string' ),
				),
			),
			array( $this, 'get_fix' ),
			$readonly
		);

		$this->ability(
			'disable-autoload',
			__( 'Turn autoload off for options', 'axle-runtime-doctor' ),
			__( 'Stops WordPress from loading the given options on every request. The options and their values stay; they load when code asks for them. Safe to repeat.', 'axle-runtime-doctor' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'options' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'minItems' => 1,
						'maxItems' => 100,
					),
				),
				'required'             => array( 'options' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'changed' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'skipped' => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'string' ),
					),
				),
			),
			array( $this, 'disable_autoload' ),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Runs checks.
	 *
	 * @param array|null $input checks, group.
	 * @return array
	 */
	public function run_checks( $input = null ): array {
		$input = (array) $input;

		return self::shape(
			$this->runner->run(
				array(
					'checks' => array_map( 'strval', (array) ( $input['checks'] ?? array() ) ),
					'group'  => (string) ( $input['group'] ?? '' ),
					'source' => 'ability',
				)
			)
		);
	}

	/**
	 * The latest report.
	 *
	 * @return array
	 */
	public function get_report(): array {
		$report = $this->history->latest();

		return $report ? self::shape( $report ) : array(
			'time'    => 0,
			'host'    => (string) $this->hosts->current()['label'],
			'sapi'    => '',
			'counts'  => Runner::counts( array() ),
			'results' => array(),
		);
	}

	/**
	 * Slow queries from the latest report.
	 *
	 * @param array|null $input limit.
	 * @return array
	 */
	public function list_slow_queries( $input = null ): array {
		$input  = (array) $input;
		$limit  = max( 1, min( 50, (int) ( $input['limit'] ?? 10 ) ) );
		$report = $this->history->latest();
		$slow   = is_array( $report ) ? (array) ( $report['slow_queries'] ?? array() ) : array();
		$rows   = array();
		foreach ( array_slice( (array) ( $slow['digests'] ?? array() ), 0, $limit ) as $digest ) {
			$rows[] = array(
				'sql'       => (string) $digest['sql'],
				'component' => (string) $digest['component'],
				'calls'     => (int) $digest['calls'],
				'total_ms'  => (float) $digest['total_ms'],
				'max_ms'    => (float) $digest['max_ms'],
			);
		}

		return array(
			'threshold_ms' => (float) ( $slow['threshold_ms'] ?? Settings::slow_query_ms() ),
			'pages'        => array_map( 'strval', (array) ( $slow['probed'] ?? array() ) ),
			'queries'      => $rows,
		);
	}

	/**
	 * The fix for a check.
	 *
	 * @param array|null $input check, host.
	 * @return array|WP_Error
	 */
	public function get_fix( $input = null ) {
		$input = (array) $input;
		$check = sanitize_key( (string) ( $input['check'] ?? '' ) );
		$host  = self::host( $this->hosts->current(), (string) ( $input['host'] ?? '' ) );
		$fix   = $this->fixes->get( $check, $host );
		if ( ! $fix ) {
			/* translators: %s: check ID. */
			return new WP_Error( 'axle_runtime_doctor_no_fix', sprintf( __( 'There is no fix for "%s".', 'axle-runtime-doctor' ), $check ), array( 'status' => 404 ) );
		}

		$report = $this->history->latest();
		$result = $report['results'][ $check ] ?? null;
		if ( ! is_array( $result ) ) {
			$known  = $this->registry->get( $check );
			$result = array(
				'id'    => $check,
				'label' => $known ? $known->label() : $check,
			);
		}

		return array(
			'check'   => $check,
			'host'    => (string) $host['label'],
			'setting' => $fix['setting'],
			'where'   => $fix['where'],
			'steps'   => array_map(
				static function ( $step ) {
					return str_replace( '{site}', home_url( '/' ), $step );
				},
				$fix['steps']
			),
			'ticket'  => $this->fixes->ticket( $result, $host ),
		);
	}

	/**
	 * Turns autoload off.
	 *
	 * @param array|null $input options.
	 * @return array
	 */
	public function disable_autoload( $input = null ): array {
		$input = (array) $input;
		$done  = $this->autoload->disable( array_map( 'sanitize_text_field', (array) ( $input['options'] ?? array() ) ) );

		return array(
			'changed' => $done['changed'],
			'skipped' => (object) $done['skipped'],
		);
	}

	/**
	 * The detected host, or another one by ID.
	 *
	 * @param array  $detected The detected host.
	 * @param string $id       Host ID. Empty for the detected one.
	 * @return array
	 */
	public static function host( array $detected, string $id ): array {
		$id     = sanitize_key( $id );
		$labels = Hosts::labels();
		if ( '' === $id || ! isset( $labels[ $id ] ) ) {
			return $detected;
		}
		$detectors = Hosts::detectors();

		return array(
			'id'     => $id,
			'label'  => $labels[ $id ],
			'type'   => (string) ( $detectors[ $id ]['type'] ?? 'server' ),
			'server' => in_array( $id, array( 'apache', 'nginx', 'litespeed' ), true ) ? $id : (string) ( $detected['server'] ?? '' ),
		);
	}

	/**
	 * A report shaped for the output schema.
	 *
	 * @param array $report The report.
	 * @return array
	 */
	public static function shape( array $report ): array {
		$results = array();
		foreach ( (array) ( $report['results'] ?? array() ) as $result ) {
			$results[] = array(
				'id'       => (string) $result['id'],
				'group'    => (string) $result['group'],
				'label'    => (string) $result['label'],
				'status'   => (string) $result['status'],
				'summary'  => (string) $result['summary'],
				'value'    => (string) $result['value'],
				'expected' => (string) $result['expected'],
				'details'  => array_map( 'strval', (array) $result['details'] ),
				'inferred' => ! empty( $result['inferred'] ),
			);
		}

		$shaped = array(
			'time'    => (int) ( $report['time'] ?? 0 ),
			'host'    => (string) ( $report['host']['label'] ?? '' ),
			'sapi'    => (string) ( $report['sapi'] ?? '' ),
			'counts'  => (array) ( $report['counts'] ?? Runner::counts( array() ) ),
			'results' => $results,
		);
		if ( ! empty( $report['probe_error'] ) ) {
			$shaped['probe_error'] = (string) $report['probe_error'];
		}

		return $shaped;
	}

	/**
	 * Output schema of a report.
	 *
	 * @return array
	 */
	private function report_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'time'        => array(
					'type'        => 'integer',
					'description' => 'Unix time of the run. 0 when the checks have not run yet.',
				),
				'host'        => array( 'type' => 'string' ),
				'sapi'        => array(
					'type'        => 'string',
					'description' => 'web when the checks ran in the web server, cli when the probe failed and they ran in WP-CLI.',
				),
				'probe_error' => array( 'type' => 'string' ),
				'counts'      => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'integer' ),
				),
				'results'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array( 'type' => 'string' ),
							'group'    => array( 'type' => 'string' ),
							'label'    => array( 'type' => 'string' ),
							'status'   => array(
								'type' => 'string',
								'enum' => Result::statuses(),
							),
							'summary'  => array( 'type' => 'string' ),
							'value'    => array( 'type' => 'string' ),
							'expected' => array( 'type' => 'string' ),
							'details'  => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'inferred' => array( 'type' => 'boolean' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Registers one ability in the category, public for MCP.
	 *
	 * @param string   $name        Name after the slash.
	 * @param string   $label       Label.
	 * @param string   $description What it does.
	 * @param array    $input       Input schema. Empty for none.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, array $annotations ): void {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'output_schema'       => $output,
			'execute_callback'    => $execute,
			'permission_callback' => array( self::class, 'can' ),
			'meta'                => array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				// Clients such as the MCP adapter only list abilities marked public:
				// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			),
		);
		if ( $input ) {
			$args['input_schema'] = $input;
		}

		wp_register_ability( self::CATEGORY . '/' . $name, $args );
	}
}
