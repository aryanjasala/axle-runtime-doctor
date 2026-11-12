<?php
/**
 * The reports kept for comparing: named, dated, removable.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Compare;

use AryanX\Axle\RuntimeDoctor\Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores imported reports in one option (a network option on multisite).
 * Each one is a clean document from the Importer, never raw input.
 */
final class Library {

	public const OPTION = 'axle_runtime_doctor_imports';
	public const TRASH  = 'axle_runtime_doctor_import_trash';

	/**
	 * How many reports are kept.
	 *
	 * @return int
	 */
	public static function limit(): int {
		/**
		 * Filters how many imported reports are kept.
		 *
		 * @param int $limit Default 10. 1 to 50.
		 */
		return max( 1, min( 50, (int) apply_filters( 'axle_runtime_doctor_import_limit', 10 ) ) );
	}

	/**
	 * Every kept report, newest first.
	 *
	 * @return array<string, array{id: string, name: string, added: int, source: string, report: array}>
	 */
	public function all(): array {
		$stored = Store::get( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$items  = array();
		foreach ( $stored as $id => $item ) {
			if ( is_array( $item ) && isset( $item['report'] ) && is_array( $item['report'] ) && is_string( $id ) ) {
				$items[ $id ] = $item + array(
					'id'     => $id,
					'name'   => $id,
					'added'  => 0,
					'source' => 'paste',
				);
			}
		}
		uasort(
			$items,
			static function ( $a, $b ) {
				return (int) $b['added'] <=> (int) $a['added'];
			}
		);

		return $items;
	}

	/**
	 * One kept report.
	 *
	 * @param string $id Its ID.
	 * @return array|null
	 */
	public function get( string $id ): ?array {
		$all = $this->all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Keeps a report.
	 *
	 * @param array  $report A clean document from Importer::parse().
	 * @param string $name   What to call it. Empty: "Imported report".
	 * @param string $source paste, file or direct.
	 * @return array|WP_Error The stored item, or why it was refused.
	 */
	public function add( array $report, string $name, string $source = 'paste' ) {
		$all = $this->all();
		if ( count( $all ) >= self::limit() ) {
			/* translators: %d: how many reports are kept. */
			return new WP_Error( 'axle_runtime_doctor_import_full', sprintf( _n( 'You can keep %d imported report. Remove one first.', 'You can keep %d imported reports. Remove one first.', self::limit(), 'axle-runtime-doctor' ), self::limit() ) );
		}

		$name = substr( trim( wp_strip_all_tags( $name ) ), 0, 80 );
		$id   = strtolower( wp_generate_password( 10, false, false ) );
		$item = array(
			'id'     => $id,
			'name'   => '' !== $name ? $name : __( 'Imported report', 'axle-runtime-doctor' ),
			'added'  => time(),
			'source' => in_array( $source, array( 'paste', 'file', 'direct' ), true ) ? $source : 'paste',
			'report' => $report,
		);

		$all[ $id ] = $item;
		Store::set( self::OPTION, $all );

		/**
		 * Fires after a report from another site is kept.
		 *
		 * @param string $id     Its ID.
		 * @param array  $item   id, name, added, source, report.
		 */
		do_action( 'axle_runtime_doctor_report_imported', $id, $item );

		return $item;
	}

	/**
	 * Removes a report.
	 *
	 * @param string $id Its ID.
	 * @return bool Whether it was there.
	 */
	public function remove( string $id ): bool {
		$all = $this->all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		$item = $all[ $id ];
		unset( $all[ $id ] );
		if ( $all ) {
			Store::set( self::OPTION, $all );
		} else {
			Store::delete( self::OPTION );
		}

		// Kept for a few minutes, so the notice can offer Undo.
		set_site_transient( self::TRASH, $item, 15 * MINUTE_IN_SECONDS );

		/**
		 * Fires after an imported report is removed.
		 *
		 * @param string $id   Its ID.
		 * @param array  $item The report that was removed: id, name, added, source, report.
		 */
		do_action( 'axle_runtime_doctor_report_removed', $id, $item );

		return true;
	}

	/**
	 * Puts back the report that was removed last, when it is still within the undo window.
	 *
	 * @return array|null The restored item, or null when there is nothing to restore or the limit is reached.
	 */
	public function restore(): ?array {
		$item = get_site_transient( self::TRASH );
		if ( ! is_array( $item ) || ! isset( $item['id'], $item['report'] ) || ! is_string( $item['id'] ) ) {
			return null;
		}
		$all = $this->all();
		if ( isset( $all[ $item['id'] ] ) || count( $all ) >= self::limit() ) {
			return null;
		}

		$all[ $item['id'] ] = $item;
		Store::set( self::OPTION, $all );
		delete_site_transient( self::TRASH );

		/**
		 * Fires after a removed report is put back.
		 *
		 * @param string $id   Its ID.
		 * @param array  $item id, name, added, source, report.
		 */
		do_action( 'axle_runtime_doctor_report_restored', $item['id'], $item );

		return $item;
	}
}
