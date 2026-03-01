/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const CAMPAIGNS_PATH = 'admin.php?page=mission-campaigns';

/**
 * Regex that matches a REST route in both /wp-json/ and ?rest_route= formats.
 *
 * @param {string} route REST route path, e.g. '/mission/v1/campaigns'.
 * @return {RegExp} Pattern for page.route().
 */
function restRoute( route ) {
	const pattern = route.slice( 1 ).replace( /\//g, '(\\/|%2F)' );
	return new RegExp( pattern );
}

/**
 * Intercept GET /campaigns with the given response data.
 *
 * @param {import('@playwright/test').Page} page      Playwright page.
 * @param {Array}                           campaigns Campaign items to return.
 * @param {Object}                          headers   Optional response headers.
 */
async function mockCampaignsApi( page, campaigns, headers = {} ) {
	await page.route(
		restRoute( '/mission/v1/campaigns' ),
		async ( route, request ) => {
			if ( request.method() === 'GET' ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					headers: {
						'X-WP-Total': String(
							headers.total ?? campaigns.length
						),
						'X-WP-TotalPages': String( headers.totalPages ?? 1 ),
					},
					body: JSON.stringify( campaigns ),
				} );
			} else if (
				request.method() === 'DELETE' ||
				/campaigns(%2F|\/)\d+/.test( request.url() )
			) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { deleted: true, id: 1 } ),
				} );
			} else if (
				request.method() === 'POST' &&
				/batch-delete/.test( request.url() )
			) {
				const body = request.postDataJSON();
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						deleted: body.ids || [],
						errors: [],
					} ),
				} );
			} else if ( request.method() === 'POST' ) {
				const body = request.postDataJSON();
				await route.fulfill( {
					status: 201,
					contentType: 'application/json',
					body: JSON.stringify( {
						id: 99,
						title: body.title,
						excerpt: body.excerpt || '',
						status: 'active',
						edit_url: '/wp-admin/post.php?post=99&action=edit',
						view_url: '/?p=99',
						date_created: '2026-02-28 12:00:00',
						goal_amount: body.goal_amount || 0,
						total_raised: 0,
						transaction_count: 0,
						currency: 'usd',
						date_start: null,
						date_end: null,
						meta: {
							amounts: body.amounts || [
								1000, 2500, 5000, 10000,
							],
							custom_amount: true,
							minimum_amount: 500,
							recurring_enabled: true,
							recurring_frequencies: [
								'monthly',
								'quarterly',
								'annually',
							],
							recurring_default: 'one_time',
							fee_recovery: true,
							tip_enabled: true,
							tip_percentages: [ 5, 10, 15 ],
							anonymous_enabled: false,
							tribute_enabled: false,
							confirmation_message: '',
						},
					} ),
				} );
			} else {
				await route.continue();
			}
		}
	);
}

const MOCK_CAMPAIGNS = [
	{
		id: 1,
		title: 'Annual Fundraiser',
		status: 'active',
		goal_amount: 100000,
		total_raised: 45000,
		transaction_count: 23,
		edit_url: '/wp-admin/post.php?post=1&action=edit',
		date_start: '2026-01-01',
		date_end: '2026-12-31',
	},
	{
		id: 2,
		title: 'Emergency Relief',
		status: 'scheduled',
		goal_amount: 50000,
		total_raised: 0,
		transaction_count: 0,
		edit_url: '/wp-admin/post.php?post=2&action=edit',
		date_start: '2027-01-01',
		date_end: null,
	},
];

const MOCK_SINGLE_CAMPAIGN = {
	id: 1,
	title: 'Annual Fundraiser',
	excerpt: 'Our yearly fundraiser.',
	status: 'active',
	edit_url: '/wp-admin/post.php?post=1&action=edit',
	view_url: '/?p=1',
	date_created: '2026-01-15 12:00:00',
	goal_amount: 100000,
	total_raised: 45000,
	transaction_count: 23,
	currency: 'usd',
	date_start: '2026-01-01',
	date_end: '2026-12-31',
	meta: {
		amounts: [ 1000, 2500, 5000, 10000 ],
		custom_amount: true,
		minimum_amount: 500,
		recurring_enabled: true,
		recurring_frequencies: [ 'monthly', 'quarterly', 'annually' ],
		recurring_default: 'one_time',
		fee_recovery: true,
		tip_enabled: true,
		tip_percentages: [ 5, 10, 15 ],
		anonymous_enabled: false,
		tribute_enabled: false,
		confirmation_message: '',
	},
};

test.describe( 'Campaigns Page', () => {
	test( 'empty state renders with Create a Campaign button', async ( {
		admin,
		page,
	} ) => {
		await mockCampaignsApi( page, [] );
		await admin.visitAdminPage( CAMPAIGNS_PATH );

		await expect(
			page.getByRole( 'heading', { name: 'No campaigns yet' } )
		).toBeVisible();

		const createButton = page.getByRole( 'button', {
			name: 'Create a Campaign',
		} );
		await expect( createButton ).toBeVisible();
	} );

	test( 'Create a Campaign button opens modal', async ( { admin, page } ) => {
		await mockCampaignsApi( page, [] );
		await admin.visitAdminPage( CAMPAIGNS_PATH );

		await page.getByRole( 'button', { name: 'Create a Campaign' } ).click();

		const modal = page.getByRole( 'dialog' );
		await expect( modal ).toBeVisible();
		await expect(
			modal.getByRole( 'heading', { name: 'Create a Campaign' } )
		).toBeVisible();
	} );

	test( 'campaign list renders with data', async ( { admin, page } ) => {
		await mockCampaignsApi( page, MOCK_CAMPAIGNS );
		await admin.visitAdminPage( CAMPAIGNS_PATH );

		await expect(
			page.getByRole( 'heading', { name: 'Campaigns', level: 1 } )
		).toBeVisible();

		await expect( page.getByText( 'Annual Fundraiser' ) ).toBeVisible();
		await expect( page.getByText( 'Emergency Relief' ) ).toBeVisible();

		await expect( page.getByText( 'Active' ).first() ).toBeVisible();
		await expect( page.getByText( 'Scheduled' ).first() ).toBeVisible();
	} );

	test( 'Add Campaign button opens modal', async ( { admin, page } ) => {
		await mockCampaignsApi( page, MOCK_CAMPAIGNS );
		await admin.visitAdminPage( CAMPAIGNS_PATH );

		await page.getByRole( 'button', { name: 'Add Campaign' } ).click();

		const modal = page.getByRole( 'dialog' );
		await expect( modal ).toBeVisible();
	} );

	test( 'modal steps navigate correctly', async ( { admin, page } ) => {
		await mockCampaignsApi( page, MOCK_CAMPAIGNS );
		await admin.visitAdminPage( CAMPAIGNS_PATH );

		await page.getByRole( 'button', { name: 'Add Campaign' } ).click();

		const modal = page.getByRole( 'dialog' );

		// Step 1: Next should be disabled without title.
		const nextButton = modal.getByRole( 'button', { name: 'Next' } );
		await expect( nextButton ).toBeDisabled();

		// Fill title and proceed.
		await modal
			.getByRole( 'textbox', { name: 'Campaign Title' } )
			.fill( 'Test Campaign' );
		await expect( nextButton ).toBeEnabled();
		await nextButton.click();

		// Step 2: Should see Goal Amount field.
		await expect(
			modal.getByRole( 'spinbutton', { name: /Goal Amount/ } )
		).toBeVisible();
		await nextButton.click();

		// Step 3: Should see Create Campaign button.
		await expect(
			modal.getByRole( 'button', { name: 'Create Campaign' } )
		).toBeVisible();

		// Back button should go to step 2.
		await modal.getByRole( 'button', { name: 'Back' } ).click();
		await expect(
			modal.getByRole( 'spinbutton', { name: /Goal Amount/ } )
		).toBeVisible();
	} );

	test( 'campaign creation submits and redirects to detail view', async ( {
		admin,
		page,
	} ) => {
		await mockCampaignsApi( page, MOCK_CAMPAIGNS );
		await admin.visitAdminPage( CAMPAIGNS_PATH );

		await page.getByRole( 'button', { name: 'Add Campaign' } ).click();

		const modal = page.getByRole( 'dialog' );

		// Step 1.
		await modal
			.getByRole( 'textbox', { name: 'Campaign Title' } )
			.fill( 'New Test Campaign' );
		await modal.getByRole( 'button', { name: 'Next' } ).click();

		// Step 2 — skip.
		await modal.getByRole( 'button', { name: 'Next' } ).click();

		// Step 3 — submit.
		const submitButton = modal.getByRole( 'button', {
			name: 'Create Campaign',
		} );

		// Expect navigation to detail view after submit.
		const navigationPromise = page.waitForURL( /campaign=99/ );
		await submitButton.click();
		await navigationPromise;

		expect( page.url() ).toContain( 'campaign=99' );
	} );

	test( 'delete action shows confirmation modal', async ( {
		admin,
		page,
	} ) => {
		await mockCampaignsApi( page, MOCK_CAMPAIGNS );
		await admin.visitAdminPage( CAMPAIGNS_PATH );

		// Wait for data to load.
		await expect( page.getByText( 'Annual Fundraiser' ) ).toBeVisible();

		// Open the actions menu on the first row.
		const firstRow = page.getByRole( 'row' ).nth( 1 );
		await firstRow.getByRole( 'button', { name: 'Actions' } ).click();

		// Click Delete.
		await page.getByRole( 'menuitem', { name: 'Delete' } ).click();

		// Confirmation modal should appear.
		const modal = page.getByRole( 'dialog' );
		await expect( modal ).toBeVisible();
		await expect(
			modal.getByText( 'Are you sure you want to delete this campaign?' )
		).toBeVisible();
		await expect( modal.getByText( 'Annual Fundraiser' ) ).toBeVisible();

		// Cancel should close the modal.
		await modal.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( modal ).not.toBeVisible();
	} );

	test( 'delete confirm removes campaign without errors', async ( {
		admin,
		page,
	} ) => {
		let deleteRequested = false;

		await page.route(
			restRoute( '/mission/v1/campaigns' ),
			async ( route, request ) => {
				const isDelete =
					request.method() === 'DELETE' ||
					/campaigns(%2F|\/)\d+/.test( request.url() );
				if ( isDelete ) {
					deleteRequested = true;
					await route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify( {
							deleted: true,
							id: 1,
						} ),
					} );
				} else if ( request.method() === 'GET' ) {
					// After delete, return only the remaining campaign.
					const items = deleteRequested
						? [ MOCK_CAMPAIGNS[ 1 ] ]
						: MOCK_CAMPAIGNS;
					await route.fulfill( {
						status: 200,
						contentType: 'application/json',
						headers: {
							'X-WP-Total': String( items.length ),
							'X-WP-TotalPages': '1',
						},
						body: JSON.stringify( items ),
					} );
				} else {
					await route.continue();
				}
			}
		);

		// Collect console errors.
		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await admin.visitAdminPage( CAMPAIGNS_PATH );
		await expect( page.getByText( 'Annual Fundraiser' ) ).toBeVisible();

		// Open actions menu and click Delete.
		const firstRow = page.getByRole( 'row' ).nth( 1 );
		await firstRow.getByRole( 'button', { name: 'Actions' } ).click();
		await page.getByRole( 'menuitem', { name: 'Delete' } ).click();

		// Confirm deletion.
		const modal = page.getByRole( 'dialog' );
		await modal.getByRole( 'button', { name: 'Delete' } ).click();

		// Modal should close and campaign should disappear.
		await expect( modal ).not.toBeVisible();
		await expect( page.getByText( 'Annual Fundraiser' ) ).not.toBeVisible();
		await expect( page.getByText( 'Emergency Relief' ) ).toBeVisible();

		// No JS errors should have occurred.
		expect( errors ).toHaveLength( 0 );
	} );

	test( 'bulk delete removes multiple campaigns', async ( {
		admin,
		page,
	} ) => {
		let batchDeleteRequested = false;

		await page.route(
			restRoute( '/mission/v1/campaigns' ),
			async ( route, request ) => {
				if (
					request.method() === 'POST' &&
					/batch-delete/.test( request.url() )
				) {
					batchDeleteRequested = true;
					await route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify( {
							deleted: [ 1, 2 ],
							errors: [],
						} ),
					} );
				} else if ( request.method() === 'GET' ) {
					const items = batchDeleteRequested ? [] : MOCK_CAMPAIGNS;
					await route.fulfill( {
						status: 200,
						contentType: 'application/json',
						headers: {
							'X-WP-Total': String( items.length ),
							'X-WP-TotalPages': items.length ? '1' : '0',
						},
						body: JSON.stringify( items ),
					} );
				} else {
					await route.continue();
				}
			}
		);

		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await admin.visitAdminPage( CAMPAIGNS_PATH );
		await expect( page.getByText( 'Annual Fundraiser' ) ).toBeVisible();

		// Select all campaigns via the header checkbox.
		const headerCheckbox = page
			.getByRole( 'table' )
			.getByRole( 'checkbox' )
			.first();
		await headerCheckbox.check();

		// Click the bulk Delete action.
		await page.getByRole( 'button', { name: 'Delete' } ).click();

		// Confirmation modal should list both campaigns.
		const modal = page.getByRole( 'dialog' );
		await expect( modal ).toBeVisible();
		await expect(
			modal.getByText(
				'Are you sure you want to delete these campaigns?'
			)
		).toBeVisible();
		await expect( modal.getByText( 'Annual Fundraiser' ) ).toBeVisible();
		await expect( modal.getByText( 'Emergency Relief' ) ).toBeVisible();

		// Confirm deletion.
		await modal.getByRole( 'button', { name: 'Delete' } ).click();

		// Modal should close and campaigns should disappear.
		await expect( modal ).not.toBeVisible();
		await expect( page.getByText( 'Annual Fundraiser' ) ).not.toBeVisible();
		await expect( page.getByText( 'Emergency Relief' ) ).not.toBeVisible();

		expect( errors ).toHaveLength( 0 );
	} );
} );

test.describe( 'Campaign Detail View', () => {
	test( 'detail view renders stats and info', async ( { admin, page } ) => {
		await page.route(
			restRoute( '/mission/v1/campaigns' ),
			async ( route, request ) => {
				if (
					request.method() === 'GET' &&
					/campaigns(%2F|\/)\d+/.test( request.url() )
				) {
					await route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify( MOCK_SINGLE_CAMPAIGN ),
					} );
				} else {
					await route.continue();
				}
			}
		);

		await admin.visitAdminPage( CAMPAIGNS_PATH + '&campaign=1' );

		// Title and status.
		await expect(
			page.getByRole( 'heading', { name: 'Annual Fundraiser' } )
		).toBeVisible();
		await expect( page.getByText( 'Active' ).first() ).toBeVisible();

		// Stats.
		await expect( page.getByText( '$450.00' ) ).toBeVisible();
		await expect( page.getByText( '45%' ).first() ).toBeVisible();
		await expect( page.getByText( '23' ) ).toBeVisible();

		// Campaign Info card.
		await expect(
			page.getByRole( 'heading', { name: 'Campaign Info' } )
		).toBeVisible();

		// Donation Settings card.
		await expect(
			page.getByRole( 'heading', { name: 'Donation Settings' } )
		).toBeVisible();

		// Recent Transactions placeholder.
		await expect( page.getByText( 'Coming soon' ) ).toBeVisible();
	} );

	test( 'Edit Campaign Content links to block editor', async ( {
		admin,
		page,
	} ) => {
		await page.route(
			restRoute( '/mission/v1/campaigns' ),
			async ( route, request ) => {
				if (
					request.method() === 'GET' &&
					/campaigns(%2F|\/)\d+/.test( request.url() )
				) {
					await route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify( MOCK_SINGLE_CAMPAIGN ),
					} );
				} else {
					await route.continue();
				}
			}
		);

		await admin.visitAdminPage( CAMPAIGNS_PATH + '&campaign=1' );

		const editLink = page.getByRole( 'link', {
			name: 'Edit Campaign Content',
		} );
		await expect( editLink ).toBeVisible();

		const href = await editLink.getAttribute( 'href' );
		expect( href ).toContain( 'post.php' );
		expect( href ).toContain( 'action=edit' );
	} );
} );
