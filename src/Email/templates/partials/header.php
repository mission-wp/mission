<?php
/**
 * Email header template partial.
 *
 * @var array $data Template data.
 */

defined( 'ABSPATH' ) || exit;

$settings      = new \Mission\Settings\SettingsService();
$site_name     = $settings->get( 'org_name', get_bloginfo( 'name' ) );
$subject       = $data['subject'] ?? $site_name;
$primary_color = $settings->get( 'primary_color', '#2fa36b' );
?>
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="x-apple-disable-message-reformatting">
	<meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
	<title><?php echo esc_html( $subject ); ?></title>
	<!--[if mso]>
	<noscript>
		<xml>
			<o:OfficeDocumentSettings>
				<o:AllowPNG/>
				<o:PixelsPerInch>96</o:PixelsPerInch>
			</o:OfficeDocumentSettings>
		</xml>
	</noscript>
	<style>
		table { border-collapse: collapse; }
		td { font-family: 'Segoe UI', Helvetica, Arial, sans-serif; }
		a { text-decoration: none; }
	</style>
	<![endif]-->
	<style>
		body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
		table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
		img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
		body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; }
		@media only screen and (max-width: 600px) {
			.email-container { width: 100% !important; }
			.email-padding { padding-left: 20px !important; padding-right: 20px !important; }
		}
	</style>
</head>
<body style="margin: 0; padding: 0; background-color: #f7f7f5; width: 100% !important;">

	<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color: #f7f7f5;">
		<tr>
			<td align="center" valign="top" style="padding: 32px 16px;">

				<!--[if mso]>
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" align="center"><tr><td>
				<![endif]-->

				<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" class="email-container" style="max-width: 560px; width: 100%;">

					<!-- White card -->
					<tr>
						<td>
							<!--[if mso]>
							<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color: #ffffff; border: 1px solid #e8e8e6;"><tr><td>
							<![endif]-->
							<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color: #ffffff; border-radius: 8px; border: 1px solid #e8e8e6;">

								<!-- Green top accent -->
								<tr>
									<td style="height: 4px; background-color: <?php echo esc_attr( $primary_color ); ?>; font-size: 0; line-height: 0;">&nbsp;</td>
								</tr>

								<!-- Organization name -->
								<tr>
									<td class="email-padding" style="padding: 32px 40px 0 40px;">
										<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
											<tr>
												<td style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; font-weight: 700; color: #1a1a1a; letter-spacing: -0.01em;">
													<?php echo esc_html( $site_name ); ?>
												</td>
											</tr>
										</table>
									</td>
								</tr>

								<!-- Divider -->
								<tr>
									<td class="email-padding" style="padding: 20px 40px 0 40px;">
										<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
											<tr>
												<td style="height: 1px; background-color: #eeeeee; font-size: 0; line-height: 0;">&nbsp;</td>
											</tr>
										</table>
									</td>
								</tr>

								<!-- Body content -->
								<tr>
									<td class="email-padding" style="padding: 28px 40px 32px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #666666;">
