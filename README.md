# TWP - LinkedIn Publisher

TWP - LinkedIn Publisher is a lightweight WordPress plugin that allows you to manually publish your posts to a LinkedIn Company Page using OAuth2 authentication.

## Features

- **Manual Publishing**: Add a "Publish to LinkedIn" button to your WordPress post editor.
- **LinkedIn OAuth2 Integration**: Securely connect your WordPress site to LinkedIn.
- **Company Page Support**: Specifically designed to post on behalf of an organization.

## Installation

1. Upload the `twp-linkedin-publisher` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Settings > LinkedIn Publisher** to configure the plugin.

## Configuration

To use this plugin, you need to create a LinkedIn App in the [LinkedIn Developers Portal](https://www.linkedin.com/developers/).

1. **Create an App**: Set up a new app and enable the "Share on LinkedIn" and "Marketing Developer Platform" products (if required for organization posting).
2. **Redirect URI**: Add `https://yourdomain.com/linkedin-callback/` to the "Authorized redirect URLs" in your LinkedIn App settings.
3. **Client Credentials**: Copy the **Client ID** and **Client Secret** into the plugin settings page in WordPress.
4. **Authorize**: Click the "Authorize with LinkedIn" button in the plugin settings.
5. **Organization ID**: After authorization, select the Organization you wish to post to.

## How to Use

1. Open a Post in the WordPress editor.
2. Look for the "LinkedIn Publisher" meta box (usually in the sidebar or below the editor).
3. Click the **Publish to LinkedIn** button.
4. You will receive a notification confirming if the post was successfully shared.

## Author

- **Tommaso Vietina**
