import {
	__experimentalHeading as Heading,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const BRAND_COLOR = '#2FA36B';

export default function Campaigns() {
	return (
		<div className="mission-admin-page">
			<VStack spacing={ 6 }>
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

				<div>
					<a
						href={
							window.missionAdmin?.adminUrl
								? `${ window.missionAdmin.adminUrl }post-new.php?post_type=mission_campaign`
								: '#'
						}
						className="components-button is-primary"
						style={ {
							backgroundColor: BRAND_COLOR,
							borderColor: BRAND_COLOR,
							textDecoration: 'none',
						} }
					>
						{ __( 'Add Campaign', 'mission' ) }
					</a>
				</div>
			</VStack>
		</div>
	);
}
