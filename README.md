# TWP - LinkedIn Publisher

TWP - LinkedIn Publisher is a lightweight WordPress plugin that allows you to manually publish your posts to a LinkedIn Company Page using OAuth2 authentication.

## Features

- **Manual Publishing**: Add a "Publish to LinkedIn" button to your WordPress post editor.
- **LinkedIn OAuth2 Integration**: Securely connect your WordPress site to LinkedIn.
- **Company Page Support**: Specifically designed to post on behalf of an organization.
- **Custom Post Text**: Write a dedicated message for LinkedIn in a text area. If left empty, the post excerpt is used as a fallback.
- **Image Gallery Selection**: Pick which images to publish by ticking checkboxes. Detected images include the featured image, media uploaded to the article, and images referenced in the content — including **WPBakery** media grids/galleries (`vc_media_grid`, `vc_gallery`, `vc_single_image`) and `<img>` tags. Selected images are published as a multi-image LinkedIn post (max 9). If none are selected, the featured image is used as before.
- **Dry-run Simulation**: A "Simula (dry-run)" button validates the publish without posting anything: it checks authentication, verifies the access token with a read-only LinkedIn API call, previews the exact text, and confirms each image is reachable and of a supported type. No assets are uploaded and no post is created.

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
2. Look for the "Pubblica su LinkedIn" meta box in the sidebar.
3. (Optional) Write a dedicated **LinkedIn text** in the text area. Leave it empty to use the post excerpt.
4. (Optional) Tick the **images** you want to include from the article gallery (max 9). Leave them all unchecked to use the featured image.
5. **Save/Update the post** so your text and image choices are stored.
6. (Optional) Click **Simula (dry-run)** to validate authentication, text, and images without publishing.
7. Click the **Publish to LinkedIn** button.
8. You will receive a notification confirming if the post was successfully shared.

## Author

- **Tommaso Vietina**
