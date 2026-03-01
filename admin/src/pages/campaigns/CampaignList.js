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
import CampaignCreateModal from './CampaignCreateModal';

const BRAND_COLOR = '#2FA36B';

/**
 * Format cents as a dollar amount.
 *
 * @param {number} cents Amount in cents.
 * @return {string} Formatted string like "$12.50".
 */
export function formatAmount( cents ) {
	return (
		'$' +
		( cents / 100 ).toLocaleString( undefined, {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		} )
	);
}

const STATUS_STYLES = {
	active: {
		backgroundColor: '#eafaf0',
		color: '#1a7338',
		label: __( 'Active', 'mission' ),
	},
	scheduled: {
		backgroundColor: '#e8f0fe',
		color: '#1a56db',
		label: __( 'Scheduled', 'mission' ),
	},
	ended: {
		backgroundColor: '#f0f0f0',
		color: '#757575',
		label: __( 'Ended', 'mission' ),
	},
};

/**
 * Status badge component.
 *
 * @param {Object} props
 * @param {string} props.status Campaign status.
 * @return {JSX.Element} Badge element.
 */
export function StatusBadge( { status } ) {
	const style = STATUS_STYLES[ status ] || STATUS_STYLES.active;
	return (
		<span
			style={ {
				display: 'inline-block',
				padding: '2px 8px',
				borderRadius: '2px',
				fontSize: '12px',
				fontWeight: 500,
				backgroundColor: style.backgroundColor,
				color: style.color,
			} }
		>
			{ style.label }
		</span>
	);
}

const adminUrl = window.missionAdmin?.adminUrl || '';

function campaignDetailUrl( id ) {
	return `${ adminUrl }admin.php?page=mission-campaigns&campaign=${ id }`;
}

const fields = [
	{
		id: 'title',
		label: __( 'Campaign', 'mission' ),
		enableGlobalSearch: true,
		enableSorting: true,
		enableHiding: false,
		render: ( { item } ) => (
			<a
				href={ campaignDetailUrl( item.id ) }
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
			{ value: 'active', label: __( 'Active', 'mission' ) },
			{ value: 'scheduled', label: __( 'Scheduled', 'mission' ) },
			{ value: 'ended', label: __( 'Ended', 'mission' ) },
		],
		filterBy: {
			operators: [ 'is' ],
		},
	},
	{
		id: 'goal_amount',
		label: __( 'Goal', 'mission' ),
		enableSorting: true,
		render: ( { item } ) => (
			<Text style={ { textAlign: 'right', display: 'block' } }>
				{ item.goal_amount ? formatAmount( item.goal_amount ) : '—' }
			</Text>
		),
	},
	{
		id: 'total_raised',
		label: __( 'Raised', 'mission' ),
		enableSorting: true,
		render: ( { item } ) => (
			<Text style={ { textAlign: 'right', display: 'block' } }>
				{ formatAmount( item.total_raised ) }
			</Text>
		),
	},
	{
		id: 'transaction_count',
		label: __( 'Transactions', 'mission' ),
		enableSorting: true,
		render: ( { item } ) => (
			<Text style={ { textAlign: 'right', display: 'block' } }>
				{ item.transaction_count.toLocaleString() }
			</Text>
		),
	},
	{
		id: 'date_start',
		label: __( 'Starts', 'mission' ),
		enableSorting: true,
		render: ( { item } ) => (
			<Text variant="muted" size="small">
				{ item.date_start
					? new Date( item.date_start ).toLocaleDateString()
					: '—' }
			</Text>
		),
	},
	{
		id: 'date_end',
		label: __( 'Ends', 'mission' ),
		enableSorting: true,
		render: ( { item } ) => (
			<Text variant="muted" size="small">
				{ item.date_end
					? new Date( item.date_end ).toLocaleDateString()
					: '\u221E' }
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
		'date_start',
		'date_end',
	],
	search: '',
	filters: [],
	sort: {},
	page: 1,
	perPage: 25,
	layout: {
		styles: {
			goal_amount: { width: '120px' },
			total_raised: { width: '120px' },
			transaction_count: { width: '120px' },
			date_start: { width: '120px' },
			date_end: { width: '120px' },
			status: { width: '100px' },
		},
	},
};

export default function CampaignList() {
	const [ data, setData ] = useState( [] );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ showCreateModal, setShowCreateModal ] = useState( false );

	const fetchCampaigns = useCallback( async () => {
		setIsLoading( true );

		const params = new URLSearchParams( {
			page: String( view.page ),
			per_page: String( view.perPage ),
			order: view.sort?.direction?.toUpperCase() || 'DESC',
			orderby: view.sort?.field || 'date',
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
				window.location.href = campaignDetailUrl( items[ 0 ].id );
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

	const onCreated = ( id ) => {
		window.location.href = campaignDetailUrl( id );
	};

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
								<Button
									variant="primary"
									style={ {
										backgroundColor: BRAND_COLOR,
										borderColor: BRAND_COLOR,
									} }
									onClick={ () => setShowCreateModal( true ) }
									__next40pxDefaultSize
								>
									{ __( 'Create a Campaign', 'mission' ) }
								</Button>
							</VStack>
						</CardBody>
					</Card>
				</VStack>

				{ showCreateModal && (
					<CampaignCreateModal
						onClose={ () => setShowCreateModal( false ) }
						onCreated={ onCreated }
					/>
				) }
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
					<Button
						variant="primary"
						style={ {
							backgroundColor: BRAND_COLOR,
							borderColor: BRAND_COLOR,
						} }
						onClick={ () => setShowCreateModal( true ) }
						__next40pxDefaultSize
					>
						{ __( 'Add Campaign', 'mission' ) }
					</Button>
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

			{ showCreateModal && (
				<CampaignCreateModal
					onClose={ () => setShowCreateModal( false ) }
					onCreated={ onCreated }
				/>
			) }
		</div>
	);
}
