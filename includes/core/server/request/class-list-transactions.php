<?php
/**
 * Class file for WCPay\Core\Server\Request\List_Transactions.
 *
 * @package WooCommerce Payments
 */

namespace WCPay\Core\Server\Request;

use WC_Payments_API_Client;
use WC_Payments_DB;
use WC_Payments_Utils;
use WCPay\Core\Server\Response;
use WP_REST_Request;

/**
 * Request class for listing transactions.
 */
class List_Transactions extends Paginated {

	use Date_Parameters, Order_Info;

	const DEFAULT_PARAMS = [
		'sort'      => 'date',
		'direction' => 'desc',
	];

	/**
	 * Set deposit id.
	 *
	 * @param mixed $deposit_id Deposit id.
	 *
	 * @return void
	 */
	public function set_deposit_id( $deposit_id ) {
		$this->set_param( 'deposit_id', $deposit_id );
	}

	/**
	 * Get api URI.
	 *
	 * @return string
	 */
	public function get_api(): string {
		return WC_Payments_API_Client::TRANSACTIONS_API;
	}

	/**
	 * Used to prepare request from WP Rest data.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return static
	 */
	public static function from_rest_request( $request ) {
		$wcpay_request       = parent::from_rest_request( $request );
		$date_between_filter = $request->get_param( 'date_between' );
		$user_timezone       = $request->get_param( 'user_timezone' );

		if ( ! is_null( $date_between_filter ) ) {
			$date_between_filter = array_map(
				function ( $transaction_date ) use ( $user_timezone ) {
					return Paginated::format_transaction_date_with_timestamp( $transaction_date, $user_timezone );
				},
				$date_between_filter
			);
		}

		$filters = [
			'match'                    => $request->get_param( 'match' ),
			'date_before'              => self::format_transaction_date_with_timestamp( $request->get_param( 'date_before' ), $user_timezone ),
			'date_after'               => self::format_transaction_date_with_timestamp( $request->get_param( 'date_after' ), $user_timezone ),
			'date_between'             => $date_between_filter,
			'type_is'                  => $request->get_param( 'type_is' ),
			'type_is_not'              => $request->get_param( 'type_is_not' ),
			'source_device_is'         => $request->get_param( 'source_device_is' ),
			'source_device_is_not'     => $request->get_param( 'source_device_is_not' ),
			'store_currency_is'        => $request->get_param( 'store_currency_is' ),
			'customer_currency_is'     => $request->get_param( 'customer_currency_is' ),
			'customer_currency_is_not' => $request->get_param( 'customer_currency_is_not' ),
			'loan_id_is'               => $request->get_param( 'loan_id_is' ),
			'search'                   => (array) $request->get_param( 'search' ),
			'deposit_id'               => $request->get_param( 'deposit_id' ),
		];
		$wcpay_request->set_filters( $filters );
		return $wcpay_request;
	}

	/**
	 * Used to prepare request data from reports api request.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return static
	 */
	public static function from_reports_rest_request( $request ) {
		$wcpay_request = static::create();
		$wcpay_request->set_page( $request->get_param( 'page' ) );
		$wcpay_request->set_page_size( $request->get_param( 'per_page' ) );
		$wcpay_request->set_sort_by( $request->get_param( 'orderby' ) );
		$wcpay_request->set_sort_direction( $request->get_param( 'order' ) );

		$date_between_filter = $request->get_param( 'date_between' );
		$user_timezone       = $request->get_param( 'user_timezone' );

		if ( ! is_null( $date_between_filter ) ) {
			$date_between_filter = array_map(
				function ( $transaction_date ) use ( $user_timezone ) {
					return Paginated::format_transaction_date_with_timestamp( $transaction_date, $user_timezone );
				},
				$date_between_filter
			);
		}

		$filters = [
			'match'                => $request->get_param( 'match' ),
			'date_before'          => self::format_transaction_date_with_timestamp( $request->get_param( 'date_before' ), $user_timezone ),
			'date_after'           => self::format_transaction_date_with_timestamp( $request->get_param( 'date_after' ), $user_timezone ),
			'date_between'         => $date_between_filter,
			'order_id_is'          => $request->get_param( 'order_id' ),
			'deposit_id'           => $request->get_param( 'deposit_id' ),
			'customer_email_is'    => $request->get_param( 'deposit_id' ),
			'payment_method_id_is' => $request->get_param( 'payment_method_id' ),
			'type_is'              => $request->get_param( 'type' ),
			'transaction_id_is'    => $request->get_param( 'transaction_id' ),
			'payment_intent_id_is' => $request->get_param( 'payment_intent_id' ),
			'store_currency_is'    => $request->get_param( 'store_currency_is' ),
			'customer_currency_is' => $request->get_param( 'customer_currency_is' ),
			'search'               => (array) $request->get_param( 'search' ),
		];
		$wcpay_request->set_filters( $filters );
		return $wcpay_request;
	}

	/**
	 * Set match.
	 *
	 * @param string $match Match.
	 *
	 * @return void
	 */
	public function set_match( string $match ) {
		$this->set_param( 'match', $match );
	}

	/**
	 * Set loan id is.
	 *
	 * @param string $loan_id Loan id.
	 *
	 * @return void
	 */
	public function set_loan_id_is( string $loan_id ) {
		$this->set_param( 'loan_id_is', $loan_id );
	}

	/**
	 * Set currency is.
	 *
	 * @param string $currency Store currency.
	 *
	 * @return void
	 */
	public function set_store_currency_is( string $currency ) {
		$this->set_param( 'store_currency_is', $currency );
	}

	/**
	 * Set customer currency is.
	 *
	 * @param string $customer_currency_is Store currency.
	 *
	 * @return void
	 */
	public function set_customer_currency_is( string $customer_currency_is ) {
		$this->set_param( 'customer_currency_is', $customer_currency_is );
	}

	/**
	 * Set customer currency is not.
	 *
	 * @param string $currency Store currency.
	 *
	 * @return void
	 */
	public function set_customer_currency_is_not( string $currency ) {
		$this->set_param( 'customer_currency_is_not', $currency );
	}

	/**
	 * Set search.
	 *
	 * @param array $search Search term.
	 *
	 * @return void
	 */
	public function set_search( array $search ) {
		if ( ! empty( $search ) ) {
			$search = WC_Payments_Utils::map_search_orders_to_charge_ids( $search );
			$this->set_param( 'search', $search );
		}
	}

	/**
	 * Set type is.
	 *
	 * @param string $type_is Type is.
	 *
	 * @return void
	 */
	public function set_type_is( string $type_is ) {
		$this->set_param( 'type_is', $type_is );
	}

	/**
	 * Set type is not.
	 *
	 * @param string $type_is_not Type is not.
	 *
	 * @return void
	 */
	public function set_type_is_not( string $type_is_not ) {
		$this->set_param( 'type_is_not', $type_is_not );
	}

	/**
	 * Set payment intent id is.
	 *
	 * @param string $payment_intent_id Payment intent id.
	 *
	 * @return void
	 */
	public function set_payment_intent_id_is( string $payment_intent_id ) {
		$this->set_param( 'payment_intent_id_is', $payment_intent_id );
	}

	/**
	 * Set Source device type is.
	 *
	 * @param string $source_device_is Source device type is.
	 *
	 * @return void
	 */
	public function set_source_device_is( string $source_device_is ) {
		$this->set_param( 'source_device_is', $source_device_is );
	}

	/**
	 * Set Source Device type is not.
	 *
	 * @param string $source_device_is_not Source Device type is not.
	 *
	 * @return void
	 */
	public function set_source_device_is_not( string $source_device_is_not ) {
		$this->set_param( 'source_device_is_not', $source_device_is_not );
	}

	/**
	 * Return formatted response.
	 *
	 * @param mixed $response Transactions from server.
	 *
	 * @return Response
	 */
	public function format_response( $response ) {

		// Add order information to each transaction available.
		// TODO: Throw exception when `$response` or `$transaction` don't have the fields expected?
		if ( isset( $response['data'] ) ) {
			$wcpay_db               = new WC_Payments_DB();
			$charge_ids             = array_column( $response['data'], 'charge_id' );
			$orders_with_charge_ids = count( $charge_ids ) ? $wcpay_db->orders_with_charge_id_from_charge_ids( $charge_ids ) : [];
			foreach ( $response['data'] as &$transaction ) {
				foreach ( $orders_with_charge_ids as $order_with_charge_id ) {
					if ( $order_with_charge_id['charge_id'] === $transaction['charge_id'] && ! empty( $transaction['charge_id'] ) ) {
						$order                            = $order_with_charge_id['order'];
						$transaction['order']             = $this->build_order_info( $order );
						$transaction['payment_intent_id'] = $order->get_meta( '_intent_id' );
					}
				}
			}
			// Securing future changes from modifying reference content.
			unset( $transaction );
		}

		return new Response( $response );
	}
}
