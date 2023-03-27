<?php
/**
 * Class Store_Metadata_Step
 *
 * @package WooCommerce\Payments
 */

namespace WCPay\Payment_Process\Step;

use WC_Order;
use WC_Payment_Tokens;
use WC_Subscriptions;
use WC_Payments_Subscriptions_Utilities;
use WCPay\Payment_Process\Order_Payment;
use WCPay\Payment_Process\Payment;
use WCPay\Payment_Process\Payment_Method\Saved_Payment_Method;

/**
 * Associates payment tokens with orders and subscriptions.
 */
class Add_Token_To_Order_Step extends Abstract_Step {
	use WC_Payments_Subscriptions_Utilities;

	/**
	 * Returns the ID of the step.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'add-token-to-order';
	}

	/**
	 * Checks if the step is applicable.
	 *
	 * @param Payment $payment The processing payment.
	 * @return bool
	 */
	public function is_applicable( Payment $payment ) {
		return $payment instanceof Order_Payment
			&& $payment->get_payment_method() instanceof Saved_Payment_Method;
	}

	/**
	 * Completes the step.
	 *
	 * @param Payment $payment The processing payment.
	 */
	public function complete( Payment $payment ) {
		if ( ! $payment instanceof Order_Payment ) {
			return;
		}

		$payment_method = $payment->get_payment_method();
		if ( ! $payment_method instanceof Saved_Payment_Method ) {
			return;
		}

		// We need to make sure the saved payment method is saved to the order so we can
		// charge the payment method for a future payment.
		$payment_token = $payment_method->get_token();
		$order         = $payment->get_order();

		// Load the existing token, if any.
		$order_token = $this->get_order_token( $order );

		// This could lead to tokens being saved twice in an order's payment tokens, but it is needed so that shoppers
		// may re-use a previous card for the same subscription, as we consider the last token to be the active one.
		// We can't remove the previous entry for the token because WC_Order does not support removal of tokens [1] and
		// we can't delete the token as it might be used somewhere else.
		// [1] https://github.com/woocommerce/woocommerce/issues/11857.
		if ( is_null( $order_token ) || $payment_token->get_id() !== $order_token->get_id() ) {
			$order->add_payment_token( $payment_token );
		}

		if ( $this->is_subscriptions_enabled() ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order->get_id() );
			if ( is_array( $subscriptions ) ) {
				foreach ( $subscriptions as $subscription ) {
					$subscription_token = $this->get_order_token( $subscription );
					if ( is_null( $subscription_token ) || $payment_token->get_id() !== $subscription_token->get_id() ) {
						$subscription->add_payment_token( $payment_token );
					}
				}
			}
		}
	}

	/**
	 * Retrieves the payment token, associated with an order.
	 *
	 * @param WC_Order $order Order, which might have a token.
	 * @return WC_Payment_Token|null
	 */
	protected function get_order_token( WC_Order $order ) {
		$tokens   = $order->get_payment_tokens();
		$token_id = end( $tokens );
		$token    = $token_id ? null : WC_Payment_Tokens::get( $token_id );

		return $token;
	}
}