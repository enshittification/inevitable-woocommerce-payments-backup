<?php
/**
 * Class Payment
 *
 * @package WooCommerce\Payments
 */

namespace WCPay\Payment_Process;

use Exception;
use WCPay\Payment_Process\Storage\Payment_Storage;
use WCPay\Payment_Process\Payment_Method\Payment_Method;
use WCPay\Payment_Process\Payment_Method\Payment_Method_Factory;
use WCPay\Payment_Process\Step\{ Metadata_Step, Abstract_Step, Add_Token_To_Order_Step, Bump_Transaction_Limiter_Step, Check_Attached_Intent_Success_Step, Check_Session_Against_Processing_Order_Step, Complete_Without_Payment_Step, Create_UPE_Intent_Step, Customer_Details_Step, Load_Intent_After_Authentication_Step, Redirect_UPE_Payment_Step, Save_Payment_Method_Step, Setup_Payment_Step, Standard_Payment_Step, Store_Metadata_Step, Update_Order_Step, Update_Saved_Payment_Method_Step, Update_UPE_Intent_Step, Verify_Fraud_Token_Step, Verify_Minimum_Amount_Step };

/**
 * Main class, representing payments.
 */
class Payment {
	use Payment_Vars;

	/**
	 * Indicates if the payment is merchant-initiated.
	 * If the flag is not present, it means that it's a standard
	 * payment, initiated by a customer action on the site.
	 */
	const MERCHANT_INITIATED = 1;

	/**
	 * Indicates if manual capture should be used for the payment.
	 * If the flag is not present, it's automatic capture.
	 */
	const MANUAL_CAPTURE = 2;

	/**
	 * Indicates if this payment will be recurring.
	 * If not, it is just a single payment.
	 *
	 * @todo: Check if this flag is only required for the initial payment.
	 */
	const RECURRING = 4;

	/**
	 * Indicates whether the payment is related to changing
	 * the payment method for a subscription.
	 */
	const CHANGING_SUBSCRIPTION_PAYMENT_METHOD = 8;

	/**
	 * Whether the payment method should be saved upon payment success.
	 */
	const SAVE_PAYMENT_METHOD_TO_STORE = 16;

	/**
	 * Indicates whether the payment method should be saved to the platform.
	 */
	const SAVE_PAYMENT_METHOD_TO_PLATFORM = 32;

	/**
	 * Used for the standard payment flow (non-UPE).
	 */
	const STANDARD_FLOW = 'STANDARD_FLOW';

	/**
	 * Used to check the status of an intent, after SCA authentication.
	 */
	const POST_CHECKOUT_REDIRECT_FLOW = 'POST_CHECKOUT_REDIRECT_FLOW';

	/**
	 * UPE flows:
	 *
	 * 1. The intent is created through AJAX in order to display fields on checkout.
	 *    - This does not happen without a customer present (ex. in-person, merchant-initiated, etc.).
	 * 2. Within `process_payment`, the intent is not ready yet, gets updated.
	 *    - If it's an off-site payment, or a saved PM is used, there is no intent available.
	 *    - Intent is not ready yet, but it's a good opportunity to update it, and use checkout data.
	 * 3. After checkout, there is a redirect, which can finally act on success/failure.
	 */
	const UPE_PREPARE_INTENT_FLOW   = 'UPE_PREPARE_INTENT_FLOW';
	const UPE_PROCESS_PAYMENT_FLOW  = 'UPE_PROCESS_PAYMENT_FLOW';
	const UPE_PROCESS_REDIRECT_FLOW = 'UPE_PROCESS_REDIRECT_FLOW';

	/**
	 * Payment storage, used to store the payment.
	 *
	 * @var Payment_Storage
	 */
	protected $payment_storage;

	/**
	 * Holds the ID of the payment.
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * Holds all flags, related to the type of payment.
	 *
	 * @var int
	 */
	protected $flags;

	/**
	 * The factory for payment methods.
	 *
	 * @var Payment_Method_Factory
	 */
	protected $payment_method_factory;

	/**
	 * Holds the payment method.
	 *
	 * @var Payment_Method
	 */
	protected $payment_method;

	/**
	 * Stores the type of payment flow, which triggers the process.
	 *
	 * @var string
	 */
	protected $flow;

	/**
	 * Holds all variables, related to the payment.
	 *
	 * @var array
	 */
	protected $vars = [];

	/**
	 * Holds the response, which should be provided at the end of `process()`.
	 *
	 * @var mixed
	 */
	protected $response;

	/**
	 * Holds the logger object.
	 *
	 * @var Logger
	 */
	protected $logger;

	/**
	 * The current payment processing stage.
	 *
	 * @var string
	 */
	protected $current_stage = 'initialization';

	// Constants, used for `current_stage`.
	const STAGE_PREPARE  = 'preparation';
	const STAGE_ACTION   = 'action';
	const STAGE_COMPLETE = 'completion stage';

	/**
	 * The current step, which is being executed.
	 *
	 * @var Abstract_Step
	 */
	protected $current_step;

	/**
	 * Instantiates the class.
	 *
	 * @param Payment_Storage        $storage                Storage to load/save payments from/to.
	 * @param Payment_Method_Factory $payment_method_factory Factory for payment methods.
	 */
	public function __construct(
		Payment_Storage $storage,
		Payment_Method_Factory $payment_method_factory
	) {
		// Dependencies.
		$this->payment_storage        = $storage;
		$this->payment_method_factory = $payment_method_factory;

		// Sub-object.
		$this->logger = new Logger();
	}

	/**
	 * Loads the payment from an array.
	 *
	 * @param array $data The pre-existing payment data.
	 */
	public function load_data( array $data ) {
		if ( isset( $data['flags'] ) ) {
			$this->flags = $data['flags'];
		}

		if ( isset( $data['vars'] ) ) {
			$this->vars = $data['vars'];
		}

		if ( isset( $data['payment_method'] ) && ! empty( $data['payment_method'] ) ) {
			$this->payment_method = $this->payment_method_factory->from_storage( $data['payment_method'] );
		}

		if ( isset( $data['logs'] ) && ! empty( $data['logs'] ) ) {
			$this->logger->set_logs( $data['logs'] );
		}
	}

	/**
	 * Returns the payment data, ready to store.
	 *
	 * @return array An array with everything important.
	 */
	public function get_data() {
		$payment_method = isset( $this->payment_method )
			? $this->payment_method->get_data()
			: null;

		return [
			'flags'          => $this->flags,
			'payment_method' => $payment_method,
			'vars'           => $this->vars,
			'logs'           => $this->logger->get_logs(),
		];
	}

	/**
	 * Saves the payment data in storage.
	 */
	public function save() {
		$this->payment_storage->store( $this );
	}

	/**
	 * Allows the ID of the object to be stored.
	 *
	 * @param mixed $id The ID of the payment, used for storage.
	 */
	public function set_id( $id ) {
		$this->id = $id;
	}

	/**
	 * Returns the ID of the payment if any.
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Sets the payment flow, indicating where the process is invoked.
	 *
	 * @param string $flow The flow value, matching a class constant.
	 * @throws Exception If the flow does not exist.
	 */
	public function set_flow( string $flow ) {
		if ( ! defined( get_class( $this ) . '::' . $flow ) ) {
			throw new Exception( 'Payment flows must be defined as constants of the Payment class.' );
		}

		$this->flow = $flow;
	}

	/**
	 * Checks if the payment is a part of a specific flow.
	 *
	 * @param string $flow The flow value.
	 * @return bool
	 */
	public function is_flow( string $flow ) {
		return $flow === $this->flow;
	}

	/**
	 * Sets a new flag for the payment.
	 *
	 * @param int $flag One of constants of the class.
	 */
	public function set_flag( $flag ) {
		$this->flags = $this->flags | $flag;
	}

	/**
	 * Unsets/removes a specific flag.
	 *
	 * @param int $flag One of constants of the class.
	 */
	public function unset_flag( $flag ) {
		if ( $this->is( $flag ) ) {
			$this->flags = $this->flags - $flag;
		}
	}

	/**
	 * Checks if a specific payment-related flag is present.
	 *
	 * @param int $flag One of constants of the class.
	 */
	public function is( $flag ) {
		return ( $this->flags & $flag ) > 0;
	}

	/**
	 * Changes the payment method, used for the payment.
	 *
	 * @param Payment_Method $payment_method The used payment method. Optional.
	 */
	public function set_payment_method( Payment_Method $payment_method = null ) {
		$this->payment_method = $payment_method;
	}

	/**
	 * Returns the used payment method.
	 *
	 * @return Payment_Method
	 */
	public function get_payment_method() {
		return $this->payment_method;
	}

	/**
	 * Returns all possible steps, needed for the payment.
	 *
	 * @return string[] An array of class names.
	 * @todo Make this private and non-static. It's only public and static to allow this early.
	 */
	public static function get_available_steps() {
		/**
		 * Allows the list of payment steps, and their order to be modified.
		 *
		 * This filter only contains the names of classes for available steps.
		 * Those will be later instantiated, and the process will check whether
		 * the step is applicable or not, hence no further context for this filter.
		 *
		 * @param string[] $steps An array of class names.
		 */
		return apply_filters(
			'wcpay_payment_available_steps',
			[
				Metadata_Step::class, // Prepare.
				Customer_Details_Step::class, // Prepare & act.
				Bump_Transaction_Limiter_Step::class, // Act & Complete.
				Verify_Fraud_Token_Step::class, // Action.
				Load_Intent_After_Authentication_Step::class, // Action.
				// Check_Session_Against_Processing_Order_Step::class, // Act & Complete.
				Check_Attached_Intent_Success_Step::class, // Action.
				Create_UPE_Intent_Step::class, // Action.
				Redirect_UPE_Payment_Step::class, // Action.
				Update_UPE_Intent_Step::class, // Action.
				Complete_Without_Payment_Step::class, // Action.
				Verify_Minimum_Amount_Step::class, // Action.
				Standard_Payment_Step::class, // Action.
				Setup_Payment_Step::class, // Action.
				Update_Saved_Payment_Method_Step::class, // Complete.
				Save_Payment_Method_Step::class, // Complete.
				Store_Metadata_Step::class, // Complete.
				Update_Order_Step::class, // Complete.
				Add_Token_To_Order_Step::class, // Complete.
			]
		);
	}

	/**
	 * Processes the payment, once all external set-up is done.
	 *
	 * @return mixed The result of the successful action call.
	 * @throws \Exception If there is no flow set for the payment.
	 */
	public function process() {
		// The flow is required, make sure it's set.
		if ( ! $this->flow ) {
			throw new \Exception( 'Processing payments requires a flow to be set' );
		}

		// Clear any previous responses.
		$this->response = null;

		$this->current_stage = static::STAGE_PREPARE;
		// Contains all steps, applicable to the payment.
		$steps = [];
		foreach ( static::get_available_steps() as $class_name ) {
			if ( ! is_subclass_of( $class_name, Abstract_Step::class ) ) {
				// Ignore steps, which do not use the base class.
				continue;
			}

			$step = new $class_name();

			// Allow logs.
			$this->current_step = $step;

			// Check if the step is applicable to the process.
			if ( ! $step->is_applicable( $this ) ) {
				continue;
			}

			/**
			 * Stage 1: Collect data.
			 *
			 * This allows each step to collect the necessary data.
			 * Note: This step is in the initial loop, because follow-up
			 * steps might depend on the data, collected by previous ones.
			 *
			 * @todo: Prevent this.
			 */
			$step->collect_data( $this );

			$steps[] = $step;
		}

		/**
		 * Stage 2: Act.
		 *
		 * Allow every step to try and process the payment.
		 * Once a single step has called `$payment->complete()`, the
		 * loop will be broken, and no further *actions* will be executed.
		 *
		 * This was `Complete_Without_Payment_Step` can complete the processing,
		 * and other steps (ex. intent creation) will be skipped. Order is important.
		 */
		$this->current_stage = static::STAGE_ACTION;
		foreach ( $steps as $step ) {
			// Allow logs.
			$this->current_step = $step;

			$step->action( $this );

			// Once there's a response, there should be no further action.
			if ( ! is_null( $this->response ) ) {
				break;
			}
		}

		/**
		 * Stage 3: Complete all steps.
		 *
		 * This allows each step to go ahead and save the necessary data
		 * like payment tokens, meta, update subscriptions and etc.
		 */
		$this->current_stage = static::STAGE_COMPLETE;
		foreach ( $steps as $step ) {
			// Allow logs.
			$this->current_step = $step;

			$step->complete( $this );
		}

		// Whatever was updated during the process, save the order.
		if ( $this instanceof Order_Payment ) {
			$this->order->save();
		}

		// Save the payment process as well.
		$this->save();

		return $this->response;
	}

	/**
	 * Allows the payment to be completed, ending the main part of the processing.
	 *
	 * Completion steps will still be performed after this call.
	 *
	 * @param mixed $response The response, which will be provided by `process()` above.
	 */
	public function complete( $response ) {
		$this->response = $response;
	}

	/**
	 * Retrieves variables, set in previous steps.
	 *
	 * @param string $key Key of the value.
	 * @return mixed|null
	 */
	protected function get_var( $key ) {
		return isset( $this->vars[ $key ] ) ? $this->vars[ $key ] : null;
	}

	/**
	 * Allows any step to store variables, related to the process.
	 *
	 * Those variables will be stored with the payment, so using
	 * simple types is strongly recommended.
	 *
	 * Example: Instead of storing a `$user` object here, store
	 * just `$user_id`, and keep the full object in the step.
	 *
	 * @param string $key   Name of the value.
	 * @param mixed  $value Value to set.
	 */
	protected function set_var( $key, $value ) {
		// Prepare the previous value for logs.
		$previous = isset( $this->vars[ $key ] ) ? $this->vars[ $key ] : null;

		// Store the change, and log it.
		$this->vars[ $key ] = $value;
		$this->logger->var_changed( $key, $previous, $value, $this->current_stage, $this->current_step );
	}
}