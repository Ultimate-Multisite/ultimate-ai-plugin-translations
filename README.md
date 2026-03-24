# Gratis AI Plugin Translations

A WordPress plugin that automatically provides AI-generated translations for WordPress plugins when official translations from translate.wordpress.org are missing or incomplete.

## Overview

The official WordPress translation platform (translate.wordpress.org) relies on human volunteers and only supports plugins hosted in the official WordPress.org plugin repository. This creates a gap for:

- Premium plugins not hosted on wordpress.org
- Plugins with incomplete translations
- Plugins that haven't been fully translated by volunteers

**Gratis AI Plugin Translations** bridges this gap by providing AI-powered translations that are:

- Automatically downloaded when needed
- Generated on-demand using advanced language models
- Only used when official translations are missing or incomplete
- Always respectful of official translations (they take precedence)

## How It Works

1. **Automatic Detection**: When WordPress checks for plugin updates, the plugin detects which plugins need translations
2. **Smart Filtering**: Only requests AI translations for:
   - Languages with no official translation
   - Incomplete official translations (when enabled)
3. **On-Demand Generation**: Translation jobs are triggered when a real site needs them
4. **Local Caching**: Translations are cached locally for performance
5. **Priority System**: Popular plugins get translated first

## Installation

### Requirements

- WordPress 5.8 or higher
- PHP 8.0 or higher
- Multisite supported (network-activated)

### Install via ZIP

1. Download the plugin ZIP file
2. Go to **Plugins > Add New** in your WordPress admin
3. Click **Upload Plugin** and select the ZIP file
4. Click **Install Now** and then **Activate**

### Install via Composer

```bash
composer require ultimatemultisite/gratis-ai-plugin-translations
```

### For Multisite

1. Network activate the plugin from **My Sites > Network Admin > Plugins**
2. Configure settings at **My Sites > Network Admin > Settings > AI Translations**

## Configuration

### Settings

Navigate to **Settings > AI Translations** (single site) or **Network Admin > Settings > AI Translations** (multisite).

| Setting | Description | Default |
|---------|-------------|---------|
| **Enable AI Translations** | Toggle automatic translation downloads | Enabled |
| **Fill Incomplete Translations** | Provide AI translations for missing strings in partial translations | Enabled |
| **API Base URL** | The translation server endpoint | `https://translate.ultimatemultisite.com/wp-json/gratis-ai-translations/v1` |
| **Cache Duration** | How long to cache translation checks | 1 hour |

### Constants

You can define these in your `wp-config.php`:

```php
// Custom API endpoint (if running your own server)
define('GRATIS_AI_PT_API_BASE', 'https://your-server.com/wp-json/gratis-ai-translations/v1');
```

## WP-CLI Commands

The plugin provides several WP-CLI commands for management:

```bash
# Check API status
wp gratis-ai-translations status

# Check translations for a specific plugin
wp gratis-ai-translations check woocommerce

# Request translation for specific locale
wp gratis-ai-translations check woocommerce --locale=es_ES

# Request translation generation
wp gratis-ai-translations request woocommerce --locale=de_DE

# List all AI translations
wp gratis-ai-translations list

# Clear translation cache
wp gratis-ai-translations clear-cache

# Get translation status
wp gratis-ai-translations status-plugin woocommerce de_DE
```

## Architecture

### Core Classes

- **Translation_Manager**: Hooks into WordPress update system, manages translation lifecycle
- **Translation_API_Client**: Communicates with the translation server
- **Admin_Settings**: Settings page and configuration UI
- **CLI**: WP-CLI command handlers

### Hooks Used

- `pre_set_site_transient_update_plugins`: Inject AI translation updates
- `translations_api`: Filter translation API results
- `upgrader_pre_download`: Handle AI translation package downloads

### Translation Priority

Translations are prioritized based on plugin popularity:
- 1M+ active installs: Priority 10 (highest)
- 100K+ active installs: Priority 8
- 10K+ active installs: Priority 7
- Others: Priority 5 (default)

## Privacy

- Only plugin metadata (name, version, textdomain) is sent to the translation server
- No personal data or site content is transmitted
- Translations are cached locally on your server
- Site URL is sent for usage analytics only

## Server Requirements

The translation server (`translate.ultimatemultisite.com`) uses:
- GlotPress for translation management
- OpenAI-compatible LLMs for translation generation
- WordPress REST API for client communication

## Development

### File Structure

```
gratis-ai-plugin-translations/
├── gratis-ai-plugin-translations.php  # Main plugin file
├── src/
│   ├── class-translation-manager.php  # Core translation logic
│   ├── class-translation-api-client.php  # API communication
│   ├── class-admin-settings.php       # Settings UI
│   └── class-cli.php                  # WP-CLI commands
├── languages/                         # Plugin translations
└── README.md
```

### Coding Standards

- PHP 8.0+ with strict typing
- PSR-2 coding standards
- WordPress coding standards for hooks/filters
- Namespaced classes with autoloading

## Troubleshooting

### Translations Not Downloading

1. Check API status on the settings page
2. Verify the plugin is enabled
3. Check that your site's locale is not English (en_US)
4. Review error logs for API communication issues

### Cache Issues

Clear the translation cache:
```bash
wp gratis-ai-translations clear-cache
```

Or delete transients manually:
```sql
DELETE FROM wp_sitemeta WHERE meta_key LIKE '%gratis_ai_pt_%';
```

## License

GPL-2.0-or-later

## Credits

- Developed by Ultimate Multisite
- Powered by OpenAI-compatible LLMs
- Inspired by the WordPress Polyglots team
