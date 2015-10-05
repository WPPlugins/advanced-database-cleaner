<?php
global $current_user;
$aDBc_admin = get_user_by( 'login', 'admin' );
$aDBc_admin_exists = 1;
if (!isset($aDBc_admin->user_login ) || $aDBc_admin->user_level < 10 ){
	$aDBc_admin_exists = 0;
}
?>



<h4 style="color:#32373c; font-size:15px">Details about the reset:</h4>

<ul style="list-style: square outside none; margin-left:40px;">
	<li><font color="red"><b>ANY</b></font> data in your database will be lost. The reset makes a fresh installation of your database.</li>
	<li>The reset does not delete or modify any of your plugins files or server files.</li>
	<li>All your plugins will be deactivated (including this one). You should activate them manually after the reset.</li>
	<?php if ($aDBc_admin_exists) { ?>
	<li>The '<strong>admin</strong>' user exists and will be recreated with its <strong>current password</strong>.</li>
	<?php } else {?>
	<li>The 'admin' user does not exist. The user '<strong><?php echo esc_html( $current_user->user_login ); ?></strong>' will be recreated with its <strong>current password</strong> with user level 10.</li>
	<?php } ?>
	<li>After the reset, you will be redirected to the admin login page.</li>
</ul>
<br/>
<h3 style="color:#32373c">Go reset</h3>
<p>Type '<strong>reset</strong>' in the confirmation field below to confirm the reset and then click the reset button.</p>

<form id="wordpress_reset_form" action="" method="post">
	<input type="hidden" name="aDBc_reset_form" value="" />
	<input type="text" name="aDBc_reset_comfirmation" value="" />
	<p class="submit">
		<input type="submit" class="button-primary" value="Reset database" style="width: 130px; height: 50px; text-align:center; font-weight:bold"/>
	</p>
</form>
