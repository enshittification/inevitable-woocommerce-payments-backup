<?php
/**
 * Class WC_Payments_Onboarding_Service
 *
 * @package WooCommerce\Payments
 */

use WCPay\Database_Cache;
use WCPay\Exceptions\API_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class handling onboarding related business logic.
 */
class WC_Payments_Onboarding_Service {

	const TEST_MODE_OPTION = 'wcpay_onboarding_test_mode';

	/**
	 * Client for making requests to the WooCommerce Payments API
	 *
	 * @var WC_Payments_API_Client
	 */
	private $payments_api_client;

	/**
	 * Cache util for managing onboarding data.
	 *
	 * @var Database_Cache
	 */
	private $database_cache;

	/**
	 * Class constructor
	 *
	 * @param WC_Payments_API_Client $payments_api_client Payments API client.
	 * @param Database_Cache         $database_cache      Database cache util.
	 */
	public function __construct( WC_Payments_API_Client $payments_api_client, Database_Cache $database_cache ) {
		$this->payments_api_client = $payments_api_client;
		$this->database_cache      = $database_cache;

		add_filter( 'wcpay_dev_mode', [ $this, 'maybe_enable_dev_mode' ], 100 );
	}

	/**
	 * Gets and caches the business types per country from the server.
	 *
	 * @param bool $force_refresh Forces data to be fetched from the server, rather than using the cache.
	 *
	 * @return array|bool Business types, or false if failed to retrieve.
	 */
	public function get_cached_business_types( bool $force_refresh = false ) {
		if ( ! $this->payments_api_client->is_server_connected() ) {
			return [];
		}

		$refreshed = false;

		$business_types = $this->database_cache->get_or_add(
			Database_Cache::BUSINESS_TYPES_KEY,
			function () {
				try {
					$business_types = $this->payments_api_client->get_onboarding_business_types();
				} catch ( API_Exception $e ) {
					// Return false to signal retrieval error.
					return false;
				}

				if ( ! $this->is_valid_cached_business_types( $business_types ) ) {
					return false;
				}

				return $business_types;
			},
			[ $this, 'is_valid_cached_business_types' ],
			$force_refresh,
			$refreshed
		);

		if ( null === $business_types ) {
			return false;
		}

		return $business_types;
	}

	/**
	 * Get the required verification information for the selected country/type/structure combination from the API.
	 *
	 * @param string      $country_code The currently selected country code.
	 * @param string      $type         The currently selected business type.
	 * @param string|null $structure    The currently selected business structure (optional).
	 *
	 * @return array
	 */
	public function get_required_verification_information( string $country_code, string $type, $structure = null ): array {
		return $this->payments_api_client->get_onboarding_required_verification_information( $country_code, $type, $structure );
	}

	/**
	 * Check whether the business types fetched from the cache are valid.
	 *
	 * @param array|bool|string $business_types The business types returned from the cache.
	 *
	 * @return bool
	 */
	public function is_valid_cached_business_types( $business_types ): bool {
		if ( null === $business_types || false === $business_types ) {
			return false;
		}

		// Non-array values are not expected, and we expect a non-empty array.
		if ( ! is_array( $business_types ) || empty( $business_types ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Enable dev mode if onboarding test mode is enabled.
	 *
	 * @param bool $dev_mode Current dev mode value.
	 *
	 * @return bool
	 */
	public function maybe_enable_dev_mode( $dev_mode ) {
		return get_option( self::TEST_MODE_OPTION, $dev_mode );
	}

	/**
	 * Enable onboarding test mode.
	 * This will enable WCPay dev mode.
	 */
	public static function enable_test_mode() {
		add_option( self::TEST_MODE_OPTION, true );
		// Ensure dev mode is enabled immediately.
		WC_Payments::mode()->dev();
	}
}
