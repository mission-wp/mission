<?php
/**
 * Email header template partial.
 *
 * @var array $data Template data.
 */

defined( 'ABSPATH' ) || exit;

$site_name = get_bloginfo( 'name' );
$site_url  = home_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html( $data['subject'] ?? $site_name ); ?></title>
	<style type="text/css">
		@media only screen and (max-width: 620px) {
			.email-container {
				width: 100% !important;
				max-width: 100% !important;
			}
			.content-padding {
				padding: 30px 20px !important;
			}
		}
	</style>
</head>
<body style="margin: 0; padding: 0;">
	<div style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333333; background-color: #f5f5f5; width: 100%;">
		<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f5f5f5;">
			<tr>
				<td align="center" style="padding: 20px;">
					<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="email-container" style="max-width: 600px; width: 100%;">
						<!-- Main Content Area -->
						<tr>
							<td>
								<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #ffffff; border-radius: 8px;">
									<tr>
										<td class="content-padding" style="padding: 40px 30px;">
