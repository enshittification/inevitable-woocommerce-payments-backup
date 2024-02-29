/**
 * Internal dependencies
 */
import { getConfig } from 'wcpay/utils/checkout';
import request from 'wcpay/checkout/utils/request';
import { buildAjaxURL } from 'wcpay/payment-request/utils';
import UserConnect from 'wcpay/checkout/woopay/connect/user-connect';
import SessionConnect from 'wcpay/checkout/woopay/connect/session-connect';

/**
 * The WoopayDirectCheckout class is responsible for injecting the WooPayConnectIframe into the
 * page and for handling the communication between the WooPayConnectIframe and the page.
 */
class WoopayDirectCheckout {
	static userConnect;
	static sessionConnect;

	/**
	 * Initializes the WooPay direct checkout feature.
	 */
	static init() {
		this.getSessionConnect();
	}

	/**
	 * Gets the user connect instance.
	 *
	 * @return {*} The instance of a WooPay user connect.
	 */
	static getUserConnect() {
		if ( ! this.userConnect ) {
			this.userConnect = new UserConnect();
		}

		return this.userConnect;
	}

	/**
	 * Gets the session connect.
	 *
	 * @return {*} The instance of a WooPay session connect.
	 */
	static getSessionConnect() {
		if ( ! this.sessionConnect ) {
			this.sessionConnect = new SessionConnect();
		}

		return this.sessionConnect;
	}

	/**
	 * Teardown WoopayDirectCheckout.
	 */
	static teardown() {
		this.sessionConnect?.detachMessageListener();
		this.userConnect?.detachMessageListener();

		this.sessionConnect = null;
		this.userConnect = null;
	}

	/**
	 * Checks if WooPay is enabled.
	 *
	 * @return {boolean} True if WooPay is enabled.
	 */
	static isWooPayEnabled() {
		return getConfig( 'isWooPayEnabled' );
	}

	/**
	 * Checks if the user is logged in.
	 *
	 * @return {Promise<*>} Resolves to true if the user is logged in.
	 */
	static async isUserLoggedIn() {
		return this.getUserConnect().isUserLoggedIn();
	}

	/**
	 * Checks if third-party cookies are enabled.
	 *
	 * @return {Promise<*>} Resolves to true if third-party cookies are enabled.
	 */
	static async isWooPayThirdPartyCookiesEnabled() {
		return this.getSessionConnect().isWooPayThirdPartyCookiesEnabled();
	}

	/**
	 * Resolves the redirect URL to the WooPay checkout page or throws an error if the request fails.
	 * This function should only be called when we have determined the shopper is already logged in to WooPay.
	 *
	 * @return {string} The redirect URL.
	 * @throws {Error} If the session data could not be sent to WooPay.
	 */
	static async getWooPayCheckoutUrl() {
		// We're intentionally adding a try-catch block to catch any errors
		// that might occur other than the known validation errors.
		try {
			const encryptedSessionData = await this.getEncryptedSessionData();
			if ( ! this.isValidEncryptedSessionData( encryptedSessionData ) ) {
				throw new Error(
					'Could not retrieve encrypted session data from store.'
				);
			}

			const woopaySessionData = await this.getSessionConnect().sendRedirectSessionDataToWooPay(
				encryptedSessionData
			);
			if ( ! woopaySessionData?.redirect_url ) {
				throw new Error( 'Could not retrieve WooPay checkout URL.' );
			}

			const { redirect_url: redirectUrl } = woopaySessionData;
			if (
				! this.validateRedirectUrl(
					redirectUrl,
					'platform_checkout_key'
				)
			) {
				throw new Error( 'Invalid WooPay session URL: ' + redirectUrl );
			}

			return woopaySessionData.redirect_url;
		} catch ( error ) {
			throw new Error( error.message );
		}
	}

	/**
	 * Checks if the encrypted session object is valid.
	 *
	 * @param {Object} encryptedSessionData The encrypted session data.
	 * @return {boolean} True if the session is valid.
	 */
	static isValidEncryptedSessionData( encryptedSessionData ) {
		return (
			encryptedSessionData &&
			encryptedSessionData?.blog_id &&
			encryptedSessionData?.data?.session &&
			encryptedSessionData?.data?.iv &&
			encryptedSessionData?.data?.hash
		);
	}

	/**
	 * Gets the necessary merchant data to create session from WooPay request or throws an error if the request fails.
	 * This function should only be called if we still need to determine if the shopper is logged into WooPay or not.
	 *
	 * @return {string} WooPay redirect URL with parameters.
	 */
	static async getWooPaySessionCheckUrl() {
		const redirectData = await this.getWooPayRedirectDataFromMerchant();
		if ( redirectData.success === false ) {
			throw new Error(
				'Could not retrieve redirect data from merchant.'
			);
		}
		const setCacheSessionPromise = await this.getSessionConnect().setCacheSessionDataCallback(
			redirectData
		);
		const setCacheSessionResult = await setCacheSessionPromise;
		if (
			setCacheSessionResult?.is_error ||
			! setCacheSessionResult?.redirect_url
		) {
			throw new Error( 'Could not retrieve session data from WooPay.' );
		}

		const { redirect_url: redirectUrl } = setCacheSessionResult;
		if ( ! this.validateRedirectUrl( redirectUrl, 'cache_checkout_key' ) ) {
			throw new Error( 'Invalid WooPay session URL: ' + redirectUrl );
		}

		return redirectUrl;
	}

	/**
	 * Gets the checkout redirect elements.
	 *
	 * @return {*[]} The checkout redirect elements.
	 */
	static getCheckoutRedirectElements() {
		const elements = [];
		const addElementBySelector = ( selector ) => {
			const element = document.querySelector( selector );
			if ( element ) {
				elements.push( element );
			}
		};

		// Classic 'Proceed to Checkout' button.
		addElementBySelector( '.wc-proceed-to-checkout .checkout-button' );
		// Blocks 'Proceed to Checkout' button.
		addElementBySelector(
			'.wp-block-woocommerce-proceed-to-checkout-block a'
		);

		return elements;
	}

	/**
	 * Adds a click-event listener to the given elements that redirects to the WooPay checkout page.
	 *
	 * @param {*[]} elements The elements to add a click-event listener to.
	 * @param {boolean} userIsLoggedIn True if we determined the user is already logged in, false otherwise.
	 */
	static redirectToWooPay( elements, userIsLoggedIn ) {
		elements.forEach( ( element ) => {
			element.addEventListener( 'click', async ( event ) => {
				// Store href before the async call to not lose the reference.
				const currTargetHref = event.currentTarget.href;

				// If there's no link where to redirect the user, do not break the expected behavior.
				if ( ! currTargetHref ) {
					this.teardown();
					return;
				}

				event.preventDefault();

				try {
					let woopayRedirectUrl = '';
					if ( userIsLoggedIn ) {
						woopayRedirectUrl = await this.getWooPayCheckoutUrl();
					} else {
						woopayRedirectUrl = await this.getWooPaySessionCheckUrl();
					}

					this.teardown();
					// TODO: Add telemetry as to _how long_ it took to get to this step.
					window.location.href = woopayRedirectUrl;
				} catch ( error ) {
					// TODO: Add telemetry as to _why_ we've short-circuited the WooPay checkout flow.
					console.warn( error ); // eslint-disable-line no-console

					this.teardown();
					window.location.href = currTargetHref;
				}
			} );
		} );
	}

	/**
	 * Gets the WooPay session.
	 *
	 * @return {Promise<Promise<*>|*>} Resolves to the WooPay session response.
	 */
	static async getEncryptedSessionData() {
		return request(
			buildAjaxURL( getConfig( 'wcAjaxUrl' ), 'get_woopay_session' ),
			{
				_ajax_nonce: getConfig( 'woopaySessionNonce' ),
			}
		);
	}

	/**
	 * Gets the WooPay redirect data.
	 *
	 * @return {Promise<Promise<*>|*>} Resolves to the WooPay redirect response.
	 */
	static async getWooPayRedirectDataFromMerchant() {
		return request(
			buildAjaxURL(
				getConfig( 'wcAjaxUrl' ),
				'get_woopay_redirect_data'
			),
			{
				_ajax_nonce: getConfig( 'woopaySessionNonce' ),
			}
		);
	}

	/**
	 * Validates a WooPay redirect URL.
	 *
	 * @param {string} redirectUrl The URL to validate.
	 * @param {string} requiredParam The URL parameter that is required in the URL.
	 *
	 * @return {boolean} True if URL is valid, false otherwise.
	 */
	static validateRedirectUrl( redirectUrl, requiredParam ) {
		try {
			const parsedUrl = new URL( redirectUrl );
			if (
				parsedUrl.origin !== getConfig( 'woopayHost' ) ||
				! parsedUrl.searchParams.has( requiredParam )
			) {
				return false;
			}

			return true;
		} catch ( error ) {
			return false;
		}
	}
}

export default WoopayDirectCheckout;
