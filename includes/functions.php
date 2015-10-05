<?php

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

function adv_db_cleaner_reset(){
	require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );
	global $current_user, $wpdb;
	$blogname = get_option( 'blogname' );
	$admin_email = get_option( 'admin_email' );
	$blog_public = get_option( 'blog_public' );
	if ( $current_user->user_login != 'admin' ){
		$user = get_user_by( 'login', 'admin' );
	}
	if ( empty( $user->user_level ) || $user->user_level < 10 ){
		$user = $current_user;
	}
	$prefix = str_replace( '_', '\_', $wpdb->prefix );
	$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$prefix}%'" );
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE $table" );
	}
	// Install wordpress
	$result = wp_install( $blogname, $user->user_login, $user->user_email, $blog_public);
	extract( $result, EXTR_SKIP );
	// Set user password
	$query = $wpdb->prepare( "UPDATE $wpdb->users SET user_pass = %s, user_activation_key = '' WHERE ID = %d", $user->user_pass, $user_id );
	$wpdb->query( $query );
	// Test for functions
	$get_user_meta = function_exists( 'get_user_meta' ) ? 'get_user_meta' : 'get_usermeta';
	$update_user_meta = function_exists( 'update_user_meta' ) ? 'update_user_meta' : 'update_usermeta';
	// Say to wordpress that we will not use generated password
	if ( $get_user_meta( $user_id, 'default_password_nag' ) ){
		$update_user_meta( $user_id, 'default_password_nag', false );
	}
	if ( $get_user_meta( $user_id, $wpdb->prefix . 'default_password_nag' ) ){
		$update_user_meta( $user_id, $wpdb->prefix . 'default_password_nag', false );
	}
	// Reactivate the plugin (TODO)
	//activate_plugin( 'plugins/advanced-db-cleaner/advanced-db-cleaner.php' );
	// Clear all cookies associated with authentication
	wp_clear_auth_cookie();
	// Set the authentication cookies based User ID
	wp_set_auth_cookie( $user_id );
	// Redirect user to admin pannel
	wp_redirect( admin_url());
}


?>