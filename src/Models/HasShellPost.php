<?php
/**
 * HasShellPost trait for models backed by a hidden CPT "shell" post.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs a model row with a linked WordPress post that exists only to give the
 * record a real URL, slug, and SEO surface (sitemaps, og: tags). The custom
 * table stays the source of truth; the shell post carries no content.
 *
 * The consuming model must expose `public int $post_id`, `public string $status`,
 * and implement shell_post_type() + shell_post_title(). Status maps to the post:
 * active = publish, pending = pending, inactive = draft (approval is publishing).
 */
trait HasShellPost {

	/**
	 * Cached backing post for slug/permalink proxying.
	 *
	 * @var \WP_Post|null
	 */
	private ?\WP_Post $shell_post = null;

	/**
	 * True while this model type is pushing its own state to the shell post.
	 *
	 * @var bool
	 */
	private static bool $syncing_shell_post = false;

	/**
	 * Whether the current shell-post write originated from a model sync.
	 *
	 * Read by ShellPostStatusGuard to distinguish the model's own status
	 * projection from external post-status changes.
	 *
	 * @return bool
	 */
	public static function is_syncing_shell_post(): bool {
		return self::$syncing_shell_post;
	}

	/**
	 * The post type slug for this model's shell posts.
	 *
	 * @return string
	 */
	abstract protected function shell_post_type(): string;

	/**
	 * The title to give the shell post (drives the slug and SEO title).
	 *
	 * @return string
	 */
	abstract protected function shell_post_title(): string;

	/**
	 * Map the model status to a WordPress post status.
	 *
	 * @return string
	 */
	public function shell_post_status(): string {
		return match ( $this->status ) {
			'active'   => 'publish',
			'inactive' => 'draft',
			default    => 'pending',
		};
	}

	/**
	 * Save the model, keeping the shell post in sync.
	 *
	 * New records create the shell post first (the row stores its ID); if the
	 * row insert then fails (e.g. a unique-constraint violation), the post is
	 * removed so it isn't orphaned. Existing records write the row first and
	 * only project onto the post when the row update succeeded, so a failed
	 * update can't desync the two.
	 *
	 * @return int|bool New ID on insert, true on update, false on failure.
	 */
	public function save(): int|bool {
		if ( ! $this->post_id ) {
			$this->sync_shell_post();

			$result = parent::save();

			if ( ! $result && $this->post_id ) {
				wp_delete_post( $this->post_id, true );
				$this->post_id    = 0;
				$this->shell_post = null;
			}

			return $result;
		}

		$result = parent::save();

		if ( $result ) {
			$this->sync_shell_post();
		}

		return $result;
	}

	/**
	 * Create the shell post on first save, or sync title/status on later saves.
	 */
	protected function sync_shell_post(): void {
		self::$syncing_shell_post = true;

		try {
			if ( ! $this->post_id ) {
				$post_id = wp_insert_post(
					[
						'post_type'   => $this->shell_post_type(),
						'post_title'  => $this->shell_post_title(),
						'post_status' => $this->shell_post_status(),
					],
					true
				);

				if ( is_wp_error( $post_id ) ) {
					return;
				}

				$this->post_id    = (int) $post_id;
				$this->shell_post = null;

				return;
			}

			// An externally trashed post stays in the trash (writing a status
			// here would silently restore it), and a deleted post stays gone.
			// ShellPostStatusGuard deactivates the row in both cases.
			$post_status = get_post_status( $this->post_id );
			if ( ! $post_status || 'trash' === $post_status ) {
				return;
			}

			wp_update_post(
				[
					'ID'          => $this->post_id,
					'post_title'  => $this->shell_post_title(),
					'post_status' => $this->shell_post_status(),
				]
			);

			$this->shell_post = null;
		} finally {
			self::$syncing_shell_post = false;
		}
	}

	/**
	 * Trash the shell post and delete the custom table row.
	 *
	 * The syncing flag tells ShellPostStatusGuard this removal is the model's
	 * own, so its external-trash listener doesn't touch the row being deleted.
	 *
	 * @return bool
	 */
	public function trash(): bool {
		if ( $this->post_id ) {
			self::$syncing_shell_post = true;

			try {
				wp_trash_post( $this->post_id );
			} finally {
				self::$syncing_shell_post = false;
			}
		}

		return parent::delete();
	}

	/**
	 * Delete the shell post (permanently) and the custom table row.
	 *
	 * The syncing flag tells ShellPostStatusGuard this removal is the model's
	 * own, so its external-delete listener doesn't touch the row being deleted.
	 *
	 * @return bool
	 */
	public function delete(): bool {
		if ( $this->post_id ) {
			self::$syncing_shell_post = true;

			try {
				wp_delete_post( $this->post_id, true );
			} finally {
				self::$syncing_shell_post = false;
			}
		}

		return parent::delete();
	}

	/**
	 * Get the public URL of this record's shell post.
	 *
	 * @return string|null
	 */
	public function get_url(): ?string {
		if ( ! $this->post_id ) {
			return null;
		}

		$url = get_permalink( $this->post_id );

		return $url ?: null;
	}

	/**
	 * Transparent read access to the shell post slug.
	 *
	 * Note: any other undeclared property reads null (no notice), so a typoed
	 * property name fails silently — check spelling against the model's
	 * declared properties before reaching for this.
	 *
	 * @param string $name Property name.
	 * @return mixed The slug for 'slug', otherwise null.
	 */
	public function __get( string $name ): mixed {
		if ( 'slug' === $name ) {
			$this->shell_post ??= get_post( $this->post_id );

			return $this->shell_post?->post_name ?? '';
		}

		return null;
	}

	/**
	 * Support isset()/empty() for the virtual slug property.
	 *
	 * @param string $name Property name.
	 * @return bool
	 */
	public function __isset( string $name ): bool {
		if ( 'slug' === $name ) {
			$this->shell_post ??= get_post( $this->post_id );

			return null !== $this->shell_post;
		}

		return false;
	}
}
