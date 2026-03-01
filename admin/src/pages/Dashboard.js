import {
	Card,
	CardBody,
	__experimentalHeading as Heading,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

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

export default function Dashboard() {
	return (
		<div className="mission-admin-page">
			<VStack spacing={ 6 }>
				<VStack spacing={ 1 }>
					<Heading level={ 1 }>
						{ __( 'Dashboard', 'mission' ) }
					</Heading>
					<Text variant="muted">
						{ __(
							"Welcome to Mission. Here's an overview of your fundraising.",
							'mission'
						) }
					</Text>
				</VStack>

				<div
					style={ {
						display: 'grid',
						gridTemplateColumns:
							'repeat(auto-fit, minmax(200px, 1fr))',
						gap: '16px',
					} }
				>
					<StatCard
						label={ __( 'Total Transactions', 'mission' ) }
						value="0"
					/>
					<StatCard
						label={ __( 'Total Revenue', 'mission' ) }
						value="$0.00"
					/>
					<StatCard label={ __( 'Donors', 'mission' ) } value="0" />
					<StatCard
						label={ __( 'Campaigns', 'mission' ) }
						value="0"
					/>
				</div>

				<Card>
					<CardBody>
						<VStack spacing={ 3 }>
							<Heading level={ 2 }>
								{ __( 'Getting Started', 'mission' ) }
							</Heading>
							<Text>
								{ __(
									'Create your first campaign to start accepting donations. Mission makes it easy to set up campaigns, track donors, and manage your fundraising — all for free.',
									'mission'
								) }
							</Text>
							<div>
								<a
									href={
										window.missionAdmin?.adminUrl
											? `${ window.missionAdmin.adminUrl }admin.php?page=mission-campaigns`
											: '#'
									}
									className="components-button is-primary"
									style={ {
										backgroundColor: BRAND_COLOR,
										borderColor: BRAND_COLOR,
										textDecoration: 'none',
									} }
								>
									{ __( 'Create a Campaign', 'mission' ) }
								</a>
							</div>
						</VStack>
					</CardBody>
				</Card>
			</VStack>
		</div>
	);
}
