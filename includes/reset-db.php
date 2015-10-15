<?php
global $current_user;
$aDBc_admin = get_user_by( 'login', 'admin' );
$aDBc_admin_exists = 1;
if (!isset($aDBc_admin->user_login ) || $aDBc_admin->user_level < 10 ){
	$aDBc_admin_exists = 0;
}
?>
<div class="box-warning">
	<span style="color: #EA823A; font-weight:bold">WARNING:</span> The reset makes a fresh installation of your database. Therefore, ANY data in your database will be lost. This option is used primarily by developers who are testing their websites and who do not want to install wordpress after each test. Please do not use this option if you want to keep your posts and pages.
</div>
<div class="box-info">
	<ul style="list-style: disc outside none;">
		<li>The reset does not delete or modify any of your plugins files or server files.</li>
		<li>All your plugins will be deactivated (including this one). You should activate them manually after the reset.</li>
		<?php if ($aDBc_admin_exists) { ?>
		<li>The '<strong>admin</strong>' user exists and will be recreated with its <strong>current password</strong>.</li>
		<?php } else {?>
		<li>The 'admin' user does not exist. The user '<strong><?php echo esc_html( $current_user->user_login ); ?></strong>' will be recreated with its <strong>current password</strong> with user level 10.</li>
		<?php } ?>
		<li>After the reset, you will be redirected to the admin login page.</li>
	</ul>
</div>
<h3 style="color:#32373c; padding-top: 10px">Reset database</h3>
<p>Type '<strong>reset</strong>' in the confirmation field below to confirm the reset and then click the reset button.</p>
<form id="wordpress_reset_form" action="" method="post">
	<input type="hidden" name="aDBc_reset_form" value="" />
	<input type="text" name="aDBc_reset_comfirmation" value="" />
	<p class="submit">
		<input type="submit" class="button-primary" value="Reset database" style="width: 130px; height: 50px; text-align:center; font-weight:bold"/>
	</p>
</form>
