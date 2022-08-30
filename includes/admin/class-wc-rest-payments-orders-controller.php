<?php
/**
 * Class WC_REST_Payments_Orders_Controller
 *
 * @package WooCommerce\Payments\Admin
 */

defined( 'ABSPATH' ) || exit;

use WCPay\Constants\Payment_Method;
use WCPay\Logger;

/**
 * REST controller for order processing.
 */
class WC_REST_Payments_Orders_Controller extends WC_Payments_REST_Controller {

	/**
	 * Endpoint path.
	 *
	 * @var string
	 */
	protected $rest_base = 'payments/orders';

	/**
	 * Instance of WC_Payment_Gateway_WCPay
	 *
	 * @var WC_Payment_Gateway_WCPay
	 */
	private $gateway;

	/**
	 * WC_Payments_Customer_Service instance for working with customer information
	 *
	 * @var WC_Payments_Customer_Service
	 */
	private $customer_service;

	/**
	 * WC_Payments_Order_Service instance for updating order statuses.
	 *
	 * @var WC_Payments_Order_Service
	 */
	private $order_service;

	/**
	 * WC_Payments_REST_Controller constructor.
	 *
	 * @param WC_Payments_API_Client       $api_client       WooCommerce Payments API client.
	 * @param WC_Payment_Gateway_WCPay     $gateway          WooCommerce Payments payment gateway.
	 * @param WC_Payments_Customer_Service $customer_service Customer class instance.
	 * @param WC_Payments_Order_Service    $order_service    Order Service class instance.
	 */
	public function __construct( WC_Payments_API_Client $api_client, WC_Payment_Gateway_WCPay $gateway, WC_Payments_Customer_Service $customer_service, WC_Payments_Order_Service $order_service ) {
		parent::__construct( $api_client );
		$this->gateway          = $gateway;
		$this->customer_service = $customer_service;
		$this->order_service    = $order_service;
	}

	/**
	 * Configure REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/(?P<order_id>\w+)/capture_terminal_payment',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'capture_terminal_payment' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'payment_intent_id' => [
						'required' => true,
					],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/(?P<order_id>\w+)/capture_authorization',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'capture_authorization' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'payment_intent_id' => [
						'required' => true,
					],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/(?P<order_id>\w+)/create_terminal_intent',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_terminal_intent' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/(?P<order_id>\d+)/create_customer',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_customer' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
	}

	/**
	 * Given an intent ID and an order ID, add the intent ID to the order and capture it.
	 * Use-cases: Mobile apps using it for `card_present` and `interac_present` payment types.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function capture_terminal_payment( $request ) {
		try {
			$intent_id = $request['payment_intent_id'];
			$order_id  = $request['order_id'];

			// Do not process non-existing orders.
			$order = wc_get_order( $order_id );
			if ( false === $order ) {
				return new WP_Error( 'wcpay_missing_order', __( 'Order not found', 'woocommerce-payments' ), [ 'status' => 404 ] );
			}

			// Do not process orders with refund(s).
			if ( 0 < $order->get_total_refunded() ) {
				return new WP_Error(
					'wcpay_refunded_order_uncapturable',
					__( 'Payment cannot be captured for partially or fully refunded orders.', 'woocommerce-payments' ),
					[ 'status' => 400 ]
				);
			}

			// Do not process intents that can't be captured.
			$intent = $this->api_client->get_intent( $intent_id );
			if ( ! in_array( $intent->get_status(), [ 'processing', 'requires_capture', 'succeeded' ], true ) ) {
				return new WP_Error( 'wcpay_payment_uncapturable', __( 'The payment cannot be captured', 'woocommerce-payments' ), [ 'status' => 409 ] );
			}

			// Update the order: set the payment method and attach intent attributes.
			$order->set_payment_method( WC_Payment_Gateway_WCPay::GATEWAY_ID );
			$order->set_payment_method_title( __( 'WooCommerce In-Person Payments', 'woocommerce-payments' ) );
			$intent_id     = $intent->get_id();
			$intent_status = $intent->get_status();
			$charge        = $intent->get_charge();
			$charge_id     = $charge ? $charge->get_id() : null;
			$this->gateway->attach_intent_info_to_order(
				$order,
				$intent_id,
				$intent_status,
				$intent->get_payment_method_id(),
				$intent->get_customer_id(),
				$charge_id,
				$intent->get_currency()
			);
			$this->gateway->update_order_status_from_intent(
				$order,
				$intent_id,
				$intent_status,
				$charge_id
			);

			// Certain payments (eg. Interac) are captured on the client-side (mobile app).
			// The client may send us the captured intent to link it to its WC order.
			// Doing so via this endpoint is more reliable than depending on the payment_intent.succeeded event.
			$is_intent_captured         = 'succeeded' === $intent->get_status();
			$result_for_captured_intent = [
				'status' => 'succeeded',
				'id'     => $intent->get_id(),
			];

			$result = $is_intent_captured ? $result_for_captured_intent : $this->gateway->capture_charge( $order, false );

			if ( 'succeeded' !== $result['status'] ) {
				$http_code = $result['http_code'] ?? 502;
				return new WP_Error(
					'wcpay_capture_error',
					sprintf(
					// translators: %s: the error message.
						__( 'Payment capture failed to complete with the following message: %s', 'woocommerce-payments' ),
						$result['message'] ?? __( 'Unknown error', 'woocommerce-payments' )
					),
					[ 'status' => $http_code ]
				);
			}
			// Store receipt generation URL for mobile applications in order meta-data.
			$order->add_meta_data( 'receipt_url', get_rest_url( null, sprintf( '%s/payments/readers/receipts/%s', $this->namespace, $intent->get_id() ) ) );
			// Actualize order status.
			$this->order_service->mark_terminal_payment_completed( $order, $intent_id, $result['status'] );

			return rest_ensure_response(
				[
					'status' => $result['status'],
					'id'     => $result['id'],
				]
			);
		} catch ( \Throwable $e ) {
			Logger::error( 'Failed to capture a terminal payment via REST API: ' . $e );
			return new WP_Error( 'wcpay_server_error', __( 'Unexpected server error', 'woocommerce-payments' ), [ 'status' => 500 ] );
		}
	}

	/**
	 * TODO.
	 *
	 * @param  WP_REST_Request $request TODO.
	 * @return WP_REST_Response|WP_Error TODO
	 */
	public function capture_authorization( $request ) {
		try {
			$intent_id = $request['payment_intent_id'];
			$order_id  = $request['order_id'];

			// Do not process non-existing orders.
			$order = wc_get_order( $order_id );
			if ( false === $order ) {
				return new WP_Error( 'wcpay_missing_order', __( 'Order not found', 'woocommerce-payments' ), [ 'status' => 404 ] );
			}

			// Do not process intents that can't be captured.
			$intent = $this->api_client->get_intent( $intent_id );
			if ( ! in_array( $intent->get_status(), [ 'processing', 'requires_capture', 'succeeded' ], true ) ) {
				return new WP_Error( 'wcpay_payment_uncapturable', __( 'The payment cannot be captured', 'woocommerce-payments' ), [ 'status' => 409 ] );
			}

			// Update the order.
			$intent_id     = $intent->get_id();
			$intent_status = $intent->get_status();
			$charge        = $intent->get_charge();
			$charge_id     = $charge ? $charge->get_id() : null;

			$this->gateway->update_order_status_from_intent(
				$order,
				$intent_id,
				$intent_status,
				$charge_id
			);

			// Capture the charge.
			$result = $this->gateway->capture_charge( $order, false );

			if ( 'succeeded' !== $result['status'] ) {
				$http_code = $result['http_code'] ?? 502;
				return new WP_Error(
					'wcpay_capture_error',
					sprintf(
					// translators: %s: the error message.
						__( 'Payment capture failed to complete with the following message: %s', 'woocommerce-payments' ),
						$result['message'] ?? __( 'Unknown error', 'woocommerce-payments' )
					),
					[ 'status' => $http_code ]
				);
			}

			// Store receipt generation URL for mobile applications in order meta-data.
			$order->add_meta_data( 'receipt_url', get_rest_url( null, sprintf( '%s/payments/readers/receipts/%s', $this->namespace, $intent->get_id() ) ) );
			// Actualize order status.
			$this->order_service->mark_payment_capture_completed( $order, $intent_id, $result['status'], $charge_id );

			return rest_ensure_response(
				[
					'status' => $result['status'],
					'id'     => $result['id'],
				]
			);
		} catch ( \Throwable $e ) {
			Logger::error( 'Failed to capture an authorization via REST API: ' . $e );
			return new WP_Error( 'wcpay_server_error', __( 'Unexpected server error', 'woocommerce-payments' ), [ 'status' => 500 ] );
		}
	}

	/**
	 * Returns customer id from order. Create or update customer if needed.
	 * Use-cases: It was used by older versions of our Mobile apps in their workflows.
	 *
	 * @deprecated 3.9.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_customer( $request ) {
		wc_deprecated_function( __FUNCTION__, '3.9.0' );
		try {
			$order_id = $request['order_id'];

			// Do not process non-existing orders.
			$order = wc_get_order( $order_id );
			if ( false === $order || ! ( $order instanceof WC_Order ) ) {
				return new WP_Error( 'wcpay_missing_order', __( 'Order not found', 'woocommerce-payments' ), [ 'status' => 404 ] );
			}

			$disallowed_order_statuses = apply_filters( 'wcpay_create_customer_disallowed_order_statuses', [ 'completed', 'cancelled', 'refunded', 'failed' ] );
			if ( $order->has_status( $disallowed_order_statuses ) ) {
				return new WP_Error( 'wcpay_invalid_order_status', __( 'Invalid order status', 'woocommerce-payments' ), [ 'status' => 400 ] );
			}

			$order_user        = $order->get_user();
			$customer_id       = $order->get_meta( '_stripe_customer_id' );
			$customer_data     = WC_Payments_Customer_Service::map_customer_data( $order );
			$is_guest_customer = false === $order_user;

			// If the order is created for a registered customer, try extracting it's Stripe customer ID.
			if ( ! $customer_id && ! $is_guest_customer ) {
				$customer_id = $this->customer_service->get_customer_id_by_user_id( $order_user->ID );
			}

			$order_user  = $is_guest_customer ? new WP_User() : $order_user;
			$customer_id = $customer_id
				? $this->customer_service->update_customer_for_user( $customer_id, $order_user, $customer_data )
				: $this->customer_service->create_customer_for_user( $order_user, $customer_data );

			$order->update_meta_data( '_stripe_customer_id', $customer_id );
			$order->save();

			return rest_ensure_response(
				[
					'id' => $customer_id,
				]
			);
		} catch ( \Throwable $e ) {
			Logger::error( 'Failed to create / update customer from order via REST API: ' . $e );
			return new WP_Error( 'wcpay_server_error', __( 'Unexpected server error', 'woocommerce-payments' ), [ 'status' => 500 ] );
		}
	}

	/**
	 * Create a new in-person payment intent for the given order ID without confirming it.
	 * Use-cases: Mobile apps using it for `card_present` payment types. (`interac_present` is handled by the apps via Stripe SDK).
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_terminal_intent( $request ) {
		// Do not process non-existing orders.
		$order = wc_get_order( $request['order_id'] );
		if ( false === $order ) {
			return new WP_Error( 'wcpay_missing_order', __( 'Order not found', 'woocommerce-payments' ), [ 'status' => 404 ] );
		}

		try {
			$result = $this->gateway->create_intent(
				$order,
				$this->get_terminal_intent_payment_method( $request ),
				$this->get_terminal_intent_capture_method( $request ),
				$request->get_param( 'metadata' ) ?? [],
				$request->get_param( 'customer_id' )
			);
			return rest_ensure_response( $result );
		} catch ( \Throwable $e ) {
			Logger::error( 'Failed to create an intention via REST API: ' . $e );
			return new WP_Error( 'wcpay_server_error', __( 'Unexpected server error', 'woocommerce-payments' ), [ 'status' => 500 ] );
		}
	}

	/**
	 * Return terminal intent payment method array based on payment methods request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param array           $default_value - default value.
	 *
	 * @return array|null
	 * @throws \Exception
	 */
	public function get_terminal_intent_payment_method( $request, array $default_value = [ Payment_Method::CARD_PRESENT ] ) :array {
		$payment_methods = $request->get_param( 'payment_methods' );
		if ( null === $payment_methods ) {
			return $default_value;
		}

		if ( ! is_array( $payment_methods ) ) {
			throw new \Exception( 'Invalid param \'payment_methods\'!' );
		}

		foreach ( $payment_methods as $value ) {
			if ( ! in_array( $value, Payment_Method::IPP_ALLOWED_PAYMENT_METHODS, true ) ) {
				throw new \Exception( 'One or more payment methods are not supported!' );
			}
		}

		return $payment_methods;
	}

	/**
	 * Return terminal intent capture method based on capture method request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $default_value default value.
	 *
	 * @return string|null
	 * @throws \Exception
	 */
	public function get_terminal_intent_capture_method( $request, string $default_value = 'manual' ) : string {
		$capture_method = $request->get_param( 'capture_method' );
		if ( null === $capture_method ) {
			return $default_value;
		}

		if ( ! in_array( $capture_method, [ 'manual', 'automatic' ], true ) ) {
			throw new \Exception( 'Invalid param \'capture_method\'!' );
		}

		return $capture_method;
	}
}
