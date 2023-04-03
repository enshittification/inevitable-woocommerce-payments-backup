<?php
/**
 * Class Save_Payment_Method_Step
 *
 * @package WooCommerce\Payments
 */

namespace WCPay\Payment_Process\Step;

use WC_Payment_Gateway_WCPay;
use WC_Payment_Token;
use WC_Payment_Tokens;
use WCPay\Payment_Process\Order_Payment;
use WCPay\Payment_Process\Payment;
use WC_Payments_API_Intention;
use WC_Payments;
use WCPay\Payment_Process\Payment_Method\Saved_Payment_Method;

/**
 * Saves the payment method as token after a successful intent.
 */
class Save_Payment_Method_Step extends Abstract_Step {
	/**
	 * The WCpay token service.
	 *
	 * @var WC_Payments_Token_Service
	 */
	protected $token_service;

	/**
	 * Loads all required dependencies.
	 */
	public function __construct() {
		$this->token_service = WC_Payments::get_token_service();
	}

	/**
	 * Checks if the step is applicable.
	 *
	 * @param Payment $payment A payment, which is being processed.
	 * @return bool
	 */
	public function is_applicable( Payment $payment ) {
		// This is only applicable for order payments, at least for now.
		if ( ! $payment instanceof Order_Payment ) {
			return false;
		}

		// Bail there is no requirement to save the PM.
		if ( ! $payment->is( Payment::SAVE_PAYMENT_METHOD_TO_STORE ) ) {
			return false;
		}

		// Failing payment methods should not be saved.
		if ( ! $payment->get_intent()->is_successful() ) {
			return false;
		}

		return true;
	}

	/**
	 * Retrieves the user object for a payment.
	 *
	 * @param Order_Payment $payment Payment object.
	 * @return WP_User
	 */
	protected function get_user_from_payment( Order_Payment $payment ) {
		return get_user_by( 'id', $payment->get_user_id() );
	}

	/**
	 * While completing a payment, stores the payment method as a token.
	 *
	 * @param Payment $payment The payment object.
	 */
	public function complete( Payment $payment ) {
		if ( ! $payment instanceof Order_Payment ) {
			return; // keep IDEs happy.
		}

		$intent = $payment->get_intent();
		// @todo: This should support SetupIntents as well.
		$user = $this->get_user_from_payment( $payment );

		// Setup intents are currently not deserialized as payment intents are, so check if it's an array first.
		$payment_method_id = is_array( $intent ) ? $intent['payment_method'] : $intent->get_payment_method_id();

		// Create a new token or load an existing one.
		$wc_token = $this->maybe_load_woopay_subscription_token( $payment, $payment_method_id );
		if ( is_null( $wc_token ) ) {
			$wc_token = $this->token_service->add_payment_method_to_user( $payment_method_id, $user );
		}

		// Use the new token for the rest of the payment process.
		$payment_method = new Saved_Payment_Method( $wc_token );
		$payment->set_payment_method( $payment_method );
	}

	/**
	 * Checks if an order comes from WooPay and is using a PM, which is already saved.
	 *
	 * @param Order_Payment $payment Payment process.
	 * @param string        $payment_method_id The PM ID, coming from the intent.
	 * @return WC_Payment_Token|null
	 */
	protected function maybe_load_woopay_subscription_token( Order_Payment $payment, string $payment_method_id ) {
		$order = $payment->get_order();

		// Handle orders that are paid via WooPay and contain subscriptions.
		if ( $order->get_meta( 'is_woopay' ) && function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
			$customer_tokens = WC_Payment_Tokens::get_customer_tokens( $order->get_user_id(), WC_Payment_Gateway_WCPay::GATEWAY_ID );

			// Use the existing token if we already have one for the incoming payment method.
			foreach ( $customer_tokens as $saved_token ) {
				if ( $saved_token->get_token() === $payment_method_id ) {
					return $saved_token;
				}
			}
		}

		return null;
	}
}
