/**
 * External dependencies
 */
import { test, expect } from '@playwright/test';

test.describe(
	'A basic set of tests to ensure WP, wp-admin and my-account load',
	() => {
		test( 'Load the home page', async ( { page } ) => {
			await page.goto( '/' );
			const title = page.locator( 'h1.site-title' );
			await expect( title ).toHaveText(
				/WooCommerce Payments E2E site/i
			);
			await expect( page ).toHaveScreenshot();
		} );

		test.describe( 'Sign in as admin', () => {
			test.use( {
				storageState: process.env.ADMINSTATE,
			} );
			test( 'Load Payments Overview', async ( { page } ) => {
				await page.goto(
					'/wp-admin/admin.php?page=wc-admin&path=/payments/overview'
				);
				await page.waitForLoadState( 'networkidle' );
				const logo = page.getByAltText( 'WooPayments logo' );
				await expect( logo ).toBeVisible();
			} );
		} );

		test.describe( 'Sign in as customer', () => {
			test.use( {
				storageState: process.env.CUSTOMERSTATE,
			} );
			test( 'Load customer my account page', async ( { page } ) => {
				await page.goto( '/my-account' );
				const title = page.locator( 'h1.entry-title' );
				await expect( title ).toHaveText( 'My account' );
			} );
		} );
	}
);
