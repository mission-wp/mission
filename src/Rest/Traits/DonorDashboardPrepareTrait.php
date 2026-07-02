<?php
/**
 * Shared campaign caching and model preparation for donor dashboard endpoints.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Traits;

use MissionDP\Models\Campaign;
use MissionDP\Models\Transaction;
use MissionDP\Models\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Provides campaign caching, preloading, and model-to-array preparation.
 */
trait DonorDashboardPrepareTrait {

	/**
	 * Batch-loaded campaign cache keyed by campaign ID.
	 *
	 * @var array<int, Campaign|null>
	 */
	private array $campaign_cache = [];

	/**
	 * Preload campaigns into the cache to avoid N+1 queries.
	 *
	 * @param int[] $ids Campaign IDs to preload.
	 */
	private function preload_campaigns( array $ids ): void {
		$ids     = array_unique( array_filter( $ids ) );
		$missing = array_diff( $ids, array_keys( $this->campaign_cache ) );

		if ( ! empty( $missing ) ) {
			$loaded = Campaign::find_many( $missing );
			foreach ( $missing as $id ) {
				$this->campaign_cache[ $id ] = $loaded[ $id ] ?? null;
			}
		}
	}

	/**
	 * Resolve a campaign from cache, falling back to a single query.
	 *
	 * @param int|null $campaign_id Campaign ID.
	 * @return Campaign|null
	 */
	private function resolve_campaign( ?int $campaign_id ): ?Campaign {
		if ( ! $campaign_id ) {
			return null;
		}

		if ( ! array_key_exists( $campaign_id, $this->campaign_cache ) ) {
			$this->campaign_cache[ $campaign_id ] = Campaign::find( $campaign_id );
		}

		return $this->campaign_cache[ $campaign_id ];
	}

	/**
	 * The store currency code for dashboard responses.
	 *
	 * @return string Uppercase ISO currency code.
	 */
	private function dashboard_currency(): string {
		return strtoupper( (string) ( $this->settings->get( 'currency' ) ?: 'USD' ) );
	}

	/**
	 * Human-readable progress label for a fundraiser or team.
	 *
	 * @param string $raised_display Formatted amount raised.
	 * @param string $goal_display   Formatted goal, or an empty string when no goal is set.
	 * @return string
	 */
	private function progress_label( string $raised_display, string $goal_display ): string {
		return $goal_display
			/* translators: 1: amount raised, 2: goal amount */
			? sprintf( __( '%1$s raised of %2$s goal', 'mission-donation-platform' ), $raised_display, $goal_display )
			/* translators: %s: amount raised */
			: sprintf( __( '%s raised', 'mission-donation-platform' ), $raised_display );
	}

	/**
	 * The attachment ID a cover image field references, or 0 when it holds a URL.
	 *
	 * Fundraiser/team cover images are a varchar that may hold either form.
	 *
	 * @param string $cover Attachment ID or image URL.
	 * @return int
	 */
	private function cover_image_id( string $cover ): int {
		return ctype_digit( $cover ) ? (int) $cover : 0;
	}

	/**
	 * Resolve a cover image field (attachment ID or URL) to a URL.
	 *
	 * @param string $cover Attachment ID or image URL.
	 * @return string
	 */
	private function cover_image_url( string $cover ): string {
		if ( '' !== $cover && ctype_digit( $cover ) ) {
			return wp_get_attachment_image_url( (int) $cover, 'large' ) ?: '';
		}

		return $cover;
	}

	/**
	 * Prepare a transaction for REST response.
	 *
	 * @param Transaction $transaction Transaction model.
	 * @return array<string, mixed>
	 */
	private function prepare_transaction( Transaction $transaction ): array {
		$campaign = $this->resolve_campaign( $transaction->campaign_id );

		return [
			'id'              => $transaction->id,
			'amount'          => $transaction->amount,
			'fee_amount'      => $transaction->fee_amount,
			'tip_amount'      => $transaction->tip_amount,
			'total_amount'    => $transaction->total_amount,
			'currency'        => $transaction->currency,
			'type'            => $transaction->type,
			'status'          => $transaction->status,
			'campaign_id'     => $transaction->campaign_id,
			'campaign_name'   => $campaign?->title ?? __( 'Deleted Campaign', 'mission-donation-platform' ),
			'is_anonymous'    => $transaction->is_anonymous,
			'payment_gateway' => $transaction->payment_gateway,
			'date_created'    => $transaction->date_created,
			'date_completed'  => $transaction->date_completed,
		];
	}

	/**
	 * Prepare a subscription for REST response.
	 *
	 * @param Subscription $subscription Subscription model.
	 * @return array<string, mixed>
	 */
	private function prepare_subscription( Subscription $subscription ): array {
		$campaign = $this->resolve_campaign( $subscription->campaign_id );

		return [
			'id'                => $subscription->id,
			'status'            => $subscription->status,
			'amount'            => $subscription->amount,
			'fee_amount'        => $subscription->fee_amount,
			'tip_amount'        => $subscription->tip_amount,
			'total_amount'      => $subscription->total_amount,
			'currency'          => $subscription->currency,
			'frequency'         => $subscription->frequency,
			'campaign_id'       => $subscription->campaign_id,
			'campaign_name'     => $campaign?->title ?? __( 'Deleted Campaign', 'mission-donation-platform' ),
			'renewal_count'     => $subscription->renewal_count,
			'total_renewed'     => $subscription->total_renewed,
			'total_given'       => $subscription->total_amount + $subscription->total_renewed,
			'date_created'      => $subscription->date_created,
			'date_next_renewal' => $subscription->date_next_renewal,
			'date_cancelled'    => $subscription->date_cancelled,
		];
	}
}
