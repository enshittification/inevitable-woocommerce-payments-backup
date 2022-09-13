/** @format **/

/**
 * External dependencies
 */
import React from 'react';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useAuthorization } from 'wcpay/data';

const CaptureAuthorizationButton = ( {
	orderId,
	paymentIntentId,
	buttonIsPrimary = false,
	buttonIsSmall = true,
	paymentIsCaptured = true,
}: {
	orderId: number;
	paymentIntentId: string;
	buttonIsPrimary?: boolean;
	buttonIsSmall?: boolean;
	paymentIsCaptured?: boolean; // TODO: remove when getAuthorization switches to live data.
} ): JSX.Element => {
	const { doCaptureAuthorization, isLoading } = useAuthorization(
		paymentIntentId,
		orderId,
		paymentIsCaptured
	);

	return (
		<Button
			isPrimary={ buttonIsPrimary }
			isSecondary={ ! buttonIsPrimary }
			isSmall={ buttonIsSmall }
			onClick={ doCaptureAuthorization }
			isBusy={ isLoading }
			disabled={ isLoading }
		>
			{ __( 'Capture', 'woocommerce-payments' ) }
		</Button>
	);
};

export default CaptureAuthorizationButton;
