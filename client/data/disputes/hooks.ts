/** @format */

/**
 * External dependencies
 */
import type { Query } from '@woocommerce/navigation';
import { useDispatch } from '@wordpress/data';
import moment from 'moment';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, sprintf } from '@wordpress/i18n';
import { snakeCase } from 'lodash';
import {
	QueryFunctionContext,
	useMutation,
	useQuery,
	useQueryClient,
} from 'react-query';

/**
 * Internal dependencies
 */
import type {
	Dispute,
	DisputesSummary,
	CachedDisputes,
} from 'wcpay/types/disputes';
import type { ApiError } from 'wcpay/types/errors';
import { NAMESPACE, STORE_NAME } from '../constants';
import { disputeAwaitingResponseStatuses } from 'wcpay/disputes/filters/config';
import { formatDateValue } from 'wcpay/utils';
import wcpayTracks from 'tracks';

const fetchDispute = async ( { queryKey }: QueryFunctionContext ) => {
	const [ , id ] = queryKey;
	const path = addQueryArgs( `/wc/v3/payments/disputes/${ id }` );
	const response = await apiFetch< Dispute >( { path } );
	return response;
};

/**
 * Returns the dispute object, error object, and loading state.
 * Fetches the dispute object if it is not already cached.
 */
export const useDispute = (
	id: string
): {
	dispute?: Dispute;
	error?: ApiError | null;
	isLoading: boolean;
} => {
	const { createErrorNotice } = useDispatch( 'core/notices' );

	const { data, isLoading, isError, error } = useQuery<
		Dispute | undefined,
		ApiError | undefined
	>( [ 'disputes', id ], fetchDispute, {
		refetchOnMount: false,
		retry: false,
		onError: () => {
			createErrorNotice(
				__( 'Error retrieving dispute.', 'woocommerce-payments' )
			);
		},
	} );

	return {
		dispute: data,
		error: isError ? error : undefined,
		isLoading,
	};
};

const acceptDispute = async ( disputeId: string ) => {
	const response = apiFetch< Dispute >( {
		path: `${ NAMESPACE }/disputes/${ disputeId }/close`,
		method: 'post',
	} );
	return response;
};

/**
 * Returns the dispute accept function and loading state.
 * Does not return or fetch the dispute object.
 */
export const useDisputeAccept = (
	dispute: Dispute
): {
	doAccept: () => void;
	isLoading: boolean;
} => {
	const queryClient = useQueryClient();
	const { createErrorNotice, createSuccessNotice } = useDispatch(
		'core/notices'
	);

	const { isLoading, mutate } = useMutation( acceptDispute, {
		onSuccess: ( updatedDispute: Dispute ) => {
			// Invalidate all disputes queries.
			queryClient.invalidateQueries( 'disputes' );

			// TODO: Invalidate payment intent query.

			wcpayTracks.recordEvent( 'wcpay_dispute_accept_success' );

			createSuccessNotice(
				updatedDispute.order
					? sprintf(
							/* translators: #%s is an order number, e.g. 15 */
							__(
								'You have accepted the dispute for order #%s.',
								'woocommerce-payments'
							),
							updatedDispute.order.number
					  )
					: __(
							'You have accepted the dispute.',
							'woocommerce-payments'
					  )
			);
		},
		onError: () => {
			wcpayTracks.recordEvent( 'wcpay_dispute_accept_failed' );
			createErrorNotice(
				__(
					'There has been an error accepting the dispute. Please try again later.',
					'woocommerce-payments'
				)
			);
		},
	} );

	const doAccept = () => mutate( dispute.id );

	return { doAccept, isLoading };
};

export const useDisputeEvidence = (): {
	updateDispute: ( data: Dispute ) => void;
} => {
	const { updateDispute } = useDispatch( STORE_NAME );
	return { updateDispute };
};

const formatQueryFilters = ( query: any ) => ( {
	user_email: query.userEmail,
	match: query.match,
	store_currency_is: query.storeCurrencyIs,
	date_before: formatDateValue( query.dateBefore, true ),
	date_after: formatDateValue( query.dateAfter ),
	date_between: query.dateBetween && [
		formatDateValue( query.dateBetween[ 0 ] ),
		formatDateValue( query.dateBetween[ 1 ], true ),
	],
	search: query.search,
	status_is: query.statusIs,
	status_is_not: query.statusIsNot,
} );

const fetchDisputes = async ( { queryKey }: QueryFunctionContext ) => {
	const [ , query ] = queryKey;
	const path = addQueryArgs(
		`/wc/v3/payments/disputes`,
		query as Record< string, unknown >
	);
	const response = await apiFetch< {
		data: CachedDisputes[ 'disputes' ];
	} >( { path } );
	return response;
};

export const useDisputes = ( {
	paged,
	per_page: perPage,
	store_currency_is: storeCurrencyIs,
	match,
	date_before: dateBefore,
	date_after: dateAfter,
	date_between: dateBetween,
	filter,
	status_is: statusIs,
	status_is_not: statusIsNot,
	orderby: orderBy,
	order,
}: Query ): CachedDisputes => {
	const search =
		filter === 'awaiting_response'
			? disputeAwaitingResponseStatuses
			: undefined;

	let query: Record< string, unknown > = {
		paged: Number.isNaN( parseInt( paged ?? '', 10 ) ) ? '1' : paged,
		perPage: Number.isNaN( parseInt( perPage ?? '', 10 ) ) ? '25' : perPage,
		storeCurrencyIs,
		match,
		dateBefore,
		dateAfter,
		dateBetween:
			dateBetween &&
			dateBetween.sort( ( a, b ) => moment( a ).diff( moment( b ) ) ),
		search,
		statusIs,
		statusIsNot,
		orderBy: orderBy || 'created',
		order: order || 'desc',
	};

	query = {
		page: query.paged,
		pagesize: query.perPage,
		sort: snakeCase( query.orderBy as string ),
		direction: query.order,
		...formatQueryFilters( query ),
	};

	const { isLoading, data } = useQuery(
		[ 'disputes', query ],
		fetchDisputes,
		{
			refetchOnMount: true,
			refetchOnWindowFocus: true,
			refetchInterval: false,
			refetchOnReconnect: true,
		}
	);

	return {
		disputes: data?.data || [],
		isLoading,
	};
};

const fetchDisputesSummary = async ( { queryKey }: QueryFunctionContext ) => {
	const [ , query ] = queryKey;
	const path = addQueryArgs(
		`/wc/v3/payments/disputes/summary`,
		query as Record< string, unknown >
	);

	const response = await apiFetch< {
		count?: number;
		currencies?: string[];
	} >( {
		path,
	} );

	return response;
};

export const useDisputesSummary = ( {
	paged,
	per_page: perPage,
	match,
	store_currency_is: storeCurrencyIs,
	date_before: dateBefore,
	date_after: dateAfter,
	date_between: dateBetween,
	filter,
	status_is: statusIs,
	status_is_not: statusIsNot,
}: Query ): DisputesSummary => {
	const search =
		filter === 'awaiting_response'
			? disputeAwaitingResponseStatuses
			: undefined;

	let query = {
		paged: Number.isNaN( parseInt( paged ?? '', 10 ) ) ? '1' : paged,
		perPage: Number.isNaN( parseInt( perPage ?? '', 10 ) ) ? '25' : perPage,
		match,
		storeCurrencyIs,
		dateBefore,
		dateAfter,
		dateBetween,
		search,
		statusIs,
		statusIsNot,
	} as any;

	query = {
		...query,
		page: query.paged,
		pagesize: query.perPage,
		...formatQueryFilters( query ),
	};

	const { isLoading, data } = useQuery(
		[ 'disputesSummary', query ],
		fetchDisputesSummary
	);
	return {
		disputesSummary: data || {},
		isLoading,
	};
};
