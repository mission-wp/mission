<?php
/**
 * REST module - handles custom REST API endpoints.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest;

use MissionDP\Reporting\ReportingService;
use MissionDP\Rest\Endpoints\ActivityFeedEndpoint;
use MissionDP\Rest\Endpoints\CampaignsEndpoint;
use MissionDP\Rest\Endpoints\DashboardEndpoint;
use MissionDP\Rest\Endpoints\ConfirmDonationEndpoint;
use MissionDP\Rest\Endpoints\ConfirmSubscriptionEndpoint;
use MissionDP\Rest\Endpoints\CreatePaymentIntentEndpoint;
use MissionDP\Rest\Endpoints\CreateSubscriptionEndpoint;
use MissionDP\Rest\Endpoints\PaymentConfigEndpoint;
use MissionDP\Rest\Endpoints\ReviewBannerEndpoint;
use MissionDP\Rest\Endpoints\SettingsEndpoint;
use MissionDP\Rest\Endpoints\StripeConnectEndpoint;
use MissionDP\Rest\Endpoints\DonationFormSettingsEndpoint;
use MissionDP\Rest\Endpoints\NotesEndpoint;
use MissionDP\Export\ExportService;
use MissionDP\Rest\Endpoints\DonorsEndpoint;
use MissionDP\Rest\Endpoints\ExportEndpoint;
use MissionDP\Rest\Endpoints\DonorWallEndpoint;
use MissionDP\Rest\Endpoints\EmailTemplateEndpoint;
use MissionDP\Rest\Endpoints\EmailTestEndpoint;
use MissionDP\Rest\Endpoints\TransactionHistoryEndpoint;
use MissionDP\Rest\Endpoints\TributeEndpoint;

use MissionDP\Rest\Endpoints\DonorAuthEndpoint;
use MissionDP\Rest\Endpoints\DonorDashboard\OverviewEndpoint as DashboardOverviewEndpoint;
use MissionDP\Rest\Endpoints\DonorDashboard\TransactionsEndpoint as DashboardTransactionsEndpoint;
use MissionDP\Rest\Endpoints\DonorDashboard\SubscriptionsEndpoint as DashboardSubscriptionsEndpoint;
use MissionDP\Rest\Endpoints\DonorDashboard\EmailChangeEndpoint as DashboardEmailChangeEndpoint;
use MissionDP\Rest\Endpoints\DonorDashboard\ProfileEndpoint as DashboardProfileEndpoint;
use MissionDP\Rest\Endpoints\StripeWebhookEndpoint;
use MissionDP\Rest\Endpoints\SubscriptionsEndpoint;
use MissionDP\Rest\Endpoints\SystemStatusEndpoint;
use MissionDP\Rest\Endpoints\TransactionsEndpoint;
use MissionDP\Rest\Endpoints\CleanupEndpoint;
use MissionDP\Cleanup\CleanupService;
use MissionDP\DonorDashboard\DonorAuthService;
use MissionDP\Payments\PaymentIntentVerifier;
use MissionDP\Settings\SettingsService;

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
	public const NAMESPACE = 'mission-donation-platform/v1';

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
