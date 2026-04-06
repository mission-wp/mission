<?php
/**
 * Donor note email template — sent when an admin adds a donor-visible note to a transaction.
 *
 * @var array $data Template data: note, transaction, donor, organization.
 */

defined( 'ABSPATH' ) || exit;
?>
<div style="font-size: 15px; line-height: 1.6; color: #1a1a2e;">
	<?php echo nl2br( esc_html( $data['note']->content ) ); ?>
</div>
