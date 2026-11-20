<?php
/**
 * Runs the must-use loader's own code against forged, used, expired and valid
 * tokens, in a separate PHP process with a fake WordPress, to show it fails
 * closed: no valid token, no change.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Tests\Unit;

use AryanX\Axle\RuntimeDoctor\Timing\Loader;
use PHPUnit\Framework\TestCase;

final class LoaderSourceTest extends TestCase {

	private const FILE = 'elementor/elementor.php';

	private string $dir;
	private string $token = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd';

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/ard-loader-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir . '/plugins/axle-runtime-doctor', 0777, true );
		touch( $this->dir . '/plugins/axle-runtime-doctor/axle-runtime-doctor.php' );
		file_put_contents( $this->dir . '/loader.php', Loader::source() );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*.*' ) as $file ) {
			unlink( $file );
		}
		unlink( $this->dir . '/plugins/axle-runtime-doctor/axle-runtime-doctor.php' );
		rmdir( $this->dir . '/plugins/axle-runtime-doctor' );
		rmdir( $this->dir . '/plugins' );
		rmdir( $this->dir );
	}

	/**
	 * The name of the row, the way the plugin makes it.
	 */
	private function row_name( string $token ): string {
		return Loader::row_name( $token );
	}

	/**
	 * A valid row for the token.
	 */
	private function row( string $token, array $over = array() ): array {
		return array(
			$this->row_name( $token ) => $over + array(
				'without' => self::FILE,
				'digest'  => hash( 'sha256', $token ),
				'expires' => time() + 60,
			),
		);
	}

	/**
	 * Runs the loader with a header, a method and some options.
	 */
	private function run_loader( ?string $token, array $options, array $extra = array() ): array {
		$server = array( 'REQUEST_METHOD' => 'GET' );
		if ( null !== $token ) {
			$server['HTTP_X_AXLE_RUNTIME_DOCTOR_PROBE'] = $token;
		}
		$scenario = array(
			'plugin_dir' => $this->dir . '/plugins',
			'server'     => array_merge( $server, $extra['server'] ?? array() ),
			'options'    => $options,
			'wp_admin'   => $extra['wp_admin'] ?? false,
		);
		file_put_contents( $this->dir . '/scenario.json', wp_json( $scenario ) );

		$output = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/Stubs/loader-harness.php' ) . ' ' . escapeshellarg( $this->dir . '/loader.php' ) . ' ' . escapeshellarg( $this->dir . '/scenario.json' ) . ' 2>&1' );
		$result = json_decode( $output, true );
		$this->assertIsArray( $result, 'The harness printed: ' . $output );

		return $result;
	}

	private function assertEveryPluginLoads( array $result ): void {
		$this->assertSame( '', $result['skipped'] );
		$this->assertSame( array( 'a/a.php', 'elementor/elementor.php', 'axle-runtime-doctor/axle-runtime-doctor.php' ), $result['active'] );
		$this->assertSame( array( 'elementor/elementor.php', 'b/b.php' ), $result['network'] );
	}

	public function test_a_valid_token_leaves_one_plugin_out_once(): void {
		$result = $this->run_loader( $this->token, $this->row( $this->token ) );

		$this->assertSame( self::FILE, $result['skipped'] );
		$this->assertSame( array( 'a/a.php', 'axle-runtime-doctor/axle-runtime-doctor.php' ), $result['active'] );
		$this->assertSame( array( 'b/b.php' ), $result['network'] );
		$this->assertSame( array(), $result['row_left'], 'the row is used up as the request starts' );
	}

	public function test_no_token_changes_nothing(): void {
		$this->assertEveryPluginLoads( $this->run_loader( null, $this->row( $this->token ) ) );
	}

	public function test_a_forged_token_changes_nothing(): void {
		$forged = 'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ';
		$this->assertEveryPluginLoads( $this->run_loader( $forged, $this->row( $this->token ) ) );
	}

	public function test_a_malformed_token_changes_nothing(): void {
		foreach ( array( 'short', str_repeat( 'a', 41 ), str_repeat( 'a', 39 ) . '-', '' ) as $bad ) {
			$this->assertEveryPluginLoads( $this->run_loader( $bad, $this->row( $this->token ) ) );
		}
	}

	public function test_a_used_token_changes_nothing(): void {
		// The row was deleted by the first request, so the second finds nothing.
		$this->assertEveryPluginLoads( $this->run_loader( $this->token, array() ) );
	}

	public function test_an_expired_token_changes_nothing(): void {
		$result = $this->run_loader( $this->token, $this->row( $this->token, array( 'expires' => time() - 1 ) ) );

		$this->assertEveryPluginLoads( $result );
		$this->assertSame( array(), $result['row_left'], 'an expired row is still used up' );
	}

	public function test_a_row_with_the_wrong_digest_changes_nothing(): void {
		$this->assertEveryPluginLoads( $this->run_loader( $this->token, $this->row( $this->token, array( 'digest' => hash( 'sha256', 'other' ) ) ) ) );
	}

	public function test_a_row_that_is_not_ours_to_read_changes_nothing(): void {
		$this->assertEveryPluginLoads( $this->run_loader( $this->token, array( $this->row_name( $this->token ) => 'junk' ) ) );
		$this->assertEveryPluginLoads( $this->run_loader( $this->token, array( $this->row_name( $this->token ) => array( 'without' => self::FILE ) ) ) );
	}

	public function test_it_never_leaves_out_this_plugin_or_a_malformed_path(): void {
		foreach ( array( 'axle-runtime-doctor/axle-runtime-doctor.php', '../x.php', 'a/b/c.php', 'a/b.txt' ) as $without ) {
			$this->assertEveryPluginLoads( $this->run_loader( $this->token, $this->row( $this->token, array( 'without' => $without ) ) ) );
		}
	}

	public function test_only_a_get_request_on_the_front_end_is_touched(): void {
		$this->assertEveryPluginLoads( $this->run_loader( $this->token, $this->row( $this->token ), array( 'server' => array( 'REQUEST_METHOD' => 'POST' ) ) ) );
		$this->assertEveryPluginLoads( $this->run_loader( $this->token, $this->row( $this->token ), array( 'wp_admin' => true ) ) );
	}

	public function test_it_does_nothing_once_the_plugin_is_gone(): void {
		unlink( $this->dir . '/plugins/axle-runtime-doctor/axle-runtime-doctor.php' );
		$result = $this->run_loader( $this->token, $this->row( $this->token ) );
		touch( $this->dir . '/plugins/axle-runtime-doctor/axle-runtime-doctor.php' );

		$this->assertEveryPluginLoads( $result );
	}

	public function test_the_file_carries_its_marker(): void {
		$this->assertStringContainsString( Loader::MARKER, Loader::source() );
	}
}

/**
 * JSON for the scenario file.
 */
function wp_json( array $data ): string {
	return (string) json_encode( $data );
}
