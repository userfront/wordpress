<?php
/**
 * Userfront Wordpress
 * 
 * @package UserfrontWordpress
 * @author Userfront
 * @version 1.2.2
 * 
 * @wordpress-plugin
 * Plugin Name: Userfront Auth
 * Plugin URI: https://github.com/userfront/wordpress
 * Description: Userfront is a premier auth & identity platform. Install full-fledged authentication and authorization with 2FA/MFA and OAuth to WordPress within minutes. 
 * Author: Userfront 
 * Version: 1.2.2
 * Author URI: https://userfront.com
 */

/**
 * Helpers
 */

// Fetch data from a URL
function fetch($url, $method, $data = false, $headers = array())
{
	$ch = curl_init();

	switch ($method) {
		case 'POST':
			curl_setopt($ch, CURLOPT_POST, 1);
			if ($data) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			}
			break;

		case 'PUT':
			curl_setopt($ch, CURLOPT_PUT, 1);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
			if ($data) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			}
			break;

		case 'DELETE':
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
			if ($data) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			}
			break;

		default:
			if ($data) {
				$url = sprintf('%s?%s', $url, http_build_query($data));
			}
	}

	curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, array('Content-Type: application/json')));

	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	$result = curl_exec($ch);

	curl_close($ch);

	return $result;
}

// Add redirect header and kill the script
function redirect($url, $permanent = false)
{
	header('Location: ' . $url, true, $permanent ? 301 : 302);

	exit();
}

// Delete a cookie
function delete_cookie($cookieName, $useRealCookieName = true)
{
	if (isset($_COOKIE[$cookieName])) {
		unset($_COOKIE[$cookieName]);
	}

	$realCookieName = $useRealCookieName ? str_replace('_', '.', $cookieName) : $cookieName;
	if ($realCookieName && is_string($realCookieName)) {
		setcookie($realCookieName, '', time() - 3600, '/');
	}
}

// Get the self object from Userfront
function get_self($jwt)
{
	// TODO: Use jwt.verify instead
	$url = 'https://api.userfront.com/v0/self';
	$data = fetch($url, 'GET', false, array('Authorization: Bearer ' . $jwt));
	return json_decode($data);
}
function update_self($jwt, $data)
{
	$url = 'https://api.userfront.com/v0/self';
	$data_json = json_encode($data);
	$data = fetch($url, 'PUT', $data_json, array('Authorization: Bearer ' . $jwt));
	return json_decode($data);
}

// Insert a new user into the WordPress database, if it doesn't exist
// update the user if it does exist
function update_user($self, $wpUserId)
{
	$workspaceId = get_option(
		'userfront-workspaceId'
	);
	$organizationId = get_option(
		'userfront-organizationId'
	);
	$tenantId = isset($organizationId) ? $organizationId : $workspaceId;
	if (is_int($wpUserId)) {
		$wpUser = new WP_User($wpUserId);

		if (property_exists($self->authorization, $tenantId)) {
			$roles = $self->authorization->$tenantId->roles;
			foreach ($roles as $role) {
				if ($role === 'admin') {
					$wpUser->set_role('administrator');
					continue;
				}
				// Check if the role exists
				$wpRole = get_role($role);
				if (!isset($wpRole)) {
					// Create the new role
					add_role(
						$role,
						mb_convert_case($role, MB_CASE_TITLE, 'UTF-8')
					);
				}
				// Assign the new role to the user
				$wpUser->set_role($role);
			}
		}
	}
}

/**
 * Userfront database table
 */

// Create
function wp_create_database_table()
{
	global $wpdb;

	$table_name = $wpdb->prefix . 'userfront';

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
		loginPageId text DEFAULT NULL,
		signupPageId text DEFAULT NULL,
		resetPasswordPageId text DEFAULT NULL,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
}

// Insert
function wp_insert_record_into_table()
{
	global $wpdb;

	$table_name = $wpdb->prefix . 'userfront';

	$wpdb->insert(
		$table_name,
		array(
			'time' => current_time('mysql')
		)
	);
}

// Read
function wp_select_records_from_table()
{
	global $wpdb;

	$table_name = $wpdb->prefix . 'userfront';

	$results = $wpdb->get_results("SELECT * FROM $table_name");

	return $results;
}

// Update
function wp_update_record_in_table($loginPageId, $signupPageId, $resetPasswordPageId)
{
	global $wpdb;

	$table_name = $wpdb->prefix . 'userfront';

	$wpdb->update(
		$table_name,
		array(
			'loginPageId' => $loginPageId,
			'signupPageId' => $signupPageId,
			'resetPasswordPageId' => $resetPasswordPageId,
			'time' => current_time('mysql')
		),
		array('id' => 1)
	);
}

// Delete
function wp_delete_table()
{
	global $wpdb;

	$table_name = $wpdb->prefix . 'userfront';

	$wpdb->query("DROP TABLE IF EXISTS $table_name");
}

/**
 * Hooks
 */
// Fires after WordPress has finished loading but before any headers are sent
add_action('init', 'init');
function init()
{
	$workspaceId = get_option(
		'userfront-workspaceId'
	);
	$sourceOfTruth = get_option(
		'userfront-sourceOfTruth'
	);
	$redirectLogin = get_option(
		'userfront-redirect'
	) && !isset($_GET['bypass']);

	if (isset($workspaceId)) {
		$isPantheon = isset($_ENV['PANTHEON_ENVIRONMENT']);
		$cookiePrefix = $isPantheon ? 'STYXKEY_' : '';

		$userfrontAccessCookie = 'access_' . $workspaceId;
		$accessCookie = $cookiePrefix . $userfrontAccessCookie;
		$userfrontIdCookie = 'id_' . $workspaceId;
		$idCookie = $cookiePrefix . $userfrontIdCookie;
		$userfrontRefreshCookie = 'refresh_' . $workspaceId;
		$refreshCookie = $cookiePrefix . $userfrontRefreshCookie;

		$isLoggedIntoUserfront = isset($_COOKIE[$accessCookie]) && isset($_COOKIE[$idCookie]) && isset($_COOKIE[$refreshCookie]);

		$isWpLoginRoute = str_starts_with(
			$_SERVER['REQUEST_URI'],
			'/wp-login.php'
		);
		$isLoginRoute = str_starts_with(
			$_SERVER['REQUEST_URI'],
			'/login'
		);
		$isPostLoginRoute = str_starts_with(
			$_SERVER['REQUEST_URI'],
			'/post-login'
		);
		$isLogoutAction = isset($_GET['action']) && $_GET['action'] == 'logout';

		$isCreateAccountEnabled = get_option(
			'userfront-account-creation',
			true
		);

		if ($isPantheon && $isPostLoginRoute) {
			echo '<script type="text/javascript">
				function updateCookies() {
					const cookies = document.cookie.split("; ").reduce((prev, current) => {
						const [name, ...value] = current.split("=");
						prev[name] = value.join("=");
						return prev;
					}, {});
					["id", "refresh", "access"].forEach(key => document.cookie = "STYXKEY_" + key + "_' . $workspaceId . '=" + cookies[key + ".' . $workspaceId . '"]);
					window.location.href = "/dashboard";
				}
				updateCookies();
			</script>';
			die();
		}

		if (($isLoginRoute || $isWpLoginRoute) && $isLogoutAction) {
			delete_cookie($userfrontAccessCookie);
			delete_cookie($userfrontIdCookie);
			delete_cookie($userfrontRefreshCookie);

			delete_cookie($accessCookie, false);
			delete_cookie($idCookie, false);
			delete_cookie($refreshCookie, false);

			wp_logout();

			die();
		} elseif ($redirectLogin && $isWpLoginRoute) {
			redirect('/login');
		}

		$isLoggedIn = is_user_logged_in();

		if (
			!$isLoggedIn && $isLoggedIntoUserfront
		) {
			$self = get_self(
				$_COOKIE[$accessCookie]
			);
			$name = explode(' ', $self->name);
			$firstName = $name[0];
			$lastName = $name[count($name) - 1];

			if (isset($self) && property_exists($self, 'email')) {
				// Search for the user by email
				$user = get_user_by('email', $self->email);

				if ($user) {
					if ($sourceOfTruth === 'userfront') {
						// Update the WordPress user
						$wpUserId = wp_insert_user(
							array(
								'ID' => $user->ID,
								'first_name' => $firstName,
								'last_name' => $lastName,
								'display_name' => $self->name,
								'user_login' => $self->username,
								'user_email' => $self->email,
							)
						);

						update_user(
							$self,
							$wpUserId
						);
					} else if ($sourceOfTruth === 'wordpress') {
						$hasName = $user->first_name && $user->last_name;
						$wpUserName = $user->first_name . ' ' . $user->last_name;
						// Update the Userfront user
						update_self(
							$_COOKIE[$accessCookie],
							array(
								'username' => $user->user_login,
								'name' => $hasName ? $wpUserName : '',
							)
						);
					}

					wp_set_current_user($user->ID);
					wp_set_auth_cookie($user->ID, true);
				} else {
					if ($isCreateAccountEnabled) {
						// Insert a new WordPress user
						$wpUserId = wp_insert_user(
							array(
								'first_name' => $firstName,
								'last_name' => $lastName,
								'display_name' => $self->name,
								'user_login' => $self->username,
								'user_email' => $self->email,
								'user_pass' => wp_generate_password(),
							)
						);

						update_user(
							$self,
							$wpUserId
						);

						wp_set_current_user($wpUserId);
						wp_set_auth_cookie($wpUserId, true);
					} else {
						delete_cookie($userfrontAccessCookie);
						delete_cookie($userfrontIdCookie);
						delete_cookie($userfrontRefreshCookie);

						delete_cookie($accessCookie, false);
						delete_cookie($idCookie, false);
						delete_cookie($refreshCookie, false);

						if (!$isLoginRoute || !isset($_GET['error'])) {
							redirect('/login?error=no-wordpress-user');
						}
					}
				}
			}

			if ($isLoginRoute && !$isPantheon && $isCreateAccountEnabled) {
				if (isset($_GET['redirect_to'])) {
					redirect($_GET['redirect_to']);
				} else {
					redirect('/dashboard');
				}
			}
		}
	}
}

// Fires after a user is logged out
add_action('wp_logout', 'logout');
function logout()
{
	// Redirect to Userfront login page
	redirect('/login');
}

// Fires as an admin screen or script is being initialized
add_action(
	'admin_init',
	'add_admin_settings'
);
function add_admin_settings()
{
	add_settings_section(
		'userfront-settings',
		'Plugin Settings',
		'display_userfront_settings_message',
		'userfront-options-page'
	);

	add_settings_field(
		'userfront-workspaceId-input-field',
		'Workspace ID <p class="description">Reference the auth factors from this workspace.</p>',
		'display_userfront_workspace_field',
		'userfront-options-page',
		'userfront-settings',
	);

	register_setting(
		'userfront',
		'userfront-workspaceId',
		[
			'type' => 'string',
			'label' => 'Workspace ID',
			'description' => 'The multi-user organization ID to read and write roles (subscription required)',
		]
	);

	add_settings_field(
		'userfront-organizationId-input-field',
		'Organization ID <p class="description">The multi-user organization ID to read and write roles (subscription required).</p>',
		'display_userfront_organization_field',
		'userfront-options-page',
		'userfront-settings',
	);

	register_setting(
		'userfront',
		'userfront-organizationId',
		[
			'type' => 'string',
			'label' => 'Organization ID',
			'description' => 'The multi-user organization ID to read and write roles',
		]
	);

	add_settings_field(
		'userfront-login-checkbox',
		'Login <p class="description">Visitors may authenticate with Userfront</p>',
		'display_login_checkbox',
		'userfront-options-page',
		'userfront-settings',
	);

	register_setting(
		'userfront',
		'userfront-login',
		[
			'type' => 'boolean',
			'label' => 'Login',
			'description' => 'Visitors may authenticate with Userfront',
			'default' => true,
		]
	);

	add_settings_field(
		'userfront-signup-checkbox',
		'Signup <p class="description">Visitors may create a new Userfront account</p>',
		'display_signup_checkbox',
		'userfront-options-page',
		'userfront-settings',
	);

	register_setting(
		'userfront',
		'userfront-signup',
		[
			'type' => 'boolean',
			'label' => 'Signup',
			'description' => 'Visitors may create a new Userfront account',
			'default' => true,
		]
	);

	add_settings_field(
		'userfront-reset-password-checkbox',
		'Reset Password <p class="description">Visitors may reset their passwords.</p>',
		'display_reset_password_checkbox',
		'userfront-options-page',
		'userfront-settings',
	);

	register_setting(
		'userfront',
		'userfront-reset-password',
		[
			'type' => 'boolean',
			'label' => 'Reset Password',
			'description' => 'Visitors may reset their passwords',
			'default' => true,
		]
	);

	add_settings_field(
		'userfront-sourceOfTruth-checkbox',
		'Source of truth <p class="description">Overwrite user data such as first name, last name, and username from this source.</p>',
		'display_source_of_truth_checkbox',
		'userfront-options-page',
		'userfront-settings',
	);

	register_setting(
		'userfront',
		'userfront-sourceOfTruth',
		[
			'type' => 'string',
			'label' => 'Source of Truth',
			'description' => 'Use Userfront as the source of truth for user data',
		]
	);

	add_settings_field(
		'userfront-redirect-checkbox',
		'Redirect /wp-login.php to /login <p class="description">Use /wp-login.php?bypass to disable.</p>',
		'display_redirect_checkbox',
		'userfront-options-page',
		'userfront-settings',
	);

	register_setting(
		'userfront',
		'userfront-redirect',
		[
			'type' => 'boolean',
			'label' => 'Redirect /wp-login.php to /login',
			'description' => 'Redirect the WordPress login page to the Userfront login page',
		]
	);

	add_settings_field(
		'userfront-account-creation-checkbox',
		'Create a WordPress account <p class="description">After login or signup, create a new WordPress account. When disabled, block access.</p>',
		'display_account_creation_checkbox',
		'userfront-options-page',
		'userfront-settings',
	);

	register_setting(
		'userfront',
		'userfront-account-creation',
		[
			'type' => 'boolean',
			'label' => 'Create a WordPress account',
			'description' => 'After login or signup of a new user, create a new WordPress account with Userfront data',
			'default' => true,
		]
	);
}
function display_userfront_settings_message()
{
	esc_html_e('');
}
function display_userfront_workspace_field()
{
	$value = get_option('userfront-workspaceId');
	echo '<input type="text" id="userfront-workspaceId" name="userfront-workspaceId" value="' . $value . '" class="regular-text" />';
}

function display_userfront_organization_field()
{
	$value = get_option('userfront-organizationId');
	echo '<input type="text" id="userfront-organizationId" name="userfront-organizationId" value="' . $value . '" class="regular-text" />';
}

function display_login_checkbox()
{
	$value = get_option('userfront-login', true);
	echo '<input type="checkbox" id="userfront-login" name="userfront-login" ' . ($value ? 'checked ' : '') . '/>';
}
function display_signup_checkbox()
{
	$value = get_option('userfront-signup', true);
	echo '<input type="checkbox" id="userfront-signup" name="userfront-signup" ' . ($value ? 'checked ' : '') . '/>';
}
function display_reset_password_checkbox()
{
	$value = get_option('userfront-reset-password', true);
	echo '<input type="checkbox" id="userfront-reset-password" name="userfront-reset-password" ' . ($value ? 'checked ' : '') . '/>';
}
function display_source_of_truth_checkbox()
{
	$value = get_option('userfront-sourceOfTruth');
	echo '<select id="userfront-sourceOfTruth" name="userfront-sourceOfTruth">
	<option value="userfront" ' . selected($value, 'userfront', false) . '>Userfront</option>
		<option value="wordpress" ' . selected($value, 'wordpress', false) . '>WordPress</option>
		<option value="null" ' . selected($value, 'null', false) . '>Do nothing</option>
	</select>';
}
function display_redirect_checkbox()
{
	$value = get_option('userfront-redirect');
	echo '<input type="checkbox" id="userfront-redirect" name="userfront-redirect" ' . ($value ? 'checked ' : '') . '/>';
}
function display_account_creation_checkbox()
{
	$value = get_option('userfront-account-creation', true);
	echo '<input type="checkbox" id="userfront-account-creation" name="userfront-account-creation" ' . ($value ? 'checked ' : '') . '/>';
}

// Fires before the administration menu loads in the admin
add_action(
	'admin_menu',
	'add_admin_menu_page'
);
function add_admin_menu_page()
{
	add_menu_page(
		'Userfront Authentication',
		'Userfront',
		'administrator',
		'userfront',
		'display_userfront_menu_page',
		'data:image/svg+xml;base64,CjxzdmcgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB2aWV3Qm94PSIwIDAgOTAwIDkwMCI+CiAgPGc+CiAgICA8cGF0aCBzdHJva2Utd2lkdGg9IjAiIGQ9Im0xODQuMzMsNjMwLjU3bC0xNDMuMzYsMTQzLjM2Yy0xNS4xMiwxNS4xMi00MC45Nyw0LjQxLTQwLjk3LTE2Ljk3VjE1MEMwLDY3LjE2LDY3LjE2LDAsMTUwLDBoNjA2Ljk2YzIxLjM4LDAsMzIuMDksMjUuODUsMTYuOTcsNDAuOTdsLTEwOC43OCwxMDguNzhjLTYuMTgsNi4xOC0xNS4yMiw4LjUtMjMuNjIsNi4wOC0xMy4xOS0zLjc5LTI3LjEyLTUuODMtNDEuNTMtNS44M2gtMzAwYy04Mi44NCwwLTE1MCw2Ny4xNi0xNTAsMTUwdjE0NS44MmMtLjA5LDMtLjE1LDYuMDEtLjE1LDkuMDQsMCw1My4zNSwxMy45MywxMDMuNDQsMzguMzUsMTQ2Ljg1LDUuMyw5LjQzLDMuNzgsMjEuMjItMy44NywyOC44N1oiIGZpbGw9ImN1cnJlbnRDb2xvciIgLz4KICAgIDxwYXRoIHN0cm9rZS13aWR0aD0iMCIgZD0ibTc1MC4xNSwyMzQuNDZjLTYuMTksNi4xOS04LjUxLDE1LjI2LTYuMDcsMjMuNjcsMy44NiwxMy4yOSw1LjkyLDI3LjM0LDUuOTIsNDEuODd2MTU4aC0uMTljLTEuNDMsMTM4LjU0LTk2Ljc1LDI1NC41Ny0yMjUuMzYsMjg3LjQ5LTExLjkzLDMuMDUtMjQuMjEsNC41MS0zNi41Miw0LjUxaC03Ni4xM2MtMTIuNDIsMC0yNC44LTEuNDktMzYuODItNC41OC0yNy43NS03LjEzLTUzLjk2LTE4LjEzLTc3Ljk5LTMyLjM5LTkuNC01LjU4LTIxLjM5LTQuMDEtMjkuMTIsMy43MmwtMTQyLjI4LDE0Mi4yOGMtMTUuMTIsMTUuMTItNC40MSw0MC45NywxNi45Nyw0MC45N2g2MDcuNDVjODIuODQsMCwxNTAtNjcuMTYsMTUwLTE1MFYxNDIuNTVjMC0yMS4zOC0yNS44NS0zMi4wOS00MC45Ny0xNi45N2wtMTA4Ljg4LDEwOC44OFoiIGZpbGw9ImN1cnJlbnRDb2xvciIgLz4KICA8L2c+Cjwvc3ZnPg==',
		// last position
		999
	);
}
function display_userfront_menu_page()
{
	$isPantheon = isset($_ENV['PANTHEON_ENVIRONMENT']);
	$success = false;

	// Event listener for the plugin settings
	if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
		$workspaceId = get_option('userfront-workspaceId');
		$isLoginEnabled = get_option('userfront-login', true);
		$isSignupEnabled = get_option('userfront-signup', true);
		$isResetPasswordEnabled = get_option('userfront-reset-password', true);
		// Update the login, signup, and reset password pages with the new tenant ID
		$values = wp_select_records_from_table();
		foreach ($values as $value) {
			if ($isLoginEnabled && isset($value->loginPageId)) {
				// Update the login page with the new tenant ID
				wp_update_post(
					array(
						'ID' => $value->loginPageId,
						'post_content' => '<div id="userfront-error"></div><login-form tenant-id="' . $workspaceId . '"' . ($isPantheon ? ' redirect="/post-login" redirect-on-load-if-logged-in="true"' : '') . '></login-form>'
					)
				);
			} else if ($isLoginEnabled && !isset($value->loginPageId)) {
				// Insert the login page into the database
				$value->loginPageId = wp_insert_post(
					array(
						'post_title' => 'Login',
						'post_content' => '<login-form tenant-id="' . $workspaceId . '"' . ($isPantheon ? ' redirect="/post-login" redirect-on-load-if-logged-in="true"' : '') . '></login-form>',
						'post_status' => 'publish',
						'post_author' => 1,
						'post_type' => 'page',
					)
				);
			} else {
				// Delete the login page
				wp_delete_post($value->loginPageId, true);
			}
			if ($isSignupEnabled && isset($value->signupPageId)) {
				// Update the signup page with the new tenant ID
				wp_update_post(
					array(
						'ID' => $value->signupPageId,
						'post_content' => '<signup-form tenant-id="' . $workspaceId . '"></signup-form>'
					)
				);
			} else if ($isSignupEnabled && !isset($value->signupPageId)) {
				// Insert the signup page into the database
				$value->signupPageId = wp_insert_post(
					array(
						'post_title' => 'Signup',
						'post_content' => '<signup-form tenant-id="' . $workspaceId . '"></signup-form>',
						'post_status' => 'publish',
						'post_author' => 1,
						'post_type' => 'page',
					)
				);
			} else {
				// Delete the signup page
				wp_delete_post($value->signupPageId, true);
			}
			if ($isResetPasswordEnabled && isset($value->resetPasswordPageId)) {
				// Update the reset password page with the new tenant ID
				wp_update_post(
					array(
						'ID' => $value->resetPasswordPageId,
						'post_content' => '<password-reset-form tenant-id="' . $workspaceId . '"></password-reset-form>'
					)
				);
			} else if ($isResetPasswordEnabled && !isset($value->resetPasswordPageId)) {
				// Insert the reset password page into the database
				$value->resetPasswordPageId = wp_insert_post(
					array(
						'post_title' => 'Reset Password',
						'post_content' => '<password-reset-form tenant-id="' . $workspaceId . '"></password-reset-form>',
						'post_status' => 'publish',
						'post_author' => 1,
						'post_type' => 'page',
					)
				);
			} else {
				// Delete the reset password page
				wp_delete_post($value->resetPasswordPageId, true);
			}

			// Save the page IDs to the database
			wp_update_record_in_table(
				$isLoginEnabled ? $value->loginPageId : null,
				$isSignupEnabled ? $value->signupPageId : null,
				$isResetPasswordEnabled ? $value->resetPasswordPageId : null
			);
		}

		$success = true;
	}

	echo '<div class="wrap"><h1>Userfront Authentication</h1>';

	if ($isPantheon) {
		echo '<div class="notice notice-warning update-nag inline"><strong>Pantheon-mode Enabled</strong><br />Behavior is adjusted for cache-busting cookies.<br /><a href="https://docs.pantheon.io/cookies" target="_blank">Learn more about Working with Cookies on Pantheon.</a></div>';
	}

	if ($success) {
		echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>';
	}

	echo '<form method="post" action="options.php">';

	do_settings_sections('userfront-options-page');
	settings_fields('userfront');

	submit_button();

	echo '</form></div>';
}

// Fires after a plugin has been activated
register_activation_hook(
	__FILE__,
	'activation_hook'
);
function activation_hook()
{
	// Create the database table
	wp_create_database_table();
	// Insert the default record into the database
	wp_insert_record_into_table();
	// Insert the login page into the database
	$loginPageId = wp_insert_post(
		array(
			'post_title' => 'Login',
			'post_content' => 'Add your Workspace ID to <a href="/wp-admin/admin.php?page=userfront" target="_blank">the plugin settings</a> then reload this page. ☺️',
			'post_status' => 'publish',
			'post_author' => 1,
			'post_type' => 'page',
		)
	);
	// Insert the signup page into the database
	$signupPageId = wp_insert_post(
		array(
			'post_title' => 'Signup',
			'post_content' => 'Add your Workspace ID to <a href="/wp-admin/admin.php?page=userfront" target="_blank">the plugin settings</a> then reload this page. ☺️',
			'post_status' => 'publish',
			'post_author' => 1,
			'post_type' => 'page',
		)
	);
	// Insert the login page into the database
	$resetPasswordPageId = wp_insert_post(
		array(
			'post_title' => 'Reset Password',
			'post_content' => 'Add your Workspace ID to <a href="/wp-admin/admin.php?page=userfront" target="_blank">the plugin settings</a> then reload this page. ☺️',
			'post_status' => 'publish',
			'post_author' => 1,
			'post_type' => 'page',
		)
	);
	// Save the page IDs to the database
	wp_update_record_in_table(
		$loginPageId,
		$signupPageId,
		$resetPasswordPageId
	);
}

// Fires after a plugin has been deactivated
register_deactivation_hook(
	__FILE__,
	'deactivation_hook'
);
function deactivation_hook()
{
	// Delete the options
	delete_option('userfront-workspaceId');
	delete_option('userfront-organizationId');
	delete_option('userfront-sourceOfTruth');
	delete_option('userfront-redirect');
	delete_option('userfront-login');
	delete_option('userfront-signup');
	delete_option('userfront-reset-password');
	delete_option('userfront-account-creation');
	// Delete the pages
	$values = wp_select_records_from_table();
	foreach ($values as $value) {
		wp_delete_post($value->loginPageId, true);
		wp_delete_post($value->signupPageId, true);
		wp_delete_post($value->resetPasswordPageId, true);
	}
	// Delete the database table
	wp_delete_table();
}

function enqueue_userfront_script()
{
	wp_register_script('userfront-toolkit', 'https://cdn.userfront.com/@userfront/toolkit@latest/dist/web-component.umd.js', [], null, true);
	wp_enqueue_script('userfront-toolkit');
	wp_register_script('userfront-wordpress', plugins_url('js/userfront.js', __FILE__), ['jquery'], '1.0', true);
	wp_enqueue_script('userfront-wordpress');
}

add_action('wp_enqueue_scripts', 'enqueue_userfront_script');