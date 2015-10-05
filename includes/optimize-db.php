<form action="" method="post">
	<p>
		<table class="widefat" style="width: 600px; border: 0px; box-shadow: 0 1px 0px #ccc;">
			<thead>
				<tr>
					<th scope="col"><b><?php _e('#','advanced-db-cleaner'); ?></b></th>
					<th scope="col"><b><?php _e('Table','advanced-db-cleaner'); ?></b></th>
					<th scope="col" style="text-align:right; width:80px"><b><?php _e('Rows','advanced-db-cleaner'); ?></b></th>
					<th scope="col" style="text-align:right; width:100px"><b><?php _e('Size','advanced-db-cleaner'); ?></b></th>
					<th scope="col" style="text-align:right; width:80px"><b><?php _e('Lost','advanced-db-cleaner'); ?></b></th>
				</tr>
			</thead>
			<tbody id="the-list">
			<?php
				global $wpdb;
				$aDBc_tableID = 0;
				$total_rows = 0;
				$total_size = 0;
				$total_lost = 0;
				$adbc_sql = 'SHOW TABLE STATUS FROM `'.DB_NAME.'`';
				$result = $wpdb->get_results($adbc_sql);
				foreach($result as $row){
					$aDBc_tableID += 1;
					$total_rows += $row->Rows;
					$table_size = ($row->Data_length + $row->Index_length) / 1024;
					$total_size += $table_size;
					$total_lost += $row->Data_free;
					echo "<tr class='alternate'>
							<td class='column-name' style='width:30px'>". $aDBc_tableID ."</td>
							<td class='column-name'>". $row->Name ."</td>
							<td class='column-name' style='text-align:right'>". $row->Rows ."</td>
							<td class='column-name' style='text-align:right'>". sprintf("%0.2f", $table_size) ." KB</td>
							<td class='column-name' style='text-align:right'>". ($row->Data_free > 0 ? $row->Data_free . ' o' : '--') ."</td>
						</tr>\n";
				}
			?>
			</tbody>
			<tfoot>
				<tr class="alternate">
					<th scope="col" style="font-size:12px"><?php _e('Total','advanced-db-cleaner'); ?></th>
					<th scope="col" style="text-align:left"><b><?php echo $aDBc_tableID; ?></b></th>
					<th scope="col" style="text-align:right"><b><?php echo $total_rows; ?></b></th>
					<th scope="col" style="text-align:right"><b><?php echo sprintf("%0.2f", $total_size).'</b> KB'; ?></th>
					<th scope="col" style="text-align:right"><b><?php echo $total_lost .'</b> o'; ?></th>
				</tr>
			</tfoot>
		</table>
	</p>
	<p>
		<input type="hidden" name="aDBc_optimize_form" value="" />
		<input type="submit" class="button-primary" value="<?php _e('Optimize database','advanced-db-cleaner'); ?>" />
	</p>
</form>