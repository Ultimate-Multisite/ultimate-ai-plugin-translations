# AGENTS.md — Gratis AI Plugin Translations

## Project Overview

WordPress plugin (client-side) that automatically provides AI-generated translations for plugins when official translations from translate.wordpress.org are missing or incomplete. Communicates with the Gratis AI Translations Server via REST API. Designed for WordPress.org distribution.

## Build Commands

```bash
# No build step — pure PHP plugin with no compiled assets
# No Composer runtime dependencies
```

## Project Structure

```
ultimate-ai-plugin-translations/
├── gratis-ai-plugin-translations.php  # Plugin entry point (namespaced)
├── src/
│   ├── class-translation-manager.php  # Core translation logic
│   ├── class-admin-settings.php       # Network admin settings page
│   └── class-cli.php                  # WP-CLI commands
├── composer.json                      # PSR-4 autoload config only
├── readme.txt                         # WordPress.org plugin readme
├── SERVER-API.md                      # API documentation
├── TESTING_GUIDE.md                   # Testing instructions
├── SUBMISSION_GUIDE.md                # WordPress.org submission guide
├── WORDPRESS_ORG_COMPLIANCE.md        # Compliance notes
└── LICENSE                            # GPL-2.0-or-later
```

## Code Style & Conventions

- **PHP version**: >= 8.0
- **Namespace**: `GratisAIPluginTranslations\`
- **Autoloading**: Custom `spl_autoload_register` (maps class names to `src/class-{name}.php`)
- **File naming**: `class-{name}.php` in `src/` (WordPress convention with PSR-4 namespace)
- **Text domain**: `gratis-ai-plugin-translations`
- **Network plugin**: `Network: true`
- **Constants prefix**: `GRATIS_AI_PT_`
- **Uses `declare(strict_types=1)`**
- **No PHPCS config** — follow WordPress Coding Standards

## Key Patterns

- Singleton pattern on service classes: `Translation_Manager::instance()`
- Hooks into `plugins_loaded` at priority 20
- Network-level options via `get_site_option()` / `add_site_option()`
- Default API base: `https://translate.ultimatemultisite.com/wp-json/gratis-ai-translations/v1`
- Transient-based caching for API responses
- WP-CLI commands under `wp gratis-ai-translations`

## Important Notes

- This is the **client** plugin — see `ultimate-ai-translation-server` for the server component
- Designed for WordPress.org compliance — no external dependencies bundled
- API key and base URL configurable via constants (`GRATIS_AI_PT_API_BASE`)

## Local Development Environment

The shared WordPress dev install for testing this plugin is at `../wordpress` (relative to this repo root).

- **URL**: http://wordpress.local:8080
- **Admin**: http://wordpress.local:8080/wp-admin — `admin` / `admin`
- **WordPress version**: 7.0-RC2
- **This plugin**: symlinked into `../wordpress/wp-content/plugins/$(basename $PWD)`
- **Reset to clean state**: `cd ../wordpress && ./reset.sh`

WP-CLI is configured via `wp-cli.yml` in this repo root — run `wp` commands directly from here without specifying `--path`.

```bash
wp plugin activate $(basename $PWD)   # activate this plugin
wp plugin deactivate $(basename $PWD) # deactivate
wp db reset --yes && cd ../wordpress && ./reset.sh  # full reset
```
