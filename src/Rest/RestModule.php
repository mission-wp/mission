<?php
/**
 * REST module - handles custom REST API endpoints.
 *
 * @package Mission
 */

namespace Mission\Rest;

use Mission\Database\DataStore\CampaignDataStore;
use Mission\Database\DataStore\DonorDataStore;
use Mission\Database\DataStore\TransactionDataStore;
use Mission\Rest\Endpoints\CampaignsEndpoint;
use Mission\Rest\Endpoints\ConfirmDonationEndpoint;
use Mission\Rest\Endpoints\CreatePaymentIntentEndpoint;
use Mission\Rest\Endpoints\PaymentConfigEndpoint;
use Mission\Rest\Endpoints\SettingsEndpoint;
use Mission\Rest\Endpoints\StripeConnectEndpoint;
use Mission\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * REST module class.
 */
class RestModule {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'mission/v1';

	/**
	 * Initialize the REST module.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$settings_service = new SettingsService();

		( new CampaignsEndpoint( new CampaignDataStore() ) )->register();
		( new SettingsEndpoint( $settings_service ) )->register();
		( new StripeConnectEndpoint( $settings_service ) )->register();
		( new CreatePaymentIntentEndpoint( $settings_service ) )->register();
		( new ConfirmDonationEndpoint( new DonorDataStore(), new TransactionDataStore() ) )->register();
		( new PaymentConfigEndpoint( $settings_service ) )->register();
	}
}
