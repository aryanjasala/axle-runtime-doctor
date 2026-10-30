<?php
/**
 * Privacy policy text, export and erase.
 *
 * @package AryanX\Axle\RuntimeDoctor
 */

namespace AryanX\Axle\RuntimeDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin stores no visitor data. The only personal data is an email
 * address an admin may add to the alert recipients, which the exporter lists
 * and the eraser removes.
 */
final class Privacy implements Service {

	public const ID = 'axle-runtime-doctor';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the privacy tools.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ): array {
		$exporters             = (array) $exporters;
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Runtime Doctor alert recipients', 'axle-runtime-doctor' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ): array {
		$erasers             = (array) $erasers;
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Runtime Doctor alert recipients', 'axle-runtime-doctor' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Whether an address is on the saved recipients list.
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	private function listed( string $email ): bool {
		return in_array( strtolower( $email ), array_map( 'strtolower', Settings::emails( (string) $this->settings->get( 'recipients' ) ) ), true );
	}

	/**
	 * Exports whether the address gets regression alerts.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		unset( $page );
		$data = array();
		if ( $this->listed( (string) $email ) ) {
			$data[] = array(
				'group_id'    => 'axle-runtime-doctor',
				'group_label' => __( 'Runtime Doctor', 'axle-runtime-doctor' ),
				'item_id'     => 'axle-runtime-doctor-recipient',
				'data'        => array(
					array(
						'name'  => __( 'Gets regression alerts at', 'axle-runtime-doctor' ),
						'value' => (string) $email,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Removes the address from the recipients.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$removed = false;
		if ( $this->listed( (string) $email ) ) {
			$keep = array_filter(
				Settings::emails( (string) $this->settings->get( 'recipients' ) ),
				static function ( $address ) use ( $email ) {
					return strtolower( $address ) !== strtolower( (string) $email );
				}
			);
			$this->settings->save( array( 'recipients' => implode( ', ', $keep ) ) );
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested text for Settings > Privacy.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = __( 'Runtime Doctor checks how the server running this site is set up. It stores no data about visitors: its samples hold only counts and memory use, and the database queries it measures are stored with every value taken out. An administrator can add email addresses that get an alert when a check gets worse.', 'axle-runtime-doctor' );

		wp_add_privacy_policy_content( __( 'Axle Runtime Doctor', 'axle-runtime-doctor' ), '<p>' . esc_html( $text ) . '</p>' );
	}
}
