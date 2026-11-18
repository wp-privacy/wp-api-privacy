<div class="wrap">
	<h1><?php esc_html_e( 'WP API Privacy', 'wp-api-privacy' ); ?></h1>

	<p><?php esc_html_e( 'You can configure the options for API privacy here.', 'wp-api-privacy' ); ?><br />
	<?php

		printf(
			esc_html(
				/* translators: contains a number indicating the number of requests intercepted */
				_n(
					'The number of API requests that have been modified since activation is: %s',
					'The number of API requests that have been modified since activation are: %s',
					$this->getSetting( 'modification_count' ),
					'wp-api-privacy'
				)
			),
			'<strong>' . number_format( $this->getSetting( 'modification_count' ) ) . '</strong>'
		);
		?>
		</p>

	<?php $this->doOptionsHeader(); ?>

	<form method="post" action="options-general.php?page=api-privacy"> 
		<input type="hidden" name="wp_api_privacy_settings" value="1">
		<input type="hidden" name="wp_api_privacy_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpprivacy' ) ); ?>">
		<table class="form-table" role="presentation">
			<tbody>
				<?php foreach ( $this->settingsSections as $name => $data ) { ?>
					<tr>
						<th><?php echo esc_html( $data[0] ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $data[1] as $setting ) { ?>
									<?php $this->renderOneSetting( $setting ); ?>
								<?php } ?>
							</fieldset>
						</td>
					</tr>
				<?php } ?> 
			</tbody>
		</table>
		<input type="submit" id="submit" class="button button-primary" name="submit" value="<?php esc_attr_e( 'Save Changes', 'wp-api-privacy' ); ?>" />
	</form>
</div>
