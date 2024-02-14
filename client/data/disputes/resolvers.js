/** @format */

/**
 * External dependencies
 */
import { apiFetch } from '@wordpress/data-controls';
import { controls } from '@wordpress/data';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';
import { formatDateValue } from 'utils';
import { snakeCase } from 'lodash';

/**
 * Internal dependencies
 */
import { NAMESPACE } from '../constants';
import {
	updateDispute,
	updateDisputes,
	updateDisputesSummary,
	updateErrorForDispute,
} from './actions';

const formatQueryFilters = ( query ) => ( {
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
	locale: query.locale,
} );

export function getDisputesCSV( query ) {
	const path = addQueryArgs(
		`${ NAMESPACE }/disputes/download`,
		formatQueryFilters( query )
	);

	return path;
}

/**
 * Retrieve a single dispute from the disputes API.
 *
 * @param {string} id Identifier for specified dispute to retrieve.
 */
export function* getDispute( id ) {
	const path = addQueryArgs( `${ NAMESPACE }/disputes/${ id }` );

	try {
		const result = yield apiFetch( { path } );
		yield updateDispute( result );
	} catch ( e ) {
		yield controls.dispatch(
			'core/notices',
			'createErrorNotice',
			__( 'Error retrieving dispute.', 'woocommerce-payments' )
		);
		yield updateErrorForDispute( id, undefined, e );
	}
}

/**
 * Retrieves a series of disputes from the disputes list API.
 *
 * @param {string} query Data on which to parameterize the selection.
 */
export function* getDisputes( query ) {
	const path = addQueryArgs( `${ NAMESPACE }/disputes`, {
		page: query.paged,
		pagesize: query.perPage,
		sort: snakeCase( query.orderBy ),
		direction: query.order,
		...formatQueryFilters( query ),
	} );

	try {
		const results = yield apiFetch( { path } ) || {};
		yield updateDisputes( query, results.data );
	} catch ( e ) {
		yield controls.dispatch(
			'core/notices',
			'createErrorNotice',
			__( 'Error retrieving disputes.', 'woocommerce-payments' )
		);
	}
}

export function* getDisputesSummary( query ) {
	const path = addQueryArgs( `${ NAMESPACE }/disputes/summary`, {
		page: query.paged,
		pagesize: query.perPage,
		...formatQueryFilters( query ),
	} );

	try {
		const summary = yield apiFetch( { path } );
		yield updateDisputesSummary( query, summary );
	} catch ( e ) {
		yield controls.dispatch(
			'core/notices',
			'createErrorNotice',
			__(
				'Error retrieving the summary of disputes.',
				'woocommerce-payments'
			)
		);
	}
}
