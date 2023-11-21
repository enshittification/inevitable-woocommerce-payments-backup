/** @format **/

/**
 * External dependencies
 */

import React from 'react';
import { Button, RadioControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import interpolateComponents from '@automattic/interpolate-components';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies.
 */

import './style.scss';
import CardNotice from 'wcpay/components/card-notice';
import ConfirmationModal from 'wcpay/components/confirmation-modal';
import Loadable from 'wcpay/components/loadable';
import { Charge } from 'wcpay/types/charges';

interface MissingOrderNoticeProps {
	charge: Charge;
	isLoading: boolean;
	formattedAmount: string;
}

const MissingOrderNotice: React.FC< MissingOrderNoticeProps > = ( {
	charge,
	isLoading,
	formattedAmount,
} ) => {
	const [ isModalOpen, setIsModalOpen ] = useState( false );

	const [ reason, setReason ] = useState< string | null >( null );

	const handleOnButtonClick = () => {
		setIsModalOpen( true );
	};

	const handleModalCancel = () => {
		setIsModalOpen( false );
	};

	const handleModalConfirmation = async () => {
		const response = await apiFetch( {
			path: `/wc/v3/payments/refund/`,
			method: 'post',
			data: {
				charge_id: charge.id,
				amount: charge.amount,
				reason: reason,
			},
		} );
		setIsModalOpen( false );
	};

	return (
		<>
			<Loadable isLoading={ isLoading } placeholder="">
				<CardNotice
					actions={
						! charge.refunded ? (
							<Button
								variant="primary"
								isSmall={ false }
								onClick={ handleOnButtonClick }
							>
								{ __( 'Refund', 'woocommerce-payments' ) }
							</Button>
						) : (
							<></>
						)
					}
				>
					{ __(
						'This transaction is not connected to order. Investigate this purchase and refund the transaction as needed.',
						'woocommerce-payments'
					) }
				</CardNotice>
			</Loadable>
			{ isModalOpen && (
				<ConfirmationModal
					className="missing-order-notice-modal"
					title={ __( 'Refund Transaction', 'woocommerce-payments' ) }
					actions={
						<>
							<Button
								onClick={ handleModalCancel }
								variant="secondary"
							>
								{ __( 'Cancel', 'woocommerce-payments' ) }
							</Button>
							<Button
								onClick={ handleModalConfirmation }
								isPrimary
							>
								{ __(
									'Refund transaction',
									'woocommerce-payments'
								) }
							</Button>
						</>
					}
					onRequestClose={ handleModalCancel }
				>
					<p>
						{ interpolateComponents( {
							mixedString: sprintf(
								__(
									'This will issue a full refund of {{strong}}%s{{/strong}} to the customer.',
									'woocommerce-payments'
								),
								formattedAmount
							),
							components: {
								strong: <strong />,
							},
						} ) }
					</p>
					<RadioControl
						className="missing-order-notice-modal__reason"
						label={ __(
							'Select a reason (Optional)',
							'woocommerce-payments'
						) }
						selected={ reason }
						options={ [
							{
								label: __(
									'Duplicate order',
									'woocommerce-payments'
								),
								value: 'duplicate_order',
							},
							{
								label: __(
									'Fraudulent',
									'woocommerce-payments'
								),
								value: 'fraudulent',
							},
							{
								label: __(
									'Requested by customer',
									'woocommerce-payments'
								),
								value: 'requested_by_customer',
							},
							{
								label: __( 'Other', 'woocommerce-payments' ),
								value: null,
							},
						] }
						onChange={ ( value: string ) => setReason( value ) }
					/>
				</ConfirmationModal>
			) }
		</>
	);
};

export default MissingOrderNotice;
