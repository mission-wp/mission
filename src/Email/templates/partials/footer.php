<?php
/**
 * Email footer template partial.
 *
 * @var array $data Template data.
 */

defined( 'ABSPATH' ) || exit;

$settings    = new \MissionDP\Settings\SettingsService();
$site_name   = $settings->get( 'org_name', get_bloginfo( 'name' ) );
$org_address = $settings->format_org_address();
?>
									</td>
								</tr>

							</table>
							<!--[if mso]>
							</td></tr></table>
							<![endif]-->
						</td>
					</tr>

					<!-- Footer -->
					<tr>
						<td style="padding: 28px 0 0 0;">
							<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
								<tr>
									<td align="center" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 13px; color: #999999; line-height: 1.6;">
										<p style="margin: 0; font-weight: 600; color: #666666;"><?php echo esc_html( $site_name ); ?></p>
										<?php if ( $org_address ) : ?>
											<p style="margin: 2px 0 0 0;"><?php echo esc_html( $org_address ); ?></p>
										<?php endif; ?>
									</td>
								</tr>
							</table>
						</td>
					</tr>

				</table>

				<!--[if mso]>
				</td></tr></table>
				<![endif]-->

			</td>
		</tr>
	</table>

</body>
</html>
