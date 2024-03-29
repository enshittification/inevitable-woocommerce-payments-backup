<?php
/**
 * Class WC_Payments_Platform_Checkout_Button_Handler
 * Adds support for the WooPay express checkout button.
 *
 * @package WooCommerce\Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCPay\Logger;
use WCPay\Platform_Checkout\Platform_Checkout_Utilities;

/**
 * WC_Payments_Platform_Checkout_Button_Handler class.
 */
class WC_Payments_Platform_Checkout_Button_Handler extends WC_Payments_Express_Checkout_Button {

	const BUTTON_LOCATIONS_OPTION_NAME = 'platform_checkout_button_locations';

	/**
	 * WC_Payments_Account instance to get information about the account
	 *
	 * @var WC_Payments_Account
	 */
	protected $account;

	/**
	 * WC_Payment_Gateway_WCPay instance.
	 *
	 * @var WC_Payment_Gateway_WCPay
	 */
	protected $gateway;

	/**
	 * Platform_Checkout_Utilities instance.
	 *
	 * @var Platform_Checkout_Utilities
	 */
	private $platform_checkout_utilities;

	/**
	 * Initialize class actions.
	 *
	 * @param WC_Payments_Account         $account Account information.
	 * @param WC_Payment_Gateway_WCPay    $gateway WCPay gateway.
	 * @param Platform_Checkout_Utilities $platform_checkout_utilities WCPay gateway.
	 */
	public function __construct( WC_Payments_Account $account, WC_Payment_Gateway_WCPay $gateway, Platform_Checkout_Utilities $platform_checkout_utilities ) {
		parent::__construct( $account, $gateway );
		$this->account                     = $account;
		$this->gateway                     = $gateway;
		$this->platform_checkout_utilities = $platform_checkout_utilities;
	}

	/**
	 * Indicates eligibility for WooPay via feature flag.
	 *
	 * @var bool
	 */
	private $is_platform_checkout_eligible;

	/**
	 * Indicates whether WooPay is enabled.
	 *
	 * @var bool
	 */
	private $is_platform_checkout_enabled;

	/**
	 * Indicates whether WooPay express checkout is enabled.
	 *
	 * @var bool
	 */
	private $is_platform_checkout_express_button_enabled;

	/**
	 * Indicates whether WooPay and WooPay express checkout are enabled.
	 *
	 * @return bool
	 */
	public function is_woopay_enabled() {
		return $this->is_platform_checkout_eligible && $this->is_platform_checkout_enabled && $this->is_platform_checkout_express_button_enabled;
	}

	/**
	 * Initialize hooks.
	 *
	 * @return  void
	 */
	public function init() {
		// Checks if WCPay is enabled.
		if ( ! $this->gateway->is_enabled() ) {
			return;
		}

		// Checks if WooPay is enabled.
		$this->is_platform_checkout_eligible               = WC_Payments_Features::is_platform_checkout_eligible(); // Feature flag.
		$this->is_platform_checkout_enabled                = 'yes' === $this->gateway->get_option( 'platform_checkout', 'no' );
		$this->is_platform_checkout_express_button_enabled = WC_Payments_Features::is_woopay_express_checkout_enabled();

		if ( ! $this->is_woopay_enabled() ) {
			return;
		}

		// Don't load for change payment method page.
		if ( isset( $_GET['change_payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'scripts' ] );

		add_filter( 'wcpay_payment_fields_js_config', [ $this, 'add_platform_checkout_config' ] );

		add_action( 'wc_ajax_wcpay_add_to_cart', [ $this, 'ajax_add_to_cart' ] );

		add_action( 'wp_ajax_woopay_express_checkout_button_show_error_notice', [ $this, 'show_error_notice' ] );
		add_action( 'wp_ajax_nopriv_woopay_express_checkout_button_show_error_notice', [ $this, 'show_error_notice' ] );
	}

	/**
	 * Add the platform checkout button config to wcpay_config.
	 *
	 * @param array $config The existing config array.
	 *
	 * @return array The modified config array.
	 */
	public function add_platform_checkout_config( $config ) {
		$config['platformCheckoutButton']      = $this->get_button_settings();
		$config['platformCheckoutButtonNonce'] = wp_create_nonce( 'platform_checkout_button_nonce' );
		$config['addToCartNonce']              = wp_create_nonce( 'wcpay-add-to-cart' );

		return $config;
	}

	/**
	 * Load public scripts and styles.
	 */
	public function scripts() {
		// Don't load scripts if we should not show the button.
		if ( ! $this->should_show_platform_checkout_button() ) {
			return;
		}

		WC_Payments::register_script_with_dependencies( 'WCPAY_PLATFORM_CHECKOUT_EXPRESS_BUTTON', 'dist/platform-checkout-express-button' );

		$wcpay_config = rawurlencode( wp_json_encode( WC_Payments::get_wc_payments_checkout()->get_payment_fields_js_config() ) );

		wp_add_inline_script(
			'WCPAY_PLATFORM_CHECKOUT_EXPRESS_BUTTON',
			"
			var wcpayConfig = wcpayConfig || JSON.parse( decodeURIComponent( '" . esc_js( $wcpay_config ) . "' ) );
			",
			'before'
		);

		wp_set_script_translations( 'WCPAY_PLATFORM_CHECKOUT_EXPRESS_BUTTON', 'woocommerce-payments' );

		wp_enqueue_script( 'WCPAY_PLATFORM_CHECKOUT_EXPRESS_BUTTON' );

		wp_register_style(
			'WCPAY_PLATFORM_CHECKOUT',
			plugins_url( 'dist/platform-checkout.css', WCPAY_PLUGIN_FILE ),
			[],
			WCPAY_VERSION_NUMBER
		);

		wp_enqueue_style( 'WCPAY_PLATFORM_CHECKOUT' );
	}

	/**
	 * Returns the error notice HTML.
	 */
	public function show_error_notice() {
		$is_nonce_valid = check_ajax_referer( 'platform_checkout_button_nonce', false, false );

		if ( ! $is_nonce_valid ) {
			wp_send_json_error(
				__( 'You aren’t authorized to do that.', 'woocommerce-payments' ),
				403
			);
		}

		$message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';

		// $message has already been translated.
		wc_add_notice( $message, 'error' );
		$notice = wc_print_notices( true );

		wp_send_json_success(
			[
				'notice' => $notice,
			]
		);

		wp_die();
	}

	/**
	 * Adds the current product to the cart. Used on product detail page.
	 */
	public function ajax_add_to_cart() {
		check_ajax_referer( 'wcpay-add-to-cart', 'security' );

		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		WC()->shipping->reset_shipping();

		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : false;
		$qty          = ! isset( $_POST['qty'] ) ? 1 : absint( $_POST['qty'] );
		$product      = wc_get_product( $product_id );
		$product_type = $product->get_type();

		// First empty the cart to prevent wrong calculation.
		WC()->cart->empty_cart();

		if ( ( 'variable' === $product_type || 'variable-subscription' === $product_type ) && isset( $_POST['attributes'] ) ) {
			$attributes = wc_clean( wp_unslash( $_POST['attributes'] ) );

			$data_store   = WC_Data_Store::load( 'product' );
			$variation_id = $data_store->find_matching_product_variation( $product, $attributes );

			WC()->cart->add_to_cart( $product->get_id(), $qty, $variation_id, $attributes );
		}

		if ( 'simple' === $product_type || 'subscription' === $product_type ) {
			WC()->cart->add_to_cart( $product->get_id(), $qty );
		}

		WC()->cart->calculate_totals();

		$data           = [];
		$data          += $this->build_display_items();
		$data['result'] = 'success';

		wp_send_json( $data );
	}

	/**
	 * Gets the context for where the button is being displayed.
	 *
	 * @return string
	 */
	public function get_button_context() {
		if ( $this->is_product() ) {
			return 'product';
		}

		if ( $this->is_cart() ) {
			return 'cart';
		}

		if ( $this->is_checkout() ) {
			return 'checkout';
		}

		if ( $this->is_pay_for_order_page() ) {
			return 'pay_for_order';
		}

		return '';
	}

	/**
	 * The settings for the `button` attribute - they depend on the "grouped settings" flag value.
	 *
	 * @return array
	 */
	public function get_button_settings() {
		$button_type = $this->gateway->get_option( 'payment_request_button_type', 'default' );
		return [
			'type'    => $button_type,
			'theme'   => $this->gateway->get_option( 'payment_request_button_theme', 'dark' ),
			'height'  => $this->get_button_height(),
			'size'    => $this->gateway->get_option( 'payment_request_button_size' ),
			'context' => $this->get_button_context(),
		];
	}

	/**
	 * Gets the button height.
	 *
	 * @return string
	 */
	public function get_button_height() {
		$height = $this->gateway->get_option( 'payment_request_button_size' );
		if ( 'medium' === $height ) {
			return '48';
		}

		if ( 'large' === $height ) {
			return '56';
		}

		// for the "default" and "catch-all" scenarios.
		return '40';
	}

	/**
	 * Checks whether Payment Request Button should be available on this page.
	 *
	 * @return bool
	 */
	public function should_show_platform_checkout_button() {
		// WCPay is not available.
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		if ( ! isset( $gateways['woocommerce_payments'] ) ) {
			return false;
		}

		// WooPay is not enabled.
		if ( ! $this->is_woopay_enabled() ) {
			return false;
		}

		// Page not supported.
		if ( ! $this->is_product() && ! $this->is_cart() && ! $this->is_checkout() ) {
			return false;
		}

		// Check if WooPay is available in the user country.
		if ( ! $this->platform_checkout_utilities->is_country_available( $this->gateway ) ) {
			return false;
		}

		// Product page, but not available in settings.
		if ( $this->is_product() && ! $this->is_available_at( 'product' ) ) {
			return false;
		}

		// Checkout page, but not available in settings.
		if ( $this->is_checkout() && ! $this->is_available_at( 'checkout' ) ) {
			return false;
		}

		// Cart page, but not available in settings.
		if ( $this->is_cart() && ! $this->is_available_at( 'cart' ) ) {
			return false;
		}

		// Product page, but has unsupported product type.
		if ( $this->is_product() && ! $this->is_product_supported() ) {
			Logger::log( 'Product page has unsupported product type ( WooPay Express button disabled )' );
			return false;
		}

		// Cart has unsupported product type.
		if ( ( $this->is_checkout() || $this->is_cart() ) && ! $this->has_allowed_items_in_cart() ) {
			Logger::log( 'Items in the cart have unsupported product type ( WooPay Express button disabled )' );
			return false;
		}

		if ( $this->is_pay_for_order_page() ) {
			return false;
		}

		if ( ! is_user_logged_in() ) {
			// On product page for a subscription product, but not logged in, making WooPay unavailable.
			if ( $this->is_product() ) {
				$current_product = wc_get_product();

				if ( $current_product && $this->is_product_subscription( $current_product ) ) {
					return false;
				}
			}

			// On cart or checkout page with a subscription product in cart, but not logged in, making WooPay unavailable.
			if ( ( $this->is_checkout() || $this->is_cart() ) && class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
				// Check cart for subscription products.
				return false;
			}

			// If guest checkout is not allowed, and customer is not logged in, disable the WooPay button.
			if ( ! $this->platform_checkout_utilities->is_guest_checkout_enabled() ) {
				return false;
			}
		}

		/**
		 * TODO: We need to do some research here and see if there are any product types that we
		 * absolutely cannot support with WooPay at this time. There are some examples in the
		 * `WC_Payments_Payment_Request_Button_Handler->is_product_supported()` method.
		 */

		return true;
	}

	/**
	 * Display the payment request button.
	 */
	public function display_platform_checkout_button_html() {
		if ( ! $this->should_show_platform_checkout_button() ) {
			return;
		}

		?>
		<div id="wcpay-payment-request-wrapper" style="clear:both;padding-top:1.5em;">
			<div id="wcpay-platform-checkout-button" data-product_page=<?php echo esc_attr( $this->is_product() ); ?>>
				<?php // The WooPay express checkout button React component will go here. ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Whether the product page has a product compatible with the WooPay Express button.
	 *
	 * @return boolean
	 */
	private function is_product_supported() {
		$product      = $this->get_product();
		$is_supported = true;

		if ( ! is_object( $product ) ) {
			$is_supported = false;
		}

		// Pre Orders products to be charged upon release are not supported.
		if ( class_exists( 'WC_Pre_Orders_Product' ) && WC_Pre_Orders_Product::product_is_charged_upon_release( $product ) ) {
			$is_supported = false;
		}

		return apply_filters( 'wcpay_platform_checkout_button_is_product_supported', $is_supported, $product );
	}

	/**
	 * Checks the cart to see if the WooPay Express button supports all of its items.
	 *
	 * @todo Abstract this. This is a copy of the same method in the `WC_Payments_Payment_Request_Button_Handler` class.
	 *
	 * @return boolean
	 */
	private function has_allowed_items_in_cart() {
		$is_supported = true;

		// We don't support pre-order products to be paid upon release.
		if (
			class_exists( 'WC_Pre_Orders_Cart' ) &&
			WC_Pre_Orders_Cart::cart_contains_pre_order() &&
			class_exists( 'WC_Pre_Orders_Product' ) &&
			WC_Pre_Orders_Product::product_is_charged_upon_release( WC_Pre_Orders_Cart::get_pre_order_product() )
		) {
			$is_supported = false;
		}

		return apply_filters( 'wcpay_platform_checkout_button_are_cart_items_supported', $is_supported );
	}

	/**
	 * Returns true if the provided WC_Product is a subscription, false otherwise.
	 *
	 * @param WC_Product $product The product to check.
	 *
	 * @return bool  True if product is subscription, false otherwise.
	 */
	private function is_product_subscription( WC_Product $product ): bool {
		return 'subscription' === $product->get_type()
			|| 'subscription_variation' === $product->get_type()
			|| 'variable-subscription' === $product->get_type();
	}
}
