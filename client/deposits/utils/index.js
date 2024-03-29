/**
 * External dependencies
 */
import { __, sprintf, _n } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';
import moment from 'moment';
import { createInterpolateElement } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { getAdminUrl } from 'wcpay/utils';

const formatDate = ( format, date ) =>
	dateI18n(
		format,
		moment.utc( date ).toISOString(),
		true // TODO Change call to gmdateI18n and remove this deprecated param once WP 5.4 support ends.
	);

export const getDepositDate = ( deposit ) =>
	deposit ? formatDate( 'F j, Y', deposit.date ) : '—';

export const getBalanceDepositCount = ( balance ) =>
	sprintf(
		_n(
			'%d deposit',
			'%d deposits',
			balance.deposits_count,
			'woocommerce-payments'
		),
		balance.deposits_count
	);

export const getNextDepositLabelFormatted = ( deposit ) => {
	const baseLabel = deposit
		? `${ __( 'Est.', 'woocommerce-payments' ) } ${ formatDate(
				'M j, Y',
				deposit.date
		  ) }`
		: '—';
	if ( deposit && 'in_transit' === deposit.status ) {
		return `${ baseLabel } - ${ __(
			'In transit',
			'woocommerce-payments'
		) }`;
	}
	return baseLabel;
};

export const getDepositMonthlyAnchorLabel = ( {
	monthlyAnchor,
	capitalize = true,
} ) => {
	// If locale is set up as en_US or en_GB the ordinal will not show up
	// More details can be found in https://github.com/WordPress/gutenberg/issues/15221/
	// Using 'en' as the locale should be enough to workaround it
	// TODO: Remove workaround when issue is resolved
	const fixedLocale = moment.locale().startsWith( 'en' )
		? 'en'
		: moment.locale();

	let label = moment()
		.locale( fixedLocale )
		.date( monthlyAnchor )
		.format( 'Do' );

	if ( 31 === monthlyAnchor ) {
		label = __( 'Last day of the month', 'woocommerce-payments' );
	}
	if ( ! capitalize ) {
		label = label.toLowerCase();
	}
	return label;
};

const formatDepositSchedule = ( schedule ) => {
	switch ( schedule.interval ) {
		case 'manual':
			return __( 'Deposits set to manual.', 'woocommerce-payments' );
		case 'daily':
			return __( 'Deposits set to daily.', 'woocommerce-payments' );
		case 'weekly':
			return sprintf(
				/** translators: %s day of the week e.g. Monday */
				__( 'Deposits set to every %s.', 'woocommerce-payments' ),
				// moment().day() is locale based when using strings. Since Stripe's response is in English,
				// we need to temporarily set the locale to add the day before formatting
				moment()
					.locale( 'en' )
					.day( schedule.weekly_anchor )
					.locale( moment.locale() )
					.format( 'dddd' )
			);
		case 'monthly':
			return sprintf(
				/** translators: %s day of the month */
				__(
					'Deposits set to monthly on the %s.',
					'woocommerce-payments'
				),
				getDepositMonthlyAnchorLabel( {
					monthlyAnchor: schedule.monthly_anchor,
					capitalize: false,
				} )
			);
	}
};

export const getDepositScheduleDescriptor = ( {
	account: {
		deposits_schedule: schedule,
		deposits_disabled: disabled,
		deposits_blocked: blocked,
	},
	last_deposit: last,
} ) => {
	const hasCompletedWaitingPeriod =
		window.wcpaySettings?.accountStatus?.deposits
			?.completed_waiting_period ?? false;

	const learnMoreHref =
		'https://woocommerce.com/document/payments/faq/deposit-schedule/';

	if ( disabled || blocked ) {
		return createInterpolateElement(
			/* translators: <a> - suspended accounts FAQ URL */
			__(
				'Deposits temporarily suspended (<a>learn more</a>).',
				'woocommerce-payments'
			),
			{
				a: (
					// eslint-disable-next-line jsx-a11y/anchor-has-content
					<a
						href={
							'https://woocommerce.com/document/payments/faq/deposits-suspended/'
						}
						target="_blank"
						rel="noopener noreferrer"
					/>
				),
			}
		);
	}

	if ( ! last ) {
		return createInterpolateElement(
			sprintf(
				/** translators: %s - deposit schedule, <a> - waiting period doc URL */
				__(
					'%s Your first deposit is held for seven days (<a>learn more</a>).',
					'woocommerce-payments'
				),
				formatDepositSchedule( schedule )
			),
			{
				a: (
					// eslint-disable-next-line jsx-a11y/anchor-has-content
					<a
						href={ learnMoreHref }
						target="_blank"
						rel="noopener noreferrer"
					/>
				),
			}
		);
	}

	if ( hasCompletedWaitingPeriod && ! blocked && ! disabled ) {
		return createInterpolateElement(
			sprintf(
				/** translators: %s - deposit schedule, <a> - Settings page URL */
				__(
					'%s <a>Change this</a> or <learn_more_href/>.',
					'woocommerce-payments'
				),
				formatDepositSchedule( schedule )
			),
			{
				a: (
					// eslint-disable-next-line jsx-a11y/anchor-has-content
					<a
						href={
							getAdminUrl( {
								page: 'wc-settings',
								section: 'woocommerce_payments',
								tab: 'checkout',
							} ) + '#deposit-schedule'
						}
					/>
				),
				learn_more_href: (
					// eslint-disable-next-line jsx-a11y/anchor-has-content
					<a
						href={ learnMoreHref }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'learn more', 'woocommerce-payments' ) }
					</a>
				),
			}
		);
	}

	return formatDepositSchedule( schedule );
};
