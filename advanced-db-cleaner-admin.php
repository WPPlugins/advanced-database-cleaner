<?php
add_action('admin_init', 'adv_db_cleaner_register_styles' );
add_action('admin_menu', 'adv_db_cleaner_add_admin_menu');

// Add 'DB Cleaner' to Wordpress settings menu
function adv_db_cleaner_add_admin_menu() {
	$hook_adv_db_cleaner = add_options_page('Advanced DB Cleaner Options', 'Database Cleaner','manage_options', __FILE__, 'adv_db_cleaner_page');
	add_action('admin_print_styles-' . $hook_adv_db_cleaner, 'adv_db_cleaner_enqueue_styles');
}

/* Register stylesheet. */
function adv_db_cleaner_register_styles() {
	wp_register_style('adv_db_cleaner_css', plugins_url() .'/'. dirname(plugin_basename(__FILE__)) . '/css/style.css');
}

/* Enqueue stylesheet. */
function adv_db_cleaner_enqueue_styles() {
   wp_enqueue_style( 'adv_db_cleaner_css' );
}

/* The admin page content */
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
		
		if(isset($_POST['aDBc_optimize_form'])){
			adv_db_cleaner_optimize();
			$adv_db_cleaner_message = __('Your database is now optimized.', 'advanced-db-cleaner');
		}		
		
		if($adv_db_cleaner_message != ''){
			echo '<div id="adbc_message" class="updated settings-error notice is-dismissible"><p><strong>' . $adv_db_cleaner_message . '</strong></p></div>';
		}
		?>
		
		
		<div class="tabBox">
			<?php
				$aDBc_tab_list = array(
							array("id"=>"aDBc-clean","title"=>"Clean Database"),
							array("id"=>"aDBc-optimize","title"=>"Optimize Database")
						);

				foreach ($aDBc_tab_list as $key => $value){
					$checked = "";
					if((!isset($_POST["aDBc_clean_form"]) && !isset($_POST["aDBc_optimize_form"]) && $value["id"] == "aDBc-clean") 
						|| (isset($_POST["aDBc_optimize_form"]) && $value["id"] == "aDBc-optimize") 
						|| (isset($_POST["aDBc_clean_form"]) && $value["id"] == "aDBc-clean")){
						$checked = 'checked = "checked"';
					}
					echo '<input '.$checked.' type="radio" id="'.$value["id"].'" name="tabGroup1">'."\n";
					echo '<label for="'.$value["id"].'">'.$value["title"].'</label>'."\n";
				}
			?>
		
			<div class="tab1">
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
			</div>
			
			<div class="tab2">
				<form action="" method="post">
					<p>
						<table class="widefat" style="border: 0px; box-shadow: 0 1px 0px #ccc;">
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
			</div>
		</div>
		
		<div class="aDBcSidebar">
			<div>
				<center><h2>Advanced Database Cleaner</h2></center>
				<!--<p>See what is new in <a href="#">version 4.3.9</a></p>-->
				
				<p>Please donate to keep the development alive. Every contribution will be more than welcome.</p>
				<p style="text-align:right">Thank you!</p>
				<center>
					<!-- Donate -->
					<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_top">
					<input type="hidden" name="cmd" value="_donations">
					<input type="hidden" name="business" value="younes.jfr@gmail.com">
					<input type="hidden" name="lc" value="US">
					<input type="hidden" name="item_name" value="Advanced Database Cleaner">
					<input type="hidden" name="no_note" value="0">
					<input type="hidden" name="currency_code" value="USD">
					<input type="hidden" name="bn" value="PP-DonationsBF:btn_donate_LG.gif:NonHostedGuest">
					<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
					<img alt="" border="0" src="https://www.paypalobjects.com/fr_XC/i/scr/pixel.gif" width="1" height="1">
					</form>
				</center>

				
				
			</div>
			<div style="background: #1A9AC9; border-top: 1px solid #ccc; text-align: center; color: #fff">
				&copy; <?php echo date("Y"); ?> Created by <b>Younes JFR</b>
			</div>
		</div>

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

function adv_db_cleaner_optimize(){
	global $wpdb;
	$adbc_sql = 'SHOW TABLE STATUS FROM `'.DB_NAME.'`';
	$result = $wpdb->get_results($adbc_sql);
	foreach($result as $row){
		$adbc_sql = 'OPTIMIZE TABLE '.$row->Name;
		$wpdb->query($adbc_sql);
	}
}
?>