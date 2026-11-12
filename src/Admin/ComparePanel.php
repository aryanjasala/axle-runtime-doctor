<?php
/**
 * Tools > Runtime Doctor > Compare.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Admin;

use AryanX\Axle\RuntimeDoctor\Compare\Comparer;
use AryanX\Axle\RuntimeDoctor\Compare\Direct;
use AryanX\Axle\RuntimeDoctor\Compare\Exporter;
use AryanX\Axle\RuntimeDoctor\Compare\Importer;
use AryanX\Axle\RuntimeDoctor\Compare\Library;
use AryanX\Axle\RuntimeDoctor\History;
use AryanX\Axle\RuntimeDoctor\Result;
use AryanX\Axle\RuntimeDoctor\Service;
use AryanX\Axle\RuntimeDoctor\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The Compare tab: export this site's report, bring in another site's, and
 * see what differs. Forms post to admin-post.php and come back with a notice
 * kept in the user's meta for one page view.
 */
final class ComparePanel implements Service {

	public const FLASH = 'axle_runtime_doctor_flash';
	public const STATE = 'axle_runtime_doctor_compare_state';

	/**
	 * Snapshots and the latest report.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Kept reports.
	 *
	 * @var Library
	 */
	private Library $library;

	/**
	 * Sets up the tab.
	 *
	 * @param History $history Snapshots.
	 * @param Library $library Kept reports.
	 */
	public function __construct( History $history, Library $library ) {
		$this->history = $history;
		$this->library = $library;
	}

	/**
	 * Adds the form handlers.
	 */
	public function register(): void {
		foreach ( array( 'export', 'import', 'fetch', 'code', 'remove', 'restore', 'steps' ) as $action ) {
			add_action( 'admin_post_axle_runtime_doctor_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * URL of the tab.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	private static function url( array $args = array() ): string {
		return Page::url( 'compare', $args );
	}

	/**
	 * A link that runs an action behind a nonce.
	 *
	 * @param string $action admin-post action, without the prefix.
	 * @param array  $args   Extra query arguments.
	 * @return string
	 */
	private static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'axle_runtime_doctor_' . $action ), $args ), admin_url( 'admin-post.php' ) ), 'axle_runtime_doctor_' . $action );
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
	 * Keeps a notice for the next page view.
	 *
	 * @param string $type  success, warning or error.
	 * @param string $text  The message.
	 * @param array  $extra undo (an action name) or code and expires.
	 */
	private static function flash( string $type, string $text, array $extra = array() ): void {
		update_user_meta(
			get_current_user_id(),
			self::FLASH,
			array_merge(
				array(
					'type' => $type,
					'text' => $text,
				),
				$extra
			)
		);
	}

	/**
	 * Back to the tab.
	 *
	 * @param array $args Query arguments.
	 */
	private static function back( array $args = array() ): void {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * What this user did with the tab: dismissed the Get started card, compared once.
	 *
	 * @return array{dismissed: bool, compared: bool}
	 */
	private static function state(): array {
		$state = get_user_meta( get_current_user_id(), self::STATE, true );
		$state = is_array( $state ) ? $state : array();

		return array(
			'dismissed' => ! empty( $state['dismissed'] ),
			'compared'  => ! empty( $state['compared'] ),
		);
	}

	/**
	 * Remembers something about this user's use of the tab.
	 *
	 * @param string $key dismissed or compared.
	 */
	private static function remember( string $key ): void {
		$state         = self::state();
		$state[ $key ] = true;
		update_user_meta( get_current_user_id(), self::STATE, $state );
	}

	/**
	 * Downloads this site's report as a JSON file.
	 */
	public function handle_export(): void {
		self::verify( 'export' );
		$report = $this->history->latest();
		if ( ! $report ) {
			self::flash( 'warning', __( 'There is no report to export yet. Run the checks first.', 'axle-runtime-doctor' ) );
			self::back();
		}

		$json = Exporter::to_json( Exporter::publish( $report, 'download' ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . Exporter::filename() . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A JSON file, not HTML.
		exit;
	}

	/**
	 * Imports a pasted or uploaded report.
	 */
	public function handle_import(): void {
		self::verify( 'import' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in self::verify().
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$text   = isset( $_POST['report'] ) ? wp_unslash( $_POST['report'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as data by Importer::parse(), never output as it is.
		$source = 'paste';
		$upload = isset( $_FILES['report_file'] ) && is_array( $_FILES['report_file'] ) ? $_FILES['report_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is checked below.
		// phpcs:enable

		if ( ! empty( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) && UPLOAD_ERR_OK === (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			if ( ! is_uploaded_file( $upload['tmp_name'] ) || (int) ( $upload['size'] ?? 0 ) > Importer::max_bytes() ) {
				self::flash( 'error', __( 'That file is too large or did not upload properly. Reports are small: export it again.', 'axle-runtime-doctor' ) );
				self::back();
			}
			$text   = (string) file_get_contents( $upload['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local upload, size-checked above.
			$source = 'file';
			if ( '' === $name && isset( $upload['name'] ) ) {
				$name = sanitize_text_field( pathinfo( (string) $upload['name'], PATHINFO_FILENAME ) );
			}
		}

		$this->keep( Importer::parse( is_string( $text ) ? $text : '' ), $name, $source );
	}

	/**
	 * Fetches a report with a one-time code.
	 */
	public function handle_fetch(): void {
		self::verify( 'fetch' );
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in self::verify().
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in self::verify().

		$this->keep( Direct::fetch( $code ), '' !== $name ? $name : Direct::site_name( $code ), 'direct' );
	}

	/**
	 * Keeps a parsed report, or says why not, and goes back.
	 *
	 * @param array|WP_Error $document The report or the error.
	 * @param string         $name     Its name.
	 * @param string         $source   paste, file or direct.
	 */
	private function keep( $document, string $name, string $source ): void {
		if ( is_wp_error( $document ) ) {
			self::flash( 'error', $document->get_error_message() );
			self::back();
		}
		$item = $this->library->add( $document, $name, $source );
		if ( is_wp_error( $item ) ) {
			self::flash( 'error', $item->get_error_message() );
			self::back();
		}

		/* translators: %s: name of the report. */
		self::flash( 'success', sprintf( __( 'Saved the report "%s". It is compared with this site below.', 'axle-runtime-doctor' ), $item['name'] ) );
		self::back(
			array(
				'a' => 'this',
				'b' => $item['id'],
			)
		);
	}

	/**
	 * Makes a one-time code.
	 */
	public function handle_code(): void {
		self::verify( 'code' );
		$made = Direct::create_code();
		if ( is_wp_error( $made ) ) {
			self::flash( 'error', $made->get_error_message() );
			self::back();
		}

		self::flash(
			'success',
			__( 'The code is ready. It works once.', 'axle-runtime-doctor' ),
			array(
				'code'    => $made['code'],
				'expires' => $made['expires'],
			)
		);
		self::back();
	}

	/**
	 * Removes a kept report.
	 */
	public function handle_remove(): void {
		self::verify( 'remove' );
		$id   = isset( $_POST['report_id'] ) ? sanitize_key( wp_unslash( $_POST['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in self::verify().
		$item = $this->library->get( $id );
		if ( $item && $this->library->remove( $id ) ) {
			/* translators: %s: name of the report. */
			self::flash( 'success', sprintf( __( 'Removed the report "%s".', 'axle-runtime-doctor' ), $item['name'] ), array( 'undo' => 'restore' ) );
		} else {
			self::flash( 'warning', __( 'That report was already gone.', 'axle-runtime-doctor' ) );
		}
		self::back();
	}

	/**
	 * Puts back the report removed last.
	 */
	public function handle_restore(): void {
		self::verify( 'restore' );
		$item = $this->library->restore();
		if ( $item ) {
			/* translators: %s: name of the report. */
			self::flash( 'success', sprintf( __( 'Put back the report "%s".', 'axle-runtime-doctor' ), $item['name'] ) );
		} else {
			self::flash( 'warning', __( 'There is nothing to put back. The undo window is over, or the list is full.', 'axle-runtime-doctor' ) );
		}
		self::back();
	}

	/**
	 * Hides the Get started card for this user.
	 */
	public function handle_steps(): void {
		self::verify( 'steps' );
		self::remember( 'dismissed' );
		self::back();
	}

	/**
	 * The names of the reports a person can pick.
	 *
	 * @return array<string, string> ID => name. "this" is this site.
	 */
	private function choices(): array {
		$choices = array();
		if ( $this->history->latest() ) {
			$choices['this'] = __( 'This site (latest run)', 'axle-runtime-doctor' );
		}
		foreach ( $this->library->all() as $id => $item ) {
			$choices[ $id ] = (string) $item['name'];
		}

		return $choices;
	}

	/**
	 * The clean document behind a choice.
	 *
	 * @param string $id "this" or a kept report ID.
	 * @return array|null
	 */
	private function document( string $id ): ?array {
		if ( 'this' === $id ) {
			$report = $this->history->latest();

			return $report ? Exporter::build( $report ) : null;
		}
		$item = $this->library->get( $id );

		return $item ? (array) $item['report'] : null;
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		$this->print_flash();
		echo '<p class="axle-intro">' . esc_html__( 'Put two sites side by side and see what differs. Use it when staging is fast, production is slow and the host says both are the same plan.', 'axle-runtime-doctor' ) . '</p>';

		$choices = $this->choices();
		$this->steps();
		$this->comparison( $choices );
		$this->share();
		$this->add();
		$this->saved();
	}

	/**
	 * The notice kept by the last action.
	 */
	private function print_flash(): void {
		$flash = get_user_meta( get_current_user_id(), self::FLASH, true );
		if ( ! is_array( $flash ) || empty( $flash['text'] ) ) {
			return;
		}
		delete_user_meta( get_current_user_id(), self::FLASH );

		$type = in_array( $flash['type'] ?? '', array( 'success', 'warning', 'error' ), true ) ? (string) $flash['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible ard-notice" role="status"><p>' . esc_html( (string) $flash['text'] );
		if ( 'restore' === ( $flash['undo'] ?? '' ) ) {
			echo ' <a href="' . esc_url( self::action_url( 'restore' ) ) . '">' . esc_html__( 'Undo', 'axle-runtime-doctor' ) . '</a>';
		}
		echo '</p>';
		if ( ! empty( $flash['code'] ) ) {
			echo '<p><label for="ard-code-made">' . esc_html__( 'One-time code', 'axle-runtime-doctor' ) . '</label><br>';
			echo '<input type="text" readonly class="large-text code" id="ard-code-made" value="' . esc_attr( (string) $flash['code'] ) . '"> ';
			echo '<button type="button" class="button ard-copy" data-ard-copy="ard-code-made">' . esc_html__( 'Copy the code', 'axle-runtime-doctor' ) . '</button></p>';
			echo '<p class="description">' . wp_kses_post(
				sprintf(
					/* translators: %s: how long until the code stops working, such as "10 minutes". */
					__( 'Paste it on the other site under Compare, in "Fetch a report with a code". It stops working in %s, or after the first use.', 'axle-runtime-doctor' ),
					human_time_diff( time(), (int) ( $flash['expires'] ?? time() ) )
				)
			) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The Get started card.
	 */
	private function steps(): void {
		$state = self::state();
		$done  = array(
			(bool) $this->history->latest(),
			(bool) $this->library->all(),
			$state['compared'],
		);
		if ( $state['dismissed'] || ! in_array( false, $done, true ) ) {
			return;
		}

		$steps = array(
			array(
				__( 'Run the checks on this site', 'axle-runtime-doctor' ),
				__( 'The report you compare comes from the last full run.', 'axle-runtime-doctor' ),
				Page::url( 'checks' ),
				__( 'Open the checks', 'axle-runtime-doctor' ),
			),
			array(
				__( 'Bring in the other site', 'axle-runtime-doctor' ),
				__( 'Export its report there and paste it here, or make a one-time code there if it runs this plugin too.', 'axle-runtime-doctor' ),
				'#ard-add',
				__( 'Add a report', 'axle-runtime-doctor' ),
			),
			array(
				__( 'See what differs', 'axle-runtime-doctor' ),
				__( 'Differences come first, each with what it means.', 'axle-runtime-doctor' ),
				'#ard-compare',
				__( 'Compare reports', 'axle-runtime-doctor' ),
			),
		);

		echo '<section class="axle-card ard-started" aria-labelledby="ard-started-title"><h2 class="axle-card__title" id="ard-started-title">' . esc_html__( 'Get started', 'axle-runtime-doctor' ) . '</h2><ol class="axle-steps">';
		foreach ( $steps as $index => $step ) {
			echo '<li class="axle-step"><div class="axle-step__body"><strong class="axle-step__title">' . esc_html( $step[0] ) . '</strong><p>' . esc_html( $step[1] ) . '</p></div>';
			if ( $done[ $index ] ) {
				echo '<span class="axle-badge axle-badge--good">' . esc_html__( 'Done', 'axle-runtime-doctor' ) . '</span>';
			} else {
				echo '<a class="button" href="' . esc_url( $step[2] ) . '">' . esc_html( $step[3] ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ol><p><a href="' . esc_url( self::action_url( 'steps' ) ) . '">' . esc_html__( 'Hide this card', 'axle-runtime-doctor' ) . '</a></p></section>';
	}

	/**
	 * The picker and the result.
	 *
	 * @param array<string, string> $choices What can be compared.
	 */
	private function comparison( array $choices ): void {
		echo '<section class="axle-card" id="ard-compare" aria-labelledby="ard-compare-title"><h2 class="axle-card__title" id="ard-compare-title">' . esc_html__( 'Compare two reports', 'axle-runtime-doctor' ) . '</h2>';

		if ( count( $choices ) < 2 ) {
			echo '<div class="axle-empty ard-empty">';
			if ( ! $this->history->latest() ) {
				echo '<p>' . esc_html__( 'Run the checks on this site first, then add the other site\'s report. You compare any two of them.', 'axle-runtime-doctor' ) . '</p>';
				echo '<a class="button button-primary" href="' . esc_url( Page::url( 'checks' ) ) . '">' . esc_html__( 'Run the checks', 'axle-runtime-doctor' ) . '</a>';
			} else {
				echo '<p>' . esc_html__( 'Nothing to compare with yet. Add the report of your other site below, and the differences show up here, worst first.', 'axle-runtime-doctor' ) . '</p>';
				echo '<a class="button button-primary" href="#ard-add">' . esc_html__( 'Add a report', 'axle-runtime-doctor' ) . '</a>';
			}
			echo '</div></section>';

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only choice of two reports.
		$ids   = array_keys( $choices );
		$first = isset( $_GET['a'] ) ? sanitize_key( wp_unslash( $_GET['a'] ) ) : '';
		$other = isset( $_GET['b'] ) ? sanitize_key( wp_unslash( $_GET['b'] ) ) : '';
		// phpcs:enable
		$picked = '' !== $first || '' !== $other;
		$first  = isset( $choices[ $first ] ) ? $first : $ids[0];
		$other  = isset( $choices[ $other ] ) && $other !== $first ? $other : ( $ids[1] ?? $ids[0] );
		if ( $other === $first ) {
			$other = $ids[0] === $first ? $ids[1] : $ids[0];
		}

		echo '<form method="get" action="' . esc_url( is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'tools.php' ) ) . '" class="axle-toolbar ard-pick">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Page::SLUG ) . '"><input type="hidden" name="tab" value="compare">';
		foreach ( array(
			'a' => array( __( 'First report', 'axle-runtime-doctor' ), $first ),
			'b' => array( __( 'Second report', 'axle-runtime-doctor' ), $other ),
		) as $field => $config ) {
			echo '<label for="ard-pick-' . esc_attr( $field ) . '" class="screen-reader-text">' . esc_html( $config[0] ) . '</label>';
			echo '<select id="ard-pick-' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '">';
			foreach ( $choices as $id => $name ) {
				echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $config[1], (string) $id, false ) . '>' . esc_html( $name ) . '</option>';
			}
			echo '</select>';
			if ( 'a' === $field ) {
				echo '<span aria-hidden="true">' . esc_html__( 'and', 'axle-runtime-doctor' ) . '</span>';
			}
		}
		echo '<span class="axle-toolbar__end"><button type="submit" class="button button-primary">' . esc_html__( 'Compare reports', 'axle-runtime-doctor' ) . '</button></span></form>';

		$doc_a = $this->document( $first );
		$doc_b = $this->document( $other );
		if ( $doc_a && $doc_b ) {
			if ( $picked ) {
				self::remember( 'compared' );
			}
			$this->result( Comparer::compare( $doc_a, $doc_b, (string) $choices[ $first ], (string) $choices[ $other ] ), (string) $choices[ $first ], (string) $choices[ $other ] );
		}
		echo '</section>';
	}

	/**
	 * The side-by-side tables.
	 *
	 * @param array  $result Comparer::compare() output.
	 * @param string $name_a First name.
	 * @param string $name_b Second name.
	 */
	private function result( array $result, string $name_a, string $name_b ): void {
		$counts = $result['counts'];
		$rows   = $result['rows'];
		$differ = $counts[ Comparer::DIFFERENT ] + $counts[ Comparer::ONLY ];

		echo '<div class="axle-stats ard-compare-stats" role="status">';
		printf(
			'<div class="axle-stat axle-stat--%1$s"><span class="axle-stat__value">%2$d</span><span class="axle-stat__label">%3$s</span></div>',
			$differ ? 'warning' : 'good',
			(int) $differ,
			esc_html__( 'differences', 'axle-runtime-doctor' )
		);
		printf(
			'<div class="axle-stat axle-stat--info"><span class="axle-stat__value">%1$d</span><span class="axle-stat__label">%2$s</span></div>',
			(int) $counts[ Comparer::MINOR ],
			esc_html__( 'same verdict, other numbers', 'axle-runtime-doctor' )
		);
		printf(
			'<div class="axle-stat axle-stat--good"><span class="axle-stat__value">%1$d</span><span class="axle-stat__label">%2$s</span></div>',
			(int) $counts[ Comparer::SAME ],
			esc_html__( 'identical', 'axle-runtime-doctor' )
		);
		echo '</div>';

		$groups = array(
			array( array( Comparer::DIFFERENT, Comparer::ONLY ), __( 'Differences', 'axle-runtime-doctor' ), true ),
			array( array( Comparer::MINOR ), __( 'Same verdict, different numbers', 'axle-runtime-doctor' ), false ),
			array( array( Comparer::SAME ), __( 'Identical', 'axle-runtime-doctor' ), false ),
		);
		foreach ( $groups as $group ) {
			$subset = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $group ) {
						return in_array( $row['state'], $group[0], true );
					}
				)
			);
			if ( ! $subset ) {
				if ( $group[2] ) {
					echo '<p class="ard-none">' . esc_html__( 'No differences. These two reports match on every check and setting.', 'axle-runtime-doctor' ) . '</p>';
				}
				continue;
			}
			if ( $group[2] ) {
				echo '<h3 class="ard-compare-title">' . esc_html( $group[1] ) . '</h3>';
				$this->table( $subset, $name_a, $name_b, $group[1] );
			} else {
				echo '<details class="ard-compare-more"><summary>' . esc_html( sprintf( '%1$s (%2$d)', $group[1], count( $subset ) ) ) . '</summary>';
				$this->table( $subset, $name_a, $name_b, $group[1] );
				echo '</details>';
			}
		}
	}

	/**
	 * One table of rows.
	 *
	 * @param array[] $rows   Rows.
	 * @param string  $name_a First name.
	 * @param string  $name_b Second name.
	 * @param string  $label  What the table is called.
	 */
	private function table( array $rows, string $name_a, string $name_b, string $label ): void {
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr( $label ) . '"><table class="widefat striped ard-compare"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Check or setting', 'axle-runtime-doctor' ) . '</th>';
		echo '<th scope="col">' . esc_html( $name_a ) . '</th><th scope="col">' . esc_html( $name_b ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'What it means', 'axle-runtime-doctor' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr data-key="' . esc_attr( (string) $row['key'] ) . '"><th scope="row">' . esc_html( (string) $row['label'] ) . '<br><span class="axle-muted">' . esc_html( 'check' === $row['type'] ? __( 'Check', 'axle-runtime-doctor' ) : __( 'Setting', 'axle-runtime-doctor' ) ) . '</span></th>';
			echo '<td class="ard-side-a">' . $this->side( $row['a'], $row['type'] ) . '</td><td class="ard-side-b">' . $this->side( $row['b'], $row['type'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in side().
			echo '<td>' . esc_html( (string) $row['meaning'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * One side of a row.
	 *
	 * @param array|null $side Result or setting.
	 * @param string     $type check or setting.
	 * @return string HTML.
	 */
	private function side( ?array $side, string $type ): string {
		if ( null === $side ) {
			return '<span class="axle-muted">' . esc_html__( 'Not reported', 'axle-runtime-doctor' ) . '</span>';
		}
		$tones = array(
			Result::CRITICAL    => 'error',
			Result::RECOMMENDED => 'warning',
			Result::GOOD        => 'good',
		);
		$html  = '';
		if ( 'check' === $type ) {
			$status = (string) ( $side['status'] ?? Result::INFO );
			$html  .= '<span class="axle-badge axle-badge--' . esc_attr( $tones[ $status ] ?? 'info' ) . '">' . esc_html( Result::status_label( $status ) ) . '</span> ';
		}
		$value = (string) ( $side['value'] ?? '' );

		return $html . esc_html( '' !== $value ? $value : ( 'check' === $type ? (string) ( $side['summary'] ?? '' ) : '' ) );
	}

	/**
	 * Export and one-time code.
	 */
	private function share(): void {
		$report = $this->history->latest();
		echo '<section class="axle-card" id="ard-share" aria-labelledby="ard-share-title"><h2 class="axle-card__title" id="ard-share-title">' . esc_html__( 'Share this site\'s report', 'axle-runtime-doctor' ) . '</h2>';
		echo '<p>' . esc_html__( 'The file holds each check\'s verdict and the PHP, OPcache and WordPress settings. Paths, hostnames, email addresses and IP addresses are replaced before it leaves the site.', 'axle-runtime-doctor' ) . '</p>';

		if ( ! $report ) {
			echo '<div class="axle-empty ard-empty"><p>' . esc_html__( 'There is no report to share yet. Run the checks first.', 'axle-runtime-doctor' ) . '</p><a class="button" href="' . esc_url( Page::url( 'checks' ) ) . '">' . esc_html__( 'Run the checks', 'axle-runtime-doctor' ) . '</a></div></section>';

			return;
		}

		echo '<p class="axle-actions"><a class="button button-primary ard-export" href="' . esc_url( self::action_url( 'export' ) ) . '">' . esc_html__( 'Download the report', 'axle-runtime-doctor' ) . '</a></p>';
		echo '<details class="ard-json"><summary>' . esc_html__( 'Show the report as text to copy', 'axle-runtime-doctor' ) . '</summary>';
		echo '<label for="ard-export-json" class="screen-reader-text">' . esc_html__( 'The report as JSON', 'axle-runtime-doctor' ) . '</label>';
		echo '<textarea id="ard-export-json" class="large-text code" rows="10" readonly>' . esc_textarea( Exporter::to_json( Exporter::build( $report ) ) ) . '</textarea>';
		echo '<p><button type="button" class="button ard-copy" data-ard-copy="ard-export-json">' . esc_html__( 'Copy the report', 'axle-runtime-doctor' ) . '</button></p></details>';

		if ( Direct::enabled() ) {
			echo '<h3>' . esc_html__( 'Or let another site fetch it', 'axle-runtime-doctor' ) . '</h3>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: how long a code works, such as "10 minutes". */
					__( 'If the other site runs Runtime Doctor too, make a one-time code here and paste it there. The code works once and stops after %s. It needs an https address.', 'axle-runtime-doctor' ),
					human_time_diff( 0, Direct::ttl() )
				)
			) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'axle_runtime_doctor_code' );
			echo '<input type="hidden" name="action" value="axle_runtime_doctor_code"><button type="submit" class="button">' . esc_html__( 'Make a one-time code', 'axle-runtime-doctor' ) . '</button></form>';
		}
		echo '</section>';
	}

	/**
	 * The forms that bring a report in.
	 */
	private function add(): void {
		$limit = Importer::max_bytes();
		echo '<section class="axle-card" id="ard-add" aria-labelledby="ard-add-title"><h2 class="axle-card__title" id="ard-add-title">' . esc_html__( 'Add another site\'s report', 'axle-runtime-doctor' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: largest size, such as 256 KB. */
				__( 'The report is read as data only: it is checked field by field, cut to size (up to %s) and never run.', 'axle-runtime-doctor' ),
				size_format( $limit )
			)
		) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ard-import">';
		wp_nonce_field( 'axle_runtime_doctor_import' );
		echo '<input type="hidden" name="action" value="axle_runtime_doctor_import">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="ard-import-name">' . esc_html__( 'Name', 'axle-runtime-doctor' ) . '</label></th><td><input type="text" class="regular-text" id="ard-import-name" name="name" maxlength="80" aria-describedby="ard-import-name-help"><p class="description" id="ard-import-name-help">' . esc_html__( 'For example "Production" or "Before the host migration".', 'axle-runtime-doctor' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="ard-import-file">' . esc_html__( 'Report file', 'axle-runtime-doctor' ) . '</label></th><td><input type="file" id="ard-import-file" name="report_file" accept=".json,application/json"></td></tr>';
		echo '<tr><th scope="row"><label for="ard-import-text">' . esc_html__( 'Or paste the report', 'axle-runtime-doctor' ) . '</label></th><td><textarea id="ard-import-text" name="report" class="large-text code" rows="6"></textarea></td></tr>';
		echo '</tbody></table><p><button type="submit" class="button button-primary">' . esc_html__( 'Import the report', 'axle-runtime-doctor' ) . '</button></p></form>';

		if ( Direct::enabled() ) {
			echo '<h3 id="ard-fetch-title">' . esc_html__( 'Fetch a report with a code', 'axle-runtime-doctor' ) . '</h3>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ard-fetch" data-ard-busy aria-labelledby="ard-fetch-title">';
			wp_nonce_field( 'axle_runtime_doctor_fetch' );
			echo '<input type="hidden" name="action" value="axle_runtime_doctor_fetch">';
			echo '<table class="form-table" role="presentation"><tbody>';
			echo '<tr><th scope="row"><label for="ard-fetch-code">' . esc_html__( 'One-time code', 'axle-runtime-doctor' ) . '</label></th><td><input type="text" class="large-text code" id="ard-fetch-code" name="code" autocomplete="off" aria-describedby="ard-fetch-help"><p class="description" id="ard-fetch-help">' . esc_html__( 'Made on the other site under Compare, with "Make a one-time code". This site calls it once, over https.', 'axle-runtime-doctor' ) . '</p></td></tr>';
			echo '<tr><th scope="row"><label for="ard-fetch-name">' . esc_html__( 'Name', 'axle-runtime-doctor' ) . '</label></th><td><input type="text" class="regular-text" id="ard-fetch-name" name="name" maxlength="80"><p class="description">' . esc_html__( 'Empty: the other site\'s address.', 'axle-runtime-doctor' ) . '</p></td></tr>';
			echo '</tbody></table><p><button type="submit" class="button">' . esc_html__( 'Fetch the report', 'axle-runtime-doctor' ) . '</button> <span class="spinner" aria-hidden="true"></span><span class="ard-busy-text screen-reader-text" role="status"></span></p></form>';
		}
		echo '</section>';
	}

	/**
	 * The kept reports.
	 */
	private function saved(): void {
		$items = $this->library->all();
		echo '<section class="axle-card" id="ard-saved" aria-labelledby="ard-saved-title"><h2 class="axle-card__title" id="ard-saved-title">' . esc_html__( 'Saved reports', 'axle-runtime-doctor' ) . '</h2>';
		if ( ! $items ) {
			echo '<div class="axle-empty ard-empty"><p>' . esc_html__( 'No reports saved yet. A report you import stays here, so you can compare "before the host migration" with "after" any time.', 'axle-runtime-doctor' ) . '</p><a class="button" href="#ard-add">' . esc_html__( 'Add a report', 'axle-runtime-doctor' ) . '</a></div></section>';

			return;
		}

		$sources = array(
			'paste'  => __( 'Pasted', 'axle-runtime-doctor' ),
			'file'   => __( 'File', 'axle-runtime-doctor' ),
			'direct' => __( 'Fetched with a code', 'axle-runtime-doctor' ),
		);
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Saved reports', 'axle-runtime-doctor' ) . '"><table class="widefat striped ard-saved"><thead><tr>';
		foreach ( array( __( 'Name', 'axle-runtime-doctor' ), __( 'Added', 'axle-runtime-doctor' ), __( 'Taken', 'axle-runtime-doctor' ), __( 'How', 'axle-runtime-doctor' ), __( 'Host', 'axle-runtime-doctor' ), __( 'Actions', 'axle-runtime-doctor' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $items as $id => $item ) {
			$report = (array) $item['report'];
			$dom    = 'ard-remove-' . $id;
			echo '<tr data-report="' . esc_attr( (string) $id ) . '"><th scope="row">' . esc_html( (string) $item['name'] ) . '</th>';
			echo '<td>' . AxleUi::relative_time( (int) $item['added'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time().
			echo '<td>' . AxleUi::relative_time( (int) ( $report['time'] ?? 0 ), __( 'Not known', 'axle-runtime-doctor' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time().
			echo '<td>' . esc_html( $sources[ $item['source'] ] ?? '' ) . '</td>';
			echo '<td>' . esc_html( (string) ( $report['host']['label'] ?? '' ) ) . '</td><td>';
			if ( $this->history->latest() ) {
				echo '<a href="' . esc_url(
					self::url(
						array(
							'a' => 'this',
							'b' => (string) $id,
						)
					) . '#ard-compare' 
				) . '">' . esc_html__( 'Compare with this site', 'axle-runtime-doctor' ) . '<span class="screen-reader-text"> ' . esc_html( (string) $item['name'] ) . '</span></a> | ';
			}
			echo '<button type="button" class="button button-small" data-ard-confirm="' . esc_attr( $dom ) . '" aria-controls="' . esc_attr( $dom ) . '" aria-expanded="false">' . esc_html__( 'Remove', 'axle-runtime-doctor' ) . '<span class="screen-reader-text"> ' . esc_html( (string) $item['name'] ) . '</span></button>';
			echo '<div class="axle-confirm axle-confirm--danger" id="' . esc_attr( $dom ) . '" role="group" aria-labelledby="' . esc_attr( $dom ) . '-text" hidden>';
			/* translators: %s: name of the report. */
			echo '<p id="' . esc_attr( $dom ) . '-text">' . esc_html( sprintf( __( 'Remove "%s" from the saved reports? The report is deleted from this site. You can undo it for 15 minutes.', 'axle-runtime-doctor' ), (string) $item['name'] ) ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="axle-actions">';
			wp_nonce_field( 'axle_runtime_doctor_remove' );
			echo '<input type="hidden" name="action" value="axle_runtime_doctor_remove"><input type="hidden" name="report_id" value="' . esc_attr( (string) $id ) . '">';
			echo '<button type="submit" class="button axle-button--danger">' . esc_html__( 'Remove the report', 'axle-runtime-doctor' ) . '</button> <button type="button" class="button" data-ard-cancel="' . esc_attr( $dom ) . '">' . esc_html__( 'Keep it', 'axle-runtime-doctor' ) . '</button></form></div>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}
}
