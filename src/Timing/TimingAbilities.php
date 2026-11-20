<?php
/**
 * Abilities for page timings: read them, time the pages, confirm one update.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Timing;

use AryanX\Axle\RuntimeDoctor\Abilities;
use AryanX\Axle\RuntimeDoctor\Service;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers get-timings (read only), run-timing and confirm-update on
 * WordPress 6.9 and later, in the category Abilities registers. run-timing
 * and confirm-update are not read only: they load pages from the server and
 * store the result, so Axle Read-Only Mode refuses them during a freeze.
 */
final class TimingAbilities implements Service {

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
	 * Sets up the abilities.
	 *
	 * @param Timings $timings  Page timings.
	 * @param Confirm $confirm  Leaves a plugin out of one request.
	 */
	public function __construct( Timings $timings, Confirm $confirm ) {
		$this->timings = $timings;
		$this->confirm = $confirm;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		}
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$timing = array(
			'type'       => 'object',
			'properties' => array(
				'runs'      => array(
					'type'        => 'array',
					'description' => __( 'Timing runs, newest first: id, time, source, pages (path to label, median, status, cached), updates, regressions.', 'axle-runtime-doctor' ),
				),
				'slowdowns' => array(
					'type'        => 'array',
					'description' => __( 'Pages that got slower in the latest run: label, before, after, delta, percent, updates and a sentence naming the updates.', 'axle-runtime-doctor' ),
				),
				'updates'   => array(
					'type'        => 'array',
					'description' => __( 'Updates logged, newest first: id, time, type, name, from, to.', 'axle-runtime-doctor' ),
				),
			),
		);

		$this->ability(
			'get-timings',
			__( 'Get page timings and updates', 'axle-runtime-doctor' ),
			__( 'The server time of the key pages from each timing run, the pages that got slower in the latest run, and the updates logged. Reads what is stored; loads nothing.', 'axle-runtime-doctor' ),
			array(),
			$timing,
			array( $this, 'get_timings' ),
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			array( Abilities::class, 'can' )
		);

		$this->ability(
			'run-timing',
			__( 'Time the key pages now', 'axle-runtime-doctor' ),
			__( 'Loads the key pages from the server with page caches left out (one warm-up request and the median of five), stores the run and returns it with any slowdown matched to the updates since the last run.', 'axle-runtime-doctor' ),
			array(),
			array( 'type' => 'object' ),
			array( $this, 'run_timing' ),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			array( Abilities::class, 'can' )
		);

		$this->ability(
			'confirm-update',
			__( 'Confirm an update made a page slower', 'axle-runtime-doctor' ),
			__( 'Loads one page twice from the server, once as usual and once without the plugin of the given update, and compares the server times. Needs the opt-in must-use loader. Nothing is deactivated or changed on the site: the plugin is left out of that one tokenized request only.', 'axle-runtime-doctor' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'update' => array(
						'type'        => 'string',
						'description' => __( 'An update ID from get-timings.', 'axle-runtime-doctor' ),
					),
					'url'    => array(
						'type'        => 'string',
						'description' => __( 'A page of this site. Default: the page that got slowest, or the home page.', 'axle-runtime-doctor' ),
					),
				),
				'required'             => array( 'update' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'confirm_update' ),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			array( Confirm::class, 'can' )
		);
	}

	/**
	 * Reads what is stored.
	 *
	 * @return array
	 */
	public function get_timings(): array {
		return array(
			'runs'      => array_reverse( Timings::all() ),
			'slowdowns' => Timings::latest_slowdowns(),
			'updates'   => array_reverse( Updates::all() ),
		);
	}

	/**
	 * Times the pages.
	 *
	 * @return array|WP_Error
	 */
	public function run_timing() {
		return $this->timings->run( 'ability' );
	}

	/**
	 * Confirms one update.
	 *
	 * @param mixed $input update and url.
	 * @return array|WP_Error
	 */
	public function confirm_update( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return $this->confirm->run( sanitize_key( (string) ( $input['update'] ?? '' ) ), esc_url_raw( (string) ( $input['url'] ?? '' ) ) );
	}

	/**
	 * Registers one ability, public for MCP.
	 *
	 * @param string   $name        Name after the slash.
	 * @param string   $label       Label.
	 * @param string   $description What it does.
	 * @param array    $input       Input schema. Empty for none.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 * @param callable $permission  Permission callback.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, array $annotations, callable $permission ): void {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => Abilities::CATEGORY,
			'output_schema'       => $output,
			'execute_callback'    => $execute,
			'permission_callback' => $permission,
			'meta'                => array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			),
		);
		if ( $input ) {
			$args['input_schema'] = $input;
		}

		wp_register_ability( Abilities::CATEGORY . '/' . $name, $args );
	}
}
