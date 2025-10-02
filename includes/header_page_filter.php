<!-- style et code ok -->
<div class="aDBc-filter-container" style="border-radius:4px">

	<div class="aDBc-filter-section">

		<span class="aDBc-premium-tooltip">

			<?php

			$free_style = "";

			if ( ADBC_PLUGIN_PLAN == "free" ) {

				$free_style = "aDBc-filter-pro-only";

			}

			?>

			<form class="<?php echo $free_style; ?>" method="get">

				<?php
				// Generate current parameters in URL
				foreach ( $_GET as $name => $value ) {

					if ( $name != "s" && $name != "paged" && $name != "aDBc_cat" ) {

						$name 	= esc_attr( sanitize_text_field( $name ) );
						$value 	= esc_attr( sanitize_text_field( $value ) );
						echo "<input type='hidden' name='$name' value='$value'/>";

					}
				}

				// Return paged to "1" and aDBc_cat to "all" after each filter
				echo "<input type='hidden' name='paged' value='1'/>";
				echo "<input type='hidden' name='aDBc_cat' value='all'/>";
				?>

				<div class="aDBc-filter-elements">

					<div>
						<label class="aDBc-filter-label">
							<?php _e('Search for', 'advanced-database-cleaner' ); ?>
						</label>
						<input class="aDBc-filter-search-input" type="search" placeholder="<?php _e( 'Search for', 'advanced-database-cleaner' ); ?>" name="s" value="<?php echo empty( $_GET['s'] ) ? '' : esc_attr( $_GET['s'] ); ?>"/>
					</div>

					<?php

					// Show this filter tables type only for tables
					if ( isset( $_GET['aDBc_tab'] ) && $_GET['aDBc_tab'] == 'tables' ) {

						$all_selected 		= ( isset( $_GET['t_type'] ) && $_GET['t_type'] == 'all' ) 		? "selected='selected'" : "";
						$optimize_selected 	= ( isset( $_GET['t_type'] ) && $_GET['t_type'] == 'optimize' ) ? "selected='selected'" : "";
						$repair_selected 	= ( isset( $_GET['t_type'] ) && $_GET['t_type'] == 'repair' ) 	? "selected='selected'" : "";

					?>

						<div>
							<label class="aDBc-filter-label">
								<?php _e('Table status', 'advanced-database-cleaner' ); ?>
							</label>

							<select name="t_type" class="aDBc-filter-dropdown-menu" style="width:100px">

								<option value="all" <?php echo $all_selected; ?>>

									<?php _e( 'All', 'advanced-database-cleaner' ) ?>

								</option>

								<option value="optimize" <?php echo $optimize_selected; ?>>

									<?php
									echo __( 'To optimize', 'advanced-database-cleaner' ) . " (" . count( $this->aDBc_tables_name_to_optimize ) . ")"
									?>

								</option>

								<option value="repair" <?php echo $repair_selected; ?>>

									<?php
									echo __( 'To repair', 'advanced-database-cleaner' ) . " (" . count( $this->aDBc_tables_name_to_repair ) . ")"
									?>

								</option>

							</select>
						</div>

					<?php

					}

					// Show autoload only for options
					if ( isset( $_GET['aDBc_tab'] ) && $_GET['aDBc_tab'] == 'options' ) {

						$all_autoload 	= ( isset( $_GET['autoload'] ) && $_GET['autoload'] == 'all' ) 	? "selected='selected'" : "";
						$autoload_yes 	= ( isset( $_GET['autoload'] ) && $_GET['autoload'] == 'yes' ) 	? "selected='selected'" : "";
						$autoload_no 	= ( isset( $_GET['autoload'] ) && $_GET['autoload'] == 'no' ) 	? "selected='selected'" : "";

					?>

						<div>
							<label class="aDBc-filter-label">
								<?php _e('Autoload', 'advanced-database-cleaner' ); ?>
							</label>

							<select name="autoload" class="aDBc-filter-dropdown-menu" style="width:100px">

								<option value="all" <?php echo $all_autoload; ?>>

									<?php _e( 'All', 'advanced-database-cleaner' ); ?>

								</option>

								<option value="yes" <?php echo $autoload_yes; ?>>

									<?php 
									echo __( 'Yes', 'advanced-database-cleaner' );
									if(function_exists('wp_autoload_values_to_autoload')){
										echo " [on, auto, auto-on]";
									}
									?>&nbsp;

								</option>

								<option value="no" <?php echo $autoload_no; ?>>

									<?php 
										echo __( 'No', 'advanced-database-cleaner' );								
										if(function_exists('wp_autoload_values_to_autoload')){
											echo " [off, auto-off]";
										}
									?>

								</option>

							</select>
						</div>

					<?php
					}
					?>

					<div>
						<label class="aDBc-filter-label">
							<?php _e( 'Belongs to', 'advanced-database-cleaner' ); ?>
						</label>
						<select name="belongs_to" class="aDBc-filter-dropdown-menu" style="width:135px">

							<option value="all">
								<?php _e( 'All', 'advanced-database-cleaner' ); ?>
							</option>

							<?php

							$total_plugins 	= 0;
							$total_themes 	= 0;

							foreach ( $this->array_belongs_to_counts as $name => $info ) {

								if ( $info['type'] == "p" ) {

									$total_plugins++;

								} elseif ( $info['type'] == "t" ) {

									$total_themes++;

								}

							}
							?>

							<optgroup label="<?php echo __( 'Plugins', 'advanced-database-cleaner' ) . " (" . $total_plugins . ")"  ?>">

								<?php
								foreach ( $this->array_belongs_to_counts as $name => $info ) {

									if ( $info['type'] == "p" ) {

										$selected = isset( $_GET['belongs_to'] ) && $_GET['belongs_to'] == $name ? "selected='selected'" : "";

										echo "<option value='$name'" . $selected . ">" . $name . " (" . $info['count'] .")" . "</option>";

									}
								}
								?>

							</optgroup>

							<optgroup label="<?php echo __( 'Themes', 'advanced-database-cleaner' ) . " (" . $total_themes . ")" ?>">

								<?php
								foreach ( $this->array_belongs_to_counts as $name => $info ) {

									if ( $info['type'] == "t" ) {

										$selected = isset( $_GET['belongs_to'] ) && $_GET['belongs_to'] == $name ? "selected='selected'" : "";

										echo "<option value='$name'" . $selected . ">" . $name . " (" . $info['count'] .")" . "</option>";

									}

								}
								?>

							</optgroup>

						</select>
					</div>

					<?php
					if ( function_exists( 'is_multisite' ) && is_multisite() ){
					?>

						<div>
							<label class="aDBc-filter-label">
								<?php _e( 'In site', 'advanced-database-cleaner' ); ?>
							</label>

							<select name="site" class="aDBc-filter-dropdown-menu" style="width:85px">

								<option value=""> <?php _e( 'All', 'advanced-database-cleaner' ); ?> </option>

								<?php
								global $wpdb;
								$blogs_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

								foreach ( $blogs_ids as $blog_id ) {

									$blog_details = get_blog_details( $blog_id );

									$selected = ( isset( $_GET['site'] ) && $_GET['site'] == $blog_id ) ? "selected='selected'" : "";

									echo "<option value='$blog_id'". $selected .">" . __( 'Site', 'advanced-database-cleaner' ) . " ". $blog_id . " | " . $blog_details->blogname . "</option>";

								}
								?>

							</select>
						</div>

					<?php
					}
					?>

					<div>
						<label class="aDBc-filter-label" style="visibility:hidden">
							Submit
						</label>
						<input class="button-secondary aDBc-filter-botton" type="submit" value="<?php _e( 'Filter', 'advanced-database-cleaner' ); ?>"/>
					</div>

				</div>
			</form>

			<?php
			if ( ADBC_PLUGIN_PLAN == "free" ) {
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
	</div>

	<!-- Items per page -->
	<div class="aDBc-items-per-page">

		<form method="get">

			<?php
			// Generate current parameters in URL
			foreach ( $_GET as $name => $value ) {

				if ( $name != "per_page" && $name != "paged" ) {

					$name 	= esc_attr( sanitize_text_field( $name ) );
					$value 	= esc_attr( sanitize_text_field( $value ) );
					echo "<input type='hidden' name='$name' value='$value'/>";

				}
			}

			// Return paged to page 1
			echo "<input type='hidden' name='paged' value='1'/>";
			?>

			<div>
				<label class="aDBc-filter-label" style="font-weight:normal">
					<?php _e( 'Items per page', 'advanced-database-cleaner' ); ?>
				</label>
				
				<input name="per_page" class="aDBc-items-per-page-input" type="number" value="<?php echo empty( $_GET['per_page'] ) ? '50' : esc_attr( $_GET['per_page'] ); ?>"/>

				<input type="submit" class="button-secondary aDBc-show-botton" value="<?php _e( 'Show', 'advanced-database-cleaner' ); ?>"/>
			</div>

		</form>

	</div>

	<?php
	if ( ( ! empty( $_GET['s'] ) && trim( $_GET['s'] ) != "" ) ||
	       ! empty( $_GET['t_type'] ) ||
		   ! empty( $_GET['belongs_to'] ) ||
		   ! empty( $_GET['site'] )
		) {

		// Remove args to delete custom filter
		$aDBc_new_URI = $_SERVER['REQUEST_URI'];
		$aDBc_new_URI = remove_query_arg( array( 's', 't_type', 'belongs_to', 'site', 'autoload' ), $aDBc_new_URI );
		$aDBc_new_URI = add_query_arg( 'aDBc_cat', 'all', $aDBc_new_URI );
		?>

		<div class="aDBc-delete-custom-filter">
			<a style="color:red" href="<?php echo esc_url( $aDBc_new_URI ); ?>">
				<?php _e( 'Delete custom filter', 'advanced-database-cleaner' ); ?>
			</a>
		</div>

	<?php
	}
	?>

</div>