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
		status: 'publish',
		goal_amount: 100000,
		total_raised: 45000,
		transaction_count: 23,
		edit_url: '/wp-admin/post.php?post=1&action=edit',
		date_created: '2026-01-15 12:00:00',
	},
	{
		id: 2,
		title: 'Emergency Relief',
		status: 'draft',
		goal_amount: 50000,
		total_raised: 0,
		transaction_count: 0,
		edit_url: '/wp-admin/post.php?post=2&action=edit',
		date_created: '2026-02-01 08:00:00',
	},
];

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

		const createButton = page.getByRole( 'link', {
			name: 'Create a Campaign',
		} );
		await expect( createButton ).toBeVisible();

		const href = await createButton.getAttribute( 'href' );
		expect( href ).toContain( 'post-new.php?post_type=mission_campaign' );
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
		await expect( page.getByText( 'Draft' ).first() ).toBeVisible();
	} );

	test( 'Add Campaign button links correctly', async ( { admin, page } ) => {
		await mockCampaignsApi( page, MOCK_CAMPAIGNS );
		await admin.visitAdminPage( CAMPAIGNS_PATH );

		const addButton = page.getByRole( 'link', {
			name: 'Add Campaign',
		} );
		await expect( addButton ).toBeVisible();

		const href = await addButton.getAttribute( 'href' );
		expect( href ).toContain( 'post-new.php?post_type=mission_campaign' );
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
