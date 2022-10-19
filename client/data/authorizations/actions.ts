/** @format */
/**
 * External Dependencies
 */
import { Query } from '@woocommerce/navigation';
import { apiFetch, dispatch } from '@wordpress/data-controls';
import { __ } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import TYPES from './action-types';
import {
	AuthorizationsSummary,
	Authorization,
	AuthorizationsList,
} from 'wcpay/types/authorizations';
import { STORE_NAME } from '../constants';
import { ApiError } from 'wcpay/types/errors';

export function updateAuthorizations(
	query: Query,
	authorizationsList: AuthorizationsList
): {
	type: string;
	data: Authorization[];
	query: Query;
} {
	return {
		type: TYPES.SET_AUTHORIZATIONS,
		data: authorizationsList.data,
		query,
	};
}

export function updateErrorForAuthorizations(
	query: Query,
	error: ApiError
): {
	type: string;
	query: Query;
	error: ApiError;
} {
	return {
		type: TYPES.SET_ERROR_FOR_AUTHORIZATIONS,
		query,
		error,
	};
}

export function updateAuthorization(
	data: Authorization
): { type: string; data: Authorization } {
	return {
		type: TYPES.SET_AUTHORIZATION,
		data,
	};
}

export function updateAuthorizationsSummary(
	query: Query,
	data: AuthorizationsSummary
): {
	type: string;
	data: AuthorizationsSummary;
	query: Query;
} {
	return {
		type: TYPES.SET_AUTHORIZATIONS_SUMMARY,
		data,
		query,
	};
}

export function* submitCaptureAuthorization(
	paymentIntentId: string,
	orderId: number
): Generator< unknown | Authorization > {
	try {
		yield dispatch( STORE_NAME, 'startResolution', 'getAuthorization', [
			paymentIntentId,
		] );

		const result = yield apiFetch( {
			path: `/wc/v3/payments/orders/${ orderId }/capture_authorization`,
			method: 'post',
			data: {
				payment_intent_id: paymentIntentId,
			},
		} );

		const authorization = {
			payment_intent_id: ( result as any ).id,
			captured: ( result as any ).status === 'succeeded',
		};

		yield updateAuthorization( authorization as Authorization );

		// Need to invalidate the resolution so that the components will render again.
		yield dispatch(
			STORE_NAME,
			'invalidateResolutionForStoreSelector',
			'getAuthorizations'
		);

		yield dispatch(
			STORE_NAME,
			'invalidateResolutionForStoreSelector',
			'getAuthorizationsSummary'
		);
		// Create success notice.
		yield dispatch(
			'core/notices',
			'createSuccessNotice',
			__( 'You have captured the payment.', 'woocommerce-payments' )
		);
	} catch ( error ) {
		const message = __(
			'There has been an error capturing the payment. Please try again later.',
			'woocommerce-payments'
		);
		yield dispatch( 'core/notices', 'createErrorNotice', message );
	} finally {
		yield dispatch( STORE_NAME, 'finishResolution', 'getAuthorization', [
			paymentIntentId,
		] );
	}
}
