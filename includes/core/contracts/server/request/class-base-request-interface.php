<?php
/**
 * Base request interface.
 *
 * @package WooCommerce\Payments
 */

namespace WCPay\Core\Contracts\Server\Request;

/**
 * Base request interface.
 */
interface Base_Request_Interface {

	/**
	 * Get params.
	 *
	 * @return array
	 */
	public function get_parameters();

	/**
	 * Validate and get request data.
	 *
	 * @return array
	 */
	public function get_request_data();

	/**
	 * Get request method.
	 *
	 * @return string
	 */
	public function get_method();

	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_route();

	/**
	 * Is site specific request.
	 *
	 * @return bool
	 */
	public function is_site_specific();

	/**
	 * Use user token for auth.
	 *
	 * @return bool
	 */
	public function use_user_token();

	/**
	 * Get headers.
	 *
	 * @return bool
	 */
	public function get_headers();

	/**
	 * Set request headers.
	 *
	 * @param array $headers Headers.
	 * @return array
	 */
	public function set_headers( $headers );

	/**
	 * Add header to existing headers.
	 *
	 * @param string $name  Name of header.
	 * @param string $value Value of header.
	 * @return void
	 */
	public function add_header( $name, $value );
}
