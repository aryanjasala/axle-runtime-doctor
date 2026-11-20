<?php
/**
 * Stand-in for Axle Leftovers' public lookup, driven by a global in tests.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\Leftovers;

/**
 * Answers from $GLOBALS['ard_leftovers'] (a callable), or null.
 *
 * @param string $type Item type.
 * @param string $name Item name.
 * @return array|null
 */
function owner_of( string $type, string $name ): ?array {
	$answer = $GLOBALS['ard_leftovers'] ?? null;

	return is_callable( $answer ) ? $answer( $type, $name ) : null;
}
