<p align="center">
  <br />
  <a href="https://userfront.com">
    <img src="https://raw.githubusercontent.com/userfront/userfront/main/logo.png" width="160">
  </a>
  <br />
</p>

# Wordpress Plugin

Upload and activate this plugin to use Userfront with your WordPress website. Introduce new features such as multi-tenacy, social sign-on, role-based access control, and so much more.

[Learn more about Userfront.](https://userfront.com)

## Getting Started

### Installation

[Step-by-step Instructions](https://userfront.com/docs/integrations/wordpress)

### Activation

When this plugin is activated, 3 new pages are created automatically:

- Login
- Signup
- Reset Password

After you add your workspace ID, these pages are updated with the Toolkit HTML script. When you enable or disable one of these features, the corresponding pages will be created or deleted upon save.

## Login

### Redirect

You may redirect visitors from `/wp-login.php` to `/login`. Use `/wp-login.php?bypass` to skip the redirect and access the WordPress login.

### Require a WordPress account

If a user does not exist in WordPress, after login or signup, the plugin will create a new WordPress account with their Userfront information. Disable this feature from the plugin settings to block access to your WordPress website.

## Logout

There is no logout page, the functionality is working alongside WordPress. Therefore, you can logout a user by sending them to `/wp-login.php?action=logout`.

## Roles

Userfront roles are written into WordPress after login or signup. Users will need to logout and log back in to update their role.

The default roles in Userfront are the same as the WordPress roles:

- **Administrator**: The highest level of permission. Admins have the power to access almost everything.
- **Editor**: Has access to all posts, pages, comments, categories, and tags, and can upload media.
- **Author**: Can write, upload media, edit, and publish their own posts.
- **Contributor**: Has no publishing or uploading capability but can write and edit their own posts until they are published.
- **Viewer**: Viewers can read and comment on posts and pages on private sites.
- **Subscriber**: People who subscribe to your site’s updates.

[Learn more about WordPress User Roles.](https://wordpress.com/support/invite-people/user-roles/)

If a role exists in Userfront but does not exist in WordPress, the role will be created in WordPress and assigned to the user after login or signup.

## Themes and Appearance

If you make customizations to your toolkit theme and appearance in the [Userfront Dashboard](https://userfront.com/dashboard/authentication?tab=style), you'll need to copy the new code into the new pages.

## Troubleshooting

If you're experiencing miscellaneous issues with the render and behavior of this plugin, try to disable other plugins as there could be conflicts between them.

### Cookies and caching

Some hosting platforms cache cookies. For Pantheon, we've worked around this with the `STYXKEY_` prefix. If you're experiencing logout issues, try to disable caching of the Userfront cookies (`access.{workspace id}`, `refresh.{workspace id}`, `id.{workspace id}`) or contact your hosting provider.

[Working with Cookies on Pantheon › Cache-Busting Cookies](https://docs.pantheon.io/cookies#cache-busting-cookies)
[How to Use Varnish at Cloudways ›How to Exclude URLs and Cookies in Varnish](https://support.cloudways.com/en/articles/5496342-how-to-use-varnish-at-cloudways#h_289d92cc87)

Please reach out and let us know about other hosting platforms!

### Login links

The automatically generated Login page could be effected by your permalink structure. This could break Login Links via email if your permalink structure involves a redirect because WordPress will drop any query parameters, including the required `uuid` and `token`. Try changing the permalink structure to `/%postname%/` in your WordPress Admin Dashboard under Settings > Permalink Settings > Permalink structure.

### More

If you're experiencing other problems, please [contact us](https://userfront.com/contact).
