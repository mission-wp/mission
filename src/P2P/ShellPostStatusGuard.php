<?php
/**
 * Reverts external status changes on fundraiser/team shell posts.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;

defined( 'ABSPATH' ) || exit;

/**
 * The custom table is canonical for fundraiser/team status; the shell post's
 * status is a one-way projection (active = publish, pending = pending,
 * inactive = draft). An external write flipping the post status (WP-CLI,
 * another plugin) would silently desync public visibility from the table, so
 * incoming statuses are forced back to the table-mapped value unless the
 * write originated from the model's own sync. Trashing stays allowed.
 */
class ShellPostStatusGuard {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'wp_insert_post_data', [ $this, 'enforce_mapped_status' ], 10, 2 );
	}

	/**
	 * Force shell-post status writes back to the table-mapped status.
	 *
	 * @param array<string, mixed> $data    Slashed, sanitized post data about to be written.
	 * @param array<string, mixed> $postarr Raw data passed to wp_insert_post().
	 * @return array<string, mixed>
	 */
	public function enforce_mapped_status( array $data, array $postarr ): array {
		$post_type = $data['post_type'] ?? '';

		if ( ! in_array( $post_type, [ Fundraiser::POST_TYPE, Team::POST_TYPE ], true ) ) {
			return $data;
		}

		if ( Fundraiser::is_syncing_shell_post() || Team::is_syncing_shell_post() ) {
			return $data;
		}

		// Trashing stays allowed; a brand-new post has no row to compare yet.
		if ( 'trash' === ( $data['post_status'] ?? '' ) || empty( $postarr['ID'] ) ) {
			return $data;
		}

		$model = Fundraiser::POST_TYPE === $post_type
			? Fundraiser::find_by_post_id( (int) $postarr['ID'] )
			: Team::find_by_post_id( (int) $postarr['ID'] );

		if ( ! $model ) {
			return $data;
		}

		$mapped = $model->shell_post_status();

		if ( ( $data['post_status'] ?? '' ) !== $mapped ) {
			/**
			 * Fires when an external status change on a shell post is reverted.
			 *
			 * @param int    $post_id   The shell post ID.
			 * @param string $requested The status the external write requested.
			 * @param string $mapped    The enforced, table-mapped status.
			 */
			do_action( 'mission_shell_post_status_reverted', (int) $postarr['ID'], (string) ( $data['post_status'] ?? '' ), $mapped );

			$data['post_status'] = $mapped;
		}

		return $data;
	}
}
