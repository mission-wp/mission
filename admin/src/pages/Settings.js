import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalHeading as Heading,
	__experimentalVStack as VStack,
	__experimentalText as Text,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const BRAND_COLOR = '#2FA36B';

const CURRENCIES = [
	{ label: 'USD — US Dollar', value: 'USD' },
	{ label: 'EUR — Euro', value: 'EUR' },
	{ label: 'GBP — British Pound', value: 'GBP' },
	{ label: 'CAD — Canadian Dollar', value: 'CAD' },
	{ label: 'AUD — Australian Dollar', value: 'AUD' },
	{ label: 'NZD — New Zealand Dollar', value: 'NZD' },
	{ label: 'CHF — Swiss Franc', value: 'CHF' },
	{ label: 'JPY — Japanese Yen', value: 'JPY' },
	{ label: 'SEK — Swedish Krona', value: 'SEK' },
	{ label: 'NOK — Norwegian Krone', value: 'NOK' },
	{ label: 'DKK — Danish Krone', value: 'DKK' },
];

const STATUS_LABELS = {
	connected: __( 'Connected', 'mission' ),
	disconnected: __( 'Not connected', 'mission' ),
	error: __( 'Connection error', 'mission' ),
};

const STATUS_COLORS = {
	connected: '#2FA36B',
	disconnected: '#757575',
	error: '#cc1818',
};

export default function Settings() {
	const [ settings, setSettings ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ secretKeyEditing, setSecretKeyEditing ] = useState( false );
	const [ secretKeyOriginal, setSecretKeyOriginal ] = useState( '' );

	const fetchSettings = useCallback( () => {
		apiFetch( { path: '/mission/v1/settings' } ).then( ( data ) => {
			setSettings( data );
			setSecretKeyOriginal( data.stripe_secret_key );
		} );
	}, [] );

	useEffect( () => {
		fetchSettings();
	}, [ fetchSettings ] );

	const updateField = ( key, value ) => {
		setSettings( ( prev ) => ( { ...prev, [ key ]: value } ) );
	};

	const handleSave = async () => {
		setSaving( true );
		setNotice( null );

		try {
			const data = await apiFetch( {
				path: '/mission/v1/settings',
				method: 'POST',
				data: settings,
			} );
			setSettings( data );
			setSecretKeyOriginal( data.stripe_secret_key );
			setSecretKeyEditing( false );
			setNotice( {
				status: 'success',
				message: __( 'Settings saved.', 'mission' ),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error.message ||
					__( 'Failed to save settings.', 'mission' ),
			} );
		}

		setSaving( false );
	};

	if ( ! settings ) {
		return (
			<div className="mission-admin-page">
				<Text>{ __( 'Loading…', 'mission' ) }</Text>
			</div>
		);
	}

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
							{ __( 'Settings', 'mission' ) }
						</Heading>
						<Text variant="muted">
							{ __(
								'Configure your donation plugin.',
								'mission'
							) }
						</Text>
					</VStack>
					<Button
						variant="primary"
						onClick={ handleSave }
						isBusy={ saving }
						disabled={ saving }
						style={ {
							backgroundColor: BRAND_COLOR,
							borderColor: BRAND_COLOR,
						} }
					>
						{ saving
							? __( 'Saving…', 'mission' )
							: __( 'Save Settings', 'mission' ) }
					</Button>
				</div>

				{ notice && (
					<Notice
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
					</Notice>
				) }

				{ /* General */ }
				<Card>
					<CardHeader>
						<Heading level={ 2 }>
							{ __( 'General', 'mission' ) }
						</Heading>
					</CardHeader>
					<CardBody>
						<SelectControl
							label={ __( 'Currency', 'mission' ) }
							value={ settings.currency }
							options={ CURRENCIES }
							onChange={ ( value ) =>
								updateField( 'currency', value )
							}
						/>
					</CardBody>
				</Card>

				{ /* Stripe */ }
				<Card>
					<CardHeader>
						<Heading level={ 2 }>
							{ __( 'Stripe', 'mission' ) }
						</Heading>
					</CardHeader>
					<CardBody>
						<VStack spacing={ 4 }>
							<TextControl
								label={ __( 'Publishable Key', 'mission' ) }
								value={ settings.stripe_publishable_key }
								onChange={ ( value ) =>
									updateField(
										'stripe_publishable_key',
										value
									)
								}
								placeholder="pk_live_..."
							/>
							<TextControl
								label={ __( 'Secret Key', 'mission' ) }
								value={
									secretKeyEditing
										? settings.stripe_secret_key
										: secretKeyOriginal
								}
								onChange={ ( value ) => {
									setSecretKeyEditing( true );
									updateField( 'stripe_secret_key', value );
								} }
								onFocus={ () => {
									if ( ! secretKeyEditing ) {
										setSecretKeyEditing( true );
										updateField( 'stripe_secret_key', '' );
									}
								} }
								onBlur={ () => {
									if ( settings.stripe_secret_key === '' ) {
										setSecretKeyEditing( false );
										updateField(
											'stripe_secret_key',
											secretKeyOriginal
										);
									}
								} }
								placeholder="sk_live_..."
							/>
							<div
								style={ {
									display: 'flex',
									alignItems: 'center',
									gap: '8px',
								} }
							>
								<span
									style={ {
										width: '10px',
										height: '10px',
										borderRadius: '50%',
										backgroundColor:
											STATUS_COLORS[
												settings
													.stripe_connection_status
											] || STATUS_COLORS.disconnected,
										display: 'inline-block',
									} }
								/>
								<Text>
									{ STATUS_LABELS[
										settings.stripe_connection_status
									] || STATUS_LABELS.disconnected }
								</Text>
							</div>
						</VStack>
					</CardBody>
				</Card>

				{ /* Tips */ }
				<Card>
					<CardHeader>
						<Heading level={ 2 }>
							{ __( 'Tips', 'mission' ) }
						</Heading>
					</CardHeader>
					<CardBody>
						<VStack spacing={ 4 }>
							<ToggleControl
								label={ __( 'Enable tips', 'mission' ) }
								help={ __(
									'Allow donors to leave an optional tip to support the plugin.',
									'mission'
								) }
								checked={ settings.tip_enabled }
								onChange={ ( value ) =>
									updateField( 'tip_enabled', value )
								}
							/>
							{ settings.tip_enabled && (
								<NumberControl
									label={ __(
										'Default tip percentage',
										'mission'
									) }
									value={ settings.tip_default_percentage }
									min={ 0 }
									max={ 100 }
									onChange={ ( value ) =>
										updateField(
											'tip_default_percentage',
											Number( value )
										)
									}
								/>
							) }
						</VStack>
					</CardBody>
				</Card>

				{ /* Email */ }
				<Card>
					<CardHeader>
						<Heading level={ 2 }>
							{ __( 'Email', 'mission' ) }
						</Heading>
					</CardHeader>
					<CardBody>
						<VStack spacing={ 4 }>
							<TextControl
								label={ __( 'From Name', 'mission' ) }
								value={ settings.email_from_name }
								onChange={ ( value ) =>
									updateField( 'email_from_name', value )
								}
							/>
							<TextControl
								label={ __( 'From Address', 'mission' ) }
								type="email"
								value={ settings.email_from_address }
								onChange={ ( value ) =>
									updateField( 'email_from_address', value )
								}
							/>
						</VStack>
					</CardBody>
				</Card>
			</VStack>
		</div>
	);
}
