# Gratis AI Translation Server API Specification

This document describes the REST API endpoints that the translation server (`translate.ultimatemultisite.com`) must implement to serve AI translations to WordPress sites.

## Base URL

```
https://translate.ultimatemultisite.com/wp-json/gratis-ai-translations/v1
```

## Authentication

The client plugin does not send authentication credentials. The server should:
- Rate limit by IP address
- Validate request signatures (optional, not yet implemented)
- Log usage for analytics

## Endpoints

### 1. Health Check

Check if the API is operational.

**Endpoint:** `GET /health`

**Response:**
```json
{
  "status": "ok",
  "version": "1.0.0",
  "timestamp": "2024-01-15T10:30:00Z",
  "supported_locales": ["es_ES", "de_DE", "fr_FR", "it_IT", "pt_BR", "nl_NL"],
  "queue_length": 42
}
```

**Status Codes:**
- `200`: API is operational
- `503`: API is temporarily unavailable

---

### 2. Check Available Translations

Check which AI translations are available for a plugin.

**Endpoint:** `POST /check-translations`

**Request Body:**
```json
{
  "textdomain": "woocommerce",
  "version": "8.2.0",
  "locales": ["es_ES", "de_DE", "fr_FR"],
  "site_url": "https://example.com",
  "wp_version": "6.4.2"
}
```

**Response (Success - Translations Available):**
```json
{
  "es_ES": {
    "package_url": "https://translate.ultimatemultisite.com/translations/woocommerce/8.2.0/woocommerce-es_ES.zip",
    "updated": "2024-01-15 10:00:00",
    "completeness": 100,
    "source": "ai",
    "model": "gpt-4"
  },
  "de_DE": {
    "package_url": "https://translate.ultimatemultisite.com/translations/woocommerce/8.2.0/woocommerce-de_DE.zip",
    "updated": "2024-01-15 09:30:00",
    "completeness": 100,
    "source": "ai",
    "model": "gpt-4"
  }
}
```

**Response (Partial - Some Not Ready):**
```json
{
  "es_ES": {
    "package_url": "https://translate.ultimatemultisite.com/translations/woocommerce/8.2.0/woocommerce-es_ES.zip",
    "updated": "2024-01-15 10:00:00",
    "completeness": 100,
    "source": "ai",
    "model": "gpt-4"
  },
  "de_DE": {
    "status": "pending",
    "queue_position": 15,
    "estimated_time": "10 minutes"
  }
}
```

**Response (Empty - None Available):**
```json
{}
```

**Status Codes:**
- `200`: Request successful
- `400`: Invalid request parameters
- `429`: Rate limit exceeded

---

### 3. Request Translation Generation

Request that translations be generated for a plugin. This queues a job for processing.

**Endpoint:** `POST /request-translation`

**Request Body:**
```json
{
  "textdomain": "woocommerce",
  "version": "8.2.0",
  "locales": ["es_ES", "de_DE"],
  "site_url": "https://example.com",
  "wp_version": "6.4.2",
  "priority": 8
}
```

**Response (Accepted):**
```json
{
  "status": "queued",
  "job_id": "abc123",
  "queue_position": 23,
  "estimated_time": "15 minutes",
  "message": "Translation generation has been queued"
}
```

**Response (Already Exists):**
```json
{
  "status": "exists",
  "message": "Translations already exist for this plugin version",
  "translations": {
    "es_ES": {
      "package_url": "https://translate.ultimatemultisite.com/translations/woocommerce/8.2.0/woocommerce-es_ES.zip",
      "updated": "2024-01-15 10:00:00"
    }
  }
}
```

**Status Codes:**
- `202`: Request accepted and queued
- `200`: Translations already exist
- `400`: Invalid request parameters
- `429`: Rate limit exceeded

---

### 4. Get Translation Status

Check the status of a specific translation.

**Endpoint:** `POST /translation-status`

**Request Body:**
```json
{
  "textdomain": "woocommerce",
  "version": "8.2.0",
  "locale": "es_ES"
}
```

**Response (Completed):**
```json
{
  "status": "completed",
  "textdomain": "woocommerce",
  "version": "8.2.0",
  "locale": "es_ES",
  "package_url": "https://translate.ultimatemultisite.com/translations/woocommerce/8.2.0/woocommerce-es_ES.zip",
  "updated": "2024-01-15 10:00:00",
  "completeness": 100,
  "string_count": 5423,
  "model": "gpt-4",
  "quality_score": 0.92
}
```

**Response (Pending):**
```json
{
  "status": "pending",
  "textdomain": "woocommerce",
  "version": "8.2.0",
  "locale": "es_ES",
  "queue_position": 15,
  "estimated_time": "10 minutes",
  "requested_at": "2024-01-15T10:20:00Z"
}
```

**Response (Processing):**
```json
{
  "status": "processing",
  "textdomain": "woocommerce",
  "version": "8.2.0",
  "locale": "es_ES",
  "progress": 45,
  "strings_translated": 2440,
  "strings_total": 5423,
  "estimated_time": "5 minutes"
}
```

**Status Codes:**
- `200`: Status retrieved successfully
- `404`: Translation job not found

---

### 5. Submit Feedback

Submit feedback about translation quality.

**Endpoint:** `POST /feedback`

**Request Body:**
```json
{
  "textdomain": "woocommerce",
  "version": "8.2.0",
  "locale": "es_ES",
  "feedback": "good",
  "details": "Very accurate translations for product descriptions",
  "site_url": "https://example.com"
}
```

**Feedback Types:**
- `good`: Positive feedback
- `bad`: Negative feedback
- `report`: Report an issue (requires details)

**Response:**
```json
{
  "status": "received",
  "message": "Thank you for your feedback"
}
```

**Status Codes:**
- `200` or `202`: Feedback received
- `400`: Invalid feedback data

---

## Translation Package Format

Translation packages are ZIP files containing `.mo` and `.po` files:

```
woocommerce-es_ES.zip
├── woocommerce-es_ES.mo
├── woocommerce-es_ES.po
└── woocommerce-es_ES.l10n.php (optional, for PHP 8+ performance)
```

### PO File Requirements

- Must follow GNU gettext PO file format
- Include proper headers (Project-Id-Version, Report-Msgid-Bugs-To, etc.)
- Use UTF-8 encoding
- Include translator comments for context

Example PO header:
```po
msgid ""
msgstr ""
"Project-Id-Version: WooCommerce 8.2.0\n"
"Report-Msgid-Bugs-To: https://example.com/support\n"
"POT-Creation-Date: 2024-01-15 10:00:00+00:00\n"
"PO-Revision-Date: 2024-01-15 10:00:00+00:00\n"
"Last-Translator: AI Translator <ai@example.com>\n"
"Language-Team: Spanish\n"
"Language: es_ES\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
"X-Generator: Gratis AI Translator 1.0\n"
"X-Translation-Source: ai\n"
```

## Server Implementation Notes

### Queue System

1. **Priority Levels** (1-10):
   - Higher priority = processed sooner
   - Based on plugin popularity (active installs)
   - Can be overridden for sponsored translations

2. **Processing Pipeline**:
   ```
   Request → Validate → Queue → Extract POT → Translate → Build MO/PO → Package → Store → Serve
   ```

3. **Storage**:
   - Keep generated translations indefinitely
   - Version-specific (plugin v8.2.0 ≠ v8.3.0)
   - Cache aggressively with CDN

### Rate Limiting

Suggested limits per IP:
- `/health`: 60 requests/minute
- `/check-translations`: 30 requests/minute
- `/request-translation`: 10 requests/minute
- `/translation-status`: 60 requests/minute
- `/feedback`: 30 requests/minute

### Caching Strategy

1. **Generated Translations**: Permanent (version-specific)
2. **API Responses**: 5 minutes for status checks
3. **POT Files**: Cache per plugin version (24 hours)

### Error Handling

Standard HTTP status codes with JSON error responses:

```json
{
  "code": "rate_limit_exceeded",
  "message": "Too many requests. Please try again in 60 seconds.",
  "data": {
    "retry_after": 60
  }
}
```

## GlotPress Integration

The server should integrate with GlotPress:

1. Create a project per plugin (if not exists)
2. Import POT file into GlotPress
3. Use GlotPress API to:
   - Check existing translations
   - Add new AI-generated translations
   - Export .po/.mo files

### GlotPress Project Structure

```
Projects
├── woocommerce (meta: version=8.2.0, source=wordpress.org)
│   └── Translation Sets
│       ├── Spanish (es_ES) - 100%
│       ├── German (de_DE) - 100%
│       └── French (fr_FR) - 100%
├── contact-form-7 (meta: version=5.8.0)
└── [other plugins]
```

## AI Translation Process

### 1. Extract Strings

Download plugin from wordpress.org or use provided POT file.

### 2. Batch Translation

Send strings to AI with context:
```json
{
  "source_language": "en_US",
  "target_language": "es_ES",
  "strings": [
    {"original": "Add to Cart", "context": "woocommerce"},
    {"original": "Checkout", "context": "woocommerce"}
  ],
  "glossary": {
    "Cart": "Carrito",
    "Checkout": "Finalizar compra"
  }
}
```

### 3. Quality Assurance

- Validate placeholders (`%s`, `%d`, `{variable}`)
- Check for consistent terminology
- Flag low-confidence translations for review

### 4. Build Package

Generate .mo and .po files, package as ZIP.

## Security Considerations

1. **Validate Input**: Sanitize all textdomains, versions, and locales
2. **Rate Limiting**: Prevent abuse and control costs
3. **File Validation**: Ensure generated files are valid .mo/.po
4. **Access Control**: Consider API keys for high-volume users
5. **HTTPS Only**: All endpoints must use TLS

## Monitoring

Track these metrics:
- Translation requests per plugin
- Queue depth and processing time
- API response times
- Error rates
- AI costs per translation

## Future Enhancements

- Webhook notifications when translations complete
- Batch translation API for multiple plugins
- Translation quality scoring
- Community corrections/feedback integration
- Support for themes (not just plugins)
