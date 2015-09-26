<?php
/*
Plugin Name: Advanced Database Cleaner
Description: Clean your database and save space by removing unused data such as "draft", "Spam Comments", "Orphan Postmeta", etc.
Version: 1.1.0
Author: Jaafar Y.
Contributors: symptote
Text Domain: advanced-db-cleaner
Domain Path: /languages/
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'db_cleaner_add_action_links');

// Add 'settings' link under the plugin name in plugins page
function db_cleaner_add_action_links($links){
	$adc_settings_link = '<a href = "options-general.php?page=' . dirname(plugin_basename(__FILE__)) . '/advanced-db-cleaner-admin.php">' . __('Settings', 'advanced-db-cleaner') . '</a>';
	array_unshift($links, $adc_settings_link);
	return $links;
}

if(is_admin()){require_once('advanced-db-cleaner-admin.php');}

?>