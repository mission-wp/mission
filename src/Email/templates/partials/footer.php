<?php
/**
 * Email footer template partial.
 *
 * @var array $data Template data.
 */

defined( 'ABSPATH' ) || exit;

$site_name = get_bloginfo( 'name' );
$site_url  = home_url();
?>
										</td>
									</tr>
								</table>
							</td>
						</tr>
						<!-- Footer Area -->
						<tr>
							<td align="center" style="padding: 30px;">
								<p style="margin: 0; font-size: 14px; color: #999999;">
									<?php echo esc_html( $site_name ); ?> &mdash;
									<a href="<?php echo esc_url( $site_url ); ?>" style="color: #999999;"><?php echo esc_html( $site_url ); ?></a>
								</p>
							</td>
						</tr>
					</table>
				</td>
			</tr>
		</table>
	</div>
</body>
</html>
