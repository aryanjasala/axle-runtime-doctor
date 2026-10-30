<?php
/**
 * Site Health tests and debug information.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Health;

use AryanX\Axle\RuntimeDoctor\Admin\Page;
use AryanX\Axle\RuntimeDoctor\Checks\Registry;
use AryanX\Axle\RuntimeDoctor\History;
use AryanX\Axle\RuntimeDoctor\Result;
use AryanX\Axle\RuntimeDoctor\Runner;
use AryanX\Axle\RuntimeDoctor\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the checks that matter most to Tools > Site Health > Status, as
 * axle_runtime_doctor_<check> tests, and every value from the latest report
 * to the Info tab. None of them repeats a core test.
 */
final class SiteHealth implements Service {

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
	 * The checks.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Sets up Site Health.
	 *
	 * @param Runner   $runner   Runs the checks.
	 * @param History  $history  Snapshots.
	 * @param Registry $registry The checks.
	 */
	public function __construct( Runner $runner, History $history, Registry $registry ) {
		$this->runner   = $runner;
		$this->history  = $history;
		$this->registry = $registry;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
	}

	/**
	 * Which checks become Site Health tests.
	 *
	 * @return string[]
	 */
	public static function tests(): array {
		$tests = array( 'opcache_coverage', 'opcache_memory', 'opcache_restarts', 'object_cache_roundtrip', 'php_branch', 'php_input_vars', 'cron_mode', 'fs_uploads_php', 'db_version' );
		if ( version_compare( (string) get_bloginfo( 'version' ), '7.0-alpha', '<' ) ) {
			array_unshift( $tests, 'opcache_enabled' );
		}

		/**
		 * Filters which checks are added to Site Health as tests.
		 *
		 * @param string[] $tests Check IDs.
		 */
		$filtered = (array) apply_filters( 'axle_runtime_doctor_site_health_tests', $tests );

		return array_values( array_unique( array_map( 'strval', array_filter( $filtered, 'is_scalar' ) ) ) );
	}

	/**
	 * Adds the tests.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function add_tests( $tests ): array {
		$tests = (array) $tests;
		foreach ( self::tests() as $id ) {
			$check = $this->registry->get( $id );
			if ( ! $check ) {
				continue;
			}
			$tests['direct'][ 'axle_runtime_doctor_' . $id ] = array(
				'label' => $check->label(),
				'test'  => function () use ( $id ) {
					return $this->run( $id );
				},
			);
		}

		return $tests;
	}

	/**
	 * Runs one test.
	 *
	 * @param string $id Check ID.
	 * @return array Site Health result.
	 */
	public function run( string $id ): array {
		$report = $this->runner->run_local(
			array(
				'checks' => array( $id ),
				'save'   => false,
				'source' => 'site-health',
			)
		);
		$check  = $this->registry->get( $id );
		$result = $report['results'][ $id ] ?? null;
		if ( ! is_array( $result ) ) {
			$result = array(
				'status'  => Result::GOOD,
				/* translators: %s: check name. */
				'summary' => sprintf( __( '%s: does not apply here', 'axle-runtime-doctor' ), $check ? $check->label() : $id ),
				'value'   => '',
				'why'     => '',
			);
		}

		$status      = in_array( $result['status'], array( Result::CRITICAL, Result::RECOMMENDED ), true ) ? $result['status'] : Result::GOOD;
		$security    = in_array( $id, array( 'php_branch', 'fs_uploads_php', 'db_version', 'php_patch', 'fs_config' ), true );
		$description = '<p>' . esc_html( (string) $result['why'] ) . '</p>';
		if ( '' !== (string) $result['value'] ) {
			/* translators: %s: measured value. */
			$description .= '<p>' . esc_html( sprintf( __( 'Measured: %s', 'axle-runtime-doctor' ), (string) $result['value'] ) ) . '</p>';
		}

		return array(
			'label'       => (string) $result['summary'],
			'status'      => $status,
			'badge'       => array(
				'label' => $security ? __( 'Security', 'axle-runtime-doctor' ) : __( 'Performance', 'axle-runtime-doctor' ),
				'color' => Result::CRITICAL === $status ? 'red' : ( $security ? 'blue' : 'orange' ),
			),
			'description' => $description,
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( Page::url( 'checks' ) . '#ard-check-' . $id ), esc_html__( 'Details and how to fix it in Runtime Doctor', 'axle-runtime-doctor' ) ),
			'test'        => 'axle_runtime_doctor_' . $id,
		);
	}

	/**
	 * Adds the latest values to Site Health > Info.
	 *
	 * @param array $info Debug information.
	 * @return array
	 */
	public function debug_information( $info ): array {
		$info   = (array) $info;
		$report = $this->history->latest();
		$fields = array();
		if ( $report ) {
			$fields['host'] = array(
				'label' => __( 'Host', 'axle-runtime-doctor' ),
				'value' => (string) ( $report['host']['label'] ?? '' ),
			);
			$fields['time'] = array(
				'label' => __( 'Last run', 'axle-runtime-doctor' ),
				'value' => gmdate( 'Y-m-d H:i:s', (int) $report['time'] ) . ' UTC',
				'debug' => (int) $report['time'],
			);
			foreach ( (array) $report['results'] as $id => $result ) {
				$fields[ $id ] = array(
					'label' => (string) $result['label'],
					'value' => Result::status_label( (string) $result['status'] ) . ': ' . ( '' !== (string) $result['value'] ? (string) $result['value'] : (string) $result['summary'] ),
					'debug' => (string) $result['status'] . ': ' . (string) $result['value'],
				);
			}
		} else {
			$fields['none'] = array(
				'label' => __( 'Last run', 'axle-runtime-doctor' ),
				'value' => __( 'The checks have not run yet.', 'axle-runtime-doctor' ),
			);
		}

		$info['axle-runtime-doctor'] = array(
			'label'  => __( 'Axle Runtime Doctor', 'axle-runtime-doctor' ),
			'fields' => $fields,
		);

		return $info;
	}
}
