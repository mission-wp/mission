<?php
/**
 * Trait for server-side minimum donation amount validation.
 *
 * @package Mission
 */

namespace Mission\Rest\Traits;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the donation amount against the block's configured minimum.
 */
trait MinimumAmountTrait {

	/**
	 * Absolute minimum donation in minor units ($1.00).
	 */
	private const HARD_FLOOR = 100;

	/**
	 * Default block minimum in minor units ($5.00).
	 */
	private const DEFAULT_BLOCK_MINIMUM = 500;

	/**
	 * Validate the donation amount against the configured minimum.
	 *
	 * Enforces a hard floor of $1.00 unconditionally. When form_id and
	 * source_post_id are available, looks up the block's minimumAmount
	 * and validates against that.
	 *
	 * @param int    $amount         Donation amount in minor units (cents).
	 * @param int    $source_post_id Post ID containing the donation form block.
	 * @param string $form_id        The block's formId attribute.
	 * @return WP_Error|true True if valid, WP_Error if below minimum.
	 */
	private function validate_minimum_amount( int $amount, int $source_post_id, string $form_id ): WP_Error|true {
		if ( $amount < self::HARD_FLOOR ) {
			return new WP_Error(
				'donation_below_minimum',
				__( 'Donation amount must be at least $1.00.', 'mission' ),
				[ 'status' => 400 ]
			);
		}

		// Look up the block-level minimum when we can identify the form.
		if ( $source_post_id < 1 || '' === $form_id ) {
			return true;
		}

		$block_minimum = $this->get_block_minimum( $source_post_id, $form_id );

		if ( null !== $block_minimum && $amount < $block_minimum ) {
			return new WP_Error(
				'donation_below_minimum',
				sprintf(
					/* translators: %s: formatted minimum amount */
					__( 'Donation amount must be at least %s.', 'mission' ),
					'$' . number_format( $block_minimum / 100, 2 )
				),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Look up the minimumAmount from a donation form block in a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $form_id Block's formId attribute.
	 * @return int|null Minimum amount in minor units, or null if not found.
	 */
	private function get_block_minimum( int $post_id, string $form_id ): ?int {
		$post = get_post( $post_id );

		if ( ! $post || empty( $post->post_content ) ) {
			return null;
		}

		$blocks = parse_blocks( $post->post_content );

		return $this->find_minimum_in_blocks( $blocks, $form_id );
	}

	/**
	 * Recursively search parsed blocks for a donation form with a matching formId.
	 *
	 * @param array  $blocks  Parsed blocks array.
	 * @param string $form_id Form ID to match.
	 * @return int|null Minimum amount in minor units, or null if not found.
	 */
	private function find_minimum_in_blocks( array $blocks, string $form_id ): ?int {
		foreach ( $blocks as $block ) {
			if ( 'mission/donation-form' === $block['blockName'] ) {
				$block_form_id = $block['attrs']['formId'] ?? '';
				if ( $block_form_id === $form_id ) {
					return (int) ( $block['attrs']['minimumAmount'] ?? self::DEFAULT_BLOCK_MINIMUM );
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$result = $this->find_minimum_in_blocks( $block['innerBlocks'], $form_id );
				if ( null !== $result ) {
					return $result;
				}
			}
		}

		return null;
	}
}
