<?php
defined( 'ABSPATH' ) or die( 'No direct access allowed' );

/*
Plugin Name: Advanced Database Cleaner
Description: Clean up your database by deleting unused data such as 'revision', optimize your database, or reset it to its initial state, etc.
Version: 1.3.5
Author: Younes JFR.
Contributors: symptote
Text Domain: advanced-db-cleaner
Domain Path: /languages/
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

/********************************************************************
* Define common variables
********************************************************************/
if (!defined("ADB_CLEANER_PLUGIN_VERSION")) 	define("ADB_CLEANER_PLUGIN_VERSION",  "1.3.5");
if (!defined("ADB_CLEANER_PLUGIN_BASENAME")) 	define("ADB_CLEANER_PLUGIN_BASENAME",  plugin_basename(__FILE__));
if (!defined("ADB_CLEANER_PLUGIN_DIR")) 		define("ADB_CLEANER_PLUGIN_DIR",  plugins_url() .'/'. dirname(plugin_basename(__FILE__)));

/********************************************************************
* Add 'settings' link under the plugin name in plugins page
********************************************************************/
add_filter('plugin_action_links_' . ADB_CLEANER_PLUGIN_BASENAME, 'db_cleaner_add_action_links');
function db_cleaner_add_action_links($links){
	$adc_settings_link = '<a href = "options-general.php?page=' . ADB_CLEANER_PLUGIN_BASENAME . '">' . __('Settings', 'advanced-db-cleaner') . '</a>';
	array_unshift($links, $adc_settings_link);
	return $links;
}

/********************************************************************
* Add 'Database Cleaner' to Wordpress settings menu
********************************************************************/
add_action('admin_menu', 'adv_db_cleaner_add_admin_menu');
function adv_db_cleaner_add_admin_menu() {
	$hook_adv_db_cleaner = add_options_page('Advanced DB Cleaner Options', 'Database Cleaner','manage_options', __FILE__, 'adv_db_cleaner_page');
	add_action('admin_print_styles-' . $hook_adv_db_cleaner, 'adv_db_cleaner_enqueue_styles');
}

/********************************************************************
* Register stylesheet
********************************************************************/
add_action('admin_init', 'adv_db_cleaner_register_styles' );
function adv_db_cleaner_register_styles() {
	wp_register_style('adv_db_cleaner_css', ADB_CLEANER_PLUGIN_DIR . '/css/style.css');
}

/********************************************************************
* Enqueue stylesheet
********************************************************************/
function adv_db_cleaner_enqueue_styles() {
   wp_enqueue_style( 'adv_db_cleaner_css' );
}

/******************************************************************************************
* The scheduler
* Get more info here: http://codex.wordpress.org/Plugin_API/Filter_Reference/cron_schedules
******************************************************************************************/
add_filter('cron_schedules', 'aDBc_additional_schedules');
function aDBc_additional_schedules($schedules){
	// Add weekly schedule
	$schedules['weekly'] = array(
		'interval' => 604800,
		'display'  => __('Once Weekly')
	);
	// Add monthly schedule
	$schedules['monthly'] = array(
		'interval' => 2635200,
		'display'  => __('Once a month')
	);
	return $schedules;
}

/********************************************************************
* (RE)-schedule tasks after (RE)-activation or update of the plugin
********************************************************************/
register_activation_hook(__FILE__, 'aDBc_activate_plugin');
add_action('aDBc_optimize_scheduler', 'adv_db_cleaner_optimize');
add_action('aDBc_clean_scheduler', 'aDBc_cleanAll');
function aDBc_activate_plugin($network_wide){
	global $wpdb;
	if(function_exists('is_multisite') && is_multisite() && $network_wide){
		$blogs_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
		foreach($blogs_ids as $blog_id){
			switch_to_blog($blog_id);
			$aDBc_optimize_schedule = get_option('aDBc_optimize_schedule');
			if($aDBc_optimize_schedule && $aDBc_optimize_schedule != 'no_schedule'){
				if(!wp_next_scheduled('aDBc_optimize_scheduler'))
					wp_schedule_event(time()+60, $aDBc_optimize_schedule, 'aDBc_optimize_scheduler');
			}
			$aDBc_clean_schedule = get_option('aDBc_clean_schedule');
			if($aDBc_clean_schedule && $aDBc_clean_schedule != 'no_schedule'){
				if(!wp_next_scheduled('aDBc_clean_scheduler'))
					wp_schedule_event(time()+60, $aDBc_clean_schedule, 'aDBc_clean_scheduler');
			}
			restore_current_blog();
		}
	}else{
		$aDBc_optimize_schedule = get_option('aDBc_optimize_schedule');
		if($aDBc_optimize_schedule && $aDBc_optimize_schedule != 'no_schedule'){
			if(!wp_next_scheduled('aDBc_optimize_scheduler'))
				wp_schedule_event(time()+60, $aDBc_optimize_schedule, 'aDBc_optimize_scheduler');
		}
		$aDBc_clean_schedule = get_option('aDBc_clean_schedule');
		if($aDBc_clean_schedule && $aDBc_clean_schedule != 'no_schedule'){
			if(!wp_next_scheduled('aDBc_clean_scheduler'))
				wp_schedule_event(time()+60, $aDBc_clean_schedule, 'aDBc_clean_scheduler');
		}	
	}
}

/********************************************************************
* Clear current scheduled tasks (if any) when deactivated
********************************************************************/
register_deactivation_hook(__FILE__, 'aDBc_deactivate_plugin' );
function aDBc_deactivate_plugin($network_wide){
	global $wpdb;
	if(function_exists('is_multisite') && is_multisite() && $network_wide){
		$blogs_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
		foreach($blogs_ids as $blog_id){
			switch_to_blog($blog_id);
			wp_clear_scheduled_hook('aDBc_optimize_scheduler');
			wp_clear_scheduled_hook('aDBc_clean_scheduler');
			restore_current_blog();
		}
	}else{
		wp_clear_scheduled_hook('aDBc_optimize_scheduler');
		wp_clear_scheduled_hook('aDBc_clean_scheduler');
	}
}

/********************************************************************
* Clear scheduled tasks + options if UNINSTALL
********************************************************************/
register_uninstall_hook(__FILE__, 'aDBc_uninstall');
function aDBc_uninstall(){
	global $wpdb;
	if(function_exists('is_multisite') && is_multisite()){
		$blogs_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
		foreach($blogs_ids as $blog_id){
			switch_to_blog($blog_id);
			delete_option('aDBc_optimize_schedule');
			delete_option('aDBc_clean_schedule');
			wp_clear_scheduled_hook('aDBc_optimize_scheduler');
			wp_clear_scheduled_hook('aDBc_clean_scheduler');
			restore_current_blog();
		}
	}else{
		delete_option('aDBc_optimize_schedule');
		delete_option('aDBc_clean_schedule');
		wp_clear_scheduled_hook('aDBc_optimize_scheduler');
		wp_clear_scheduled_hook('aDBc_clean_scheduler');
	}
}

/********************************************************************
* The admin page content
********************************************************************/
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
		}elseif(isset($_POST['aDBc_optimize_schedule_form'])){
			wp_clear_scheduled_hook('aDBc_optimize_scheduler');
			if($_POST['aDBc_optimize_schedule'] == 'no_schedule'){
				delete_option('aDBc_optimize_schedule');
			}else{
				update_option('aDBc_optimize_schedule', $_POST['aDBc_optimize_schedule']);
				wp_schedule_event(time()+60, $_POST['aDBc_optimize_schedule'], 'aDBc_optimize_scheduler');
			}
			$adv_db_cleaner_message = __('The optimization schedule is saved successfully!', 'advanced-db-cleaner');
		}elseif(isset($_POST['aDBc_clean_schedule_form'])){
			wp_clear_scheduled_hook('aDBc_clean_scheduler');
			if($_POST['aDBc_clean_schedule'] == 'no_schedule'){
				delete_option('aDBc_clean_schedule');
			}else{
				update_option('aDBc_clean_schedule', $_POST['aDBc_clean_schedule']);
				wp_schedule_event(time()+60, $_POST['aDBc_clean_schedule'], 'aDBc_clean_scheduler');
			}
			$adv_db_cleaner_message = __('The clean-up schedule is saved successfully!', 'advanced-db-cleaner');
		}elseif(isset($_POST['adbc_cron_hooks'])){
			//Clean cron (scheduled tasks)
			if(function_exists('is_multisite') && is_multisite() && is_main_site()){
				foreach($_POST['adbc_cron_hooks'] as $adbc_cron_hook) {
					// Get the id of the blog and the hook name
					$adbc_blog_id_and_hook = explode("|", $adbc_cron_hook);
					switch_to_blog($adbc_blog_id_and_hook[0]);
					wp_clear_scheduled_hook($adbc_blog_id_and_hook[1]);
					restore_current_blog();
				}			
			}else{
				foreach($_POST['adbc_cron_hooks'] as $adbc_cron_hook) {
					wp_clear_scheduled_hook($adbc_cron_hook);
				}
			}
			$adv_db_cleaner_message = __('Cron(s) cleaned successfully!', 'advanced-db-cleaner');
		}
		// Print message
		if($adv_db_cleaner_message != ''){
			echo '<div id="adbc_message" class="'. $adv_db_cleaner_message_class .'"><p><strong>' . $adv_db_cleaner_message . '</strong></p></div>';
		}
		?>
		<div class="marginRight300">
			<div class="tabBox">
				<?php
					$aDBc_tab_list = array(
								array("id"=>"aDBc-clean", 		"title"=>__('Clean Database', 'advanced-db-cleaner')),
								array("id"=>"aDBc-optimize",	"title"=>__('Optimize Database', 'advanced-db-cleaner')),
								array("id"=>"aDBc-cron",		"title"=>__('Clean Cron', 'advanced-db-cleaner')),
								array("id"=>"aDBc-reset", 		"title"=>__('Reset Database', 'advanced-db-cleaner'))
							);
					foreach ($aDBc_tab_list as $key => $value){
						$checked = "";
						if($value["id"] == "aDBc-clean"
							|| (isset($_POST["aDBc_optimize_form"]) && $value["id"] == "aDBc-optimize")
							|| (isset($_POST["aDBc_optimize_schedule_form"]) && $value["id"] == "aDBc-optimize")
							|| (isset($_POST["aDBc_clean_cron_form"]) && $value["id"] == "aDBc-cron")							
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
					<?php include_once 'includes/clean-cron.php'; ?>
				</div>				
				<div class="tab4">
					<?php 
					if(is_main_site()){
						include_once 'includes/reset-db.php';
					}else{
						echo "<div style='text-align:center; padding-top:100px; color:#777'>";
						_e('Sorry! The reset can be executed from the main site in your Network only!', 'advanced-db-cleaner');
						echo "</div>";
					}
					?>
				</div>
			</div>
			<div class="aDBcSidebar">
				<?php include_once 'includes/sidebar.php'; ?>
			</div>
		</div>
	</div>
<?php 
}
/***************************************************************
*
* Get functions
*
***************************************************************/
include_once 'includes/functions.php';
?>