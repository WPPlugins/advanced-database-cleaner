<div class="marginTop20">
	<div style="float: left; margin-right:25px; margin-bottom:40px">
		<form action="" method="post">
			<?php if(function_exists('is_multisite') && is_multisite() && is_main_site()){ ?>
				<div style="margin-bottom:15px;">
					<a class="tooltips" href="#">
						<img src="<?php echo  ADB_CLEANER_PLUGIN_DIR . '/images/about.png'?>"/>
						<span>The plugin has detected that you use a multisite installation. This is your main site from which you can manage all scheduled tasks in all sites of your network. Other sites can manage their own scheduled tasks only.</span>
					</a>
				</div>
			<?php } ?>
			<table class="widefat" style="width: 800px; border: 1px solid #eee; box-shadow: 0 0px 0px #ccc;">
				<thead style="background:#f0f5fa; border-bottom:1px solid dashed">
					<tr>
						<th scope="col" style="color:#222; font-size:13px; border-bottom: 0px"><?php _e('Hook name', 'advanced-db-cleaner'); ?></th>
						<?php if(function_exists('is_multisite') && is_multisite() && is_main_site()){ ?>
							<th scope="col" style="color:#222; font-size:13px; border-bottom: 0px"><?php _e('Blog id', 'advanced-db-cleaner'); ?></th>
						<?php } ?>						
						<th scope="col" style="color:#222; font-size:13px; border-bottom: 0px"><?php _e('Frequency', 'advanced-db-cleaner'); ?></th>
						<th scope="col" style="font-size:13px; color:#222; border-bottom: 0px;"><?php _e('Next run', 'advanced-db-cleaner'); ?></th>
						<th scope="col" style="font-size:13px; color:#222; border-bottom: 0px;"><?php _e('UTC', 'advanced-db-cleaner'); ?></th>
						<th scope="col" style="text-align:center; color:#222; width: 40px; font-size:13px; border-bottom: 0px"><?php _e('Select','advanced-db-cleaner'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php 
					if(function_exists('is_multisite') && is_multisite() && is_main_site()){
						$blogs_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
						foreach($blogs_ids as $blog_id){
							switch_to_blog($blog_id);
							// Fetch tasks for all blogs
							$cron = _get_cron_array();
							$schedules = wp_get_schedules(); 
							$date_format = _x( 'M j, Y @ G:i', 'Publish box date format', 'advanced-db-cleaner' );
							foreach ( $cron as $timestamp => $cronhooks ) {
								foreach ( (array) $cronhooks as $hook => $events ) {
									foreach ( (array) $events as $key => $event ) {
										$cron[ $timestamp ][ $hook ][ $key ][ 'date' ] = date_i18n( $date_format, $timestamp );
									}
								}
							}
							foreach ( $cron as $timestamp => $cronhooks ) {
								foreach ( (array) $cronhooks as $hook => $events ) {
									foreach ( (array) $events as $event ) { ?>
										<tr>
											<td><?php echo $hook; ?></td>
											<td><?php echo $blog_id; ?></td>
											<td>
												<?php 
													if ( $event[ 'schedule' ] ) {
														echo $schedules [ $event[ 'schedule' ] ][ 'display' ];
													} else {
														?><em><?php _e('One-off event', 'advanced-db-cleaner'); ?></em><?php
													}
												?>
											</td>
											<td><?php echo $event[ 'date' ]; ?></td>
											<td><?php echo $timestamp; ?></td>
											<td class="column-name">
												<div class="squaredFour" style="text-align:left; ">
													<input id="aDBc_<?php echo $blog_id. '|' . $hook; ?>" type="checkbox" name="adbc_cron_hooks[]" value="<?php echo $blog_id. '|' . $hook; ?>" />
													<label for="aDBc_<?php echo $blog_id. '|' . $hook; ?>"></label>
												</div>
											</td>
										</tr>
							<?php	}
								}
							}
							// Switch back to original blog
							restore_current_blog();
						}
					}else{
						// Fetch tasks for single blog
						$cron = _get_cron_array();
						$schedules = wp_get_schedules(); 
						$date_format = _x( 'M j, Y @ G:i', 'Publish box date format', 'advanced-db-cleaner' );
						foreach ( $cron as $timestamp => $cronhooks ) {
							foreach ( (array) $cronhooks as $hook => $events ) {
								foreach ( (array) $events as $key => $event ) {
									$cron[ $timestamp ][ $hook ][ $key ][ 'date' ] = date_i18n( $date_format, $timestamp );
								}
							}
						}
						foreach ( $cron as $timestamp => $cronhooks ) {
							foreach ( (array) $cronhooks as $hook => $events ) {
								foreach ( (array) $events as $event ) { ?>
									<tr>
										<td><?php echo $hook; ?></td>
										<td>
											<?php 
												if ( $event[ 'schedule' ] ) {
													echo $schedules [ $event[ 'schedule' ] ][ 'display' ];
												} else {
													?><em><?php _e('One-off event', 'advanced-db-cleaner'); ?></em><?php
												}
											?>
										</td>
										<td><?php echo $event[ 'date' ]; ?></td>
										<td><?php echo $timestamp; ?></td>
										<td class="column-name">
											<div class="squaredFour" style="text-align:left; ">
												<input id="aDBc_<?php echo $hook; ?>" type="checkbox" name="adbc_cron_hooks[]" value="<?php echo $hook; ?>" />
												<label for="aDBc_<?php echo $hook; ?>"></label>
											</div>
										</td>
									</tr>
						<?php	}
							}
						}
					}
					?>
				</tbody>
			</table>
			<p>
				<input type="hidden" name="aDBc_clean_cron_form" value="" />
				<input type="submit" class="button-primary" value="<?php _e('Clean selected schedules','advanced-db-cleaner'); ?>"  />
			</p>
		</form>
	</div>
</div>