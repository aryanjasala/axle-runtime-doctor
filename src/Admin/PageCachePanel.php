<?php
/**
 * Tools > Runtime Doctor > Page cache.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Admin;

use AryanX\Axle\RuntimeDoctor\Checks\PageCache as PageCacheCheck;
use AryanX\Axle\RuntimeDoctor\Fixes;
use AryanX\Axle\RuntimeDoctor\Hosts;
use AryanX\Axle\RuntimeDoctor\PageCache\Analyzer;
use AryanX\Axle\RuntimeDoctor\PageCache\Scanner;
use AryanX\Axle\RuntimeDoctor\Result;
use AryanX\Axle\RuntimeDoctor\Service;
use AryanX\Axle\RuntimeDoctor\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The Page cache tab: ask the key pages twice as a visitor, see what answered
 * and what stops a cache, with the plugin or theme named when it can be traced.
 * The form posts to admin-post.php and comes back with a notice kept in the
 * user's meta for one page view, like the Compare tab.
 */
final class PageCachePanel implements Service {

	public const FLASH = 'axle_runtime_doctor_cache_flash';
	public const STATE = 'axle_runtime_doctor_cache_state';

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
	 * Sets up the tab.
	 *
	 * @param Fixes $fixes Fix data.
	 * @param Hosts $hosts Host detection.
	 */
	public function __construct( Fixes $fixes, Hosts $hosts ) {
		$this->fixes = $fixes;
		$this->hosts = $hosts;
	}

	/**
	 * Adds the form handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_axle_runtime_doctor_cache_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_axle_runtime_doctor_cache_steps', array( $this, 'handle_steps' ) );
	}

	/**
	 * URL of the tab.
	 *
	 * @param string $fragment Anchor, with the #.
	 * @return string
	 */
	private static function url( string $fragment = '' ): string {
		return Page::url( 'page-cache' ) . $fragment;
	}

	/**
	 * Checks the capability and the nonce of a request.
	 *
	 * @param string $action Action, without the prefix.
	 */
	private static function verify( string $action ): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'axle-runtime-doctor' ), 403 );
		}
		check_admin_referer( 'axle_runtime_doctor_' . $action );
	}

	/**
	 * What this user did with the tab: hid the Get started card, ran the test.
	 *
	 * @return array{dismissed: bool, runs: int}
	 */
	private static function state(): array {
		$state = get_user_meta( get_current_user_id(), self::STATE, true );
		$state = is_array( $state ) ? $state : array();

		return array(
			'dismissed' => ! empty( $state['dismissed'] ),
			'runs'      => (int) ( $state['runs'] ?? 0 ),
		);
	}

	/**
	 * Saves this user's state.
	 *
	 * @param array $change Keys to change.
	 */
	private static function remember( array $change ): void {
		update_user_meta( get_current_user_id(), self::STATE, array_merge( self::state(), $change ) );
	}

	/**
	 * Runs the test and goes back to the tab with a notice.
	 */
	public function handle_test(): void {
		self::verify( 'cache_test' );

		$scan = Scanner::scan();
		if ( null === $scan ) {
			$flash = array(
				'type' => 'warning',
				'text' => __( 'The page cache test is switched off on this site.', 'axle-runtime-doctor' ),
			);
		} else {
			self::remember( array( 'runs' => self::state()['runs'] + 1 ) );
			$result = self::result( $scan );
			$tone   = $result ? $result->status : Result::INFO;
			$flash  = array(
				'type' => Result::GOOD === $tone ? 'success' : ( Result::INFO === $tone ? 'info' : 'warning' ),
				'text' => $result ? $result->summary . '. ' . $result->value . '.' : __( 'The test ran.', 'axle-runtime-doctor' ),
			);
		}

		update_user_meta( get_current_user_id(), self::FLASH, $flash );
		wp_safe_redirect( self::url( '#ard-cache-result' ) );
		exit;
	}

	/**
	 * Hides the Get started card for this user.
	 */
	public function handle_steps(): void {
		self::verify( 'cache_steps' );
		self::remember( array( 'dismissed' => true ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * What the check says about a scan, without sending any request.
	 *
	 * @param array $scan A scan.
	 * @return Result|null
	 */
	public static function result( array $scan ): ?Result {
		return PageCacheCheck::from_scan( $scan );
	}

	/**
	 * A status as a badge tone.
	 *
	 * @param string $status Result status.
	 * @return string
	 */
	private static function tone( string $status ): string {
		$tones = array(
			Result::CRITICAL    => 'error',
			Result::RECOMMENDED => 'warning',
			Result::GOOD        => 'good',
		);

		return $tones[ $status ] ?? 'info';
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		$this->print_flash();
		echo '<p class="axle-intro">' . esc_html__( 'Find the cookie, header or plugin that makes every visitor skip the page cache. Use it when the host\'s cache is on and almost nothing is served from it.', 'axle-runtime-doctor' ) . '</p>';

		$scan = Scanner::latest();
		$this->steps( $scan );
		$this->run_card( $scan );
		if ( $scan ) {
			$this->results( $scan );
		}
	}

	/**
	 * The notice kept by the last test.
	 */
	private function print_flash(): void {
		$flash = get_user_meta( get_current_user_id(), self::FLASH, true );
		if ( ! is_array( $flash ) || empty( $flash['text'] ) ) {
			return;
		}
		delete_user_meta( get_current_user_id(), self::FLASH );

		$type = in_array( $flash['type'] ?? '', array( 'success', 'warning', 'error' ), true ) ? (string) $flash['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible ard-notice" role="status"><p>' . esc_html( (string) $flash['text'] ) . '</p></div>';
	}

	/**
	 * The Get started card.
	 *
	 * @param array|null $scan The latest scan.
	 */
	private function steps( ?array $scan ): void {
		$state  = self::state();
		$result = $scan ? self::result( $scan ) : null;
		$done   = array(
			null !== $scan,
			null !== $result && Result::GOOD === $result->status,
			$state['runs'] >= 2,
		);
		if ( $state['dismissed'] || ! in_array( false, $done, true ) ) {
			return;
		}

		$steps = array(
			array(
				__( 'Test your key pages', 'axle-runtime-doctor' ),
				__( 'The test loads your home page, latest post and a page twice, as a visitor who is not logged in.', 'axle-runtime-doctor' ),
				'#ard-cache-run',
				__( 'Run the test', 'axle-runtime-doctor' ),
			),
			array(
				__( 'Fix what stops the cache', 'axle-runtime-doctor' ),
				__( 'The findings name each cookie and header, and the plugin or theme behind it when the trace can tell.', 'axle-runtime-doctor' ),
				'#ard-cache-result',
				__( 'Read the findings', 'axle-runtime-doctor' ),
			),
			array(
				__( 'Test again', 'axle-runtime-doctor' ),
				__( 'After a fix, run the test a second time. A cache should answer every page.', 'axle-runtime-doctor' ),
				'#ard-cache-run',
				__( 'Run the test', 'axle-runtime-doctor' ),
			),
		);

		echo '<section class="axle-card ard-started" aria-labelledby="ard-cache-started-title"><h2 class="axle-card__title" id="ard-cache-started-title">' . esc_html__( 'Get started', 'axle-runtime-doctor' ) . '</h2><ol class="axle-steps">';
		foreach ( $steps as $index => $step ) {
			echo '<li class="axle-step"><div class="axle-step__body"><strong class="axle-step__title">' . esc_html( $step[0] ) . '</strong><p>' . esc_html( $step[1] ) . '</p></div>';
			if ( $done[ $index ] ) {
				echo '<span class="axle-badge axle-badge--good">' . esc_html__( 'Done', 'axle-runtime-doctor' ) . '</span>';
			} else {
				echo '<a class="button" href="' . esc_url( $step[2] ) . '">' . esc_html( $step[3] ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ol><p><a href="' . esc_url( wp_nonce_url( add_query_arg( 'action', 'axle_runtime_doctor_cache_steps', admin_url( 'admin-post.php' ) ), 'axle_runtime_doctor_cache_steps' ) ) . '">' . esc_html__( 'Hide this card', 'axle-runtime-doctor' ) . '</a></p></section>';
	}

	/**
	 * The card with the Run button.
	 *
	 * @param array|null $scan The latest scan.
	 */
	private function run_card( ?array $scan ): void {
		echo '<section class="axle-card" id="ard-cache-run" aria-labelledby="ard-cache-run-title"><h2 class="axle-card__title" id="ard-cache-run-title">' . esc_html__( 'Test the page cache', 'axle-runtime-doctor' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each page is requested twice, with no cookies, the way a visitor asks. The second answer shows whether a cache holds the page. This takes up to half a minute.', 'axle-runtime-doctor' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="axle-actions ard-cache-form" data-ard-busy data-ard-busy-text="' . esc_attr__( 'Testing the page cache. This takes a few seconds.', 'axle-runtime-doctor' ) . '">';
		wp_nonce_field( 'axle_runtime_doctor_cache_test' );
		echo '<input type="hidden" name="action" value="axle_runtime_doctor_cache_test">';
		echo '<button type="submit" class="button button-primary">' . esc_html( $scan ? __( 'Test again', 'axle-runtime-doctor' ) : __( 'Test the page cache', 'axle-runtime-doctor' ) ) . '</button> ';
		echo '<span class="spinner" aria-hidden="true"></span><span class="ard-busy-text screen-reader-text" role="status"></span>';
		if ( $scan ) {
			echo '<span class="axle-muted">' . esc_html__( 'Last test:', 'axle-runtime-doctor' ) . ' ' . AxleUi::relative_time( (int) $scan['time'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time().
		}
		echo '</form>';
		if ( ! $scan ) {
			echo '<div class="axle-empty ard-empty"><p>' . esc_html__( 'No test yet. The result lists each page, whether a cache answered it, and what stops a cache, with the plugin or theme behind it when that can be found.', 'axle-runtime-doctor' ) . '</p></div>';
		}
		echo '</section>';
	}

	/**
	 * The result of the latest scan.
	 *
	 * @param array $scan The latest scan.
	 */
	private function results( array $scan ): void {
		$result = self::result( $scan );
		if ( ! $result ) {
			return;
		}

		echo '<section class="axle-card" id="ard-cache-result" aria-labelledby="ard-cache-result-title"><h2 class="axle-card__title" id="ard-cache-result-title">' . esc_html__( 'What the test found', 'axle-runtime-doctor' ) . '</h2>';
		echo '<p class="ard-check-summary"><span class="axle-badge axle-badge--' . esc_attr( self::tone( $result->status ) ) . '">' . esc_html( Result::status_label( $result->status ) ) . '</span> ' . esc_html( $result->summary );
		if ( $result->inferred ) {
			echo ' <span class="ard-inferred">' . esc_html__( '(inferred)', 'axle-runtime-doctor' ) . '</span>';
		}
		echo '</p>';
		if ( '' !== $result->value ) {
			echo '<p>' . esc_html( $result->value ) . '</p>';
		}
		echo '<p class="description">' . esc_html( $result->why ) . '</p>';

		$this->pages( (array) $scan['pages'] );

		if ( $result->details ) {
			echo '<h3>' . esc_html__( 'What we found', 'axle-runtime-doctor' ) . '</h3><ul class="ard-details ard-cache-details">';
			foreach ( $result->details as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}

		if ( in_array( $result->status, array( Result::CRITICAL, Result::RECOMMENDED ), true ) ) {
			FixCard::render( $this->fixes, $result->to_array(), $this->hosts->current() );
		}
		echo '</section>';
	}

	/**
	 * The table with one row per page.
	 *
	 * @param array $pages Pages of a scan.
	 */
	private function pages( array $pages ): void {
		$via = array_unique( array_column( $pages, 'via' ) );
		if ( in_array( 'loopback', $via, true ) ) {
			echo '<p class="description">' . esc_html__( 'The server could not reach its own public address, so these requests went straight to the server. A CDN in front of the site was not tested.', 'axle-runtime-doctor' ) . '</p>';
		}

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Pages tested', 'axle-runtime-doctor' ) . '"><table class="widefat striped ard-cache-pages"><thead><tr>';
		foreach ( array( __( 'Page', 'axle-runtime-doctor' ), __( 'Second request', 'axle-runtime-doctor' ), __( 'Time, first and second', 'axle-runtime-doctor' ), __( 'What stops a cache', 'axle-runtime-doctor' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			echo '<tr><th scope="row">' . esc_html( PageCacheCheck::path( (string) ( $page['url'] ?? '' ) ) ) . '</th><td>';
			if ( '' !== (string) ( $page['error'] ?? '' ) ) {
				echo '<span class="axle-badge axle-badge--info">' . esc_html__( 'Not tested', 'axle-runtime-doctor' ) . '</span> ' . esc_html( (string) $page['error'] ) . '</td><td></td><td></td></tr>';
				continue;
			}
			$state = (string) ( $page['answered'] ?? Analyzer::NONE );
			if ( Analyzer::HIT === $state ) {
				echo '<span class="axle-badge axle-badge--good">' . esc_html__( 'Answered from a cache', 'axle-runtime-doctor' ) . '</span>';
			} elseif ( Analyzer::MISS === $state ) {
				echo '<span class="axle-badge axle-badge--warning">' . esc_html__( 'Not cached', 'axle-runtime-doctor' ) . '</span>';
			} else {
				echo '<span class="axle-badge axle-badge--info">' . esc_html__( 'No cache seen', 'axle-runtime-doctor' ) . '</span>';
			}
			if ( ! empty( $page['by'] ) ) {
				echo '<br><span class="axle-muted">' . esc_html( implode( ', ', array_map( 'strval', (array) $page['by'] ) ) ) . '</span>';
			}
			/* translators: %d: HTTP status code. */
			echo '<br><span class="axle-muted">' . esc_html( sprintf( __( 'HTTP %d', 'axle-runtime-doctor' ), (int) ( $page['status'] ?? 0 ) ) ) . '</span></td>';
			/* translators: 1: milliseconds of the first request, 2: milliseconds of the second request. */
			echo '<td>' . esc_html( sprintf( __( '%1$d ms, %2$d ms', 'axle-runtime-doctor' ), (int) ( $page['cold_ms'] ?? 0 ), (int) ( $page['warm_ms'] ?? 0 ) ) ) . '</td><td>';
			$blockers = (array) ( $page['blockers'] ?? array() );
			if ( ! $blockers ) {
				echo '<span class="axle-muted">' . esc_html__( 'Nothing found in the headers', 'axle-runtime-doctor' ) . '</span>';
			} else {
				echo '<ul class="ard-details">';
				foreach ( $blockers as $blocker ) {
					echo '<li>' . esc_html( (string) ( $blocker['detail'] ?? '' ) ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
