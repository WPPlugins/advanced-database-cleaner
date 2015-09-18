<?php

add_action('admin_menu', 'adv_db_cleaner_admin');

// Add 'DB Cleaner' option page under settings in wordpress admin pannel
function adv_db_cleaner_admin() {
	add_options_page('Advanced DB Cleaner Options', 'DB Cleaner','manage_options', __FILE__, 'adv_db_cleaner_page');
}

function adv_db_cleaner_page(){
?>
	<div class="wrap">
		<h2>Advanced DB Cleaner</h2>
		<?php
		// Clean database and print result message
		$adv_db_cleaner_message = '';
		if(isset($_POST['adbc_post_type'])){
			foreach($_POST['adbc_post_type'] as $adbc_type) {
				adv_db_cleaner($adbc_type);
			}
			$adv_db_cleaner_message = __('Your database is now cleaned.', 'advanced-db-cleaner');
		}
		if($adv_db_cleaner_message != ''){
			echo '<div id="adbc_message" class="updated settings-error notice is-dismissible"><p><strong>' . $adv_db_cleaner_message . '</strong></p></div>';
		}
		?>
		<form action="" method="post">
			<p>
				<table class="widefat" style="width:419px;">
					<thead>
						<tr>
							<th scope="col"><?php _e('Type','advanced-db-cleaner'); ?></th>
							<th scope="col"><?php _e('Count','advanced-db-cleaner'); ?></th>
							<th scope="col"><?php _e('Select','advanced-db-cleaner'); ?></th>
						</tr>
					</thead>
					<tbody id="the-list">
						<tr class="alternate">
							<td class="column-name"><?php _e('Revision','advanced-db-cleaner'); ?></td>
							<td class="column-name"><?php echo adv_db_cleaner_count('revision'); ?></td>
							<td class="column-name"><input type="checkbox" name="adbc_post_type[]" value="revision"></td>
						</tr>
						<tr>
							<td class="column-name"><?php _e('Draft','advanced-db-cleaner'); ?></td>
							<td class="column-name"><?php echo adv_db_cleaner_count('draft'); ?></td>
							<td class="column-name"><input type="checkbox" name="adbc_post_type[]" value="draft"></td>
						</tr>
						<tr class="alternate">
							<td class="column-name"><?php _e('Auto Draft','advanced-db-cleaner'); ?></td>
							<td class="column-name"><?php echo adv_db_cleaner_count('autodraft'); ?></td>
							<td class="column-name"><input type="checkbox" name="adbc_post_type[]" value="autodraft"></td>
						</tr>
						<tr>
							<td class="column-name"><?php _e('Moderated Comments','advanced-db-cleaner'); ?></td>
							<td class="column-name"><?php echo adv_db_cleaner_count('moderated'); ?></td>
							<td class="column-name"><input type="checkbox" name="adbc_post_type[]" value="moderated"></td>
						</tr>
						<tr class="alternate">
							<td class="column-name"><?php _e('Spam Comments','advanced-db-cleaner'); ?></td>
							<td class="column-name"><?php echo adv_db_cleaner_count('spam'); ?></td>
							<td class="column-name"><input type="checkbox" name="adbc_post_type[]" value="spam"></td>
						</tr>
						<tr>
							<td class="column-name"><?php _e('Trash Comments','advanced-db-cleaner'); ?></td>
							<td class="column-name"><?php echo adv_db_cleaner_count('trash'); ?></td>
							<td class="column-name"><input type="checkbox" name="adbc_post_type[]" value="trash"></td>
						</tr>
						<tr class="alternate">
							<td class="column-name"><?php _e('Orphan Postmeta','advanced-db-cleaner'); ?></td>
							<td class="column-name"><?php echo adv_db_cleaner_count('postmeta'); ?></td>
							<td class="column-name"><input type="checkbox" name="adbc_post_type[]" value="postmeta"></td>
						</tr>
						<tr>
							<td class="column-name"><?php _e('Orphan Commentmeta','advanced-db-cleaner'); ?></td>
							<td class="column-name"><?php echo adv_db_cleaner_count('commentmeta'); ?></td>
							<td class="column-name"><input type="checkbox" name="adbc_post_type[]" value="commentmeta"></td>
						</tr>
						<tr class="alternate">
							<td class="column-name"><?php _e('Orphan Relationships','advanced-db-cleaner'); ?></td>
							<td class="column-name"><?php echo adv_db_cleaner_count('relationships'); ?></td>
							<td class="column-name"><input type="checkbox" name="adbc_post_type[]" value="relationships"></td>
						</tr>
						<tr>
							<td class="column-name"><?php _e('Dashboard Transient Feed','advanced-db-cleaner'); ?></td>
							<td class="column-name"><?php echo adv_db_cleaner_count('feed'); ?></td>
							<td class="column-name"><input type="checkbox" name="adbc_post_type[]" value="feed"></td>
						</tr>
					</tbody>
				</table>
			</p>
			<p>
				<input type="submit" class="button-primary" value="<?php _e('Delete selected items','advanced-db-cleaner'); ?>" />
			</p>
		</form>
		<br />
		<div style="text-align:center">&copy; <?php echo date("Y"); ?> By Symptote</div>
	</div>
<?php 
}

function adv_db_cleaner($type){
	global $wpdb;
	switch($type){
		case "revision":
			$adbc_sql = "DELETE FROM $wpdb->posts WHERE post_type = 'revision'";
			$wpdb->query($adbc_sql);
			break;
		case "draft":
			$adbc_sql = "DELETE FROM $wpdb->posts WHERE post_status = 'draft'";
			$wpdb->query($adbc_sql);
			break;
		case "autodraft":
			$adbc_sql = "DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'";
			$wpdb->query($adbc_sql);
			break;
		case "moderated":
			$adbc_sql = "DELETE FROM $wpdb->comments WHERE comment_approved = '0'";
			$wpdb->query($adbc_sql);
			break;
		case "spam":
			$adbc_sql = "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'";
			$wpdb->query($adbc_sql);
			break;
		case "trash":
			$adbc_sql = "DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'";
			$wpdb->query($adbc_sql);
			break;
		case "postmeta":
			$adbc_sql = "DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL";
			//$adbc_sql = "DELETE FROM $wpdb->postmeta WHERE NOT EXISTS ( SELECT * FROM $wpdb->posts WHERE $wpdb->postmeta.post_id = $wpdb->posts.ID )";
			$wpdb->query($adbc_sql);
			break;
		case "commentmeta":
			$adbc_sql = "DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)";
			$wpdb->query($adbc_sql);
			break;
		case "relationships":
			$adbc_sql = "DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)";
			$wpdb->query($adbc_sql);
			break;
		case "feed":
			$adbc_sql = "DELETE FROM $wpdb->options WHERE option_name LIKE '_site_transient_browser_%' OR option_name LIKE '_site_transient_timeout_browser_%' OR option_name LIKE '_transient_feed_%' OR option_name LIKE '_transient_timeout_feed_%'";
			$wpdb->query($adbc_sql);
			break;
	}
}

function adv_db_cleaner_count($type){
	global $wpdb;
	switch($type){
		case "revision":
			$adbc_sql = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'";
			$count = $wpdb->get_var($adbc_sql);
			break;
		case "draft":
			$adbc_sql = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'draft'";
			$count = $wpdb->get_var($adbc_sql);
			break;
		case "autodraft":
			$adbc_sql = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'";
			$count = $wpdb->get_var($adbc_sql);
			break;
		case "moderated":
			$adbc_sql = "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = '0'";
			$count = $wpdb->get_var($adbc_sql);
			break;
		case "spam":
			$adbc_sql = "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'";
			$count = $wpdb->get_var($adbc_sql);
			break;
		case "trash":
			$adbc_sql = "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'trash'";
			$count = $wpdb->get_var($adbc_sql);
			break;
		case "postmeta":
			$adbc_sql = "SELECT COUNT(*) FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL";
			//$adbc_sql = "SELECT COUNT(*) FROM $wpdb->postmeta WHERE NOT EXISTS ( SELECT * FROM $wpdb->posts WHERE $wpdb->postmeta.post_id = $wpdb->posts.ID )";
			$count = $wpdb->get_var($adbc_sql);
			break;
		case "commentmeta":
			$adbc_sql = "SELECT COUNT(*) FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)";
			$count = $wpdb->get_var($adbc_sql);
			break;
		case "relationships":
			$adbc_sql = "SELECT COUNT(*) FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)";
			$count = $wpdb->get_var($adbc_sql);
			break;
		case "feed":
			$adbc_sql = "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE '_site_transient_browser_%' OR option_name LIKE '_site_transient_timeout_browser_%' OR option_name LIKE '_transient_feed_%' OR option_name LIKE '_transient_timeout_feed_%'";
			$count = $wpdb->get_var($adbc_sql);
			break;
	}
	return $count;
}
?>