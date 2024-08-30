<?php
/**
 * Userfront Wordpress
 * 
 * @package UserfrontWordpress
 * @author Userfront
 * @version 1.0.0
 * 
 * @wordpress-plugin
 * Plugin Name: Userfront Authentication
 * Plugin URI: https://github.com/userfront/wordpress
 * Description: Userfront is a premier auth & identity platform. Install full-fledged authentication and authorization with 2FA/MFA and OAuth to WordPress within minutes. 
 * Author: Userfront 
 * Version: 1.0.0
 * Author URI: https://userfront.com
 */

/**
 * Helpers
 */

// Fetch data from a URL
function fetch($url, $method, $data = false, $headers = array())
{
	$curl = curl_init();

	switch ($method) {
		case "POST":
			curl_setopt($curl, CURLOPT_POST, 1);

			if ($data)
				curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
			break;
		case "PUT":
			curl_setopt($curl, CURLOPT_PUT, 1);
			break;
		default:
			if ($data)
				$url = sprintf("%s?%s", $url, http_build_query($data));
	}

	curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

	$result = curl_exec($curl);

	curl_close($curl);

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

	$realCookieName = $useRealCookieName ? str_replace("_", ".", $cookieName) : $cookieName;
	if ($realCookieName && is_string($realCookieName)) {
		setcookie($realCookieName, "", time() - 3600, "/");
	}
}

// Get the self object from Userfront
function get_self($jwt)
{
	// TODO: Use jwt.verify instead
	$url = "https://api.userfront.com/v0/self";
	$data = fetch($url, "GET", false, array("Authorization: Bearer " . $jwt));
	return json_decode($data);
}

// Insert a new user into the WordPress database, if it doesn't exist
// update the user if it does exist
function update_user($tenantId, $self, $wpUserId)
{
	if (is_int($wpUserId)) {
		$wpUser = new WP_User($wpUserId);

		if (property_exists($self->authorization, $tenantId)) {
			$roles = $self->authorization->$tenantId->roles;
			foreach ($roles as $role) {
				if ($role === "admin") {
					$wpUser->set_role("administrator");
					continue;
				}
				// Check if the role exists
				$wpRole = get_role($role);
				if (!isset($wpRole)) {
					// Create the new role
					add_role(
						$role,
						mb_convert_case($role, MB_CASE_TITLE, "UTF-8")
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
	$tenantId = get_option(
		'userfront-tenantId'
	);

	if (isset($tenantId)) {
		$isPantheon = isset($_ENV['PANTHEON_ENVIRONMENT']);
		$cookiePrefix = $isPantheon ? "STYXKEY_" : "";

		$userfrontAccessCookie = "access_" . $tenantId;
		$accessCookie = $cookiePrefix . $userfrontAccessCookie;
		$userfrontIdCookie = "id_" . $tenantId;
		$idCookie = $cookiePrefix . $userfrontIdCookie;
		$userfrontRefreshCookie = "refresh_" . $tenantId;
		$refreshCookie = $cookiePrefix . $userfrontRefreshCookie;

		$isLoggedIntoUserfront = isset($_COOKIE[$accessCookie]) && isset($_COOKIE[$idCookie]) && isset($_COOKIE[$refreshCookie]);

		$isWpLoginRoute = str_starts_with(
			$_SERVER["REQUEST_URI"],
			"/wp-login.php"
		);
		$isLoginRoute = str_starts_with(
			$_SERVER["REQUEST_URI"],
			"/login"
		);
		$isPostLoginRoute = str_starts_with(
			$_SERVER["REQUEST_URI"],
			"/post-login"
		);
		$isLogoutAction = isset($_GET["action"]) && $_GET["action"] == "logout";

		if ($isPantheon && $isPostLoginRoute) {
			echo '<script type="text/javascript">
				function updateCookies() {
					const cookies = document.cookie.split("; ").reduce((prev, current) => {
						const [name, ...value] = current.split("=");
						prev[name] = value.join("=");
						return prev;
					}, {});
					["id", "refresh", "access"].forEach(key => document.cookie = "STYXKEY_" + key + "_' . $tenantId . '=" + cookies[key + ".' . $tenantId . '"]);
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
			// Uncomment to disable the WordPress login page
			// } elseif ($isWpLoginRoute) {
			// redirect("/login");
		}

		$isLoggedIn = is_user_logged_in();

		if (
			!$isLoggedIn && $isLoggedIntoUserfront
		) {
			$self = get_self(
				$_COOKIE[$accessCookie]
			);

			if (isset($self) && property_exists($self, "email")) {
				// Search for the user by email
				$user = get_user_by('email', $self->email);

				if ($user) {
					// Update the WordPress user
					$wpUserId = wp_insert_user(
						array(
							"ID" => $user->ID,
							"user_login" => $self->username,
							"user_email" => $self->email,
						)
					);

					update_user(
						$tenantId,
						$self,
						$wpUserId
					);
					wp_set_current_user($wpUserId);
					wp_set_auth_cookie($wpUserId, true);
				} else {
					// Insert a new WordPress user
					$wpUserId = wp_insert_user(
						array(
							"user_login" => $self->username,
							"user_email" => $self->email,
							"user_pass" => wp_generate_password(),
						)
					);

					update_user(
						$tenantId,
						$self,
						$wpUserId
					);
					wp_set_current_user($wpUserId);
					wp_set_auth_cookie($wpUserId, true);
				}
			}

			if ($isLoginRoute && !$isPantheon) {
				if (isset($_GET["redirect_to"])) {
					redirect($_GET["redirect_to"]);
				} else {
					redirect("/dashboard");
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
	redirect("/login");
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
		'userfront-input-field',
		'Tenant ID',
		'display_userfront_tenant_field',
		'userfront-options-page',
		'userfront-settings',
	);

	register_setting(
		"userfront",
		"userfront-tenantId",
		[
			"type" => "string",
			"label" => "Tenant ID",
			"description" => "The tenant ID for your Userfront account",
		]
	);
}
function display_userfront_settings_message()
{
	echo 'Use the Tenant ID found in the <a href="https://userfront.com/test/dashboard/tenants" target="_blank">Userfront dashboard</a>.';
}
function display_userfront_tenant_field()
{
	$value = get_option(
		'userfront-tenantId'
	);
	echo '<input type="text" id="userfront-tenantId" name="userfront-tenantId" value="' . $value . '" />';
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

	// Event listener for the plugin settings
	if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === "true") {
		$tenantId = get_option(
			'userfront-tenantId'
		);
		// Update the login, signup, and reset password pages with the new tenant ID
		$values = wp_select_records_from_table();
		foreach ($values as $value) {
			wp_update_post(
				array(
					"ID" => $value->loginPageId,
					"post_content" => '<login-form tenant-id="' . $tenantId . '"' . ($isPantheon ? ' redirect="/post-login" redirect-on-load-if-logged-in="true"' : '') . '></login-form><script src="https://cdn.userfront.com/@userfront/toolkit@latest/dist/web-component.umd.js"></script>'
				)
			);
			wp_update_post(
				array(
					"ID" => $value->signupPageId,
					"post_content" => '<signup-form tenant-id="' . $tenantId . '"></signup-form><script src="https://cdn.userfront.com/@userfront/toolkit@latest/dist/web-component.umd.js"></script>'
				)
			);
			wp_update_post(
				array(
					"ID" => $value->resetPasswordPageId,
					"post_content" => '<password-reset-form tenant-id="' . $tenantId . '"></password-reset-form><script src="https://cdn.userfront.com/@userfront/toolkit@latest/dist/web-component.umd.js"></script>'
				)
			);
		}
	}

	echo "<h1>Userfront Authentication</h1>";
	echo '<form method="post" action="options.php">';

	do_settings_sections(
		'userfront-options-page'
	);
	settings_fields(
		'userfront'
	);

	submit_button();

	echo '</form>';
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
			'post_title' => wp_strip_all_tags('Login'),
			'post_content' => 'Add your Tenant ID to <a href="/wp-admin/admin.php?page=userfront" target="_blank">the plugin settings</a> then reload this page. ☺️',
			'post_status' => 'publish',
			'post_author' => 1,
			'post_type' => 'page',
		)
	);
	// Insert the signup page into the database
	$signupPageId = wp_insert_post(
		array(
			'post_title' => wp_strip_all_tags('Signup'),
			'post_content' => 'Add your Tenant ID to <a href="/wp-admin/admin.php?page=userfront" target="_blank">the plugin settings</a> then reload this page. ☺️',
			'post_status' => 'publish',
			'post_author' => 1,
			'post_type' => 'page',
		)
	);
	// Insert the login page into the database
	$resetPasswordPageId = wp_insert_post(
		array(
			'post_title' => wp_strip_all_tags('Reset Password'),
			'post_content' => 'Add your Tenant ID to <a href="/wp-admin/admin.php?page=userfront" target="_blank">the plugin settings</a> then reload this page. ☺️',
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
	delete_option('userfront-tenantId');
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
