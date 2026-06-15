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
	protected function shell_post_status(): string {
		return match ( $this->status ) {
			'active'   => 'publish',
			'inactive' => 'draft',
			default    => 'pending',
		};
	}

	/**
	 * Save the model, creating or syncing the shell post first.
	 *
	 * If the row insert for a brand-new record fails (e.g. a unique-constraint
	 * violation), the shell post just created is removed so it isn't orphaned.
	 *
	 * @return int|bool New ID on insert, true on update, false on failure.
	 */
	public function save(): int|bool {
		$was_new = ! $this->post_id;

		$this->sync_shell_post();

		$result = parent::save();

		if ( $was_new && ! $result && $this->post_id ) {
			wp_delete_post( $this->post_id, true );
			$this->post_id    = 0;
			$this->shell_post = null;
		}

		return $result;
	}

	/**
	 * Create the shell post on first save, or sync title/status on later saves.
	 */
	protected function sync_shell_post(): void {
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

		wp_update_post(
			[
				'ID'          => $this->post_id,
				'post_title'  => $this->shell_post_title(),
				'post_status' => $this->shell_post_status(),
			]
		);

		$this->shell_post = null;
	}

	/**
	 * Trash the shell post and delete the custom table row.
	 *
	 * @return bool
	 */
	public function trash(): bool {
		if ( $this->post_id ) {
			wp_trash_post( $this->post_id );
		}

		return parent::delete();
	}

	/**
	 * Delete the shell post (permanently) and the custom table row.
	 *
	 * @return bool
	 */
	public function delete(): bool {
		if ( $this->post_id ) {
			wp_delete_post( $this->post_id, true );
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
