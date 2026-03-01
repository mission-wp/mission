import { useState, useEffect } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	Spinner,
	__experimentalHeading as Heading,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { formatAmount, StatusBadge } from './CampaignList';
import CampaignProgressBar from './CampaignProgressBar';

const BRAND_COLOR = '#2FA36B';

function StatCard( { label, value } ) {
	return (
		<Card>
			<CardBody>
				<VStack spacing={ 1 }>
					<Text variant="muted" size="small">
						{ label }
					</Text>
					<Text weight="600" size="24">
						{ value }
					</Text>
				</VStack>
			</CardBody>
		</Card>
	);
}

function InfoRow( { label, children } ) {
	return (
		<HStack justify="space-between" alignment="top">
			<Text variant="muted" style={ { minWidth: '140px' } }>
				{ label }
			</Text>
			<Text style={ { textAlign: 'right' } }>{ children }</Text>
		</HStack>
	);
}

function formatDate( dateStr ) {
	if ( ! dateStr || dateStr.startsWith( '0000' ) ) {
		return '—';
	}
	// MySQL datetime ("2026-01-15 12:00:00") needs the T separator for
	// reliable cross-browser parsing.
	const normalized = dateStr.includes( 'T' )
		? dateStr
		: dateStr.replace( ' ', 'T' );
	return new Date( normalized ).toLocaleDateString();
}

function formatFrequency( freq ) {
	const map = {
		monthly: __( 'Monthly', 'mission' ),
		quarterly: __( 'Quarterly', 'mission' ),
		annually: __( 'Annually', 'mission' ),
		one_time: __( 'One-Time', 'mission' ),
	};
	return map[ freq ] || freq;
}

export default function CampaignDetail( { id } ) {
	const [ campaign, setCampaign ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		const fetchCampaign = async () => {
			try {
				const data = await apiFetch( {
					path: `/mission/v1/campaigns/${ id }`,
				} );
				setCampaign( data );
			} catch ( err ) {
				setError(
					err.message || __( 'Failed to load campaign.', 'mission' )
				);
			} finally {
				setIsLoading( false );
			}
		};
		fetchCampaign();
	}, [ id ] );

	const adminUrl = window.missionAdmin?.adminUrl || '';
	const campaignsUrl = `${ adminUrl }admin.php?page=mission-campaigns`;

	if ( isLoading ) {
		return (
			<div className="mission-admin-page">
				<VStack
					spacing={ 6 }
					alignment="center"
					style={ { padding: '48px 0' } }
				>
					<Spinner />
				</VStack>
			</div>
		);
	}

	if ( error || ! campaign ) {
		return (
			<div className="mission-admin-page">
				<VStack spacing={ 4 }>
					<a
						href={ campaignsUrl }
						style={ { color: BRAND_COLOR, textDecoration: 'none' } }
					>
						{ __( 'Back to Campaigns', 'mission' ) }
					</a>
					<Text>
						{ error || __( 'Campaign not found.', 'mission' ) }
					</Text>
				</VStack>
			</div>
		);
	}

	const { meta } = campaign;
	const goalProgress =
		campaign.goal_amount > 0
			? Math.round(
					( campaign.total_raised / campaign.goal_amount ) * 100
			  )
			: 0;

	return (
		<div className="mission-admin-page">
			<VStack spacing={ 6 }>
				{ /* Header */ }
				<VStack spacing={ 3 }>
					<a
						href={ campaignsUrl }
						style={ {
							color: BRAND_COLOR,
							textDecoration: 'none',
							fontSize: '13px',
						} }
					>
						&larr; { __( 'Back to Campaigns', 'mission' ) }
					</a>
					<HStack justify="space-between" alignment="center">
						<HStack spacing={ 3 } alignment="center">
							<Heading level={ 1 }>{ campaign.title }</Heading>
							<StatusBadge status={ campaign.status } />
						</HStack>
						<a
							href={ campaign.edit_url }
							className="components-button is-secondary"
							style={ { textDecoration: 'none' } }
						>
							{ __( 'Edit Campaign Content', 'mission' ) }
						</a>
					</HStack>
				</VStack>

				{ /* Stats row */ }
				<div
					style={ {
						display: 'grid',
						gridTemplateColumns:
							'repeat(auto-fit, minmax(180px, 1fr))',
						gap: '16px',
					} }
				>
					<StatCard
						label={ __( 'Total Raised', 'mission' ) }
						value={ formatAmount( campaign.total_raised ) }
					/>
					<StatCard
						label={ __( 'Goal Progress', 'mission' ) }
						value={
							campaign.goal_amount > 0
								? `${ goalProgress }%`
								: '—'
						}
					/>
					<StatCard
						label={ __( 'Transactions', 'mission' ) }
						value={ campaign.transaction_count.toLocaleString() }
					/>
					<StatCard
						label={ __( 'Status', 'mission' ) }
						value={
							{
								active: __( 'Active', 'mission' ),
								scheduled: __( 'Scheduled', 'mission' ),
								ended: __( 'Ended', 'mission' ),
							}[ campaign.status ] || __( 'Active', 'mission' )
						}
					/>
				</div>

				{ /* Progress bar */ }
				{ campaign.goal_amount > 0 && (
					<Card>
						<CardBody>
							<VStack spacing={ 2 }>
								<HStack justify="space-between">
									<Text weight="600">
										{ formatAmount(
											campaign.total_raised
										) }
									</Text>
									<Text variant="muted">
										{ __( 'of', 'mission' ) }{ ' ' }
										{ formatAmount( campaign.goal_amount ) }
									</Text>
								</HStack>
								<CampaignProgressBar
									raised={ campaign.total_raised }
									goal={ campaign.goal_amount }
								/>
							</VStack>
						</CardBody>
					</Card>
				) }

				{ /* Campaign Info + Donation Settings */ }
				<div
					style={ {
						display: 'grid',
						gridTemplateColumns:
							'repeat(auto-fit, minmax(320px, 1fr))',
						gap: '16px',
					} }
				>
					<Card>
						<CardHeader>
							<Heading level={ 3 }>
								{ __( 'Campaign Info', 'mission' ) }
							</Heading>
						</CardHeader>
						<CardBody>
							<VStack spacing={ 3 }>
								<InfoRow label={ __( 'Starts', 'mission' ) }>
									{ formatDate( campaign.date_start ) }
								</InfoRow>
								<InfoRow label={ __( 'Ends', 'mission' ) }>
									{ campaign.date_end
										? formatDate( campaign.date_end )
										: '\u221E' }
								</InfoRow>
								<InfoRow label={ __( 'Goal', 'mission' ) }>
									{ campaign.goal_amount
										? formatAmount( campaign.goal_amount )
										: __( 'No goal set', 'mission' ) }
								</InfoRow>
								<InfoRow label={ __( 'Currency', 'mission' ) }>
									{ campaign.currency.toUpperCase() }
								</InfoRow>
								<InfoRow label={ __( 'Created', 'mission' ) }>
									{ formatDate( campaign.date_created ) }
								</InfoRow>
							</VStack>
						</CardBody>
					</Card>

					<Card>
						<CardHeader>
							<Heading level={ 3 }>
								{ __( 'Donation Settings', 'mission' ) }
							</Heading>
						</CardHeader>
						<CardBody>
							<VStack spacing={ 3 }>
								<InfoRow
									label={ __(
										'Suggested Amounts',
										'mission'
									) }
								>
									{ meta.amounts?.length
										? meta.amounts
												.map( ( c ) =>
													formatAmount( c )
												)
												.join( ', ' )
										: '—' }
								</InfoRow>
								<InfoRow
									label={ __( 'Custom Amount', 'mission' ) }
								>
									{ meta.custom_amount
										? __( 'Yes', 'mission' )
										: __( 'No', 'mission' ) }
								</InfoRow>
								<InfoRow
									label={ __( 'Minimum Amount', 'mission' ) }
								>
									{ formatAmount( meta.minimum_amount || 0 ) }
								</InfoRow>
								<InfoRow label={ __( 'Recurring', 'mission' ) }>
									{ meta.recurring_enabled
										? meta.recurring_frequencies
												?.map( formatFrequency )
												.join( ', ' ) || '—'
										: __( 'Disabled', 'mission' ) }
								</InfoRow>
								{ meta.recurring_enabled && (
									<InfoRow
										label={ __( 'Default', 'mission' ) }
									>
										{ formatFrequency(
											meta.recurring_default
										) }
									</InfoRow>
								) }
								<InfoRow
									label={ __( 'Fee Recovery', 'mission' ) }
								>
									{ meta.fee_recovery
										? __( 'Yes', 'mission' )
										: __( 'No', 'mission' ) }
								</InfoRow>
							</VStack>
						</CardBody>
					</Card>
				</div>

				{ /* Recent Transactions placeholder */ }
				<Card>
					<CardHeader>
						<Heading level={ 3 }>
							{ __( 'Recent Transactions', 'mission' ) }
						</Heading>
					</CardHeader>
					<CardBody>
						<Text
							variant="muted"
							style={ { textAlign: 'center', padding: '24px 0' } }
						>
							{ __( 'Coming soon', 'mission' ) }
						</Text>
					</CardBody>
				</Card>
			</VStack>
		</div>
	);
}
