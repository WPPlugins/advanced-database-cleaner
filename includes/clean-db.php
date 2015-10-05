<form action="" method="post">
	<p>
		<table class="widefat" style="width: 400px; border: 0px; box-shadow: 0 1px 0px #ccc;">
			<thead>
				<tr>
					<th scope="col"><b><?php _e('Type','advanced-db-cleaner'); ?></b></th>
					<th scope="col"><b><?php _e('Count','advanced-db-cleaner'); ?></b></th>
					<th scope="col"><b><?php _e('Select','advanced-db-cleaner'); ?></b></th>
				</tr>
			</thead>
			<tbody id="the-list">
				<tr class="alternate">
					<td class="column-name"><?php _e('Revision','advanced-db-cleaner'); ?></td>
					<td class="column-name aDBcCount"><?php echo adv_db_cleaner_count('revision'); ?></td>
					<td class="column-name">
						<div class="squaredFour">
							<input id="aDBc_check1" type="checkbox" name="adbc_post_type[]" value="revision" />
							<label for="aDBc_check1"></label>
						</div>
					</td>
				</tr>
				<tr class="alternate">
					<td class="column-name"><?php _e('Draft','advanced-db-cleaner'); ?></td>
					<td class="column-name aDBcCount"><?php echo adv_db_cleaner_count('draft'); ?></td>
					<td class="column-name">
						<div class="squaredFour">
							<input id="aDBc_check2" type="checkbox" name="adbc_post_type[]" value="draft" />
							<label for="aDBc_check2"></label>
						</div>
					</td>
				</tr>
				<tr class="alternate">
					<td class="column-name"><?php _e('Auto Draft','advanced-db-cleaner'); ?></td>
					<td class="column-name aDBcCount"><?php echo adv_db_cleaner_count('autodraft'); ?></td>
					<td class="column-name">
						<div class="squaredFour">
							<input id="aDBc_check3" type="checkbox" name="adbc_post_type[]" value="autodraft" />
							<label for="aDBc_check3"></label>
						</div>
					</td>
				</tr>
				<tr class="alternate">
					<td class="column-name"><?php _e('Moderated Comments','advanced-db-cleaner'); ?></td>
					<td class="column-name aDBcCount"><?php echo adv_db_cleaner_count('moderated'); ?></td>
					<td class="column-name">
						<div class="squaredFour">
							<input id="aDBc_check4" type="checkbox" name="adbc_post_type[]" value="moderated" />
							<label for="aDBc_check4"></label>
						</div>
					</td>
				</tr>
				<tr class="alternate">
					<td class="column-name"><?php _e('Spam Comments','advanced-db-cleaner'); ?></td>
					<td class="column-name aDBcCount"><?php echo adv_db_cleaner_count('spam'); ?></td>
					<td class="column-name">
						<div class="squaredFour">
							<input id="aDBc_check5" type="checkbox" name="adbc_post_type[]" value="spam" />
							<label for="aDBc_check5"></label>
						</div>
					</td>
				</tr>
				<tr class="alternate">
					<td class="column-name"><?php _e('Trash Comments','advanced-db-cleaner'); ?></td>
					<td class="column-name aDBcCount"><?php echo adv_db_cleaner_count('trash'); ?></td>
					<td class="column-name">
						<div class="squaredFour">
							<input id="aDBc_check6" type="checkbox" name="adbc_post_type[]" value="trash" />
							<label for="aDBc_check6"></label>
						</div>
					</td>
				</tr>
				<tr class="alternate">
					<td class="column-name"><?php _e('Orphan Postmeta','advanced-db-cleaner'); ?></td>
					<td class="column-name aDBcCount"><?php echo adv_db_cleaner_count('postmeta'); ?></td>
					<td class="column-name">
						<div class="squaredFour">
							<input id="aDBc_check7" type="checkbox" name="adbc_post_type[]" value="postmeta" />
							<label for="aDBc_check7"></label>
						</div>
					</td>
				</tr>
				<tr class="alternate">
					<td class="column-name"><?php _e('Orphan Commentmeta','advanced-db-cleaner'); ?></td>
					<td class="column-name aDBcCount"><?php echo adv_db_cleaner_count('commentmeta'); ?></td>
					<td class="column-name">
						<div class="squaredFour">
							<input id="aDBc_check8" type="checkbox" name="adbc_post_type[]" value="commentmeta" />
							<label for="aDBc_check8"></label>
						</div>
					</td>
				</tr>
				<tr class="alternate">
					<td class="column-name"><?php _e('Orphan Relationships','advanced-db-cleaner'); ?></td>
					<td class="column-name aDBcCount"><?php echo adv_db_cleaner_count('relationships'); ?></td>
					<td class="column-name">
						<div class="squaredFour">
							<input id="aDBc_check9" type="checkbox" name="adbc_post_type[]" value="relationships" />
							<label for="aDBc_check9"></label>
						</div>
					</td>
				</tr>
				<tr class="alternate">
					<td class="column-name"><?php _e('Dashboard Transient Feed','advanced-db-cleaner'); ?></td>
					<td class="column-name aDBcCount"><?php echo adv_db_cleaner_count('feed'); ?></td>
					<td class="column-name">
						<div class="squaredFour">
							<input id="aDBc_check10" type="checkbox" name="adbc_post_type[]" value="feed" />
							<label for="aDBc_check10"></label>
						</div>
					</td>
				</tr>
			</tbody>
		</table>
	</p>
	<p>
		<input type="hidden" name="aDBc_clean_form" value="" />
		<input type="submit" class="button-primary" value="<?php _e('Delete selected items','advanced-db-cleaner'); ?>" />
	</p>
</form>