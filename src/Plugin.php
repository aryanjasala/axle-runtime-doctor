<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

use AryanX\Axle\RuntimeDoctor\Admin\Notice;
use AryanX\Axle\RuntimeDoctor\Admin\Page;
use AryanX\Axle\RuntimeDoctor\Checks\Registry;
use AryanX\Axle\RuntimeDoctor\Cli\Command;
use AryanX\Axle\RuntimeDoctor\Health\SiteHealth;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shared objects once and registers the services.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The checks.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Host detection.
	 *
	 * @var Hosts
	 */
	private Hosts $hosts;

	/**
	 * Fix data.
	 *
	 * @var Fixes
	 */
	private Fixes $fixes;

	/**
	 * Front-end samples.
	 *
	 * @var Sampler
	 */
	private Sampler $sampler;

	/**
	 * Autoloaded options.
	 *
	 * @var Autoload
	 */
	private Autoload $autoload;

	/**
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Snapshots.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Runs the checks.
	 *
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			Probe::$booted  = microtime( true );
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->registry = new Registry();
		$this->hosts    = new Hosts( $this->settings );
		$this->fixes    = new Fixes();
		$this->sampler  = new Sampler( $this->settings );
		$this->autoload = new Autoload();
		$this->alerts   = new Alerts( $this->settings );
		$this->history  = new History( $this->alerts );
		$this->runner   = new Runner( $this->settings, $this->registry, $this->hosts, $this->sampler, $this->autoload, new PhpReleases(), $this->history );
	}

	/**
	 * Runs the checks, for other plugins and tests.
	 *
	 * @return Runner
	 */
	public function runner(): Runner {
		return $this->runner;
	}

	/**
	 * Snapshots, for other plugins and tests.
	 *
	 * @return History
	 */
	public function history(): History {
		return $this->history;
	}

	/**
	 * Settings, for other plugins and tests.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		Probe::maybe_capture();

		$services = array(
			new Probe(),
			$this->sampler,
			new Rest( $this->runner, $this->history ),
			new Scheduler( $this->runner ),
			new Page( $this->settings, $this->runner, $this->history, $this->fixes, $this->hosts, $this->autoload, $this->alerts, $this->registry ),
			new Notice( $this->alerts ),
			new SiteHealth( $this->runner, $this->history, $this->registry ),
			new Privacy( $this->settings ),
			new Abilities( $this->runner, $this->history, $this->fixes, $this->hosts, $this->autoload, $this->registry ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-runtime-doctor', new Command( $this->settings, $this->runner, $this->history, $this->fixes, $this->hosts, $this->autoload, $this->registry ) );
		}
	}
}
