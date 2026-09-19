<?php
/**
 * Trait for donations submitted with the optional tip section suppressed.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Traits;

use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Forces the flat platform fee and records the event when the form reports
 * that custom code hid or removed the tip section.
 */
trait TipHiddenTrait {

	/**
	 * Force flat fee mode when the client reports the tip UI was suppressed.
	 *
	 * Rewrites the request so every downstream read (API body, description,
	 * transaction record) sees fee_mode 'flat' and a zero tip.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool Whether the override was applied.
	 */
	private function apply_tip_hidden_override( WP_REST_Request $request ): bool {
		if ( ! rest_sanitize_boolean( $request->get_param( 'tip_hidden' ) ) ) {
			return false;
		}

		$request->set_param( 'fee_mode', 'flat' );
		$request->set_param( 'tip_amount', 0 );

		return true;
	}

	/**
	 * Resolve the page URL the donation was made from, for the Mission API.
	 *
	 * Prefers the URL the form reported, stripped of its query string and
	 * fragment so tokens and tracking parameters never leave the site. Falls
	 * back to the source post's permalink, which is kept intact because plain
	 * permalinks identify the post in the query string.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string Page URL, or an empty string.
	 */
	private function resolve_page_url( WP_REST_Request $request ): string {
		$url = (string) $request->get_param( 'page_url' );

		if ( '' === $url ) {
			$post_id = (int) $request->get_param( 'source_post_id' );
			return $post_id ? (string) get_permalink( $post_id ) : '';
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . $port . ( $parts['path'] ?? '/' );
	}

	/**
	 * Mark the records of a suppressed-tip donation.
	 *
	 * Internal only: nothing surfaces this to the site. The donation is
	 * otherwise indistinguishable from one made with the flat fee opted in.
	 *
	 * @param Transaction       $transaction  Pending transaction.
	 * @param Subscription|null $subscription Subscription, for recurring gifts.
	 * @return void
	 */
	private function record_tip_hidden( Transaction $transaction, ?Subscription $subscription = null ): void {
		$transaction->add_meta( 'tip_hidden', '1' );
		$subscription?->add_meta( 'tip_hidden', '1' );
	}
}
