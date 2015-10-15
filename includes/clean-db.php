<?php
/* Get the totals of unused data */
$aDBc_count_unused = adv_db_cleaner_count();
?>
<div style="color:#888; margin-top:20px;">
	<div style="float: left;  margin-right:30px; margin-bottom:40px">
		<form action="" method="post">
			<table class="widefat" style="width: 400px; border: 0px; box-shadow: 0 1px 0px #ccc;">
				<thead style="background:#f0f5fa; border-bottom:1px solid dashed">
					<tr>
						<th scope="col" style="font-size:13px; border-bottom: 1px dashed #ccc; border-top: 1px solid #eee"><?php _e('Elements to clean','advanced-db-cleaner'); ?></th>
						<th scope="col" style="text-align: right; width: 80px; font-size:13px; border-bottom: 1px dashed #ccc; border-top: 1px solid #eee"><?php _e('Count','advanced-db-cleaner'); ?></th>
						<th scope="col" style="text-align: right; width: 80px; font-size:13px; border-bottom: 1px dashed #ccc; border-top: 1px solid #eee"><?php _e('Select','advanced-db-cleaner'); ?></th>
					</tr>
				</thead>
				<tbody id="the-list">
					<?php 
					if($aDBc_count_unused["total"] == 0){ ?>
						<tr class="alternate">
							<td class="column-name" style="color: #999"><?php _e('Nothing to do!','advanced-db-cleaner'); ?></td>
							<td class="column-name"></td>
							<td class="column-name"></td>
						</tr>
					<?php 
					} else {
						if($aDBc_count_unused["revision"] > 0){ ?>
							<tr class="alternate">
								<td class="column-name"><?php _e('Revision','advanced-db-cleaner'); ?></td>
								<td class="column-name aDBcCount"><?php echo $aDBc_count_unused["revision"]; ?></td>
								<td class="column-name">
									<div class="squaredFour">
										<input id="aDBc_check1" type="checkbox" name="adbc_post_type[]" value="revision" />
										<label for="aDBc_check1"></label>
									</div>
								</td>
							</tr>
						<?php 
						}
						if($aDBc_count_unused["draft"] > 0){ ?>
							<tr class="alternate">
								<td class="column-name"><?php _e('Draft','advanced-db-cleaner'); ?></td>
								<td class="column-name aDBcCount"><?php echo $aDBc_count_unused["draft"]; ?></td>
								<td class="column-name">
									<div class="squaredFour">
										<input id="aDBc_check2" type="checkbox" name="adbc_post_type[]" value="draft" />
										<label for="aDBc_check2"></label>
									</div>
								</td>
							</tr>
						<?php 
						}
						if($aDBc_count_unused["autodraft"] > 0){ ?>
							<tr class="alternate">
								<td class="column-name"><?php _e('Auto Draft','advanced-db-cleaner'); ?></td>
								<td class="column-name aDBcCount"><?php echo $aDBc_count_unused["autodraft"]; ?></td>
								<td class="column-name">
									<div class="squaredFour">
										<input id="aDBc_check3" type="checkbox" name="adbc_post_type[]" value="autodraft" />
										<label for="aDBc_check3"></label>
									</div>
								</td>
							</tr>
						<?php 
						}						
						if($aDBc_count_unused["moderated"] > 0){ ?>
							<tr class="alternate">
								<td class="column-name"><?php _e('Moderated Comments','advanced-db-cleaner'); ?></td>
								<td class="column-name aDBcCount"><?php echo $aDBc_count_unused["moderated"]; ?></td>
								<td class="column-name">
									<div class="squaredFour">
										<input id="aDBc_check4" type="checkbox" name="adbc_post_type[]" value="moderated" />
										<label for="aDBc_check4"></label>
									</div>
								</td>
							</tr>
						<?php 
						}
						if($aDBc_count_unused["spam"] > 0){ ?>
							<tr class="alternate">
								<td class="column-name"><?php _e('Spam Comments','advanced-db-cleaner'); ?></td>
								<td class="column-name aDBcCount"><?php echo $aDBc_count_unused["spam"]; ?></td>
								<td class="column-name">
									<div class="squaredFour">
										<input id="aDBc_check5" type="checkbox" name="adbc_post_type[]" value="spam" />
										<label for="aDBc_check5"></label>
									</div>
								</td>
							</tr>
						<?php 
						}
						if($aDBc_count_unused["trash"] > 0){ ?>
							<tr class="alternate">
								<td class="column-name"><?php _e('Trash Comments','advanced-db-cleaner'); ?></td>
								<td class="column-name aDBcCount"><?php echo $aDBc_count_unused["trash"]; ?></td>
								<td class="column-name">
									<div class="squaredFour">
										<input id="aDBc_check6" type="checkbox" name="adbc_post_type[]" value="trash" />
										<label for="aDBc_check6"></label>
									</div>
								</td>
							</tr>
						<?php 
						}

						if($aDBc_count_unused["postmeta"] > 0){ ?>
							<tr class="alternate">
								<td class="column-name"><?php _e('Orphan Postmeta','advanced-db-cleaner'); ?></td>
								<td class="column-name aDBcCount"><?php echo $aDBc_count_unused["postmeta"]; ?></td>
								<td class="column-name">
									<div class="squaredFour">
										<input id="aDBc_check7" type="checkbox" name="adbc_post_type[]" value="postmeta" />
										<label for="aDBc_check7"></label>
									</div>
								</td>
							</tr>
						<?php 
						}
						if($aDBc_count_unused["commentmeta"] > 0){ ?>
							<tr class="alternate">
								<td class="column-name"><?php _e('Orphan Commentmeta','advanced-db-cleaner'); ?></td>
								<td class="column-name aDBcCount"><?php echo $aDBc_count_unused["commentmeta"]; ?></td>
								<td class="column-name">
									<div class="squaredFour">
										<input id="aDBc_check8" type="checkbox" name="adbc_post_type[]" value="commentmeta" />
										<label for="aDBc_check8"></label>
									</div>
								</td>
							</tr>
						<?php 
						}
						if($aDBc_count_unused["relationships"] > 0){ ?>
							<tr class="alternate">
								<td class="column-name"><?php _e('Orphan Relationships','advanced-db-cleaner'); ?></td>
								<td class="column-name aDBcCount"><?php echo $aDBc_count_unused["relationships"]; ?></td>
								<td class="column-name">
									<div class="squaredFour">
										<input id="aDBc_check9" type="checkbox" name="adbc_post_type[]" value="relationships" />
										<label for="aDBc_check9"></label>
									</div>
								</td>
							</tr>
						<?php 
						}
						if($aDBc_count_unused["feed"] > 0){ ?>
							<tr class="alternate">
								<td class="column-name"><?php _e('Dashboard Transient Feed','advanced-db-cleaner'); ?></td>
								<td class="column-name aDBcCount"><?php echo $aDBc_count_unused["feed"]; ?></td>
								<td class="column-name">
									<div class="squaredFour">
										<input id="aDBc_check10" type="checkbox" name="adbc_post_type[]" value="feed" />
										<label for="aDBc_check10"></label>
									</div>
								</td>
							</tr>
						<?php 
						}
					} ?>
				</tbody>
				<tfoot style="background:#f0f5fa">
					<tr>
						<th scope="col" style="font-size:13px; border-top: 1px dashed #ccc"><?php _e('Total','advanced-db-cleaner'); ?></th>
						<th scope="col" class="aDBcCount" style="border-top: 1px dashed #ccc"><b><?php echo $aDBc_count_unused["total"]; ?></b></th>
						<th scope="col" style="border-top: 1px dashed #ccc"></th>
					</tr>
				</tfoot>			
			</table>
			<p>
				<input type="hidden" name="aDBc_clean_form" value="" />
				<input type="submit" class="button-primary" value="<?php _e('Clean selected items','advanced-db-cleaner'); ?>"  <?php echo $aDBc_count_unused["total"] == 0 ? 'disabled' : ''; ?> />
			</p>
		</form>
	</div>
	<div style="float:left; padding-left:20px; border-left: 1px dashed #ccc; ">
		<div style="text-align:center;">
			<?php if($aDBc_count_unused["total"] == 0){ ?>
				<img src="<?php echo plugins_url() .'/'. dirname(dirname(plugin_basename(__FILE__))) . '/images/db_clean.png'?>"/>
				<div style="font-color:#ccc; color: #999; font-family: tahoma">Your database is clean!</div>
			<?php } else { ?>
				<img src="<?php echo plugins_url() .'/'. dirname(dirname(plugin_basename(__FILE__))) . '/images/db_not_clean.png'?>"/>
				<div style="font-color:#ccc; color: #999; font-family: tahoma"><b><?php echo $aDBc_count_unused["total"]; ?></b> element(s) <br/>should be cleaned!</div>		
			<?php }  ?>
		</div>
		<div class="box-notice">
			Cleaning your database helps you saving space by removing unused data. The plugin has detected that the following items are already cleaned:
			<div style="margin-top:15px">
				<?php 
				if($aDBc_count_unused["revision"] == 0)
					echo "<div class='already-cleaned'>". __('Revision','advanced-db-cleaner') ."</div>";
				if($aDBc_count_unused["draft"] == 0)
					echo "<div class='already-cleaned'>". __('Draft','advanced-db-cleaner') ."</div>"; 
				if($aDBc_count_unused["autodraft"] == 0)
					echo "<div class='already-cleaned'>". __('Auto Draft','advanced-db-cleaner') ."</div>"; 
				if($aDBc_count_unused["postmeta"] == 0)
					echo "<div class='already-cleaned'>". __('Postmeta','advanced-db-cleaner') ."</div>"; 					
				if($aDBc_count_unused["moderated"] == 0)
					echo "<div class='already-cleaned'>". __('Moderated Comments','advanced-db-cleaner') ."</div>"; 
				if($aDBc_count_unused["spam"] == 0)
					echo "<div class='already-cleaned'>". __('Spam Comments','advanced-db-cleaner') ."</div>"; 
				if($aDBc_count_unused["trash"] == 0)
					echo "<div class='already-cleaned'>". __('Trash Comments','advanced-db-cleaner') ."</div>"; 
				if($aDBc_count_unused["commentmeta"] == 0)
					echo "<div class='already-cleaned'>". __('Commentmeta','advanced-db-cleaner') ."</div>"; 
				if($aDBc_count_unused["relationships"] == 0)
					echo "<div class='already-cleaned'>". __('Relationships','advanced-db-cleaner') ."</div>"; 
				if($aDBc_count_unused["feed"] == 0)
					echo "<div class='already-cleaned'>". __('Dashboard Transient Feed','advanced-db-cleaner') ."</div>"; 
				?>
				<div style="clear:both"></div>
			</div>
		</div>
	</div>
</div>
<?php
//Once we have displayed counts, delete the array to optimize usage of memory.
unset($aDBc_count_unused);
?>