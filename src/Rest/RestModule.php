<?php
/**
 * REST module - handles custom REST API endpoints.
 *
 * @package Mission
 */

namespace Mission\Rest;

use Mission\Reporting\ReportingService;
use Mission\Rest\Endpoints\ActivityFeedEndpoint;
use Mission\Rest\Endpoints\CampaignsEndpoint;
use Mission\Rest\Endpoints\DashboardEndpoint;
use Mission\Rest\Endpoints\ConfirmDonationEndpoint;
use Mission\Rest\Endpoints\ConfirmSubscriptionEndpoint;
use Mission\Rest\Endpoints\CreatePaymentIntentEndpoint;
use Mission\Rest\Endpoints\CreateSubscriptionEndpoint;
use Mission\Rest\Endpoints\PaymentConfigEndpoint;
use Mission\Rest\Endpoints\ReviewBannerEndpoint;
use Mission\Rest\Endpoints\SettingsEndpoint;
use Mission\Rest\Endpoints\StripeConnectEndpoint;
use Mission\Rest\Endpoints\DonationFormSettingsEndpoint;
use Mission\Rest\Endpoints\NotesEndpoint;
use Mission\Export\ExportService;
use Mission\Rest\Endpoints\DonorsEndpoint;
use Mission\Rest\Endpoints\ExportEndpoint;
use Mission\Rest\Endpoints\DonorWallEndpoint;
use Mission\Rest\Endpoints\EmailTemplateEndpoint;
use Mission\Rest\Endpoints\EmailTestEndpoint;
use Mission\Rest\Endpoints\TransactionHistoryEndpoint;
use Mission\Rest\Endpoints\TributeEndpoint;

use Mission\Rest\Endpoints\DonorAuthEndpoint;
use Mission\Rest\Endpoints\DonorDashboard\OverviewEndpoint as DashboardOverviewEndpoint;
use Mission\Rest\Endpoints\DonorDashboard\TransactionsEndpoint as DashboardTransactionsEndpoint;
use Mission\Rest\Endpoints\DonorDashboard\SubscriptionsEndpoint as DashboardSubscriptionsEndpoint;
use Mission\Rest\Endpoints\DonorDashboard\EmailChangeEndpoint as DashboardEmailChangeEndpoint;
use Mission\Rest\Endpoints\DonorDashboard\ProfileEndpoint as DashboardProfileEndpoint;
use Mission\Rest\Endpoints\StripeWebhookEndpoint;
use Mission\Rest\Endpoints\SubscriptionsEndpoint;
use Mission\Rest\Endpoints\SystemStatusEndpoint;
use Mission\Rest\Endpoints\TransactionsEndpoint;
use Mission\Rest\Endpoints\CleanupEndpoint;
use Mission\Cleanup\CleanupService;
use Mission\DonorDashboard\DonorAuthService;
use Mission\Payments\PaymentIntentVerifier;
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
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$settings  = new SettingsService();
		$reporting = new ReportingService( $settings );
		$verifier  = new PaymentIntentVerifier( $settings );

		( new CampaignsEndpoint( $reporting, $settings ) )->register();
		( new SettingsEndpoint( $settings ) )->register();
		( new StripeConnectEndpoint( $settings ) )->register();
		( new CreatePaymentIntentEndpoint( $settings ) )->register();
		( new ConfirmDonationEndpoint( $verifier ) )->register();
		( new CreateSubscriptionEndpoint( $settings ) )->register();
		( new ConfirmSubscriptionEndpoint( $verifier ) )->register();
		( new PaymentConfigEndpoint( $settings ) )->register();
		( new DonorsEndpoint( $reporting, $settings ) )->register();
		( new NotesEndpoint() )->register();
		( new TransactionsEndpoint( $reporting, $settings ) )->register();
		( new SubscriptionsEndpoint( $reporting, $settings ) )->register();
		( new TransactionHistoryEndpoint() )->register();
		( new TributeEndpoint() )->register();
		( new ActivityFeedEndpoint( $settings ) )->register();
		( new DashboardEndpoint( $reporting, $settings ) )->register();
		( new ReviewBannerEndpoint() )->register();
		( new DonationFormSettingsEndpoint() )->register();
		( new DonorWallEndpoint( $reporting, $settings ) )->register();
		( new StripeWebhookEndpoint( $settings ) )->register();
		( new DonorAuthEndpoint( new DonorAuthService() ) )->register();
		( new DashboardOverviewEndpoint( $reporting, $settings ) )->register();
		( new DashboardTransactionsEndpoint( $reporting, $settings ) )->register();
		( new DashboardSubscriptionsEndpoint( $settings ) )->register();
		( new DashboardProfileEndpoint() )->register();
		( new DashboardEmailChangeEndpoint() )->register();
		( new EmailTestEndpoint( $settings ) )->register();
		( new EmailTemplateEndpoint( $settings ) )->register();
		( new ExportEndpoint( new ExportService( $settings ) ) )->register();
		( new SystemStatusEndpoint( $settings ) )->register();
		( new CleanupEndpoint( new CleanupService( $settings ) ) )->register();
	}
}
