=== Gratis AI Plugin Translations ===
Contributors: ultimatemultisite
Tags: translation, ai, machine-translation, i18n, localization
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically provides AI-generated translations for WordPress plugins when official translations are missing or incomplete.

== Description ==

The official WordPress translation platform (translate.wordpress.org) relies on human volunteers and only supports plugins hosted in the WordPress.org plugin repository. This creates a gap for premium plugins, plugins with incomplete translations, and plugins that haven't been fully translated.

**Gratis AI Plugin Translations** bridges this gap by providing AI-powered translations that are:

* Automatically downloaded when needed
* Generated on-demand using advanced language models
* Only used when official translations are missing or incomplete
* Always respectful of official translations (they take precedence)

= How It Works =

1. **Automatic Detection**: When WordPress checks for plugin updates, the plugin detects which plugins need translations
2. **Smart Filtering**: Only requests AI translations for languages with no official translation or incomplete official translations
3. **On-Demand Generation**: Translation jobs are triggered when a real site needs them
4. **Local Caching**: Translations are cached locally for performance
5. **Priority System**: Popular plugins get translated first

= External Service Usage =

This plugin relies on an external service to generate translations:

* **Service**: translate.ultimatemultisite.com
* **Data Transmitted**: Plugin metadata (name, version, textdomain, site URL, WordPress version)
* **Data NOT Transmitted**: No personal data, content, or confidential information
* **Purpose**: Generate AI translations for missing plugin strings
* **Terms of Use**: https://ultimatemultisite.com/terms
* **Privacy Policy**: https://ultimatemultisite.com/privacy

Translations are cached locally on your server. No data is stored on external servers permanently.

= Features =

* **Smart Detection**: Only downloads AI translations when official ones are missing
* **On-Demand Generation**: Translation jobs triggered when needed
* **WordPress Integration**: Uses standard WordPress translation update mechanisms
* **Multisite Support**: Network-activated with per-site locale detection
* **Priority System**: Popular plugins get translated first
* **Caching**: Both API responses and translation files are cached
* **WP-CLI Support**: Full command-line management
* **Privacy Friendly**: Only sends plugin metadata, no personal data

== Installation ==

= Requirements =

* WordPress 5.8 or higher
* PHP 8.0 or higher
* Multisite supported (network-activated)

= From WordPress.org =

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "Gratis AI Plugin Translations"
3. Click **Install Now** and then **Activate**

= Manual Installation =

1. Download the plugin ZIP file
2. Go to **Plugins > Add New > Upload Plugin**
3. Select the ZIP file and click **Install Now**
4. Click **Activate**

= For Multisite =

1. Network activate the plugin from **My Sites > Network Admin > Plugins**
2. Configure settings at **My Sites > Network Admin > Settings > AI Translations**

== Frequently Asked Questions ==

= How does this differ from Google Translate or other translation plugins? =

This plugin specifically fills the gap in plugin translations. Unlike page translation plugins, it downloads actual .mo/.po translation files that WordPress uses natively. It only activates when official translations from wordpress.org are missing or incomplete.

= Is my data safe? =

Yes. The plugin only sends plugin metadata (name, version, textdomain) to generate translations. No personal data, user data, or site content is transmitted. All translations are cached locally.

= What languages are supported? =

The service currently supports: Spanish, German, French, Italian, Portuguese, Dutch, Russian, Polish, Swedish, Danish, Finnish, Hungarian, Czech, Romanian, Turkish, Greek, Chinese, and Japanese.

= Can I use this with existing translation plugins like Polylang or WPML? =

Yes! This plugin handles plugin translations (the .mo files), while Polylang/WPML handle content translations. They work together perfectly.

= What happens if an official translation becomes available? =

Official translations from wordpress.org always take precedence. If a human-reviewed translation becomes available, it will automatically replace the AI translation.

= How much does this cost? =

The plugin is free. The translation service is currently offered at no cost while in beta.

== Screenshots ==

1. Settings page showing API status and statistics
2. Translation queue showing pending translations

== Changelog ==

= 1.0.0 - 2026-04-23 =
* New: Automatic AI translation downloads for plugins missing official translations
* New: Smart filtering — parses .po files to detect genuinely incomplete translations, not just missing ones
* New: Detect WordPress.org vs premium plugins; source is included in batch requests to the server
* New: Chunked, batched translation refresh to handle large plugin lists without timeouts
* New: Rich admin status page with per-plugin and per-locale translation counts
* New: "Check for updates now" button that forces a fresh WordPress update check before triggering an AI refresh
* New: Default auto_approve=false — AI translations wait for server-side approval before downloading
* New: Allow downloads from translation server on private-IP/local networks (development environments)
* New: WP-CLI support (`wp gratis-ai-translations`)
* New: Full multisite support with network-admin settings page

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade necessary.

== Credits ==

* Developed by Ultimate Multisite
* Translations powered by OpenAI GPT models
* Inspired by the WordPress Polyglots team

== Privacy Policy ==

This plugin communicates with translate.ultimatemultisite.com to generate translations. The following data is transmitted:

* Plugin textdomain (e.g., "woocommerce")
* Plugin version
* Site URL
* WordPress version
* Requested locale

This data is used solely to generate translations and is not stored permanently. Translations are cached locally on your server.

For more information, see our full Privacy Policy at https://ultimatemultisite.com/privacy
