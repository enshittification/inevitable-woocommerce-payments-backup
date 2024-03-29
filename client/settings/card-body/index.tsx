/**
 * External dependencies
 */
import React from 'react';
import { CardBody } from '@wordpress/components';
import classNames from 'classnames';

/**
 * Internal dependencies
 */
import './styles.scss';

const WcpayCardBody = ( {
	className,
	...props
}: {
	className?: string;
	children?: JSX.Element;
} ): JSX.Element => (
	<CardBody
		className={ classNames( 'wcpay-card-body', className ) }
		{ ...props }
	/>
);

export default WcpayCardBody;
