<?php
/**
 * Whether max_input_vars fits the biggest menu and settings form.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor\Checks;

use AryanX\Axle\RuntimeDoctor\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Whether max_input_vars fits the biggest menu and settings form.
 */
final class PhpInputVars extends BaseCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'php_input_vars';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'php';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'max_input_vars', 'axle-runtime-doctor' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'PHP drops every form field past max_input_vars without an error. A big menu saved in Appearance > Menus sends about 12 fields per item, so the last items vanish on save. Big settings forms lose their last fields the same way.', 'axle-runtime-doctor' );
	}

	/**
	 * Runs the check.
	 *
	 * @param Context $context Facts.
	 * @return Result|null
	 */
	public function run( Context $context ): ?Result {
		$raw = $context->ini( 'max_input_vars' );
		if ( '' === $raw ) {
			return null;
		}

		$max      = (int) $raw;
		$menu     = $context->arr( 'menus' );
		$form     = $context->arr( 'settings_form' );
		$items    = (int) ( $menu['count'] ?? 0 );
		$needed   = $items * 12 + 20;
		$fields   = (int) ( $form['count'] ?? 0 );
		$details  = array();
		$problems = array();

		if ( $items > 0 ) {
			/* translators: 1: menu name, 2: number of items, 3: number of fields. */
			$details[] = sprintf( __( 'Largest menu: %1$s, %2$d items, about %3$d fields', 'axle-runtime-doctor' ), (string) ( $menu['name'] ?? '' ), $items, $needed );
			if ( $needed > $max ) {
				/* translators: 1: menu name, 2: number of items. */
				$problems[] = sprintf( __( 'Saving the menu "%1$s" (%2$d items) loses items', 'axle-runtime-doctor' ), (string) ( $menu['name'] ?? '' ), $items );
			}
		}
		if ( $fields > 0 ) {
			/* translators: 1: settings group, 2: number of fields. */
			$details[] = sprintf( __( 'Largest settings form: %1$s, %2$d fields', 'axle-runtime-doctor' ), (string) ( $form['group'] ?? '' ), $fields );
			if ( $fields > $max ) {
				/* translators: %s: settings group. */
				$problems[] = sprintf( __( 'Saving the settings form "%s" loses fields', 'axle-runtime-doctor' ), (string) ( $form['group'] ?? '' ) );
			}
		}

		$suggested = (int) max( 1000, ceil( max( $needed, $fields ) * 1.2 / 500 ) * 500 );
		$result    = array(
			'value'    => (string) $max,
			/* translators: %d: number of fields. */
			'expected' => sprintf( __( 'At least %d', 'axle-runtime-doctor' ), max( $needed, $fields ) ),
			'details'  => array_merge( $problems, $details ),
			'data'     => array(
				'max'       => $max,
				'menu'      => (string) ( $menu['name'] ?? '' ),
				'items'     => $items,
				'suggested' => $suggested,
			),
		);

		if ( $problems ) {
			return $this->result( Result::RECOMMENDED, $problems[0], $result );
		}

		return $this->result( Result::GOOD, __( 'max_input_vars fits the biggest menu and settings form', 'axle-runtime-doctor' ), $result );
	}
}
