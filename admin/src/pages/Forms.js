import {
	Button,
	__experimentalHeading as Heading,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

export default function Forms() {
	const addFormUrl = window.missionAdmin?.adminUrl
		? `${ window.missionAdmin.adminUrl }post-new.php?post_type=mission_form`
		: '#';

	return (
		<div className="mission-admin-page">
			<VStack spacing={ 6 }>
				<div
					style={ {
						display: 'flex',
						justifyContent: 'space-between',
						alignItems: 'center',
					} }
				>
					<VStack spacing={ 1 }>
						<Heading level={ 1 }>
							{ __( 'Donation Forms', 'mission' ) }
						</Heading>
						<Text variant="muted">
							{ __(
								'Create and manage your donation forms.',
								'mission'
							) }
						</Text>
					</VStack>
					<Button
						variant="primary"
						icon={ plus }
						href={ addFormUrl }
						__next40pxDefaultSize
						style={ {
							backgroundColor: '#2FA36B',
							borderColor: '#2FA36B',
						} }
					>
						{ __( 'Add Donation Form', 'mission' ) }
					</Button>
				</div>
			</VStack>
		</div>
	);
}
