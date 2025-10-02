<?php

class ADBC_Options_List extends WP_List_Table {

	/** Holds the message to be displayed if any */
	private $aDBc_message = "";

	/** Holds the class for the message : updated or error. Default is updated */
	private $aDBc_class_message = "updated";

	/** Holds options that will be displayed */
	private $aDBc_options_to_display = array();

	/** Holds counts + info of options categories */
	private $aDBc_options_categories_info	= array();

	/** Should we display "run search" or "continue search" button (after a timeout failed). Default is "run search" */
	private $aDBc_which_button_to_show = "new_search";

	// This array contains belongs_to info about plugins and themes
	private $array_belongs_to_counts = array();

	// Holds msg that will be shown if folder adbc_uploads cannot be created by the plugin (This is verified after clicking on scan button)
	private $aDBc_permission_adbc_folder_msg = "";

    function __construct(){

        parent::__construct(array(
            'singular'  => __('Option', 'advanced-database-cleaner'),
            'plural'    => __('Options', 'advanced-database-cleaner'),
            'ajax'      => false
		));

		$this->aDBc_prepare_and_count_options();
		$this->aDBc_print_page_content();
    }

	/** Prepare items */
	function aDBc_prepare_and_count_options() {

		if ( ADBC_PLUGIN_PLAN == "pro" ) {

			// Verify if the adbc_uploads cannot be created
			$adbc_folder_permission = get_option( "aDBc_permission_adbc_folder_needed" );

			if ( ! empty( $adbc_folder_permission ) ) {

				$this->aDBc_permission_adbc_folder_msg = sprintf( __( 'The plugin needs to create the following directory "%1$s" to save the scan results but this was not possible automatically. Please create that directory manually and set correct permissions so it can be writable by the plugin.','advanced-database-cleaner' ), ADBC_UPLOAD_DIR_PATH_TO_ADBC );

				// Once we display the msg, we delete that option from DB
				delete_option( "aDBc_permission_adbc_folder_needed" );

			}

		}

		// Verify if the user wants to edit the categorization of an option. This block test comes from edit_item_categorization.php
		if ( ADBC_PLUGIN_PLAN == "pro" ) {

			if ( isset( $_POST['aDBc_cancel'] ) ) {

				// If the user cancels the edit, remove the temp file
				if ( file_exists( ADBC_UPLOAD_DIR_PATH_TO_ADBC . "/options_manually_correction_temp.txt" ) )
					unlink( ADBC_UPLOAD_DIR_PATH_TO_ADBC . "/options_manually_correction_temp.txt" );

			} else if ( isset( $_POST['aDBc_correct'] ) ) {

				// Get the new belongs to of items
				$new_belongs_to = $_POST['new_belongs_to'];

				// Get value of checkbox to see if user wants to send correction to the server
				if ( isset( $_POST['aDBc_send_correction_to_server'] ) ) {
					$this->aDBc_message = aDBc_edit_categorization_of_items( "options", $new_belongs_to, 1 );
				} else {
					$this->aDBc_message = aDBc_edit_categorization_of_items( "options", $new_belongs_to, 0 );
				}

				// Remove the temp file
				if ( file_exists( ADBC_UPLOAD_DIR_PATH_TO_ADBC . "/options_manually_correction_temp.txt" ) )
					unlink( ADBC_UPLOAD_DIR_PATH_TO_ADBC . "/options_manually_correction_temp.txt" );
			}
		}

		// Process bulk action if any before preparing options to display
		$this->process_bulk_action();

		// Prepare data
		aDBc_prepare_items_to_display(
			$this->aDBc_options_to_display,
			$this->aDBc_options_categories_info,
			$this->aDBc_which_button_to_show,
			array(),
			array(),
			$this->array_belongs_to_counts,
			$this->aDBc_message,
			$this->aDBc_class_message,
			"options"
		);

		// Call WP prepare_items function
		$this->prepare_items();
	}

	/** WP: Get columns */
	function get_columns(){
		$aDBc_belongs_to_tooltip = "<span class='aDBc-tooltips-headers'>
									<img class='aDBc-info-image' src='".  ADBC_PLUGIN_DIR_PATH . '/images/information2.svg' . "'/>
									<span>" . __('Indicates the creator of the option: either a plugin, a theme or WordPress itself. If not sure about the creator, an estimation (%) will be displayed. The higher the percentage is, the more likely that the option belongs to that creator.','advanced-database-cleaner') ." </span>
								  </span>";

		$autoload_true_values  = 'yes';
		$autoload_false_values = 'no';

		if(function_exists('wp_autoload_values_to_autoload')){
			$autoload_true_values  = 'yes, on, auto, auto-on';
			$autoload_false_values = 'no, off, auto-off';
		}

		$autoload_message = sprintf(
			__( 'Indicates whether an option is autoloaded or not. Values to autoload are: %1$s. Values to not autoload are: %2$s', 'advanced-database-cleaner' ),
			$autoload_true_values,
			$autoload_false_values
		);

		$aDBc_autoload_tooltip = "<span class='aDBc-tooltips-headers' style='position:absolute;margin-left:18px;margin-top:-1px'>
									<img class='aDBc-info-image' src='".  ADBC_PLUGIN_DIR_PATH . '/images/information2.svg' . "'/>
									<span>" . $autoload_message ." </span>
								  </span>";

		$columns = array(
			'cb'          		=> '<input type="checkbox" />',
			'option_name' 		=> __('Option name','advanced-database-cleaner'),
			'option_value' 		=> __('Value','advanced-database-cleaner'),
			'option_size' 		=> __('Size','advanced-database-cleaner'),
			'option_autoload' 	=> __('Autoload','advanced-database-cleaner') . $aDBc_autoload_tooltip,
			'site_id'   		=> __('Site','advanced-database-cleaner'),
			'option_belongs_to' => __('Belongs to','advanced-database-cleaner') . $aDBc_belongs_to_tooltip
		);
		return $columns;
	}

	function get_sortable_columns() {

		$sortable_columns = array(
			'option_name'   	=> array('option_name',false),
			'option_size'   	=> array('option_size',false),
			'option_autoload'   => array('option_autoload',false),
			'site_id'    		=> array('site_id',false)
		);

		return $sortable_columns;
	}

	/** WP: Prepare items to display */
	function prepare_items() {
		$columns 	= $this->get_columns();
		$hidden 	= $this->get_hidden_columns();
		$sortable 	= $this->get_sortable_columns();
		$this->_column_headers  = array($columns, $hidden, $sortable);
		$per_page 	= 50;
		if(!empty($_GET['per_page'])){
			$per_page = absint($_GET['per_page']);
		}
		$current_page = $this->get_pagenum();
		// Prepare sequence of options to display
		$display_data = array_slice($this->aDBc_options_to_display,(($current_page-1) * $per_page), $per_page);
		$this->set_pagination_args( array(
			'total_items' => count($this->aDBc_options_to_display),
			'per_page'    => $per_page
		));
		$this->items = $display_data;
	}

	/** WP: Get columns that should be hidden */
    function get_hidden_columns(){
		// If MU, nothing to hide, else hide Side ID column
		if(function_exists('is_multisite') && is_multisite()){
			return array();
		}else{
			return array('site_id');
		}
    }

	/** WP: Column default */
	function column_default($item, $column_name){
		switch($column_name){
			case 'option_name':
				return esc_html($item[$column_name]);
			case 'option_size':
				return aDBc_format_bytes((int) $item[$column_name]);
			case 'option_value':
			case 'option_autoload':
			case 'site_id':
			case 'option_belongs_to':
			  return $item[$column_name];
			default:
			  return print_r($item, true) ; //Show the whole array for troubleshooting purposes
		}
	}

	/** WP: Column cb for check box */
	function column_cb($item) {
		$value = $item['site_id'] . "|" . $item['option_name'];
		return sprintf(
			'<input type="checkbox" name="aDBc_elements_to_process[]" value="%s" />', 
			esc_attr($value)
		);
	}

	/** WP: Get bulk actions */
	function get_bulk_actions() {

		$autoload_on = function_exists('wp_autoload_values_to_autoload') ? " (on)" : "";
		$autoload_off = function_exists('wp_autoload_values_to_autoload') ? " (off)" : "";

		$actions = array(
			'scan_selected' 		=> __( 'Scan selected options','advanced-database-cleaner' ),
			'edit_categorization' 	=> __( 'Edit categorization','advanced-database-cleaner' ),
			'autoload_yes'  		=> __( 'Set autoload to yes','advanced-database-cleaner' ) . $autoload_on,
			'autoload_no'  			=> __( 'Set autoload to no','advanced-database-cleaner' ) . $autoload_off,
			'delete'    			=> __( 'Delete','advanced-database-cleaner' )
		);

		if ( ADBC_PLUGIN_PLAN == "free" ) {

			unset( $actions['scan_selected'] );
			unset( $actions['edit_categorization'] );

		}

		return $actions;
	}

	/** WP: Message to display when no items found */
	function no_items() {

		_e( 'No options found!', 'advanced-database-cleaner' );

	}

	/** WP: Process bulk actions */
    public function process_bulk_action() {

		global $wpdb;

		// Detect when a bulk action is being triggered.
		$action = $this->current_action();

		if ( ! $action )
			return;

		// security check!
		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		// Check role
		if ( ! current_user_can( 'administrator' ) )
			wp_die( 'Security check failed!' );

        if ( $action == 'delete' ) {
			// If the user wants to clean the options he/she selected
			if(isset($_POST['aDBc_elements_to_process'])){
				if(function_exists('is_multisite') && is_multisite()){
					// Prepare options to delete in organized array to minimize switching from blogs
					$options_to_delete = array();
					foreach($_POST['aDBc_elements_to_process'] as $option){
						$option_info 	= explode("|", $option, 2);
						$site_id 		= sanitize_html_class($option_info[0]);
						$option_name 	= wp_unslash($option_info[1]);
						if(is_numeric($site_id)){
							if(empty($options_to_delete[$site_id])){
								$options_to_delete[$site_id] = array();
							}
							array_push($options_to_delete[$site_id], $option_name);
						}
					}
					// Delete options
					foreach($options_to_delete as $site_id => $options){
						switch_to_blog($site_id);
						foreach($options as $option) {
							delete_option($option);
						}
						restore_current_blog();
					}
				}else{
					foreach($_POST['aDBc_elements_to_process'] as $option) {
						$aDBc_option_info 	= explode("|", $option, 2);
						$option_name 		= wp_unslash($aDBc_option_info[1]); // Because WP adds slashes to options in the POST array
						delete_option($option_name);
					}
				}
				// Update the message to show to the user
				$this->aDBc_message = __('Selected options cleaned successfully!', 'advanced-database-cleaner');
			}

        } else if ( $action == 'autoload_yes' || $action == 'autoload_no' ) {

			$autoload_value = function_exists( 'wp_autoload_values_to_autoload' )
				? ( $action == 'autoload_yes' ? 'on' : 'off' )
				: ( $action == 'autoload_yes' ? 'yes' : 'no' );

			// If the user wants to change autoload for selected options
			if ( isset( $_POST['aDBc_elements_to_process'] ) ) {

				if ( function_exists( 'is_multisite' ) && is_multisite() ) {

					// Prepare options to process in organized array to minimize switching from blogs
					$options_to_process = array();

					foreach ( $_POST['aDBc_elements_to_process'] as $option ) {

						$option_info 	= explode( "|", $option, 2);
						$site_id 		= sanitize_html_class( $option_info[0] );
						$option_name 	= wp_unslash($option_info[1]); // Because WP adds slashes to options in the POST array

						if ( is_numeric( $site_id ) ) {

							if ( empty( $options_to_process[$site_id] ) )
								$options_to_process[$site_id] = array();

							array_push( $options_to_process[$site_id], $option_name );

						}
					}

					// Change autoload
					foreach ( $options_to_process as $site_id => $options ) {

						switch_to_blog( $site_id );

							if ( ! empty( $options ) ) {
								// Build the placeholders for each option name.
								$placeholders = implode( ',', array_fill( 0, count( $options ), '%s' ) );
								// Construct the options table name with the blog prefix.
								$table_name = $wpdb->prefix . 'options';
								// Create the SQL update statement.
								$sql = "UPDATE {$table_name} SET autoload = %s WHERE option_name IN ($placeholders)";
								// Merge the autoload value and option names into a single array.
								$args = array_merge( [ $autoload_value ], $options );
								// Execute the prepared query.
								$wpdb->query( $wpdb->prepare( $sql, $args ) );
							}

						restore_current_blog();
					}

				} else {

					// Array to store the option names to update
					$options_list = array();

					// Loop through the selected options
					foreach ( $_POST['aDBc_elements_to_process'] as $option ) {

						$aDBc_option_info 	= explode( "|", $option, 2 );
						$option_name 		= wp_unslash($aDBc_option_info[1]); // Because WP adds slashes to options in the POST array

						// Collect the option names to update
						$options_list[]   = $option_name;
					}

					// Update the autoload value for the selected options
					if ( ! empty( $options_list ) ) {
						// Build the placeholders for each option name
						$placeholders = implode( ',', array_fill( 0, count( $options_list ), "'%s'" ) );
						// Create the SQL update statement
						$sql = "UPDATE {$wpdb->options} SET autoload = %s WHERE option_name IN ($placeholders)";
						// Merge the autoload value and option names into a single array
						$args = array_merge( [ $autoload_value ], $options_list );
						// Execute the prepared query
						$wpdb->query( $wpdb->prepare( $sql, $args ) );
					}

				}

				// Update the message to show to the user
				$this->aDBc_message = __( 'Autoload value successfully changed!', 'advanced-database-cleaner' );

			}

		} else if ( $action == 'edit_categorization' ) {

			// If the user wants to edit categorization of the options he/she selected
			if(isset($_POST['aDBc_elements_to_process'])){
				// Create a temp file containing options names to change categorization for
				$aDBc_path_items = @fopen(ADBC_UPLOAD_DIR_PATH_TO_ADBC . "/options_manually_correction_temp.txt", "w");
				if($aDBc_path_items){
					foreach($_POST['aDBc_elements_to_process'] as $option){
						$option_info = explode("|", $option, 2);
						$option_name = wp_unslash($option_info[1]); // Because WP adds slashes to options in the POST array
						fwrite($aDBc_path_items, $option_name . "\n");
					}
					fclose($aDBc_path_items);
				}
			}
		}
    }

	/** Print the page content */
	function aDBc_print_page_content(){

		// Print a message if any
		if($this->aDBc_message != ""){
			echo '<div id="aDBc_message" class="' . $this->aDBc_class_message . ' notice is-dismissible"><p>' . $this->aDBc_message . '</p></div>';
		}

		// If the folder adbc_uploads cannot be created, show a msg to users
		if(!empty($this->aDBc_permission_adbc_folder_msg)){
			echo '<div class="error notice is-dismissible"><p>' . $this->aDBc_permission_adbc_folder_msg . '</p></div>';
		}

		?>
		<div class="aDBc-content-max-width">

		<?php

		// If options_manually_correction_temp.txt exist, this means that user want to edit categorization

		if ( ADBC_PLUGIN_PLAN == "pro" && file_exists( ADBC_UPLOAD_DIR_PATH_TO_ADBC . '/options_manually_correction_temp.txt' ) ) {

			include_once 'edit_item_categorization.php';

		} else {

			// If not, we print the options normally
			// Print a notice/warning according to each type of options
			if ( ADBC_PLUGIN_PLAN == "pro" ) {

				$aDBc_iteration = get_option( "aDBc_temp_last_iteration_options" );
				$aDBc_currently_scanning = get_option( "aDBc_temp_currently_scanning_options" );

				if ($aDBc_iteration === false && $aDBc_currently_scanning === false) {
					if($_GET['aDBc_cat'] == 'o' && $this->aDBc_options_categories_info['o']['count'] > 0){
						echo '<div class="aDBc-box-warning-orphan">' . __('Options below seem to be orphan! However, please delete only those you are sure to be orphan!','advanced-database-cleaner') . '</div>';
					}else if(($_GET['aDBc_cat'] == 'all' || $_GET['aDBc_cat'] == 'u') && $this->aDBc_options_categories_info['u']['count'] > 0){

						$aDBc_settings = get_option('aDBc_settings');
						$hide_not_categorized_msg = empty($aDBc_settings['hide_not_categorized_yet_msg']) ? "" : $aDBc_settings['hide_not_categorized_yet_msg'];
						if ( $hide_not_categorized_msg != "yes" ) {
							echo '<div id="aDBc-box-info" class="aDBc-box-info">' 
								. '<div style="width:100%">' 
								. __('Some of your options are not categorized yet! Please click on the button below to categorize them!','advanced-database-cleaner') 
								. '</div>'
								. '<div><a href="#" id="aDBc-dismiss-not-categorized-yet-msg" title="' . __('Dismiss similar messages', 'advanced-database-cleaner') . '"><span class="dashicons dashicons-dismiss" style="text-decoration:none;font-size:16px;margin-top:4px"></span></a></div>'
							. '</div>';
						}
					}
				}
			}

		?>

			<div class="aDBc-clear-both" style="margin-top:15px"></div>

			<!-- Code for "run new search" button + Show loading image -->
			<div style="float:left">

				<?php
				if ( $this->aDBc_which_button_to_show == "new_search" ) {
					$aDBc_search_text  	= __( 'Scan options', 'advanced-database-cleaner' );
				} else {
					$aDBc_search_text  	= __( 'Continue scanning ...', 'advanced-database-cleaner' );
				}
				?>

				<!-- Hidden input used by ajax to know which item type we are dealing with -->
				<input type="hidden" id="aDBc_item_type" value="options"/>

				<?php
				// These hidden inputs are used by ajax to see if we should execute the scan automatically after reloading a page
				$iteration = get_option("aDBc_temp_last_iteration_options");
				$currently_scanning = get_option("aDBc_temp_currently_scanning_options");
				?>
				<input type="hidden" id="aDBc_currently_scanning" value="<?php echo $currently_scanning; ?>"/>
				<input type="hidden" id="aDBc_iteration" value="<?php echo $iteration; ?>"/>
				<input type="hidden" id="aDBc_count_uncategorized" value="<?php echo $this->aDBc_options_categories_info['u']['count']; ?>"/>
				<input type="hidden" id="aDBc_count_all_items" value="<?php echo $this->aDBc_options_categories_info['all']['count']; ?>"/>

				<?php

				if ( ADBC_PLUGIN_PLAN == "pro" ) {

				?>

					<input id="aDBc_new_search_button" type="submit" class="aDBc-run-new-search" value="<?php echo $aDBc_search_text; ?>"  name="aDBc_new_search_button" />

				<?php

				} else {

				?>

					<div class="aDBc-premium-tooltip">

						<input id="aDBc_new_search_button" type="submit" class="aDBc-run-new-search" value="<?php echo $aDBc_search_text; ?>"  name="aDBc_new_search_button" style="opacity:0.5" disabled />

						<span style="width:390px" class="aDBc-premium-tooltiptext">

							<?php _e('Please <a href="?page=advanced_db_cleaner&aDBc_tab=premium">upgrade</a> to Pro to categorize and detect orphaned options','advanced-database-cleaner') ?>

						</span>

					</div>

				<?php
				}
				?>

			</div>

			<!-- Print numbers of items found in each category -->
			<div class="aDBc-category-counts">

				<?php

				$aDBc_new_URI = $_SERVER['REQUEST_URI'];

				// Remove the paged parameter to start always from the first page when selecting a new category
				$aDBc_new_URI = remove_query_arg( 'paged', $aDBc_new_URI );

				foreach ( $this->aDBc_options_categories_info as $abreviation => $category_info ) {

					$aDBc_new_URI 		= add_query_arg( 'aDBc_cat', $abreviation, $aDBc_new_URI );
					$selected_color 	= $abreviation == $_GET['aDBc_cat'] ? $category_info['color'] : '#eee';
					$aDBc_link_style 	= "color:" . $category_info['color'];
					$aDBc_count 		= $category_info['count'];

					if ( ADBC_PLUGIN_PLAN == "free" && $abreviation != "all" && $abreviation != "u" ) {

						$aDBc_new_URI 		= "";
						$aDBc_link_style 	= $aDBc_link_style . ";cursor:default;pointer-events:none";
						$aDBc_count 		= "-";

					}

				?>
					<span class="<?php echo $abreviation == $_GET['aDBc_cat'] ? 'aDBc-selected-category' : ''?>">

						<span class="aDBc-premium-tooltip aDBc-category-span">

							<a href="<?php echo esc_url( $aDBc_new_URI ) ?>" class="aDBc-category-counts-links" style="<?php echo $aDBc_link_style ?>">

								<span><?php echo $category_info['name']; ?></span>

							</a>

							<div class="aDBc-category-total" style="border:1px solid <?php echo $selected_color ?>; border-bottom:3px solid <?php echo $selected_color ?>;">

								<span style="color:#000"><?php echo $aDBc_count ?></span>

							</div>

							<?php
							if ( ADBC_PLUGIN_PLAN == "free" && $abreviation != "all" && $abreviation != "u" ) {
							?>

								<span style="width:150px" class="aDBc-premium-tooltiptext">
									<a href="https://sigmaplugin.com/downloads/wordpress-advanced-database-cleaner/" target="_blank">
										<?php _e( 'Available in Pro version!', 'advanced-database-cleaner' ); ?>
									</a>
								</span>

							<?php
							}
							?>

						</span>

					</span>

				<?php
				}
				?>

			</div>

			<div class="aDBc-clear-both"></div>

			<div id="aDBc-progress-container">

				<span id="aDBc_collected_files" href="#" style="color:gray">
				</span>

				<div class="aDBc-progress-background">
					<div id="aDBc-progress-bar" class="aDBc-progress-bar"></div>
				</div>

				<a id="aDBc_stop_scan" href="#" style="color:red">
					<?php _e('Stop the scan','advanced-database-cleaner') ?>
				</a>

				<span id="aDBc_stopping_msg" style="display:none">
					<?php _e('Stopping...','advanced-database-cleaner') ?>
				</span>

			</div>

			<?php include_once 'header_page_filter.php'; ?>
			

			<div class="aDBc-clear-both"></div>

			<?php
				// print the total size of autoloaded options
				[$total_size, $health_status] = adbc_get_total_autoload_size('KB');
				$style = $health_status == 'good' ? 'background:rgb(235, 252, 239);border:1px solid rgb(207, 246, 217)':'background:rgb(255, 242, 242);border:1px solid rgb(242, 217, 217)';
				$dashicon = $health_status == 'good' ? 'dashicons-yes' : 'dashicons-warning';
				$dashicon_style = $health_status == 'good' ? 'color:green' : 'color:orange';
				$status_sentence = $health_status == 'good' ? '[' . __('Good', 'advanced-database-cleaner') . ']' : __('This size should be reduced to prevent performance issues', 'advanced-database-cleaner');
				
				echo '<div style="' . $style . ';color:#222;margin-top:-10px;margin-bottom:15px;padding-bottom:10px;padding-left:10px;padding-right:10px;border-radius:4px">' 
				. '<span class="dashicons ' . $dashicon . '" style="' . $dashicon_style . ';padding-right:5px"></span>'
				. __('Total size of autoloaded options: ','advanced-database-cleaner') . '<b>' . $total_size . '</b> KB. ' . $status_sentence . '</div>';
			?>

			<form id="aDBc_form" action="" method="post">

				<?php
				$this->display();
				?>

			</form>
		<?php
		}
		?>
		</div>
	<?php
	}
}

new ADBC_Options_List();

?>
