<?php
/*
 Plugin Name: Shibboleth
 Plugin URI: http://wordpress.org/extend/plugins/shibboleth
 Description: Easily externalize user authentication to a <a href="http://shibboleth.internet2.edu">Shibboleth</a> Service Provider
 Author: Will Norris
 Author URI: http://willnorris.com/
 Version: 0.1
 License: Apache 2 (http://www.apache.org/licenses/LICENSE-2.0.html)
 */

define ( 'SHIBBOLETH_PLUGIN_REVISION', preg_replace( '/\$Rev: (.+) \$/', '\\1',
	'$Rev$') ); // this needs to be on a separate line so that svn:keywords can work its magic


// run activation function if new revision of plugin
if (get_option('shibboleth_plugin_revision') === false || SHIBBOLETH_PLUGIN_REVISION != get_option('shibboleth_plugin_revision')) {
	shibboleth_activate_plugin();
}

/**
 * Activate the plugin.  This registers default values for all of the 
 * Shibboleth options and attempts to add the appropriate mod_rewrite rules to 
 * WordPress's .htaccess file.
 */
function shibboleth_activate_plugin() {
	add_option('shibboleth_login_url', get_option('home') .  
		'/Shibboleth.sso/Login');
	add_option('shibboleth_logout_url', get_option('home') .  
		'/Shibboleth.sso/Logout');

	$headers = array(
		'username' => 'eppn',
		'first_name' => 'givenName',
		'last_name' => 'sn',
		'display_name' => 'displayName',
		'email' => 'mail',
	);
	add_option('shibboleth_headers', $headers);

	$roles = array(
		'administrator' => array(
			'header' => 'entitlement',
			'value' => 'urn:mace:example.edu:entitlement:wordpress:admin',
		),
		'author' => array(
			'header' => 'affiliation',
			'value' => 'faculty',
		),
		'default' => 'subscriber',
	);
	add_option('shibboleth_roles', $roles);

	add_option('shibboleth_update_users', true);
	add_option('shibboleth_update_roles', true);

	shibboleth_insert_htaccess();
}
register_activation_hook('shibboleth/shibboleth.php', 'shibboleth_activate_plugin');


//TODO deactivation hook


/**
 * Use the 'authenticate' filter if it is available (WordPress >= 2.8).
 * Otherwise, hook into 'init'.
 */
if (has_filter('authenticate')) {
	add_filter('authenticate', 'shibboleth_authenticate', 10, 3);
} else {
	add_action('init', 'shibboleth_wp_login');
}


/**
 * Authenticate the user.
 */
function shibboleth_authenticate($user, $username, $password) {
	global $action;
	if ($action == 'local_login' || array_key_exists('loggedout', $_REQUEST) || array_key_exists('wp-submit', $_POST)) return $user;

	if ($_SERVER['Shib-Session-ID'] || $_SERVER['HTTP_SHIB_IDENTITY_PROVIDER']) {
		return shibboleth_authenticate_user();
	} else {
		shibboleth_start_login();
	}
}


/**
 * Process requests to wp-login.php.
 */
function shibboleth_wp_login() {
	if ($GLOBALS['pagenow'] != 'wp-login.php') return;

	switch ($GLOBALS['action']) {
		case 'local_login':
			add_action('login_form', 'shibboleth_login_form');
			break;

		case 'login':
		case '':
			if (array_key_exists('wp-submit', $_POST)) break;

			if ($_SERVER['Shib-Session-ID'] || $_SERVER['HTTP_SHIB_IDENTITY_PROVIDER']) {
				shibboleth_finish_login();
			} else {
				shibboleth_start_login();
			}
			break;

		// TODO: redirect lostpassword action to institution password reset page

		default:
			break;
	}
}


/**
 * After logging out of WordPress, log user out of Shibboleth.
 */
function shibboleth_logout() {
	$logout_url = get_option('shibboleth_logout_url');
	wp_redirect($logout_url);
	exit;
}
add_action('wp_logout', 'shibboleth_logout', 20);


/**
 * Initiate Shibboleth Login by redirecting user to the Shibboleth login URL.
 */
function shibboleth_start_login() {
	$login_url = shibboleth_login_url();
	wp_redirect($login_url);
	exit;
}


/**
 * Generate the URL to initiate Shibboleth login.
 *
 * @return the URL to direct the user to in order to initiate Shibboleth login
 */
function shibboleth_login_url() {
	$target = site_url('wp-login.php');
	$target = add_query_arg('redirect_to', urlencode($_REQUEST['redirect_to']), $target);
	$target = add_query_arg('action', 'login', $target);

	$login_url = get_option('shibboleth_login_url');
	$login_url = add_query_arg('target', urlencode($target), $login_url);

	return $login_url;
}


/**
 * Authenticate the user based on the current Shibboleth headers.
 *
 * If the data available does not map to a WordPress role (based on the
 * configured role-mapping), the user will not be allowed to login.
 *
 * If this is the first time we've seen this user (based on the username
 * attribute), a new account will be created.
 *
 * Known users will have their profile data updated based on the Shibboleth
 * data present if the plugin is configured to do so.
 *
 * @return WP_User|WP_Error authenticated user or error if unable to authenticate
 */
function shibboleth_authenticate_user() {
	$shib_headers = get_option('shibboleth_headers');

	// ensure user is authorized to login
	$user_role = shibboleth_get_user_role();
	if (empty($user_role)) {
		return new WP_Error('no_access', __('You do not have sufficient access.'));
	}

	$username = $_SERVER[$shib_headers['username']];
	$user = new WP_User($username);

	if ($user->ID) {
		if (!get_usermeta($user->ID, 'shibboleth_account')) {
			// TODO: what happens if non-shibboleth account by this name already exists?
			//return new WP_Error('invalid_username', __('Account already exists by this name.'));
		}
	}

	// create account if new user
	if (!$user->ID) {
		$user = shibboleth_create_new_user($username);
	}

	if (!$user->ID) {
		$error_message = 'Unable to create account based on data provided.';
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$error_message .= '<!-- ' . print_r($_SERVER, true) . ' -->';
		}
		return new WP_Error('missing_data', $error_message);
	}

	// update user data
	update_usermeta($user->ID, 'shibboleth_account', true);
	if (get_option('shibboleth_update_users')) shibboleth_update_user_data($user->ID);
	if (get_option('shibboleth_update_roles')) $user->set_role($user_role);

	return $user;
}


/**
 * Finish logging a user in based on the Shibboleth headers present.
 *
 * This function is only used if the 'authenticate' filter is not present.  
 * This filter was added in WordPress 2.8, and will take care of everything 
 * shibboleth_finish_login is doing.
 */
function shibboleth_finish_login() {
	$user = shibboleth_authenticate_user();

	if (is_wp_error($user)) {
		wp_die($user->get_error_message());
	}

	// log user in
	set_current_user($user->ID);
	wp_set_auth_cookie($user->ID, $remember);
	do_action('wp_login', $user->user_login);

	// redirect user to whever they were going
	$request_redirect = (isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : '');
	$redirect_to = ($request_redirect ? $request_redirect : admin_url());
	$redirect_to = apply_filters('login_redirect', $redirect_to, $request_redirect, $user);
	if ( !$user->has_cap('edit_posts') && ( empty($redirect_to) || $redirect_to == 'wp-admin/' ) )  {
		$redirect_to = admin_url('profile.php');
	}
	wp_safe_redirect($redirect_to);
	exit();
}


/**
 * Create a new WordPress user account, and mark it as a Shibboleth account.
 *
 * @param string $user_login login name for the new user
 * @return object WP_User object for newly created user
 */
function shibboleth_create_new_user($user_login) {
	if (empty($user_login)) return null;

	// create account and flag as a shibboleth acount
	require_once( ABSPATH . WPINC . '/registration.php');
	$user_id = wp_insert_user(array('user_login'=>$user_login));
	$user = new WP_User($user_id);
	update_usermeta($user->ID, 'shibboleth_account', true);

	// always update user data and role on account creation
	shibboleth_update_user_data($user->ID);
	$user_role = shibboleth_get_user_role();
	$user->set_role($user_role);

	return $user;
}


/**
 * Get the role the current user should have.  This is determined by the role
 * mapping configured for the plugin, and the Shibboleth headers present at the
 * time of login.
 *
 * return string the role the current user should have
 */
function shibboleth_get_user_role() {
	global $wp_roles;
	if (!$wp_roles) $wp_roles = new WP_Roles();

	$shib_roles = get_option('shibboleth_roles');
	$user_role = $shib_roles['default'];

	foreach ($wp_roles->role_names as $key => $name) {
		$role_header = $shib_roles[$key]['header'];
		$role_value = $shib_roles[$key]['value'];

		if (empty($role_header) || empty($role_value)) continue;

		$values = split(';', $_SERVER[$role_header]);
		if (in_array($role_value, $values)) {
			$user_role = $key;
			break;
		}
	}

	return $user_role;
}


/**
 * Update the user data for the specified user based on the current Shibboleth headers.
 *
 * @param int $user_id ID of the user to update
 */
function shibboleth_update_user_data($user_id) {
	require_once( ABSPATH . WPINC . '/registration.php');

	$shib_headers = get_option('shibboleth_headers');

	$user_data = array(
		'ID' => $user_id,
		'user_login' => $_SERVER[$shib_headers['username']],
		'user_nicename' => $_SERVER[$shib_headers['username']],
		'first_name' => $_SERVER[$shib_headers['first_name']],
		'last_name' => $_SERVER[$shib_headers['last_name']],
		'display_name' => $_SERVER[$shib_headers['display_name']],
		'user_email' => $_SERVER[$shib_headers['email']],
	);

	wp_update_user($user_data);
}


/**
 * Add a "Login with Shibboleth" link to the WordPress login form.
 */
function shibboleth_login_form() {
	$login_url = shibboleth_login_url();
	echo '<p><a href="' . $login_url . '">Login with Shibboleth</a></p>';
}


/**
 * For WordPress accounts that were created by Shibboleth, limit what profile
 * attributes they can modify.
 */
function shibboleth_profile_personal_options() {
	$user = wp_get_current_user();
	if (get_usermeta($user->ID, 'shibboleth_account')) {
		add_filter('show_password_fields', create_function('$v', 'return false;'));
		// TODO: add link to institution's password change page

		if (get_option('shibboleth_update_users')) {
			echo '
			<script type="text/javascript">
				var cannot_change = " This field cannot be changed from WordPress.";
				jQuery(function() {
					jQuery("#first_name,#last_name,#nickname,#display_name,#email")
						.attr("disabled", true).after(cannot_change);
				});
			</script>';
		}
	}
}


/**
 * Ensure profile data isn't updated by the user.  This only applies to 
 * accounts that were provisioned through Shibboleth, and only if the option
 * to manage user attributes exclusively from Shibboleth is enabled.
 */
function shibboleth_personal_options_update() {
	$user = wp_get_current_user();

	if (get_usermeta($user->ID, 'shibboleth_account') && get_option('shibboleth_update_users')) {
		add_filter('pre_user_first_name', 
			create_function('$n', 'return $GLOBALS["current_user"]->first_name;'));

		add_filter('pre_user_last_name', 
			create_function('$n', 'return $GLOBALS["current_user"]->last_name;'));

		add_filter('pre_user_nickname', 
			create_function('$n', 'return $GLOBALS["current_user"]->nickname;'));

		add_filter('pre_user_display_name', 
			create_function('$n', 'return $GLOBALS["current_user"]->display_name;'));

		add_filter('pre_user_email', 
			create_function('$e', 'return $GLOBALS["current_user"]->user_email;'));
	}
}


/**
 * Setup admin menus for Shibboleth options.
 *
 * @action: admin_menu
 **/
function shibboleth_admin_panels() {
	// global options page
	$hookname = add_options_page(__('Shibboleth options', 'shibboleth'), __('Shibboleth', 'shibboleth'), 8, 'shibboleth-options', 'shibboleth_options_page' );

	add_action('profile_personal_options', 'shibboleth_profile_personal_options');
	add_action('personal_options_update', 'shibboleth_personal_options_update');
}
add_action('admin_menu', 'shibboleth_admin_panels');


/**
 * WordPress options page to configure the Shibboleth plugin.
 */
function shibboleth_options_page() {
	global $wp_roles;

	if (isset($_POST['submit'])) {
		check_admin_referer('shibboleth_update_options');

		$shib_headers = get_option('shibboleth_headers');
		if (!is_array($shib_headers)) $shib_headers = array();
		$shib_headers = array_merge($shib_headers, $_POST['headers']);
		update_option('shibboleth_headers', $shib_headers);

		$shib_roles = get_option('shibboleth_roles');
		if (!is_array($shib_roles)) $shib_roles = array();
		$shib_roles = array_merge($shib_roles, $_POST['shibboleth_roles']);
		update_option('shibboleth_roles', $shib_roles);

		update_option('shibboleth_login_url', $_POST['login_url']);
		update_option('shibboleth_logout_url', $_POST['logout_url']);
		update_option('shibboleth_update_users', $_POST['update_users']);
		update_option('shibboleth_update_roles', $_POST['update_roles']);
	}

	$shib_headers = get_option('shibboleth_headers');
	$shib_roles = get_option('shibboleth_roles');

	screen_icon('shibboleth');

?>
	<style type="text/css">
		#icon-shibboleth { background: url("<?php echo plugins_url('shibboleth/icon.png') ?>") no-repeat; height: 36px width: 36px; }
	</style>

	<div class="wrap">
		<form method="post">

			<h2><?php _e('Shibboleth Options', 'shibboleth') ?></h2>

			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="login_url"><?php _e('Session Initiator URL') ?></label</th>
					<td>
						<input type="text" id="login_url" name="login_url" value="<?php echo get_option('shibboleth_login_url') ?>" size="50" />
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="logout_url"><?php _e('Logout URL') ?></label</th>
					<td><input type="text" id="logout_url" name="logout_url" value="<?php echo get_option('shibboleth_logout_url') ?>" size="50" /></td>
				</tr>
			</table>

			<br class="clear" />

			<h3><?php _e('User Profile Data', 'shibboleth') ?></h3>

			<p>Define the Shibboleth headers which should be mapped to each
			user profile attribute.  These header names are configured in
			<code>attribute-map.xml</code> (for Shibboleth 2.x) or
			<code>AAP.xml</code> (for Shibboleth 1.x).</p>

			<table class="form-table optiontable editform" cellspacing="2" cellpadding="5" width="100%">
				<tr valign="top">
					<th scope="row"><label for="username"><?php _e('Username') ?></label</th>
					<td><input type="text" id="username" name="headers[username]" value="<?php echo $shib_headers['username'] ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="first_name"><?php _e('First name') ?></label</th>
					<td><input type="text" id="first_name" name="headers[first_name]" value="<?php echo $shib_headers['first_name'] ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="last_name"><?php _e('Last name') ?></label</th>
					<td><input type="text" id="last_name" name="headers[last_name]" value="<?php echo $shib_headers['last_name'] ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="display_name"><?php _e('Display name') ?></label</th>
					<td><input type="text" id="display_name" name="headers[display_name]" value="<?php echo $shib_headers['display_name'] ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="email"><?php _e('Email Address') ?></label</th>
					<td><input type="text" id="email" name="headers[email]" value="<?php echo $shib_headers['email'] ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="update_users"><?php _e('Update User Data') ?></label</th>
					<td>
						<input type="checkbox" id="update_users" name="update_users" <?php echo get_option('shibboleth_update_users') ? ' checked="checked"' : '' ?> />
						<label for="update_users">Use Shibboleth data to update user profile data each time the user logs in.  This will prevent users from being
						able to manually update these fields.</label>
					  	<p>(Shibboleth data is always used to populate the user profile during account creation.)</p>

					</td>
				</tr>
			</table>

			<br class="clear" />

			<h3><?php _e('User Role Mappings', 'shibboleth') ?></h3>

			<p>Users can be placed into one of WordPress's internal roles
			based on any attribute.  For example, you could define a special
			eduPersonEntitlement value that designates the user as a WordPress
			Administrator.  Or you could automatically place all users with an
			eduPersonAffiliation of "faculty" in the Author role.</p>

			<p><strong>Current Limitations:</strong> While WordPress supports
			users having multiple roles, the Shibboleth plugin will only place
			the user in the highest ranking role.  Only a single header/value
			pair is supported for each user role.  This may be expanded in the
			future to support multiple header/value pairs or regular expression
			values.</p>

			<style type="text/css">
				#role_mappings { padding: 0; }
				#role_mappings thead th { padding: 5px 10px; }
				#role_mappings td, #role_mappings th { border-bottom: 0px; }
			</style>

			<table class="form-table optiontable editform" cellspacing="2" cellpadding="5" width="100%">

				<tr>
					<th scope="row">Role Mappings</th>
					<td id="role_mappings">
						<table id="">
						<thead>
							<tr>
								<th></th>
								<th scope="column">Header Name</th>
								<th scope="column">Header Value</th>
							</tr>
						</thead>
						<tbody>
<?php

					foreach ($wp_roles->role_names as $key => $name) {
						echo'
						<tr valign="top">
							<th scope="row">'.$name.'</th>
							<td><input type="text" id="role_'.$key.'_header" name="shibboleth_roles['.$key.'][header]" value="' . @$shib_roles[$key]['header'] . '" /></td>
							<td><input type="text" id="role_'.$key.'_value" name="shibboleth_roles['.$key.'][value]" value="' . @$shib_roles[$key]['value'] . '" /></td>
						</tr>';
					}
?>

						</tbody>
						</table>
					</td>
				</tr>

				<tr>
					<th scope="row">Default Role</th>
					<td>
						<p>If a user does not map into any of the roles above,
						they will be placed into the default role.  If there is
						no default role, the user will not be able to
						login with Shibboleth.</p>

						<select id="default_role" name="shibboleth_roles[default]">
						<option value="">(none)</option>';

<?php
			foreach ($wp_roles->role_names as $key => $name) {
				echo '
						<option value="'.$key.'"'. ($shib_roles['default'] == $key ? ' selected="selected"' : '') . '>'.$name.'</option>';
			}
?>

					</select></td>
				</tr>

				<tr>
					<th scope="row">Update User Roles</th>
					<td>
						<input type="checkbox" id="update_roles" name="update_roles" <?php echo get_option('shibboleth_update_roles') ? ' checked="checked"' : '' ?> />
						<label for="update_roles">Use Shibboleth data to update user role mappings each time the user logs in.  This
						will prevent you from setting user roles manually within WordPress.</label>
					  	<p>(Shibboleth data is always used to populate the initial user role during account creation.)</p>
					</td>
				</tr>
			</table>


			<?php wp_nonce_field('shibboleth_update_options') ?>
			<p class="submit"><input type="submit" name="submit" value="<?php _e('Update Options') ?>" /></p>
		</form>
	</div>

<?php
}


/**
 * Insert directives into .htaccess file to enable Shibboleth Lazy Sessions.
 */
function shibboleth_insert_htaccess() {
	if (got_mod_rewrite()) {
		$htaccess = get_home_path() . '.htaccess';
		$rules = array('AuthType Shibboleth', 'Require Shibboleth');
		insert_with_markers($htaccess, 'Shibboleth', $rules);
	}
}

?>
