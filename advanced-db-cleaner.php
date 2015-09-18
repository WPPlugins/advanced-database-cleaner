<?php
/*
Plugin Name: Advanced Database Cleaner
Description: Clean your database and save space by removing unused data such as "draft", "Spam Comments", "Orphan Postmeta", etc.
Version: 1.0.0
Author: Jaafar Y.
Contributors: symptote
Text Domain: advanced-db-cleaner
Domain Path: /languages/
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

add_action('init','load_db_cleaner_language');
function load_db_cleaner_language(){
	$activeLocale = get_locale();
	if(!empty($activeLocale)){
		$langFile = dirname(__FILE__) . "/languages/" . $activeLocale . ".mo";
		if(@file_exists($langFile) && is_readable($langFile)){
			load_textdomain('advanced-db-cleaner', $langFile);
		}
	}
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'db_cleaner_add_action_links');
function db_cleaner_add_action_links($links){
	$adc_settings_link = '<a href = "options-general.php?page=' . dirname(plugin_basename(__FILE__)) . '/advanced-db-cleaner-admin.php">' . __('Settings', 'advanced-db-cleaner') . '</a>';
	array_unshift($links, $adc_settings_link);
	return $links;
}

if(is_admin()){require_once('advanced-db-cleaner-admin.php');}

?>