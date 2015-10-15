<?php
add_action('admin_init', 'adv_db_cleaner_register_styles' );
add_action('admin_menu', 'adv_db_cleaner_add_admin_menu');
/* --- Add 'DB Cleaner' to Wordpress settings menu ---------- */
function adv_db_cleaner_add_admin_menu() {
	$hook_adv_db_cleaner = add_options_page('Advanced DB Cleaner Options', 'Database Cleaner','manage_options', __FILE__, 'adv_db_cleaner_page');
	add_action('admin_print_styles-' . $hook_adv_db_cleaner, 'adv_db_cleaner_enqueue_styles');
}
/* --- Register stylesheet ---------------------------------- */
function adv_db_cleaner_register_styles() {
	wp_register_style('adv_db_cleaner_css', plugins_url() .'/'. dirname(plugin_basename(__FILE__)) . '/css/style.css');
}
/* --- Enqueue stylesheet ----------------------------------- */
function adv_db_cleaner_enqueue_styles() {
   wp_enqueue_style( 'adv_db_cleaner_css' );
}
/* --- The admin page content ------------------------------- */
function adv_db_cleaner_page(){
?>
	<div class="wrap">
		<h2>Advanced DB Cleaner</h2>
		<?php
		// If one of the forms is sent, then process data and print results
		$adv_db_cleaner_message = '';
		$adv_db_cleaner_message_class = 'updated settings-error notice is-dismissible';
		if(isset($_POST['adbc_post_type'])){
			foreach($_POST['adbc_post_type'] as $adbc_type) {
				adv_db_cleaner($adbc_type);
			}
			$adv_db_cleaner_message = __('Database cleaned successfully!', 'advanced-db-cleaner');
		}elseif(isset($_POST['aDBc_optimize_form'])){
			adv_db_cleaner_optimize();
			$adv_db_cleaner_message = __('Database optimized successfully!', 'advanced-db-cleaner');
		}elseif(isset($_POST['aDBc_reset_form'])){
			if($_POST['aDBc_reset_comfirmation'] == 'reset'){
				adv_db_cleaner_reset();
			}else{
				$adv_db_cleaner_message = __('Please type the word "reset" correctly in the text box below.', 'advanced-db-cleaner');
				$adv_db_cleaner_message_class = 'error';
			}
		}
		// Print message
		if($adv_db_cleaner_message != ''){
			echo '<div id="adbc_message" class="'. $adv_db_cleaner_message_class .'"><p><strong>' . $adv_db_cleaner_message . '</strong></p></div>';
		}
		?>
		<div style="margin-right:300px">
			<div class="tabBox">
				<?php
					$aDBc_tab_list = array(
								array("id"=>"aDBc-clean","title"=>"Clean Database"),
								array("id"=>"aDBc-optimize","title"=>"Optimize Database"),
								array("id"=>"aDBc-reset","title"=>"Reset Database")
							);
					foreach ($aDBc_tab_list as $key => $value){
						$checked = "";
						if((!isset($_POST["aDBc_clean_form"]) && !isset($_POST["aDBc_optimize_form"]) && !isset($_POST["aDBc_reset_form"]) && $value["id"] == "aDBc-clean") 
							|| (isset($_POST["aDBc_optimize_form"]) && $value["id"] == "aDBc-optimize") 
							|| (isset($_POST["aDBc_clean_form"]) && $value["id"] == "aDBc-clean")
							|| (isset($_POST["aDBc_reset_form"]) && $value["id"] == "aDBc-reset")){
							$checked = 'checked = "checked"';
						}
						echo '<input '.$checked.' type="radio" id="'.$value["id"].'" name="tabGroup1">'."\n";
						echo '<label for="'.$value["id"].'">'.$value["title"].'</label>'."\n";
					}
				?>
				<div class="tab1">
					<?php include_once 'includes/clean-db.php'; ?>
				</div>
				<div class="tab2">
					<?php include_once 'includes/optimize-db.php'; ?>
				</div>
				<div class="tab3">
					<?php include_once 'includes/reset-db.php'; ?>
				</div>
			</div>
			<div class="aDBcSidebar">
				<?php include_once 'includes/sidebar.php'; ?>
			</div>
		</div>
	</div>
<?php 
}
include_once 'includes/functions.php';
?>