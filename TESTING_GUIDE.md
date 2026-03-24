# Testing Guide for Gratis AI Plugin Translations

## PHP Syntax Tests ✅ PASSED

All PHP files pass syntax validation:
- `gratis-ai-plugin-translations.php` ✅
- `src/class-translation-api-client.php` ✅
- `src/class-translation-manager.php` ✅
- `src/class-admin-settings.php` ✅
- `src/class-cli.php` ✅

## Unit Tests to Run

### 1. Client Plugin Tests

```bash
# Test autoloader
php -r "
require 'gratis-ai-plugin-translations.php';
\$client = GratisAIPluginTranslations\Translation_API_Client::instance();
echo 'Autoloader: OK\n';
"

# Test constants
define('GRATIS_AI_PT_VERSION', '1.0.0');
define('GRATIS_AI_PT_FILE', __FILE__);
define('GRATIS_AI_PT_DIR', plugin_dir_path(__FILE__));
define('GRATIS_AI_PT_API_BASE', 'https://translate.ultimatemultisite.com/wp-json/gratis-ai-translations/v1');
```

### 2. Server Plugin Tests

```bash
# Test database table creation
wp db query "SHOW TABLES LIKE '%gratis_ai_translation_jobs%'"

# Test REST API endpoints
curl -X GET https://translate.ultimatemultisite.com/wp-json/gratis-ai-translations/v1/health
```

## Integration Tests

### Test Case 1: Client Detects Missing Translation
1. Install client plugin on WordPress site
2. Set site language to non-English (e.g., es_ES)
3. Install a plugin without Spanish translations
4. Check that client detects missing translation
5. Verify request sent to server

**Expected Result**: Plugin should queue translation request

### Test Case 2: Server Processes Translation Job
1. Server receives translation request
2. Creates GlotPress project
3. Downloads POT file from wordpress.org
4. Uses gp-openai-translate to translate strings
5. Builds package and returns URL

**Expected Result**: Job completes with package URL

### Test Case 3: Client Downloads and Installs Translation
1. Client receives package URL from server
2. Downloads ZIP file
3. Extracts to wp-content/languages/plugins/
4. WordPress loads translation

**Expected Result**: Plugin shows translated strings

### Test Case 4: Official Translation Takes Precedence
1. Plugin has AI translation installed
2. Official translation becomes available on wordpress.org
3. WordPress update check runs
4. Official translation should replace AI translation

**Expected Result**: Official translation used instead of AI

## Manual Testing Checklist

### Client Plugin
- [ ] Plugin activates without errors
- [ ] No PHP warnings/notices
- [ ] Settings link appears on plugins page
- [ ] Status shows on Updates page (when translations needed)
- [ ] WP-CLI commands work:
  - `wp gratis-ai-translations status`
  - `wp gratis-ai-translations check <plugin>`
  - `wp gratis-ai-translations list`

### Server Plugin
- [ ] Plugin activates without errors
- [ ] Database table created
- [ ] REST API responds:
  - GET /health returns 200
  - POST /check-translations returns valid JSON
  - POST /request-translation accepts jobs
- [ ] Queue processes jobs
- [ ] Packages created successfully
- [ ] WP-CLI commands work:
  - `wp gratis-ai-server status`
  - `wp gratis-ai-server list`
  - `wp gratis-ai-server process`

### Integration
- [ ] Client can reach server API
- [ ] Server receives and queues jobs
- [ ] Translations are generated
- [ ] Client downloads packages
- [ ] WordPress loads AI translations

## Performance Tests

### Load Test Server API
```bash
# Test rate limiting
for i in {1..10}; do
  curl -X POST https://translate.ultimatemultisite.com/wp-json/gratis-ai-translations/v1/check-translations \
    -H "Content-Type: application/json" \
    -d '{"textdomain":"test","version":"1.0","locales":["es_ES"]}'
done
```

**Expected**: Rate limiting should kick in after limit reached

### Queue Processing
1. Queue 10 translation jobs
2. Monitor processing time
3. Check concurrent job limit

**Expected**: Jobs process at configured rate (default: 3 concurrent)

## Security Tests

### Input Validation
- [ ] Invalid textdomain rejected
- [ ] Invalid locale format rejected
- [ ] SQL injection attempts sanitized
- [ ] XSS attempts in feedback escaped

### Rate Limiting
- [ ] API limits requests per IP
- [ ] Returns 429 when limit exceeded
- [ ] Retry-After header present

### File Security
- [ ] Packages served with correct MIME type
- [ ] Directory traversal blocked
- [ ] Unauthorized downloads prevented

## WordPress.org Compliance Check

- [ ] Plugin headers complete
- [ ] readme.txt validates
- [ ] All functions prefixed
- [ ] No cURL (uses wp_remote_*)
- [ ] All output escaped
- [ ] All input sanitized
- [ ] External service documented

## Error Handling Tests

### Client Errors
- [ ] Server unreachable: Shows appropriate message
- [ ] API returns error: Handles gracefully
- [ ] Network timeout: Retries or fails gracefully
- [ ] Invalid response: Logs error

### Server Errors
- [ ] OpenAI API failure: Marks job failed
- [ ] GlotPress error: Logs and fails
- [ ] File system error: Handles gracefully
- [ ] Database error: Retries with backoff

## Regression Tests

Before each release, verify:
1. Existing translations still work
2. New plugins can be translated
3. Queue doesn't stall
4. No duplicate translations
5. Cleanup jobs run successfully

## Automated Testing Script

```php
<?php
/**
 * Run basic plugin tests
 */

// Test 1: Plugin loads
require_once 'gratis-ai-plugin-translations.php';
assert(defined('GRATIS_AI_PT_VERSION'), 'Version constant not defined');

// Test 2: Classes load
assert(class_exists('GratisAIPluginTranslations\Translation_Manager'), 'Translation_Manager class not found');
assert(class_exists('GratisAIPluginTranslations\Translation_API_Client'), 'Translation_API_Client class not found');

// Test 3: Singleton pattern
$client1 = GratisAIPluginTranslations\Translation_API_Client::instance();
$client2 = GratisAIPluginTranslations\Translation_API_Client::instance();
assert($client1 === $client2, 'Singleton pattern broken');

// Test 4: API client methods exist
assert(method_exists($client1, 'check_translations'), 'check_translations method missing');
assert(method_exists($client1, 'request_translation_generation'), 'request_translation_generation method missing');

echo "All tests passed!\n";
```

## Browser Testing

Test on:
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)

Test scenarios:
1. View Updates page with pending translations
2. Check status indicators
3. Verify no JavaScript errors in console

## Mobile Testing

Test WordPress admin on:
- [ ] iOS Safari
- [ ] Android Chrome

Verify:
- Settings page displays correctly
- Updates page shows status
- No layout issues

## Pre-Release Checklist

Before publishing to WordPress.org:
- [ ] All syntax tests pass
- [ ] Integration tests pass
- [ ] Security review complete
- [ ] Performance acceptable
- [ ] Documentation updated
- [ ] Changelog updated
- [ ] Version numbers match
- [ ] Tested on clean WordPress install
- [ ] Tested with popular plugins (WooCommerce, Yoast, etc.)
- [ ] Tested on multisite

## Known Issues & Limitations

1. **Rate Limiting**: Server may hit OpenAI rate limits during peak usage
2. **Large Plugins**: Very large plugins (>1000 strings) may timeout
3. **POT Availability**: Requires plugin to have POT file on wordpress.org
4. **Context**: Some strings may lack context for perfect translation

## Reporting Bugs

When reporting issues, include:
1. WordPress version
2. PHP version
3. Plugin version
4. Error messages (from debug.log)
5. Steps to reproduce
6. Expected vs actual behavior
