<div style="color:#888; margin-top:20px;">
	<div style="float: left;  margin-right:30px; margin-bottom:40px">
		<form action="" method="post">
			<table class="widefat" style="width: 400px; border: 0px; box-shadow: 0 1px 0px #ccc;">
				<thead style="background:#f0f5fa; border-bottom:1px solid dashed">
					<tr>
						<th scope="col" style="font-size:13px; border-bottom: 1px dashed #ccc; border-top: 1px solid #eee"><?php _e('Tables to optimize','advanced-db-cleaner'); ?></th>
						<th scope="col" style="text-align: right; width: 80px; font-size:13px; border-bottom: 1px dashed #ccc; border-top: 1px solid #eee"><?php _e('Lost space','advanced-db-cleaner'); ?></th>
					</tr>
				</thead>
				<tbody id="the-list">
				<?php
					global $wpdb;
					$total_tables_to_optimize = 0;
					$total_lost = 0;
					$adbc_sql = 'SHOW TABLE STATUS FROM `'.DB_NAME.'`';
					$result = $wpdb->get_results($adbc_sql);
					foreach($result as $row){
						$table_size = ($row->Data_length + $row->Index_length) / 1024;
						if($row->Data_free > 0){
							$total_tables_to_optimize += 1;
							$total_lost += $row->Data_free;
							echo "<tr class='alternate'>
								 <td class='column-name'>". $row->Name ."</td>
								 <td class='column-name' style='text-align:right'>". $row->Data_free . ' o' ."</td>
								 </tr>\n";
						}
					}
					
					if($total_lost == 0){
						echo "<tr class='alternate'>
							 <td class='column-name' style='color: #999'>". __('Nothing to do!','advanced-db-cleaner') ."</td>
							 <td class='column-name'></td></tr>";
					}
				?>
				</tbody>
				<tfoot style="background:#f0f5fa">
					<tr>
						<th scope="col" style="font-size:13px; border-top: 1px dashed #ccc"><?php _e('Total','advanced-db-cleaner'); ?></th>
						<th scope="col" class="aDBcCount" style="border-top: 1px dashed #ccc"><b><?php echo $total_lost .'</b> o'; ?></th>
					</tr>
				</tfoot>
			</table>
			<p>
				<input type="hidden" name="aDBc_optimize_form" value="" />
				<input type="submit" class="button-primary" value="<?php _e('Optimize database','advanced-db-cleaner'); ?>"  <?php echo $total_lost == 0 ? 'disabled' : ''; ?> />
			</p>
		</form>
	</div>
	<div style="float:left; padding-left:20px; border-left: 1px dashed #ccc; ">	
		<div style="text-align:center;">
			<?php if($total_lost == 0){ ?>
				<img src="<?php echo plugins_url() .'/'. dirname(dirname(plugin_basename(__FILE__))) . '/images/db_clean.png'?>"/>
				<div style="font-color:#ccc; color: #999; font-family: tahoma">Your database is optimized!</div>
			<?php } else { ?>
				<img src="<?php echo plugins_url() .'/'. dirname(dirname(plugin_basename(__FILE__))) . '/images/db_not_clean.png'?>"/>
				<div style="font-color:#ccc; color: #999; font-family: tahoma"><b><?php echo $total_tables_to_optimize; ?></b> table(s) <br/>should be optimized!</div>		
			<?php }  ?>
		</div>
		<div class="box-notice">
			<ul style="list-style: square outside none; padding:0px 10px 0px 10px">
				<li>"Optimizing" reduces storage space and improves efficiency when accessing your tables.</li>
				<li>Except tables listed in the table above, all your other tables are already optimized.</li>
				<li>Please note that is not necessary to run the optimization frequently, just do it from time to time.</li>
			</ul>
		</div>
	</div>
</div>