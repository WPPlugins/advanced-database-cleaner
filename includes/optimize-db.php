<div class="marginTop20">
	<div style="float: left;  margin-right:25px; margin-bottom:40px">
		<form action="" method="post">
			<?php if(function_exists('is_multisite') && is_multisite() && is_main_site()){ ?>
				<div style="margin-bottom:15px;">
					<a class="tooltips" href="#">
						<img src="<?php echo  ADB_CLEANER_PLUGIN_DIR . '/images/about.png'?>"/>
						<span>
						<?php
						_e('The plugin has detected that you use a multisite installation. This is your main site from which you can optimize all tables of all sites in your network. Other sites can optimize their own tables only.','advanced-db-cleaner');
						?>
						</span>
					</a>
				</div>
			<?php } ?>
			<table class="widefat" style="width: 400px; border: 1px solid #eee; box-shadow: 0 0px 0px #ccc;">
				<thead style="background:#f0f5fa; border-bottom:1px solid dashed">
					<tr>
						<th scope="col" style="font-size:13px; color:#222; border-bottom: 0px;"><?php _e('Tables to optimize','advanced-db-cleaner'); ?></th>
						<th scope="col" style="text-align: right; color:#222; width: 80px; font-size:13px; border-bottom: 0px;"><?php _e('Lost space','advanced-db-cleaner'); ?></th>
					</tr>
				</thead>
				<tbody id="the-list">
				<?php
					global $wpdb;
					$total_tables_to_optimize = 0;
					$total_lost = 0;
					$prefix = str_replace( '_', '\_', $wpdb->prefix );
					$adbc_sql = "SELECT table_name, data_free FROM information_schema.tables WHERE table_schema = '".DB_NAME."' and data_free > 0";
					if(!is_main_site()){
						$adbc_sql = $adbc_sql . " and table_name LIKE '{$prefix}%'";
					}					
					$result = $wpdb->get_results($adbc_sql);
					foreach($result as $row){
						$total_tables_to_optimize += 1;
						$total_lost += $row->data_free;
						echo "<tr class=''>
							 <td class='column-name'>". $row->table_name ."</td>
							 <td class='column-name' style='text-align:right'>". $row->data_free . ' o' ."</td>
							 </tr>\n";
					}
					if($total_lost == 0){
						echo "<tr class=''>
							 <td class='column-name' style='color: #999'>". __('All tables are optimized!','advanced-db-cleaner') ."</td>
							 <td class='column-name'></td></tr>";
					}
				?>
				</tbody>
				<tfoot style="background:#f0f5fa">
					<tr>
						<th scope="col" style="font-size:13px; font-weight:bold; color:#444; border-top: 0px"><?php _e('Total','advanced-db-cleaner'); ?></th>
						<th scope="col" class="aDBcCount" style="border-top: 0px"><b><?php echo $total_lost .'</b> o'; ?></th>
					</tr>
				</tfoot>
			</table>
			<p>
				<input type="hidden" name="aDBc_optimize_form" value="" />
				<input type="submit" class="button-primary" value="<?php _e('Optimize tables','advanced-db-cleaner'); ?>"  <?php echo $total_lost == 0 ? 'disabled' : ''; ?> />
			</p>
		</form>
	</div>
	<div style="float:left; padding-left:25px; border-left: 1px dashed #ccc; ">	
		<div style="text-align:center; margin-bottom: 30px">
			<?php if($total_lost == 0){ ?>
				<img src="<?php echo ADB_CLEANER_PLUGIN_DIR . '/images/db_clean.png'?>"/>
				<div style="font-color:#ccc; color: #999; font-family: tahoma"><?php _e('Your database is optimized!','advanced-db-cleaner'); ?></div>
			<?php } else { ?>
				<img src="<?php echo ADB_CLEANER_PLUGIN_DIR . '/images/db_not_clean.png'?>"/>
				<div style="font-color:#ccc; color: #999; font-family: tahoma"><b><?php echo $total_tables_to_optimize; ?></b> <?php _e('table(s) should be optimized!','advanced-db-cleaner'); ?></div>		
			<?php }  ?>
		</div>

		<div class="aDBCRightBox" style="border-bottom: 1px solid #eee">
			<div style="font-weight: bold; padding-bottom:12px; color:#111; font-size:13px">&nbsp;<?php _e('Schedule','advanced-db-cleaner'); ?></div>
			<form action="" method="post">
				<select style="height:30px; width: 240px; font-size:13px" name="aDBc_optimize_schedule" id="aDBc_optimize_schedule">
					<option value="no_schedule" <?php echo get_option('aDBc_optimize_schedule') == 'no_schedule' ? 'selected="selected"' : ''; ?>>
					<?php _e('Not scheduled','advanced-db-cleaner');?>
					</option>
					<option value="hourly" <?php echo get_option('aDBc_optimize_schedule') == 'hourly' ? 'selected="selected"' : ''; ?>>
					<?php _e('Run optimization hourly','advanced-db-cleaner');?>
					</option>
					<option value="twicedaily" <?php echo get_option('aDBc_optimize_schedule') == 'twicedaily' ? 'selected="selected"' : ''; ?>>
					<?php _e('Run optimization twice a day','advanced-db-cleaner');?>
					</option>
					<option value="daily" <?php echo get_option('aDBc_optimize_schedule') == 'daily' ? 'selected="selected"' : ''; ?>>
					<?php _e('Run optimization daily','advanced-db-cleaner');?>
					</option>
					<option value="weekly" <?php echo get_option('aDBc_optimize_schedule') == 'weekly' ? 'selected="selected"' : ''; ?>>
					<?php _e('Run optimization weekly','advanced-db-cleaner');?>
					</option>
					<option value="monthly" <?php echo get_option('aDBc_optimize_schedule') == 'monthly' ? 'selected="selected"' : ''; ?>>
					<?php _e('Run optimization monthly','advanced-db-cleaner');?>
					</option>
				</select>	
				<input type="hidden" name="aDBc_optimize_schedule_form" value="" />
				<input style="height:30px" type="submit" class="button-primary" value="<?php _e('Save','advanced-db-cleaner'); ?>" />
			</form>
			<div style="padding-top:15px; color:#111">&nbsp;<?php _e('Next run:','advanced-db-cleaner'); ?> <span style="color: green"><?php echo wp_next_scheduled('aDBc_optimize_scheduler') == true ? date('Y/m/d H:i:s', wp_next_scheduled('aDBc_optimize_scheduler')) : 'Not set'; ?></span></div>
		</div>		

		<div class="aDBCRightBox">
			<div style="font-weight: bold; padding-bottom:5px; color:#111; font-size:13px">&nbsp;<?php _e('Please note that','advanced-db-cleaner'); ?></div>
			<ul style="list-style: disc outside none; padding:0px 10px 0px 10px">
				<li><?php _e('"Optimizing" reduces storage space and improves efficiency when accessing your tables.','advanced-db-cleaner'); ?></li>
				<li><?php _e('Except tables listed in the table above, all your other tables are already optimized.','advanced-db-cleaner'); ?></li>
				<li><?php _e('It is not necessary to run the optimization frequently, just do it from time to time.','advanced-db-cleaner'); ?></li>
			</ul>
		</div>
	</div>
</div>