<?php
/**
 * Keeps fundraiser/team table rows consistent with external shell-post changes.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The custom table is canonical for fundraiser/team status; the shell post's
 * status is a one-way projection (active = publish, pending = pending,
 * inactive = draft). External writers (WP-CLI, cleanup plugins) can still
 * change, trash, or delete the post, so this guard keeps both sides sane:
 * status writes are forced back to the table-mapped value (including direct
 * writes like wp_publish_post() that bypass wp_insert_post_data), and a
 * trashed or deleted shell post deactivates its row so a page that no longer
 * resolves stops accruing donations and leaderboard credit. Writes and
 * removals originating from the model's own sync are left alone.
 */
class ShellPostStatusGuard {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'wp_insert_post_data', [ $this, 'enforce_mapped_status' ], 10, 2 );
		add_action( 'transition_post_status', [ $this, 'reassert_after_direct_write' ], 10, 3 );
		// after_delete_post (not deleted_post) so the post cache is already
		// cleaned and the row's own save can't act on a stale cached post.
		add_action( 'trashed_post', [ $this, 'deactivate_row_on_trash' ] );
		add_action( 'after_delete_post', [ $this, 'deactivate_row_on_delete' ], 10, 2 );
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

		$model = $this->find_model( $post_type, (int) $postarr['ID'] );

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

	/**
	 * Re-assert the mapped status after a direct status write.
	 *
	 * wp_publish_post() and similar writers update the posts table directly,
	 * bypassing the wp_insert_post_data filter, but they still fire
	 * transition_post_status. Trash transitions are handled by the trash
	 * listener instead.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public function reassert_after_direct_write( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ! in_array( $post->post_type, [ Fundraiser::POST_TYPE, Team::POST_TYPE ], true ) ) {
			return;
		}

		if ( Fundraiser::is_syncing_shell_post() || Team::is_syncing_shell_post() ) {
			return;
		}

		if ( 'trash' === $new_status || 'new' === $old_status ) {
			return;
		}

		$model = $this->find_model( $post->post_type, (int) $post->ID );

		if ( ! $model ) {
			return;
		}

		$mapped = $model->shell_post_status();

		if ( $new_status === $mapped ) {
			return;
		}

		/** This action is documented in src/P2P/ShellPostStatusGuard.php */
		do_action( 'mission_shell_post_status_reverted', (int) $post->ID, $new_status, $mapped );

		wp_update_post(
			[
				'ID'          => $post->ID,
				'post_status' => $mapped,
			]
		);
	}

	/**
	 * Deactivate the table row when its shell post is trashed externally.
	 *
	 * A trashed page 404s, so the row must stop counting toward leaderboards
	 * and stop accepting donation attribution. Untrashing restores the post at
	 * the mapped (inactive = draft) status; an admin can then reactivate.
	 *
	 * @param int $post_id The trashed post ID.
	 * @return void
	 */
	public function deactivate_row_on_trash( int $post_id ): void {
		if ( Fundraiser::is_syncing_shell_post() || Team::is_syncing_shell_post() ) {
			return;
		}

		$this->find_model( (string) get_post_type( $post_id ), $post_id )?->deactivate();
	}

	/**
	 * Deactivate the table row when its shell post is deleted externally.
	 *
	 * The row keeps its now-dangling post_id (post IDs are never reused);
	 * URL and slug accessors degrade to null/empty for a missing post.
	 *
	 * @param int     $post_id The deleted post ID.
	 * @param WP_Post $post    The deleted post object.
	 * @return void
	 */
	public function deactivate_row_on_delete( int $post_id, WP_Post $post ): void {
		if ( Fundraiser::is_syncing_shell_post() || Team::is_syncing_shell_post() ) {
			return;
		}

		$this->find_model( $post->post_type, $post_id )?->deactivate();
	}

	/**
	 * Find the fundraiser or team owning a shell post.
	 *
	 * @param string $post_type The post type.
	 * @param int    $post_id   The shell post ID.
	 * @return Fundraiser|Team|null Null when the type isn't a shell type or no row matches.
	 */
	private function find_model( string $post_type, int $post_id ): Fundraiser|Team|null {
		return match ( $post_type ) {
			Fundraiser::POST_TYPE => Fundraiser::find_by_post_id( $post_id ),
			Team::POST_TYPE       => Team::find_by_post_id( $post_id ),
			default               => null,
		};
	}
}
