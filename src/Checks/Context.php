<?php
/**
 * What the current request knows about the runtime.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Facts;
use AryanX\Axle\RuntimeDoctor\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Facts the checks read, gathered once per run and only when a check asks.
 *
 * Tests build a Context from an array of facts; a real run reads them from
 * Facts, lazily, so a run of one check only gathers what that check needs.
 */
final class Context {

	/**
	 * Facts gathered so far.
	 *
	 * @var array
	 */
	private array $facts;

	/**
	 * Where missing facts come from.
	 *
	 * @var Facts|null
	 */
	private ?Facts $source;

	/**
	 * Thresholds.
	 *
	 * @var array<string, float>
	 */
	private array $thresholds;

	/**
	 * Sets up the context.
	 *
	 * @param array      $facts      Facts known up front.
	 * @param Facts|null $source     Where to read the others.
	 * @param array|null $thresholds Thresholds. Default: Settings::default_thresholds().
	 */
	public function __construct( array $facts = array(), ?Facts $source = null, ?array $thresholds = null ) {
		$this->facts      = $facts;
		$this->source     = $source;
		$this->thresholds = null === $thresholds ? Settings::default_thresholds() : $thresholds;
	}

	/**
	 * One fact.
	 *
	 * @param string $name Fact name, see Facts.
	 * @return mixed Null when unknown.
	 */
	public function get( string $name ) {
		if ( ! array_key_exists( $name, $this->facts ) ) {
			$this->facts[ $name ] = $this->source ? $this->source->get( $name ) : null;
		}

		return $this->facts[ $name ];
	}

	/**
	 * One fact as an array.
	 *
	 * @param string $name Fact name.
	 * @return array
	 */
	public function arr( string $name ): array {
		$value = $this->get( $name );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * One ini value, as PHP reports it in this request.
	 *
	 * @param string $name Ini name.
	 * @return string Empty when unknown.
	 */
	public function ini( string $name ): string {
		$ini = $this->arr( 'ini' );

		return isset( $ini[ $name ] ) && false !== $ini[ $name ] ? (string) $ini[ $name ] : '';
	}

	/**
	 * One threshold.
	 *
	 * @param string $name Threshold name.
	 * @return float
	 */
	public function threshold( string $name ): float {
		return (float) ( $this->thresholds[ $name ] ?? Settings::default_thresholds()[ $name ] ?? 0 );
	}

	/**
	 * Whether this run happens in the web server's PHP, not WP-CLI.
	 *
	 * @return bool
	 */
	public function is_web(): bool {
		return 'cli' !== $this->get( 'sapi' );
	}

	/**
	 * Every fact gathered so far.
	 *
	 * @return array
	 */
	public function all(): array {
		return $this->facts;
	}
}
