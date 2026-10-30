<?php
/**
 * Reads the runtime, one fact at a time.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Every fact the checks read, gathered in the current request. Values are
 * read one by one: phpinfo() is never called, since its output carries
 * environment variables and keys.
 */
final class Facts {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Host detection.
	 *
	 * @var Hosts
	 */
	private Hosts $hosts;

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
	 * PHP release data from php.net.
	 *
	 * @var PhpReleases
	 */
	private PhpReleases $releases;

	/**
	 * Either web or cli.
	 *
	 * @var string
	 */
	private string $sapi;

	/**
	 * Sets up the reader.
	 *
	 * @param Settings    $settings Settings.
	 * @param Hosts       $hosts    Host detection.
	 * @param Sampler     $sampler  Front-end samples.
	 * @param Autoload    $autoload Autoloaded options.
	 * @param PhpReleases $releases PHP release data.
	 * @param string      $sapi     web or cli.
	 */
	public function __construct( Settings $settings, Hosts $hosts, Sampler $sampler, Autoload $autoload, PhpReleases $releases, string $sapi ) {
		$this->settings = $settings;
		$this->hosts    = $hosts;
		$this->sampler  = $sampler;
		$this->autoload = $autoload;
		$this->releases = $releases;
		$this->sapi     = $sapi;
	}

	/**
	 * One fact by name.
	 *
	 * @param string $name Fact name.
	 * @return mixed Null for an unknown fact.
	 */
	public function get( string $name ) {
		$method = 'fact_' . $name;

		return method_exists( $this, $method ) ? $this->$method() : null;
	}

	/**
	 * The time now.
	 *
	 * @return int
	 */
	private function fact_now(): int {
		return time();
	}

	/**
	 * Web or CLI.
	 *
	 * @return string
	 */
	private function fact_sapi(): string {
		return $this->sapi;
	}

	/**
	 * WordPress version.
	 *
	 * @return string
	 */
	private function fact_wp_version(): string {
		return (string) get_bloginfo( 'version' );
	}

	/**
	 * PHP version.
	 *
	 * @return string
	 */
	private function fact_php_version(): string {
		return PHP_VERSION;
	}

	/**
	 * Environment type.
	 *
	 * @return string
	 */
	private function fact_environment(): string {
		return wp_get_environment_type();
	}

	/**
	 * The host.
	 *
	 * @return array
	 */
	private function fact_host(): array {
		return $this->hosts->current();
	}

	/**
	 * OPcache: loaded, restricted, status (without the script list).
	 *
	 * @return array
	 */
	private function fact_opcache(): array {
		$loaded = function_exists( 'opcache_get_status' );
		$status = $loaded ? @opcache_get_status( false ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPressVIPMinimum.Functions.RestrictedFunctions.config_settings_opcache_get_status -- Read only; warns when restrict_api forbids it, which is handled.

		return array(
			'loaded'     => $loaded,
			'status'     => is_array( $status ) ? $status : null,
			'restricted' => $loaded && false === $status && '' !== (string) ini_get( 'opcache.restrict_api' ),
		);
	}

	/**
	 * The ini values the checks judge, read one by one.
	 *
	 * @return array<string, string|false>
	 */
	private function fact_ini(): array {
		$names = array(
			'memory_limit',
			'max_execution_time',
			'max_input_vars',
			'post_max_size',
			'upload_max_filesize',
			'display_errors',
			'realpath_cache_size',
			'user_ini.filename',
			'opcache.enable',
			'opcache.memory_consumption',
			'opcache.interned_strings_buffer',
			'opcache.max_accelerated_files',
			'opcache.validate_timestamps',
			'opcache.revalidate_freq',
			'opcache.blacklist_filename',
			'opcache.file_cache_only',
			'opcache.restrict_api',
			'opcache.jit',
			'opcache.jit_buffer_size',
		);
		$ini   = array();
		foreach ( $names as $name ) {
			$ini[ $name ] = ini_get( $name );
		}

		return $ini;
	}

	/**
	 * The files the coverage check asks OPcache about, all included in every request.
	 *
	 * @return array{core: string[], content: string[]}
	 */
	public static function coverage_files(): array {
		$content = array( PLUGIN_FILE );
		$theme   = get_template_directory() . '/functions.php';
		if ( is_readable( $theme ) ) {
			$content[] = $theme;
		}
		$mu = function_exists( 'wp_get_mu_plugins' ) ? wp_get_mu_plugins() : array();
		if ( $mu ) {
			$content[] = (string) reset( $mu );
		}
		$files = array(
			'core'    => array( ABSPATH . 'wp-includes/plugin.php' ),
			'content' => $content,
		);

		/**
		 * Filters the files the OPcache coverage check looks at. Each must be
		 * included in every request, or it is never cached.
		 *
		 * @param array $files core => core files, content => files under wp-content.
		 */
		$filtered = apply_filters( 'axle_runtime_doctor_coverage_files', $files );

		return array(
			'core'    => array_values( array_map( 'strval', (array) ( $filtered['core'] ?? $files['core'] ) ) ),
			'content' => array_values( array_map( 'strval', (array) ( $filtered['content'] ?? $files['content'] ) ) ),
		);
	}

	/**
	 * Whether OPcache holds the coverage files in this request.
	 *
	 * @return array core (bool|null), content (bool|null), files (path => bool).
	 */
	public static function observe_coverage(): array {
		$observation = array(
			'core'    => null,
			'content' => null,
			'files'   => array(),
		);
		if ( ! function_exists( 'opcache_is_script_cached' ) ) {
			return $observation;
		}

		// OPcache does not cache a file changed in the last few seconds (opcache.file_update_protection).
		$recent = time() - max( 2, (int) ini_get( 'opcache.file_update_protection' ) ) - 1;
		foreach ( self::coverage_files() as $kind => $files ) {
			foreach ( $files as $file ) {
				$cached = (bool) @opcache_is_script_cached( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPressVIPMinimum.Functions.RestrictedFunctions.config_settings_opcache_is_script_cached -- Read only; warns when restrict_api forbids it.

				$observation['files'][ self::short( $file ) ] = $cached;
				if ( ! $cached && (int) @filemtime( $file ) > $recent ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file counts as old.
					continue;
				}
				$observation[ $kind ] = null === $observation[ $kind ] ? $cached : ( $observation[ $kind ] && $cached );
			}
		}

		return $observation;
	}

	/**
	 * OPcache coverage: this request, a second request when wp-content looks
	 * skipped, and what could explain it.
	 *
	 * @return array available, observations, reasons, timing.
	 */
	private function fact_coverage(): array {
		$opcache = $this->fact_opcache();
		if ( $opcache['restricted'] || ! function_exists( 'opcache_is_script_cached' ) ) {
			$timing = array();
			if ( 'web' === $this->sapi ) {
				$first  = Probe::call( 'timing' );
				$second = Probe::call( 'timing' );
				if ( is_array( $first ) && is_array( $second ) ) {
					$timing = array(
						'first_ms'  => (float) ( $first['ms'] ?? 0 ),
						'second_ms' => (float) ( $second['ms'] ?? 0 ),
					);
				}
			}

			return array(
				'available' => false,
				'reasons'   => $this->coverage_reasons(),
				'timing'    => $timing,
			);
		}

		$observations = array( self::observe_coverage() );
		if ( ! empty( $observations[0]['core'] ) && false === $observations[0]['content'] ) {
			$second = Probe::call( 'read' );
			if ( is_array( $second ) && isset( $second['coverage'] ) && is_array( $second['coverage'] ) ) {
				$observations[] = $second['coverage'];
			}
		}

		$skipped = false === $observations[0]['content'];

		return array(
			'available'    => true,
			'observations' => $observations,
			'reasons'      => $skipped ? $this->coverage_reasons() : array(),
		);
	}

	/**
	 * What could keep OPcache from caching wp-content.
	 *
	 * @return string[]
	 */
	private function coverage_reasons(): array {
		$reasons   = array();
		$content   = wp_normalize_path( WP_CONTENT_DIR ) . '/';
		$blacklist = (string) ini_get( 'opcache.blacklist_filename' );

		if ( '' !== $blacklist ) {
			$files = glob( $blacklist );
			foreach ( is_array( $files ) ? $files : array() as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local php.ini blacklist file.
				$lines = is_readable( $file ) ? explode( "\n", (string) file_get_contents( $file ) ) : array();
				foreach ( $lines as $number => $line ) {
					$entry = trim( $line );
					if ( '' === $entry || ';' === $entry[0] ) {
						continue;
					}
					if ( self::blacklist_matches( $entry, $content . 'plugins/x.php' ) || self::blacklist_matches( $entry, $content . 'themes/x.php' ) ) {
						/* translators: 1: blacklist entry, 2: file, 3: line number. */
						$reasons[] = sprintf( __( 'opcache.blacklist_filename: "%1$s" in %2$s (line %3$d) matches wp-content', 'axle-runtime-doctor' ), $entry, $file, $number + 1 );
					}
				}
			}
			if ( ! $reasons && is_array( $files ) && ! $files ) {
				/* translators: %s: path. */
				$reasons[] = sprintf( __( 'opcache.blacklist_filename is set to %s, but this site cannot read it', 'axle-runtime-doctor' ), $blacklist );
			}
		}

		if ( in_array( strtolower( (string) ini_get( 'opcache.file_cache_only' ) ), array( '1', 'on' ), true ) ) {
			$reasons[] = __( 'opcache.file_cache_only is on: OPcache uses only its file cache, not shared memory', 'axle-runtime-doctor' );
		}

		$restrict = (string) ini_get( 'opcache.restrict_api' );
		if ( '' !== $restrict ) {
			/* translators: %s: path. */
			$reasons[] = sprintf( __( 'opcache.restrict_api is %s: only scripts under that path may ask OPcache about itself', 'axle-runtime-doctor' ), $restrict );
		}

		$user_ini = (string) ini_get( 'user_ini.filename' );
		if ( '' !== $user_ini ) {
			foreach ( array( ABSPATH, WP_CONTENT_DIR . '/', WP_PLUGIN_DIR . '/', get_template_directory() . '/' ) as $folder ) {
				$file = $folder . $user_ini;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local per-directory ini file.
				if ( is_readable( $file ) && false !== stripos( (string) file_get_contents( $file ), 'opcache' ) ) {
					/* translators: %s: file path. */
					$reasons[] = sprintf( __( '%s changes OPcache settings for its folder', 'axle-runtime-doctor' ), self::short( $file ) );
				}
			}
		}

		if ( ! $reasons ) {
			$reasons[] = __( 'No blacklist or setting this site can read explains it, so it is most likely the host\'s OPcache policy.', 'axle-runtime-doctor' );
		}

		return $reasons;
	}

	/**
	 * Whether an OPcache blacklist entry matches a path. Entries are path
	 * prefixes with * and ? wildcards.
	 *
	 * @param string $entry Blacklist line.
	 * @param string $path  Path.
	 * @return bool
	 */
	public static function blacklist_matches( string $entry, string $path ): bool {
		$pattern = '#^' . str_replace( array( '\*', '\?' ), array( '.*', '.' ), preg_quote( wp_normalize_path( $entry ), '#' ) ) . '#';

		return (bool) preg_match( $pattern, wp_normalize_path( $path ) );
	}

	/**
	 * PHP files under the WordPress folder, counted once a day.
	 *
	 * @return int|null Null when the count stopped at the limit.
	 */
	private function fact_php_files(): ?int {
		$cached = get_transient( 'axle_runtime_doctor_php_files' );
		if ( is_int( $cached ) ) {
			return $cached;
		}

		$count = self::count_files( ABSPATH, '/\.php$/i', 100000 );
		if ( null !== $count ) {
			set_transient( 'axle_runtime_doctor_php_files', $count, DAY_IN_SECONDS );
		}

		return $count;
	}

	/**
	 * Counts files whose name matches, walking at most $limit entries.
	 *
	 * @param string $folder  Folder.
	 * @param string $pattern File name pattern.
	 * @param int    $limit   Most entries to walk.
	 * @param array  $found   Filled with the first 20 matches, relative to the folder.
	 * @return int|null Null when the limit was reached.
	 */
	public static function count_files( string $folder, string $pattern, int $limit, array &$found = array() ): ?int {
		if ( ! is_dir( $folder ) ) {
			return 0;
		}
		$count  = 0;
		$walked = 0;
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $folder, \FilesystemIterator::SKIP_DOTS ),
					static function ( $file ) {
						return ! in_array( $file->getFilename(), array( 'node_modules', '.git' ), true );
					}
				)
			);
			foreach ( $iterator as $file ) {
				if ( ++$walked > $limit ) {
					return null;
				}
				if ( $file->isFile() && preg_match( $pattern, $file->getFilename() ) ) {
					++$count;
					if ( count( $found ) < 20 ) {
						$found[] = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( wp_normalize_path( $folder ) ) ), '/' );
					}
				}
			}
		} catch ( \Exception $e ) {
			return $count;
		}

		return $count;
	}

	/**
	 * The object cache drop-in and backend.
	 *
	 * @return array persistent, dropin, name, version, backend, info.
	 */
	private function fact_object_cache(): array {
		global $wp_object_cache;
		$file  = WP_CONTENT_DIR . '/object-cache.php';
		$cache = array(
			'persistent' => (bool) wp_using_ext_object_cache(),
			'dropin'     => is_readable( $file ),
			'name'       => '',
			'version'    => '',
			'backend'    => '',
			'info'       => array(),
		);
		if ( ! $cache['dropin'] ) {
			return $cache;
		}

		$header           = get_file_data(
			$file,
			array(
				'name'    => 'Plugin Name',
				'version' => 'Version',
			)
		);
		$cache['name']    = (string) $header['name'];
		$cache['version'] = (string) $header['version'];

		if ( is_object( $wp_object_cache ) ) {
			$class = get_class( $wp_object_cache );
			if ( method_exists( $wp_object_cache, 'redis_instance' ) || property_exists( $wp_object_cache, 'redis' ) || false !== stripos( $class, 'redis' ) ) {
				$cache['backend'] = 'Redis';
				$cache['info']    = self::redis_info( $wp_object_cache );
			} elseif ( property_exists( $wp_object_cache, 'mc' ) || property_exists( $wp_object_cache, 'm' ) || false !== stripos( $class, 'memcache' ) ) {
				$cache['backend'] = 'Memcached';
			} else {
				$cache['backend'] = $class;
			}
		}

		return $cache;
	}

	/**
	 * Memory and eviction from Redis, when the drop-in hands out its client.
	 *
	 * @param object $cache The object cache.
	 * @return array
	 */
	private static function redis_info( $cache ): array {
		try {
			$client = method_exists( $cache, 'redis_instance' ) ? $cache->redis_instance() : null;
			if ( ! is_object( $client ) || ! method_exists( $client, 'info' ) ) {
				return array();
			}
			$info = (array) $client->info();
			$keep = array( 'redis_version', 'used_memory_human', 'maxmemory_human', 'maxmemory_policy', 'evicted_keys' );

			return array_intersect_key( $info, array_flip( $keep ) );
		} catch ( \Throwable $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * Writes a key here and reads it back in another request.
	 *
	 * @return array tested, found, error.
	 */
	private function fact_roundtrip(): array {
		if ( ! wp_using_ext_object_cache() ) {
			return array( 'tested' => false );
		}
		if ( 'web' !== $this->sapi ) {
			return array(
				'tested' => false,
				'error'  => __( 'Not tested from WP-CLI.', 'axle-runtime-doctor' ),
			);
		}

		$key = 'roundtrip_' . wp_generate_password( 12, false, false );
		wp_cache_set( $key, 'ok', 'axle_runtime_doctor', 300 );
		$answer = Probe::call( 'read', array( 'key' => $key ) );
		wp_cache_delete( $key, 'axle_runtime_doctor' );

		if ( is_wp_error( $answer ) ) {
			return array(
				'tested' => false,
				'error'  => $answer->get_error_message(),
			);
		}

		return array(
			'tested' => true,
			'found'  => ! empty( $answer['found'] ),
		);
	}

	/**
	 * Front-end samples.
	 *
	 * @return array
	 */
	private function fact_samples(): array {
		return $this->sampler->all();
	}

	/**
	 * Memory use and the WordPress memory constants.
	 *
	 * @return array
	 */
	private function fact_memory(): array {
		return array(
			'peak'                => memory_get_peak_usage(),
			'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
			'wp_max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : '',
		);
	}

	/**
	 * The menu with the most items.
	 *
	 * @return array name, count.
	 */
	private function fact_menus(): array {
		$largest = array(
			'name'  => '',
			'count' => 0,
		);
		foreach ( wp_get_nav_menus() as $menu ) {
			if ( (int) $menu->count > $largest['count'] ) {
				$largest = array(
					'name'  => (string) $menu->name,
					'count' => (int) $menu->count,
				);
			}
		}

		return $largest;
	}

	/**
	 * The registered settings group with the most fields.
	 *
	 * @return array group, count.
	 */
	private function fact_settings_form(): array {
		$groups = array_count_values( array_map( 'strval', array_column( get_registered_settings(), 'group' ) ) );
		arsort( $groups );

		return array(
			'group' => (string) key( $groups ),
			'count' => (int) current( $groups ),
		);
	}

	/**
	 * PHP branch support dates.
	 *
	 * @return array
	 */
	private function fact_php_versions(): array {
		$table = Fixes::read_json( 'php-versions.json' );

		/**
		 * Filters the PHP support table: branches => branch => released, active, security (Y-m-d).
		 *
		 * @param array $table From data/php-versions.json.
		 */
		return (array) apply_filters( 'axle_runtime_doctor_php_versions', $table );
	}

	/**
	 * Database end-of-life dates.
	 *
	 * @return array
	 */
	private function fact_db_versions(): array {
		$table = Fixes::read_json( 'db-versions.json' );

		/**
		 * Filters the database end-of-life table: mysql and mariadb => release series => Y-m-d.
		 *
		 * @param array $table From data/db-versions.json.
		 */
		return (array) apply_filters( 'axle_runtime_doctor_db_versions', $table );
	}

	/**
	 * Latest PHP releases from php.net, when that check is on.
	 *
	 * @return array|null
	 */
	private function fact_php_releases(): ?array {
		return $this->settings->patch_check() ? $this->releases->get() : null;
	}

	/**
	 * Bytes used in the realpath cache of this PHP process.
	 *
	 * @return int|null
	 */
	private function fact_realpath_used(): ?int {
		return function_exists( 'realpath_cache_size' ) ? (int) realpath_cache_size() : null;
	}

	/**
	 * Autoloaded options.
	 *
	 * @return array
	 */
	private function fact_autoload(): array {
		return $this->autoload->report();
	}

	/**
	 * Scheduled events.
	 *
	 * @return array
	 */
	private function fact_cron(): array {
		$crons  = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$now    = time();
		$events = 0;
		$oldest = 0;
		$seen   = array();
		$dupes  = array();
		$hooks  = array();
		foreach ( $crons as $timestamp => $by_hook ) {
			foreach ( (array) $by_hook as $hook => $by_args ) {
				$hooks[ $hook ] = true;
				foreach ( (array) $by_args as $key => $event ) {
					++$events;
					if ( (int) $timestamp <= $now && ( 0 === $oldest || (int) $timestamp < $oldest ) ) {
						$oldest = (int) $timestamp;
					}
					$id = $hook . '|' . $key;
					if ( isset( $seen[ $id ] ) ) {
						$dupes[ $hook ] = true;
					}
					$seen[ $id ] = true;
				}
			}
		}

		$orphans = array();
		foreach ( array_keys( $hooks ) as $hook ) {
			if ( ! has_action( (string) $hook ) ) {
				$orphans[] = (string) $hook;
			}
		}

		return array(
			'disabled'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate'        => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'action_scheduler' => class_exists( 'ActionScheduler' ),
			'events'           => $events,
			'oldest_due'       => $oldest,
			'duplicates'       => array_keys( $dupes ),
			'orphans'          => array_slice( $orphans, 0, 30 ),
		);
	}

	/**
	 * The site calling itself: time, status and what it passed through.
	 *
	 * @return array ok, ms, status, cdn, challenge, error, blocked_external.
	 */
	private function fact_loopback(): array {
		$url   = Probe::loopback( add_query_arg( 'axle-runtime-doctor', 'loopback', home_url( '/' ) ) );
		$start = microtime( true );
		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- The loopback test itself: it measures how long the site takes to answer.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback requests.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		// phpcs:enable
		$ms      = ( microtime( true ) - $start ) * 1000;
		$blocked = defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL;

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'               => false,
				'ms'               => $ms,
				'error'            => $response->get_error_message(),
				'blocked_external' => $blocked,
			);
		}

		$status    = (int) wp_remote_retrieve_response_code( $response );
		$cdn       = self::cdn( (array) wp_remote_retrieve_headers( $response )->getAll() );
		$body      = substr( (string) wp_remote_retrieve_body( $response ), 0, 20000 );
		$challenge = in_array( $status, array( 403, 429, 503 ), true ) && (bool) preg_match( '/cf-chl|challenge|captcha|attention required|sucuri|incapsula/i', $body );

		return array(
			'ok'               => $status >= 200 && $status < 400,
			'ms'               => $ms,
			'status'           => $status,
			'cdn'              => $cdn,
			'challenge'        => $challenge,
			/* translators: %d: HTTP status. */
			'error'            => $status >= 400 ? sprintf( __( 'HTTP %d', 'axle-runtime-doctor' ), $status ) : '',
			'blocked_external' => $blocked,
		);
	}

	/**
	 * CDNs and firewalls named by response headers.
	 *
	 * @param array $headers Lowercase header name => value.
	 * @return string[]
	 */
	public static function cdn( array $headers ): array {
		$headers = array_change_key_case( $headers, CASE_LOWER );
		$server  = strtolower( implode( ' ', (array) ( $headers['server'] ?? '' ) ) );
		$found   = array();
		$signs   = array(
			'Cloudflare' => isset( $headers['cf-ray'] ) || false !== strpos( $server, 'cloudflare' ),
			'Sucuri'     => isset( $headers['x-sucuri-id'] ) || false !== strpos( $server, 'sucuri' ),
			'Imperva'    => isset( $headers['x-iinfo'] ) || ( isset( $headers['x-cdn'] ) && false !== stripos( implode( ' ', (array) $headers['x-cdn'] ), 'imperva' ) ),
			'CloudFront' => isset( $headers['x-amz-cf-id'] ),
			'Fastly'     => isset( $headers['x-fastly-request-id'] ) || false !== strpos( $server, 'fastly' ),
			'Akamai'     => isset( $headers['x-akamai-transformed'] ) || false !== strpos( $server, 'akamai' ),
		);
		foreach ( $signs as $name => $present ) {
			if ( $present ) {
				$found[] = $name;
			}
		}

		return $found;
	}

	/**
	 * Files and folders.
	 *
	 * @return array
	 */
	private function fact_filesystem(): array {
		$config = is_readable( ABSPATH . 'wp-config.php' ) ? ABSPATH . 'wp-config.php' : dirname( ABSPATH ) . '/wp-config.php';
		$perms  = @fileperms( $config ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May be outside open_basedir.
		$found  = array();
		$upload = wp_upload_dir( null, false );
		$limit  = self::scan_limit();
		$count  = self::count_files( (string) $upload['basedir'], '/\.(php\d?|phtml|phar)$/i', $limit, $found );
		$walked = self::count_files( (string) $upload['basedir'], '/./', $limit );

		$owner   = @fileowner( ABSPATH . 'wp-settings.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May be outside open_basedir.
		$process = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
		$match   = ( false !== $owner && null !== $process ) ? $owner === $process : null;

		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$method = (string) get_filesystem_method();
		$free   = function_exists( 'disk_free_space' ) ? @disk_free_space( ABSPATH ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Often disabled by the host.
		$total  = function_exists( 'disk_total_space' ) ? @disk_total_space( ABSPATH ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Often disabled by the host.

		return array(
			'config_path'      => $config,
			'config_mode'      => false !== $perms ? (int) $perms : null,
			'uploads_php'      => $count ? $found : array(),
			'uploads_scanned'  => null === $walked ? $limit : $walked,
			'process_user'     => null !== $process ? self::user_name( $process ) : '',
			'file_owner'       => false !== $owner ? self::user_name( (int) $owner ) : '',
			'owner_match'      => $match,
			'fs_method'        => $method,
			'content_writable' => wp_is_writable( WP_CONTENT_DIR ),
			'disk_free'        => false !== $free ? (float) $free : null,
			'disk_total'       => false !== $total ? (float) $total : null,
		);
	}

	/**
	 * How many files the uploads scan looks at.
	 *
	 * @return int
	 */
	public static function scan_limit(): int {
		/**
		 * Filters how many files the PHP-in-uploads scan looks at.
		 *
		 * @param int $limit Default 20000.
		 */
		return max( 100, (int) apply_filters( 'axle_runtime_doctor_scan_limit', 20000 ) );
	}

	/**
	 * A user name for a user ID, when posix can tell.
	 *
	 * @param int $id User ID.
	 * @return string
	 */
	private static function user_name( int $id ): string {
		$info = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $id ) : false;

		return is_array( $info ) && ! empty( $info['name'] ) ? $info['name'] . ' (' . $id . ')' : (string) $id;
	}

	/**
	 * Database server, engines, sizes and keys.
	 *
	 * @return array type, version, myisam, largest, no_pk.
	 */
	private function fact_database(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads server metadata for the checks; nothing to cache between runs.
		$version = (string) $wpdb->get_var( 'SELECT VERSION()' );
		$schema  = (string) $wpdb->dbname;
		$like    = $wpdb->esc_like( $wpdb->base_prefix ) . '%';
		$tables  = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, ENGINE AS engine, ( DATA_LENGTH + INDEX_LENGTH ) AS size, TABLE_ROWS AS row_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = %s AND TABLE_NAME LIKE %s ORDER BY size DESC LIMIT 1000',
				$schema,
				'BASE TABLE',
				$like
			),
			ARRAY_A
		);
		$keyed   = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = %s AND CONSTRAINT_TYPE = %s AND TABLE_NAME LIKE %s LIMIT 1000',
				$schema,
				'PRIMARY KEY',
				$like
			)
		);
		// phpcs:enable

		$myisam  = array();
		$no_pk   = array();
		$largest = array();
		foreach ( $tables as $table ) {
			if ( 'myisam' === strtolower( (string) $table['engine'] ) ) {
				$myisam[] = (string) $table['name'];
			}
			if ( ! in_array( $table['name'], $keyed, true ) ) {
				$no_pk[] = (string) $table['name'];
			}
			if ( count( $largest ) < 10 ) {
				$largest[] = array(
					'name' => (string) $table['name'],
					'size' => (int) $table['size'],
					'rows' => (int) $table['row_count'],
				);
			}
		}

		preg_match( '/\d+\.\d+(\.\d+)?/', $version, $match );

		return array(
			'server'  => $version,
			'type'    => false !== stripos( $version, 'mariadb' ) ? 'mariadb' : 'mysql',
			'version' => $match[0] ?? '',
			'myisam'  => $myisam,
			'largest' => $largest,
			'no_pk'   => $tables ? $no_pk : array(),
		);
	}

	/**
	 * The key pages the slow query check loads.
	 *
	 * @return string[]
	 */
	public static function probe_urls(): array {
		$urls   = array( home_url( '/' ) );
		$latest = get_posts(
			array(
				'numberposts'      => 1,
				'post_type'        => 'post',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		if ( $latest ) {
			$urls[] = (string) get_permalink( (int) $latest[0] );
		}
		$front = (int) get_option( 'page_on_front' );
		$page  = get_posts(
			array(
				'numberposts'      => 1,
				'post_type'        => 'page',
				'fields'           => 'ids',
				'exclude'          => $front ? array( $front ) : array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- One post, to skip the front page.
				'suppress_filters' => false,
			)
		);
		if ( $page ) {
			$urls[] = (string) get_permalink( (int) $page[0] );
		}
		$category = get_terms(
			array(
				'taxonomy'   => 'category',
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 1,
				'hide_empty' => true,
			)
		);
		if ( is_array( $category ) && $category ) {
			$link = get_term_link( $category[0] );
			if ( is_string( $link ) ) {
				$urls[] = $link;
			}
		}
		$urls[] = add_query_arg( 's', 'a', home_url( '/' ) );

		/**
		 * Filters the pages the slow query check loads. At most 10 are used.
		 *
		 * @param string[] $urls Home, latest post, latest page, biggest category, a search.
		 */
		$filtered = (array) apply_filters( 'axle_runtime_doctor_probe_urls', $urls );

		return array_slice( array_values( array_unique( array_filter( array_map( 'strval', array_filter( $filtered, 'is_scalar' ) ) ) ) ), 0, 10 );
	}

	/**
	 * Loads the key pages with query logging on and groups their queries.
	 *
	 * @return array probed, failed, digests, threshold_ms, server.
	 */
	private function fact_slow_queries(): array {
		$probed  = array();
		$failed  = array();
		$digests = array();
		foreach ( self::probe_urls() as $url ) {
			$page = Probe::page_queries( $url );
			if ( is_wp_error( $page ) ) {
				$failed[] = $url . ' (' . $page->get_error_message() . ')';
				continue;
			}
			$probed[] = $url;
			foreach ( (array) ( $page['digests'] ?? array() ) as $digest ) {
				$sql = (string) $digest['sql'];
				if ( ! isset( $digests[ $sql ] ) ) {
					$digests[ $sql ]             = $digest + array( 'pages' => 0 );
					$digests[ $sql ]['calls']    = 0;
					$digests[ $sql ]['total_ms'] = 0.0;
					$digests[ $sql ]['max_ms']   = 0.0;
				}
				$digests[ $sql ]['calls']    += (int) $digest['calls'];
				$digests[ $sql ]['total_ms'] += (float) $digest['total_ms'];
				$digests[ $sql ]['max_ms']    = max( $digests[ $sql ]['max_ms'], (float) $digest['max_ms'] );
				++$digests[ $sql ]['pages'];
			}
		}
		usort(
			$digests,
			static function ( $a, $b ) {
				return $b['total_ms'] <=> $a['total_ms'];
			}
		);

		return array(
			'probed'       => $probed,
			'failed'       => $failed,
			'digests'      => array_slice( $digests, 0, 50 ),
			'threshold_ms' => Settings::slow_query_ms(),
			'server'       => self::server_slow_log(),
		);
	}

	/**
	 * Slow query counters from the database server, when the user may read them.
	 *
	 * @return array
	 */
	private static function server_slow_log(): array {
		global $wpdb;
		$found    = array();
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Server counters, read live.
		$slow = $wpdb->get_row( "SHOW GLOBAL STATUS LIKE 'Slow_queries'", ARRAY_N );
		$long = $wpdb->get_row( "SHOW VARIABLES LIKE 'long_query_time'", ARRAY_N );
		// phpcs:enable
		$wpdb->suppress_errors( $suppress );
		if ( is_array( $slow ) && isset( $slow[1] ) ) {
			$found['Slow_queries'] = (string) $slow[1];
		}
		if ( is_array( $long ) && isset( $long[1] ) ) {
			$found['long_query_time'] = (string) $long[1];
		}

		return $found;
	}

	/**
	 * A path relative to the WordPress folder.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function short( string $path ): string {
		$path = wp_normalize_path( $path );
		$root = wp_normalize_path( ABSPATH );

		return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
	}
}
