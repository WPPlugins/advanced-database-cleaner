<?php
//This function is used to clean a specific unused element type from the database
function adv_db_cleaner($type){
	global $wpdb;
	// If we are in multi-site installation and in main site then we should process all elements in all tables
	if(function_exists('is_multisite') && is_multisite() && is_main_site()){
		$blogs_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
		foreach($blogs_ids as $blog_id){
			switch_to_blog($blog_id);
			switch($type){
				case "revision":
					$wpdb->query("DELETE FROM $wpdb->posts WHERE post_type = 'revision'");
					$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
					$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
					break;
				case "draft":
					$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'draft'");
					$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
					$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
					break;
				case "autodraft":
					$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'");
					$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
					$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
					break;
				case "trash-posts":
					$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'trash'");
					$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
					$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
					break;					
				case "moderated":
					$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = '0'");
					$wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
					break;
				case "spam":
					$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'");
					$wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
					break;
				case "trash":
					$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'");
					$wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
					break;
				case "postmeta":
					$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
					break;
				case "commentmeta":
					$wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
					break;
				case "relationships":
					$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
					break;
				case "feed":
					$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_site_transient_browser_%' OR option_name LIKE '_site_transient_timeout_browser_%' OR option_name LIKE '_transient_feed_%' OR option_name LIKE '_transient_timeout_feed_%'");
					break;
			}
			restore_current_blog();
		}
	}else{
		// If we are in single site installation or in multi-site but not in main site, then we should process only current tables
		switch($type){
			case "revision":
				$wpdb->query("DELETE FROM $wpdb->posts WHERE post_type = 'revision'");
				$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
				$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
				break;
			case "draft":
				$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'draft'");
				$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
				$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
				break;
			case "autodraft":
				$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'");
				$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
				$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
				break;
			case "trash-posts":
				$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'trash'");
				$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
				$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
				break;				
			case "moderated":
				$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = '0'");
				$wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
				break;
			case "spam":
				$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'");
				$wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
				break;
			case "trash":
				$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'");
				$wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
				break;
			case "postmeta":
				$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
				break;
			case "commentmeta":
				$wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
				break;
			case "relationships":
				$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
				break;
			case "feed":
				$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_site_transient_browser_%' OR option_name LIKE '_site_transient_timeout_browser_%' OR option_name LIKE '_transient_feed_%' OR option_name LIKE '_transient_timeout_feed_%'");
				break;
		}
	}
}

// This function is used by the scheduler to clean all elements
function aDBc_cleanAll(){
	global $wpdb;
	// If we are in multi-site installation and in main site then we should process all elements in all tables
	if(function_exists('is_multisite') && is_multisite() && is_main_site()){
		$blogs_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
		foreach($blogs_ids as $blog_id){
			switch_to_blog($blog_id);
			$wpdb->query("DELETE FROM $wpdb->posts WHERE post_type = 'revision'");
			$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'draft'");
			$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'");
			$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'trash'");
			$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = '0'");
			$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'");
			$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'");
			$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
			$wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
			$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
			$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_site_transient_browser_%' OR option_name LIKE '_site_transient_timeout_browser_%' OR option_name LIKE '_transient_feed_%' OR option_name LIKE '_transient_timeout_feed_%'");
			restore_current_blog();
		}
	}else{
		// If we are in single site installation or in multi-site but not in main site, then we should process only current tables
		$wpdb->query("DELETE FROM $wpdb->posts WHERE post_type = 'revision'");
		$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'draft'");
		$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'");
		$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'trash'");
		$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = '0'");
		$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'");
		$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'");
		$wpdb->query("DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
		$wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
		$wpdb->query("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
		$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_site_transient_browser_%' OR option_name LIKE '_site_transient_timeout_browser_%' OR option_name LIKE '_transient_feed_%' OR option_name LIKE '_transient_timeout_feed_%'");
	}
}

// This function is used to count unused elements
function adv_db_cleaner_count(){
	global $wpdb;
	// If we are in multi-site installation and in main site then we should process all elements in all tables
	if(function_exists('is_multisite') && is_multisite() && is_main_site()){
		$blogs_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
		foreach($blogs_ids as $blog_id){
			switch_to_blog($blog_id);
			$aDBc_count_unused["revision"] += 		$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'");
			$aDBc_count_unused["draft"] += 			$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'draft'");
			$aDBc_count_unused["autodraft"] += 		$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'");
			$aDBc_count_unused["trashposts"] += 	$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'trash'");
			$aDBc_count_unused["moderated"] += 		$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = '0'");
			$aDBc_count_unused["spam"] += 			$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'");
			$aDBc_count_unused["trash"] += 			$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'trash'");
			$aDBc_count_unused["postmeta"] += 		$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
			$aDBc_count_unused["commentmeta"] += 	$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
			$aDBc_count_unused["relationships"] += 	$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
			$aDBc_count_unused["feed"] += 			$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE '_site_transient_browser_%' OR option_name LIKE '_site_transient_timeout_browser_%' OR option_name LIKE '_transient_feed_%' OR option_name LIKE '_transient_timeout_feed_%'");	
			restore_current_blog();
		}
		$aDBc_count_unused["total"] = $aDBc_count_unused["revision"] + $aDBc_count_unused["draft"] + $aDBc_count_unused["autodraft"] + $aDBc_count_unused["trashposts"] + $aDBc_count_unused["moderated"] + $aDBc_count_unused["spam"] + $aDBc_count_unused["trash"] + $aDBc_count_unused["postmeta"] + $aDBc_count_unused["commentmeta"] + $aDBc_count_unused["relationships"] + $aDBc_count_unused["feed"];
	}else{
		// If we are in single site installation or in multi-site but not in main site, then we should process only current tables
		$aDBc_count_unused["revision"] = 		$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'");
		$aDBc_count_unused["draft"] = 			$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'draft'");
		$aDBc_count_unused["autodraft"] = 		$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'");
		$aDBc_count_unused["trashposts"] = 		$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'trash'");
		$aDBc_count_unused["moderated"] = 		$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = '0'");
		$aDBc_count_unused["spam"] = 			$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'");
		$aDBc_count_unused["trash"] = 			$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'trash'");
		$aDBc_count_unused["postmeta"] = 		$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
		$aDBc_count_unused["commentmeta"] = 	$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM $wpdb->comments)");
		$aDBc_count_unused["relationships"] = 	$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM $wpdb->posts)");
		$aDBc_count_unused["feed"] = 			$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE '_site_transient_browser_%' OR option_name LIKE '_site_transient_timeout_browser_%' OR option_name LIKE '_transient_feed_%' OR option_name LIKE '_transient_timeout_feed_%'");
		$aDBc_count_unused["total"] = 			$aDBc_count_unused["revision"] + $aDBc_count_unused["draft"] + $aDBc_count_unused["autodraft"] + $aDBc_count_unused["trashposts"] + $aDBc_count_unused["moderated"] + $aDBc_count_unused["spam"] + $aDBc_count_unused["trash"] + $aDBc_count_unused["postmeta"] + $aDBc_count_unused["commentmeta"] + $aDBc_count_unused["relationships"] + $aDBc_count_unused["feed"];
	}
	return $aDBc_count_unused;
}

// This function optimizes tables containing lost space (data_free > 0)
function adv_db_cleaner_optimize(){
	global $wpdb;
	$prefix = str_replace( '_', '\_', $wpdb->prefix );
	$adbc_sql = "SELECT table_name, data_free FROM information_schema.tables WHERE table_schema = '".DB_NAME."' and Engine <> 'InnoDB' and data_free > 0";
	if(!is_main_site()){
		$adbc_sql = $adbc_sql . " and table_name LIKE '{$prefix}%'";
	}
	$result = $wpdb->get_results($adbc_sql);
	foreach($result as $row){
		$adbc_sql = 'OPTIMIZE TABLE '.$row->table_name;
		$wpdb->query($adbc_sql);
	}
}

// This function resets the database to its initial state like a fresh installation
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
	// Clear all cookies associated with authentication
	wp_clear_auth_cookie();
	// Set the authentication cookies based User ID
	wp_set_auth_cookie( $user_id );
	// Redirect user to admin pannel
	wp_redirect( admin_url());
}
?>