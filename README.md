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
[Step-by-step Instructions](https://userfront.notion.site/WordPress-Plugin-a84b98f821434ce899b7226f6d8ef69d?pvs=4)

### Activation
When this plugin is activated, 3 new pages are created automatically:
- Login
- Signup
- Reset Password

After you add your workspace ID to the Userfront Plugin Settings in your WordPress Admin dashboard, these pages are populated with a default Toolkit HTML script.

## Logout

There is no logout page, the functionality is working alongside WordPress. Therefore, you can logout a user by sending them to `/wp-login.php?action=logout`. 

## Roles 

Userfront is the source of truth. When a user logs in, their WordPress profile is updated with the most recent data from Userfront.

As of now, this means that when data in Userfront, such as roles, are updated, the user will need to logout and log back in.

The default roles in Userfront are the same as the WordPress roles:
- **Administrator**: The highest level of permission. Admins have the power to access almost everything.
- **Editor**: Has access to all posts, pages, comments, categories, and tags, and can upload media.
- **Author**: Can write, upload media, edit, and publish their own posts.
- **Contributor**: Has no publishing or uploading capability but can write and edit their own posts until they are published.
- **Viewer**: Viewers can read and comment on posts and pages on private sites.
- **Subscriber**: People who subscribe to your site’s updates.

[Learn more about WordPress User Roles.](https://wordpress.com/support/invite-people/user-roles/)

If a role exists in Userfront but does not exist in WordPress, when a user logs in, the role will be created in WordPress and assigned to the user.

## Themes and Appearance

If you make customizations to your toolkit theme and appearance in the [Userfront Dashboard](https://userfront.com/dashboard/authentication?tab=style), you'll need to copy the new code into the new pages.
