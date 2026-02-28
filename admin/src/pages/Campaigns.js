import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	__experimentalHeading as Heading,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import { pencil, trash } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const BRAND_COLOR = '#2FA36B';

/**
 * Format cents as a dollar amount.
 *
 * @param {number} cents Amount in cents.
 * @return {string} Formatted string like "$12.50".
 */
function formatAmount( cents ) {
	return (
		'$' +
		( cents / 100 ).toLocaleString( undefined, {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		} )
	);
}

/**
 * Status badge component.
 *
 * @param {Object} props
 * @param {string} props.status Post status.
 * @return {JSX.Element} Badge element.
 */
function StatusBadge( { status } ) {
	const isActive = status === 'publish';
	return (
		<span
			style={ {
				display: 'inline-block',
				padding: '2px 8px',
				borderRadius: '2px',
				fontSize: '12px',
				fontWeight: 500,
				backgroundColor: isActive ? '#eafaf0' : '#f0f0f0',
				color: isActive ? '#1a7338' : '#757575',
			} }
		>
			{ isActive ? __( 'Active', 'mission' ) : __( 'Draft', 'mission' ) }
		</span>
	);
}

const fields = [
	{
		id: 'title',
		label: __( 'Campaign', 'mission' ),
		enableGlobalSearch: true,
		enableSorting: false,
		enableHiding: false,
		render: ( { item } ) => (
			<a
				href={ item.edit_url }
				style={ {
					color: BRAND_COLOR,
					textDecoration: 'none',
					fontWeight: 500,
				} }
			>
				{ item.title }
			</a>
		),
	},
	{
		id: 'status',
		label: __( 'Status', 'mission' ),
		enableSorting: false,
		render: ( { item } ) => <StatusBadge status={ item.status } />,
		elements: [
			{ value: 'publish', label: __( 'Active', 'mission' ) },
			{ value: 'draft', label: __( 'Draft', 'mission' ) },
		],
		filterBy: {
			operators: [ 'is' ],
		},
	},
	{
		id: 'goal_amount',
		label: __( 'Goal', 'mission' ),
		enableSorting: false,
		render: ( { item } ) => (
			<Text style={ { textAlign: 'right', display: 'block' } }>
				{ item.goal_amount ? formatAmount( item.goal_amount ) : '—' }
			</Text>
		),
	},
	{
		id: 'total_raised',
		label: __( 'Raised', 'mission' ),
		enableSorting: false,
		render: ( { item } ) => (
			<Text style={ { textAlign: 'right', display: 'block' } }>
				{ formatAmount( item.total_raised ) }
			</Text>
		),
	},
	{
		id: 'transaction_count',
		label: __( 'Transactions', 'mission' ),
		enableSorting: false,
		render: ( { item } ) => (
			<Text style={ { textAlign: 'right', display: 'block' } }>
				{ item.transaction_count.toLocaleString() }
			</Text>
		),
	},
	{
		id: 'date_created',
		label: __( 'Date', 'mission' ),
		enableSorting: true,
		render: ( { item } ) => (
			<Text variant="muted" size="small">
				{ new Date( item.date_created ).toLocaleDateString() }
			</Text>
		),
	},
];

const DEFAULT_VIEW = {
	type: 'table',
	titleField: 'title',
	fields: [
		'status',
		'goal_amount',
		'total_raised',
		'transaction_count',
		'date_created',
	],
	search: '',
	filters: [],
	sort: { field: 'date_created', direction: 'desc' },
	page: 1,
	perPage: 25,
	layout: {
		styles: {
			goal_amount: { width: '120px' },
			total_raised: { width: '120px' },
			transaction_count: { width: '120px' },
			date_created: { width: '140px' },
			status: { width: '100px' },
		},
	},
};

export default function Campaigns() {
	const [ data, setData ] = useState( [] );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( true );

	const fetchCampaigns = useCallback( async () => {
		setIsLoading( true );

		const params = new URLSearchParams( {
			page: String( view.page ),
			per_page: String( view.perPage ),
			order: view.sort?.direction?.toUpperCase() || 'DESC',
			orderby:
				view.sort?.field === 'date_created'
					? 'date'
					: view.sort?.field || 'date',
		} );

		if ( view.search ) {
			params.set( 'search', view.search );
		}

		const statusFilter = view.filters?.find(
			( f ) => f.field === 'status'
		);
		if ( statusFilter?.value ) {
			params.set( 'status', statusFilter.value );
		}

		try {
			const response = await apiFetch( {
				path: `/mission/v1/campaigns?${ params.toString() }`,
				parse: false,
			} );

			setTotalItems(
				parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 )
			);
			setTotalPages(
				parseInt( response.headers.get( 'X-WP-TotalPages' ) || '0', 10 )
			);

			const items = await response.json();
			setData( items );
		} catch {
			setData( [] );
			setTotalItems( 0 );
			setTotalPages( 0 );
		} finally {
			setIsLoading( false );
		}
	}, [ view.page, view.perPage, view.sort, view.search, view.filters ] );

	useEffect( () => {
		fetchCampaigns();
	}, [ fetchCampaigns ] );

	const actions = [
		{
			id: 'edit',
			label: __( 'Edit', 'mission' ),
			icon: pencil,
			isPrimary: true,
			callback: ( items ) => {
				window.location.href = items[ 0 ].edit_url;
			},
		},
		{
			id: 'delete',
			label: __( 'Delete', 'mission' ),
			icon: trash,
			isDestructive: true,
			supportsBulk: true,
			RenderModal: ( { items, closeModal } ) => {
				const isBulk = items.length > 1;
				return (
					<VStack spacing={ 4 }>
						<Text>
							{ isBulk
								? __(
										'Are you sure you want to delete these campaigns? This action cannot be undone.',
										'mission'
								  )
								: __(
										'Are you sure you want to delete this campaign? This action cannot be undone.',
										'mission'
								  ) }
						</Text>
						<Text variant="muted">
							{ items.map( ( item ) => item.title ).join( ', ' ) }
						</Text>
						<HStack justify="flex-end">
							<Button
								variant="tertiary"
								onClick={ closeModal }
								__next40pxDefaultSize
							>
								{ __( 'Cancel', 'mission' ) }
							</Button>
							<Button
								variant="primary"
								isDestructive
								__next40pxDefaultSize
								onClick={ async () => {
									if ( isBulk ) {
										await apiFetch( {
											path: '/mission/v1/campaigns/batch-delete',
											method: 'POST',
											data: {
												ids: items.map(
													( item ) => item.id
												),
											},
										} );
									} else {
										await apiFetch( {
											path: `/mission/v1/campaigns/${ items[ 0 ].id }`,
											method: 'DELETE',
										} );
									}
									closeModal();
									fetchCampaigns();
								} }
							>
								{ __( 'Delete', 'mission' ) }
							</Button>
						</HStack>
					</VStack>
				);
			},
		},
	];

	const hasNoSearchOrFilters =
		! view.search && ( ! view.filters || view.filters.length === 0 );
	const showEmptyState =
		! isLoading && data.length === 0 && hasNoSearchOrFilters;

	const adminUrl = window.missionAdmin?.adminUrl || '';
	const newCampaignUrl = `${ adminUrl }post-new.php?post_type=mission_campaign`;

	if ( showEmptyState ) {
		return (
			<div className="mission-admin-page">
				<VStack spacing={ 6 }>
					<HStack justify="space-between" alignment="center">
						<VStack spacing={ 1 }>
							<Heading level={ 1 }>
								{ __( 'Campaigns', 'mission' ) }
							</Heading>
							<Text variant="muted">
								{ __(
									'Create and manage your fundraising campaigns.',
									'mission'
								) }
							</Text>
						</VStack>
					</HStack>

					<Card>
						<CardBody>
							<VStack
								spacing={ 4 }
								alignment="center"
								style={ {
									padding: '48px 24px',
									textAlign: 'center',
								} }
							>
								<Heading level={ 2 }>
									{ __( 'No campaigns yet', 'mission' ) }
								</Heading>
								<Text variant="muted">
									{ __(
										'Create your first campaign to start accepting donations.',
										'mission'
									) }
								</Text>
								<a
									href={ newCampaignUrl }
									className="components-button is-primary"
									style={ {
										backgroundColor: BRAND_COLOR,
										borderColor: BRAND_COLOR,
										textDecoration: 'none',
									} }
								>
									{ __( 'Create a Campaign', 'mission' ) }
								</a>
							</VStack>
						</CardBody>
					</Card>
				</VStack>
			</div>
		);
	}

	return (
		<div className="mission-admin-page">
			<VStack spacing={ 6 }>
				<HStack justify="space-between" alignment="center">
					<VStack spacing={ 1 }>
						<Heading level={ 1 }>
							{ __( 'Campaigns', 'mission' ) }
						</Heading>
						<Text variant="muted">
							{ __(
								'Create and manage your fundraising campaigns.',
								'mission'
							) }
						</Text>
					</VStack>
					<a
						href={ newCampaignUrl }
						className="components-button is-primary"
						style={ {
							backgroundColor: BRAND_COLOR,
							borderColor: BRAND_COLOR,
							textDecoration: 'none',
						} }
					>
						{ __( 'Add Campaign', 'mission' ) }
					</a>
				</HStack>

				<DataViews
					data={ data }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ {
						totalItems,
						totalPages,
					} }
					defaultLayouts={ { table: {} } }
					isLoading={ isLoading }
				/>
			</VStack>
		</div>
	);
}
