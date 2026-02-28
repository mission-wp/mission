import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Modal,
	Notice,
	SelectControl,
	TextControl,
	__experimentalHeading as Heading,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const BRAND_COLOR = '#2FA36B';

const StripeIcon = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 16 16"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={ { marginRight: '4px', flexShrink: 0 } }
	>
		<path
			d="M7.36 5.87c0-.67.55-.93 1.46-.93.97 0 2.2.3 3.17.82V2.82A8.6 8.6 0 0 0 8.82 2.3C6.34 2.3 4.63 3.6 4.63 5.7c0 3.24 4.46 2.73 4.46 4.12 0 .8-.69 1.05-1.66 1.05-1.43 0-2.76-.59-3.83-1.39v3a9.2 9.2 0 0 0 3.83.84c2.56 0 4.32-1.22 4.32-3.37C11.76 6.78 7.36 7.38 7.36 5.87Z"
			fill="currentColor"
		/>
	</svg>
);

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

const STATUS_COLORS = {
	connected: '#2FA36B',
	error: '#cc1818',
};

export default function Settings() {
	const [ settings, setSettings ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ connecting, setConnecting ] = useState( false );
	const [ showDisconnectModal, setShowDisconnectModal ] = useState( false );

	const fetchSettings = useCallback( () => {
		apiFetch( { path: '/mission/v1/settings' } ).then( ( data ) => {
			setSettings( data );
		} );
	}, [] );

	useEffect( () => {
		fetchSettings();
	}, [ fetchSettings ] );

	// Handle OAuth return — exchange setup_code for site_token.
	useEffect( () => {
		const params = new URLSearchParams( window.location.search );
		const setupCode = params.get( 'setup_code' );
		const siteId = params.get( 'site_id' );

		if ( ! setupCode || ! siteId ) {
			return;
		}

		// Clean the URL immediately so a refresh doesn't re-trigger.
		const cleanUrl = new URL( window.location.href );
		cleanUrl.searchParams.delete( 'setup_code' );
		cleanUrl.searchParams.delete( 'site_id' );
		window.history.replaceState( {}, '', cleanUrl.toString() );

		setConnecting( true );

		apiFetch( {
			path: '/mission/v1/stripe/connect',
			method: 'POST',
			data: { setup_code: setupCode, site_id: siteId },
		} )
			.then( ( data ) => {
				setSettings( data );
				setNotice( {
					status: 'success',
					message: __( 'Stripe connected successfully!', 'mission' ),
				} );
			} )
			.catch( ( error ) => {
				setNotice( {
					status: 'error',
					message:
						error.message ||
						__( 'Failed to connect Stripe.', 'mission' ),
				} );
			} )
			.finally( () => {
				setConnecting( false );
			} );
	}, [] );

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

	const handleDisconnect = async () => {
		setSaving( true );
		setNotice( null );

		try {
			const data = await apiFetch( {
				path: '/mission/v1/stripe/disconnect',
				method: 'POST',
			} );
			setSettings( data );
			setNotice( {
				status: 'success',
				message: __( 'Stripe disconnected.', 'mission' ),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error.message ||
					__( 'Failed to disconnect Stripe.', 'mission' ),
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

	const isConnected = settings.stripe_connection_status === 'connected';
	const stripeConnectUrl = window.missionAdmin?.stripeConnectUrl;

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

				{ connecting && (
					<Notice status="info" isDismissible={ false }>
						{ __( 'Connecting to Stripe…', 'mission' ) }
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
							<Text>
								{ __(
									'Mission uses Stripe to securely process donations. Stripe handles all payment processing — you never touch sensitive card data.',
									'mission'
								) }
							</Text>

							{ isConnected ? (
								<VStack spacing={ 3 }>
									<HStack
										spacing={ 3 }
										justify="flex-start"
										alignment="center"
									>
										<span
											style={ {
												display: 'inline-flex',
												alignItems: 'center',
												gap: '6px',
												backgroundColor: '#ecfdf5',
												color: '#065f46',
												padding: '4px 12px',
												borderRadius: '9999px',
												fontSize: '13px',
												fontWeight: 500,
											} }
										>
											<span
												style={ {
													width: '8px',
													height: '8px',
													borderRadius: '50%',
													backgroundColor:
														STATUS_COLORS.connected,
													display: 'inline-block',
												} }
											/>
											{ __( 'Connected', 'mission' ) }
											{ settings.stripe_display_name
												? ` — ${ settings.stripe_display_name }`
												: '' }
										</span>
									</HStack>
									{ settings.stripe_account_id && (
										<Text variant="muted" size="small">
											{ settings.stripe_account_id }
										</Text>
									) }
									<HStack justify="flex-start">
										<Button
											variant="tertiary"
											isDestructive
											onClick={ () =>
												setShowDisconnectModal( true )
											}
											disabled={ saving }
										>
											{ __( 'Disconnect', 'mission' ) }
										</Button>
									</HStack>
									{ showDisconnectModal && (
										<Modal
											title={ __(
												'Disconnect Stripe',
												'mission'
											) }
											onRequestClose={ () =>
												setShowDisconnectModal( false )
											}
											size="small"
										>
											<VStack spacing={ 4 }>
												<Text>
													{ __(
														'Are you sure you want to disconnect your Stripe account? You will not be able to process donations until you reconnect.',
														'mission'
													) }
												</Text>
												<HStack justify="flex-end">
													<Button
														variant="tertiary"
														onClick={ () =>
															setShowDisconnectModal(
																false
															)
														}
														__next40pxDefaultSize
													>
														{ __(
															'Cancel',
															'mission'
														) }
													</Button>
													<Button
														variant="primary"
														isDestructive
														onClick={ () => {
															setShowDisconnectModal(
																false
															);
															handleDisconnect();
														} }
														__next40pxDefaultSize
													>
														{ __(
															'Disconnect',
															'mission'
														) }
													</Button>
												</HStack>
											</VStack>
										</Modal>
									) }
								</VStack>
							) : (
								<VStack spacing={ 3 }>
									{ settings.stripe_connection_status ===
										'error' && (
										<HStack
											spacing={ 2 }
											justify="flex-start"
											alignment="center"
										>
											<span
												style={ {
													width: '10px',
													height: '10px',
													borderRadius: '50%',
													backgroundColor:
														STATUS_COLORS.error,
													display: 'inline-block',
												} }
											/>
											<Text>
												{ __(
													'Connection error',
													'mission'
												) }
											</Text>
										</HStack>
									) }

									{ stripeConnectUrl && (
										<HStack justify="flex-start">
											<Button
												variant="primary"
												href={ stripeConnectUrl }
												style={ {
													backgroundColor:
														BRAND_COLOR,
													borderColor: BRAND_COLOR,
												} }
											>
												<StripeIcon />
												{ __(
													'Connect to Stripe',
													'mission'
												) }
											</Button>
										</HStack>
									) }
								</VStack>
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
