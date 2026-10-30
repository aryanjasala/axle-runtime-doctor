<?php
/**
 * Contract for a check.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * One thing to check about the runtime.
 */
interface Check {

	/**
	 * Unique ID, such as opcache_coverage.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Group ID: opcache, object_cache, php, database, cron or filesystem.
	 *
	 * @return string
	 */
	public function group(): string;

	/**
	 * Name shown on the screen.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the check only means something inside a web request.
	 *
	 * @return bool
	 */
	public function needs_web(): bool;

	/**
	 * Whether the check is about one site rather than the shared runtime.
	 *
	 * @return bool
	 */
	public function per_site(): bool;

	/**
	 * Runs the check.
	 *
	 * @param Context $context What the request knows.
	 * @return Result|null Null when the check does not apply here.
	 */
	public function run( Context $context ): ?Result;
}
