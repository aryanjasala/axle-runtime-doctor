<?php
/**
 * The list of checks.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Every check, in screen order, and the groups they sit in.
 */
final class Registry {

	/**
	 * Checks built for this request.
	 *
	 * @var array<string, Check>|null
	 */
	private ?array $checks = null;

	/**
	 * The groups, in screen order.
	 *
	 * @return array<string, string> Group ID => label.
	 */
	public static function groups(): array {
		return array(
			'opcache'      => __( 'OPcache', 'axle-runtime-doctor' ),
			'object_cache' => __( 'Object cache', 'axle-runtime-doctor' ),
			'php'          => __( 'PHP', 'axle-runtime-doctor' ),
			'database'     => __( 'Database', 'axle-runtime-doctor' ),
			'cron'         => __( 'Cron and loopback', 'axle-runtime-doctor' ),
			'filesystem'   => __( 'Filesystem', 'axle-runtime-doctor' ),
		);
	}

	/**
	 * The built-in checks.
	 *
	 * @return Check[]
	 */
	public static function defaults(): array {
		return array(
			new OpcacheEnabled(),
			new OpcacheCoverage(),
			new OpcacheMemory(),
			new OpcacheStrings(),
			new OpcacheKeys(),
			new OpcacheRestarts(),
			new OpcacheHitRate(),
			new OpcacheRevalidate(),
			new OpcacheJit(),
			new ObjectCacheDropin(),
			new ObjectCacheRoundtrip(),
			new ObjectCacheHitRate(),
			new PhpBranch(),
			new PhpPatch(),
			new PhpMemory(),
			new PhpExecutionTime(),
			new PhpInputVars(),
			new PhpUploads(),
			new PhpDisplayErrors(),
			new PhpRealpath(),
			new DbAutoload(),
			new DbSlowQueries(),
			new DbMyisam(),
			new DbTables(),
			new DbPrimaryKeys(),
			new DbVersion(),
			new CronMode(),
			new CronEvents(),
			new Loopback(),
			new FsConfig(),
			new FsUploadsPhp(),
			new FsOwnership(),
			new FsContentWritable(),
			new FsDisk(),
		);
	}

	/**
	 * Every check, keyed by ID.
	 *
	 * @return array<string, Check>
	 */
	public function all(): array {
		if ( null !== $this->checks ) {
			return $this->checks;
		}

		$defaults = array();
		foreach ( self::defaults() as $check ) {
			$defaults[ $check->id() ] = $check;
		}

		/**
		 * Filters the checks. Add, remove or replace checks; each must implement
		 * AryanX\Axle\RuntimeDoctor\Checks\Check.
		 *
		 * @param array $checks Check ID => check.
		 */
		$filtered = (array) apply_filters( 'axle_runtime_doctor_checks', $defaults );

		$checks = array();
		foreach ( $filtered as $check ) {
			if ( $check instanceof Check && '' !== $check->id() ) {
				$checks[ $check->id() ] = $check;
			}
		}
		$this->checks = $checks;

		return $checks;
	}

	/**
	 * One check.
	 *
	 * @param string $id Check ID.
	 * @return Check|null
	 */
	public function get( string $id ): ?Check {
		$all = $this->all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Checks picked by ID and group.
	 *
	 * @param string[] $ids      Only these IDs. Empty for all.
	 * @param string   $group    Only this group. Empty for all.
	 * @param bool     $per_site Only checks about one site.
	 * @return array<string, Check>
	 */
	public function pick( array $ids = array(), string $group = '', bool $per_site = false ): array {
		return array_filter(
			$this->all(),
			static function ( Check $check ) use ( $ids, $group, $per_site ) {
				return ( ! $ids || in_array( $check->id(), $ids, true ) )
					&& ( '' === $group || $check->group() === $group )
					&& ( ! $per_site || $check->per_site() );
			}
		);
	}
}
