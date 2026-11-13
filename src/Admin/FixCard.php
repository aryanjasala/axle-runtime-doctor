<?php
/**
 * The "How to fix on <host>" block with the support ticket.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Admin;

use AryanX\Axle\RuntimeDoctor\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * Printed under a check on the Checks tab and under the page cache result.
 */
final class FixCard {

	/**
	 * Prints the fix for a result on a host, when there is one.
	 *
	 * @param Fixes $fixes  Fix data.
	 * @param array $result The result.
	 * @param array $host   The host.
	 */
	public static function render( Fixes $fixes, array $result, array $host ): void {
		$fix = $fixes->get( (string) $result['id'], $host );
		if ( ! $fix ) {
			return;
		}
		$ticket = $fixes->ticket( $result, $host );
		$id     = 'ard-ticket-' . (string) $result['id'];

		echo '<details class="ard-fix"><summary>';
		/* translators: %s: host name. */
		echo esc_html( sprintf( __( 'How to fix on %s', 'axle-runtime-doctor' ), (string) ( $host['label'] ?? '' ) ) );
		echo '</summary>';
		echo '<dl class="axle-facts ard-values">';
		echo '<dt>' . esc_html__( 'Setting', 'axle-runtime-doctor' ) . '</dt><dd>' . esc_html( $fix['setting'] ) . '</dd>';
		echo '<dt>' . esc_html__( 'Where', 'axle-runtime-doctor' ) . '</dt><dd>' . esc_html( $fix['where'] ) . '</dd>';
		echo '</dl>';
		if ( $fix['steps'] ) {
			echo '<ol class="ard-steps">';
			foreach ( $fix['steps'] as $step ) {
				echo '<li>' . esc_html( str_replace( '{site}', home_url( '/' ), $step ) ) . '</li>';
			}
			echo '</ol>';
		}
		if ( '' !== $ticket ) {
			echo '<label for="' . esc_attr( $id ) . '" class="ard-ticket-label">' . esc_html__( 'Support ticket', 'axle-runtime-doctor' ) . '</label>';
			echo '<textarea id="' . esc_attr( $id ) . '" class="ard-ticket large-text" rows="9" readonly>' . esc_textarea( $ticket ) . '</textarea>';
			echo '<p><button type="button" class="button ard-copy" data-ard-copy="' . esc_attr( $id ) . '" data-check="' . esc_attr( (string) $result['id'] ) . '" data-host="' . esc_attr( (string) ( $host['id'] ?? '' ) ) . '">' . esc_html__( 'Copy support ticket', 'axle-runtime-doctor' ) . '</button></p>';
		}
		echo '</details>';
	}
}
