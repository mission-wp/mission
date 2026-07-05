<?php
/**
 * Block Name: Donor Wall
 * Description: Display a grid of donations for a campaign.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Currency\Currency;
use MissionDP\DonorDashboard\PrimaryColorResolver;
use MissionDP\Models\Campaign;
use MissionDP\P2P\BlockSupport;
use MissionDP\Reporting\ReportingService;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes, $content, $block ): void {
// Resolve the campaign (0 shows donors across all campaigns).
$campaign    = BlockSupport::resolve_campaign( $attributes );
$campaign_id = $campaign->id ?? 0;

if ( ! $campaign_id ) {
	return;
}

// Settings.
$mission_settings = get_option( 'missiondp_settings', [] );
$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

// Attributes.
$columns            = max( 1, min( 4, (int) ( $attributes['columns'] ?? 2 ) ) );
$show_name          = $attributes['showName'] ?? true;
$show_anonymous     = $attributes['showAnonymous'] ?? true;
$show_avatar        = $attributes['showAvatar'] ?? true;
$avatar_width       = (int) ( $attributes['avatarWidth'] ?? 48 );
$donors_per_page    = max( 1, min( 50, (int) ( $attributes['donorsPerPage'] ?? 12 ) ) );
$show_amount        = $attributes['showAmount'] ?? true;
$show_date          = $attributes['showDate'] ?? true;
$show_comments      = $attributes['showComments'] ?? true;
$comment_length     = (int) ( $attributes['commentLength'] ?? 150 );
$read_more_text     = $attributes['readMoreText'] ?? __( 'Read more', 'mission-donation-platform' );
$show_sort_controls = $attributes['showSortControls'] ?? true;
$default_sort       = $attributes['defaultSort'] ?? 'recent';
$load_more_text     = $attributes['loadMoreText'] ?? __( 'Show More Donations', 'mission-donation-platform' );

// Map sort value to query params.
$sort_map    = [
	'recent'   => [
		'orderby' => 'date_completed',
		'order'   => 'DESC',
	],
	'highest'  => [
		'orderby' => 'amount',
		'order'   => 'DESC',
	],
	'earliest' => [
		'orderby' => 'date_completed',
		'order'   => 'ASC',
	],
];
$sort_params = $sort_map[ $default_sort ] ?? $sort_map['recent'];

// Query first page.
$settings  = new SettingsService();
$reporting = new ReportingService( $settings );
$result    = $reporting->donor_wall_query(
	campaign_id: $campaign_id,
	per_page: $donors_per_page,
	page: 1,
	orderby: $sort_params['orderby'],
	order: $sort_params['order'],
	show_anonymous: $show_anonymous,
);

$raw_items = $result['items'];
$total     = $result['total'];

// Frequency suffixes.
$freq_suffix = [
	'monthly'   => '/mo',
	'quarterly' => '/qtr',
	'annually'  => '/yr',
];

// Sanitize items and pre-compute display properties for Interactivity API context.
$items = [];
foreach ( $raw_items as $item ) {
	$amount_formatted = Currency::format_amount( $item['amount'], $currency );
	$type_suffix      = $freq_suffix[ $item['type'] ] ?? '';
	$formatted_amount = $amount_formatted . $type_suffix;

	/* translators: %s: human time diff, e.g. "2 hours" */
	$formatted_date = sprintf( __( '%s ago', 'mission-donation-platform' ), human_time_diff( strtotime( $item['date'] ) ) );

	$freq_labels_map = [
		'monthly'   => __( 'Monthly', 'mission-donation-platform' ),
		'quarterly' => __( 'Quarterly', 'mission-donation-platform' ),
		'annually'  => __( 'Annually', 'mission-donation-platform' ),
	];
	$frequency_label = 'one_time' !== $item['type'] ? ( $freq_labels_map[ $item['type'] ] ?? '' ) : '';

	if ( $item['is_anonymous'] ) {
		$items[] = [
			'name'            => __( 'Anonymous', 'mission-donation-platform' ),
			'initials'        => '?',
			'is_anonymous'    => true,
			'amount'          => $item['amount'],
			'currency'        => $item['currency'],
			'date'            => $item['date'],
			'type'            => $item['type'],
			'gravatar_hash'   => null,
			'gravatarSrc'     => '',
			'comment'         => esc_html( $item['comment'] ),
			'formattedAmount' => $formatted_amount,
			'formattedDate'   => $formatted_date,
			'frequencyLabel'  => $frequency_label,
		];
		continue;
	}

	$first    = $item['first_name'] ?? '';
	$last     = $item['last_name'] ?? '';
	$name     = trim( $first . ' ' . mb_substr( $last, 0, 1 ) . '.' );
	$initials = strtoupper( mb_substr( $first, 0, 1 ) . mb_substr( $last, 0, 1 ) );

	if ( '.' === $name ) {
		$name = __( 'Anonymous', 'mission-donation-platform' );
	}
	if ( '' === trim( $initials ) ) {
		$initials = '?';
	}

	$gravatar_hash = null;
	$gravatar_src  = '';
	if ( ! empty( $item['email'] ) ) {
		$gravatar_hash = md5( strtolower( trim( $item['email'] ) ) );
		$gravatar_src  = 'https://www.gravatar.com/avatar/' . $gravatar_hash . '?s=96&d=blank';
	}

	$items[] = [
		'name'            => $name,
		'initials'        => $initials,
		'is_anonymous'    => false,
		'amount'          => $item['amount'],
		'currency'        => $item['currency'],
		'date'            => $item['date'],
		'type'            => $item['type'],
		'gravatar_hash'   => $gravatar_hash,
		'gravatarSrc'     => $gravatar_src,
		'comment'         => esc_html( $item['comment'] ),
		'formattedAmount' => $formatted_amount,
		'formattedDate'   => $formatted_date,
		'frequencyLabel'  => $frequency_label,
	];
}

// Add staggered animation delays.
foreach ( $items as $i => &$entry ) {
	$entry['animDelay'] = number_format( $i * 0.05, 2 ) . 's';
}
unset( $entry );

// Primary color.
$primary_color = $mission_settings['primary_color'] ?? '#2fa36b';
$color_style   = PrimaryColorResolver::inline_style( $primary_color );

// Compute truncated comments for display.
foreach ( $items as &$entry ) {
	if ( ! empty( $entry['comment'] ) && mb_strlen( $entry['comment'] ) > $comment_length ) {
		$entry['displayComment'] = mb_substr( $entry['comment'], 0, $comment_length ) . '…';
		$entry['isTruncated']    = true;
	} else {
		$entry['displayComment'] = $entry['comment'];
		$entry['isTruncated']    = false;
	}
}
unset( $entry );

// Interactivity API context. footerHidden lives in context (not derived
// state) so the server-side directive processor can evaluate it; view.js
// recomputes it after sort changes and load-more fetches.
$context = [
	'campaignId'    => $campaign_id,
	'items'         => $items,
	'total'         => $total,
	'page'          => 1,
	'perPage'       => $donors_per_page,
	'sort'          => $default_sort,
	'isLoading'     => false,
	'showAnonymous' => $show_anonymous,
	'commentLength' => $comment_length,
	'readMoreText'  => $read_more_text,
	'restUrl'       => rest_url( 'mission-donation-platform/v1/donor-wall' ),
	'nonce'         => wp_create_nonce( 'wp_rest' ),
	'footerHidden'  => $total <= count( $items ) && $total <= $donors_per_page,
];

// Wrapper attributes.
$style_parts = [
	$color_style,
	'--mission-dw-columns:' . $columns,
	'--mission-dw-avatar-size:' . $avatar_width . 'px',
];

ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-donor-wall' . ( $show_avatar ? ' has-avatars' : '' ) ] ) ); ?>
	data-wp-interactive="mission-donation-platform/donor-wall"
	<?php echo wp_kses_post( wp_interactivity_data_wp_context( $context ) ); ?>
	style="<?php echo esc_attr( implode( ';', $style_parts ) ); ?>"
>
	<?php if ( $show_sort_controls ) : ?>
		<div class="mission-dw-toolbar">
			<select
				class="mission-dw-sort"
				data-wp-on--change="actions.changeSort"
				aria-label="<?php esc_attr_e( 'Sort donations', 'mission-donation-platform' ); ?>"
			>
				<option value="recent"<?php selected( $default_sort, 'recent' ); ?>>
					<?php esc_html_e( 'Most Recent', 'mission-donation-platform' ); ?>
				</option>
				<option value="highest"<?php selected( $default_sort, 'highest' ); ?>>
					<?php esc_html_e( 'Highest Amount', 'mission-donation-platform' ); ?>
				</option>
				<option value="earliest"<?php selected( $default_sort, 'earliest' ); ?>>
					<?php esc_html_e( 'Earliest', 'mission-donation-platform' ); ?>
				</option>
			</select>
		</div>
	<?php endif; ?>

	<?php if ( empty( $items ) ) : ?>
		<div class="mission-dw-empty">
			<div class="mission-dw-empty__icon">&#9829;</div>
			<p class="mission-dw-empty__title"><?php esc_html_e( 'No donations yet', 'mission-donation-platform' ); ?></p>
			<p class="mission-dw-empty__subtitle"><?php esc_html_e( 'Be the first to donate to this campaign!', 'mission-donation-platform' ); ?></p>
		</div>
	<?php else : ?>
		<div class="mission-dw-grid" data-wp-class--is-loading="context.isLoading">
			<template data-wp-each--donor="context.items">
				<div
					class="mission-dw-card"
					data-wp-class--is-anonymous="context.donor.is_anonymous"
					data-wp-style--animation-delay="context.donor.animDelay"
				>
					<?php if ( $show_avatar ) : ?>
						<div class="mission-dw-avatar">
							<span
								class="mission-dw-avatar-initials"
								data-wp-text="context.donor.initials"
								data-wp-class--is-hidden="context.donor.is_anonymous"
							></span>
							<span
								class="mission-dw-avatar-icon"
								data-wp-class--is-hidden="!context.donor.is_anonymous"
								aria-hidden="true"
							>
								<svg width="20" height="20" viewBox="0 0 24 24"
									fill="none" stroke="currentColor" stroke-width="1.5"
									stroke-linecap="round" stroke-linejoin="round"
								>
									<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
									<circle cx="12" cy="7" r="4" />
								</svg>
							</span>
							<img
								class="mission-dw-avatar-img"
								data-wp-bind--src="context.donor.gravatarSrc"
								alt=""
								loading="lazy"
								data-wp-class--is-hidden="!context.donor.gravatar_hash"
							/>
						</div>
					<?php endif; ?>
					<div class="mission-dw-body">
						<div class="mission-dw-top">
							<?php if ( $show_name ) : ?>
								<span class="mission-dw-name" data-wp-text="context.donor.name"></span>
							<?php endif; ?>
							<?php if ( $show_amount ) : ?>
								<span class="mission-dw-amount" data-wp-text="context.donor.formattedAmount"></span>
							<?php endif; ?>
						</div>
						<div class="mission-dw-meta">
							<?php if ( $show_date ) : ?>
								<span class="mission-dw-date" data-wp-text="context.donor.formattedDate"></span>
							<?php endif; ?>
							<span
								class="mission-dw-recurring"
								data-wp-text="context.donor.frequencyLabel"
								data-wp-class--is-hidden="!context.donor.frequencyLabel"
							></span>
						</div>
						<?php if ( $show_comments ) : ?>
							<div
								class="mission-dw-comment"
								data-wp-class--is-hidden="!context.donor.comment"
							>
								<span data-wp-text="context.donor.displayComment"></span>
								<span
									class="mission-dw-read-more"
									data-wp-class--is-hidden="!context.donor.isTruncated"
									data-wp-on--click="actions.toggleComment"
									data-wp-text="context.readMoreText"
								></span>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</template>
		</div>

		<?php
		// Directive values must be context/state references, not expressions:
		// an inline comparison never evaluated, and its "<" also broke
		// wp_kses, which escaped the tag into visible text.
		?>
		<div class="mission-dw-footer" data-wp-class--is-hidden="context.footerHidden">
			<button
				type="button"
				class="mission-dw-load-more"
				data-wp-on--click="actions.loadMore"
				data-wp-class--is-loading="context.isLoading"
				data-wp-class--is-hidden="!state.hasMore"
			>
				<?php echo esc_html( $load_more_text ); ?>
			</button>
			<p class="mission-dw-count" data-wp-text="state.showingText"></p>
		</div>
	<?php endif; ?>
</div>
<?php
$output = ob_get_clean();

/**
 * Filters the donor wall block output.
 *
 * @param string        $output     HTML output.
 * @param Campaign|null $campaign   Campaign model.
 * @param array         $attributes Block attributes.
 */
echo wp_kses( apply_filters( 'mission_donor_wall_output', $output, $campaign, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes, $content, $block );
