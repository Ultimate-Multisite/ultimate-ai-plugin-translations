<?php
/**
 * Translation Providers class
 *
 * Manages multiple translation providers with fallback support.
 *
 * @package GratisAIPluginTranslations
 */

declare(strict_types=1);

namespace GratisAIPluginTranslations;

/**
 * Translation Providers class.
 *
 * @since 1.0.0
 */
class Translation_Providers {

    /**
     * Available providers.
     *
     * @since 1.0.0
     * @var array
     */
    private array $providers = [];

    /**
     * Provider priority order.
     *
     * @since 1.0.0
     * @var array
     */
    private array $provider_priority = [
        'chrome_ai',      // Free, client-side
        'yandex',         // Free widget
        'server_openai',  // Our server
        'deepl',          // Premium
        'google',         // Premium
    ];

    /**
     * Instance of this class.
     *
     * @since 1.0.0
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Get the singleton instance.
     *
     * @since 1.0.0
     * @return self
     */
    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    private function __construct() {
        $this->register_providers();
    }

    /**
     * Register available providers.
     *
     * @since 1.0.0
     * @return void
     */
    private function register_providers(): void {
        $this->providers = [
            'server_openai' => [
                'name'        => 'OpenAI (Server)',
                'description' => 'AI-powered translations via our server',
                'is_free'     => true,
                'is_available'=> true,
                'supports'    => ['batch', 'context', 'glossary'],
            ],
            'chrome_ai' => [
                'name'        => 'Chrome Built-in AI',
                'description' => 'Client-side AI translation (Chrome only)',
                'is_free'     => true,
                'is_available'=> $this->is_chrome_ai_available(),
                'supports'    => ['client_side'],
            ],
            'yandex' => [
                'name'        => 'Yandex Translate',
                'description' => 'Free widget-based translation',
                'is_free'     => true,
                'is_available'=> true,
                'supports'    => ['widget'],
            ],
            'deepl' => [
                'name'        => 'DeepL',
                'description' => 'High-quality neural translation',
                'is_free'     => false,
                'is_available'=> $this->has_api_key('deepl'),
                'supports'    => ['batch', 'context'],
            ],
            'google' => [
                'name'        => 'Google Translate',
                'description' => 'Google Cloud Translation API',
                'is_free'     => false,
                'is_available'=> $this->has_api_key('google'),
                'supports'    => ['batch'],
            ],
        ];
    }

    /**
     * Check if Chrome AI is available.
     *
     * @since 1.0.0
     * @return bool
     */
    private function is_chrome_ai_available(): bool {
        // Chrome AI is client-side only, detected via JavaScript
        // Server-side we assume it might be available
        return true;
    }

    /**
     * Check if API key exists for provider.
     *
     * @since 1.0.0
     * @param string $provider Provider name.
     * @return bool
     */
    private function has_api_key(string $provider): bool {
        $api_key = get_site_option("gratis_ai_pt_{$provider}_api_key");
        return !empty($api_key);
    }

    /**
     * Get available providers.
     *
     * @since 1.0.0
     * @param bool $free_only Only return free providers.
     * @return array
     */
    public function get_available_providers(bool $free_only = false): array {
        $available = [];

        foreach ($this->provider_priority as $provider_id) {
            if (!isset($this->providers[$provider_id])) {
                continue;
            }

            $provider = $this->providers[$provider_id];

            if (!$provider['is_available']) {
                continue;
            }

            if ($free_only && !$provider['is_free']) {
                continue;
            }

            $available[$provider_id] = $provider;
        }

        return $available;
    }

    /**
     * Get best available provider.
     *
     * @since 1.0.0
     * @param bool $prefer_free Prefer free providers.
     * @return string|null Provider ID or null.
     */
    public function get_best_provider(bool $prefer_free = true): ?string {
        foreach ($this->provider_priority as $provider_id) {
            if (!isset($this->providers[$provider_id])) {
                continue;
            }

            $provider = $this->providers[$provider_id];

            if (!$provider['is_available']) {
                continue;
            }

            if ($prefer_free && !$provider['is_free']) {
                continue;
            }

            return $provider_id;
        }

        // Fallback to any available
        foreach ($this->provider_priority as $provider_id) {
            if (isset($this->providers[$provider_id]) && $this->providers[$provider_id]['is_available']) {
                return $provider_id;
            }
        }

        return null;
    }

    /**
     * Translate strings using best available provider.
     *
     * @since 1.0.0
     * @param array  $strings    Strings to translate.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @return array|\WP_Error Translated strings or error.
     */
    public function translate(array $strings, string $source_lang, string $target_lang) {
        $provider = $this->get_best_provider();

        if (!$provider) {
            return new \WP_Error(
                'no_provider',
                __('No translation provider available', 'gratis-ai-plugin-translations')
            );
        }

        return $this->translate_with_provider($provider, $strings, $source_lang, $target_lang);
    }

    /**
     * Translate with specific provider.
     *
     * @since 1.0.0
     * @param string $provider    Provider ID.
     * @param array  $strings     Strings to translate.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @return array|\WP_Error Translated strings or error.
     */
    public function translate_with_provider(string $provider, array $strings, string $source_lang, string $target_lang) {
        switch ($provider) {
            case 'server_openai':
                return $this->translate_with_server($strings, $source_lang, $target_lang);

            case 'chrome_ai':
                // Chrome AI is client-side, return instructions
                return new \WP_Error(
                    'client_side',
                    __('Chrome AI requires client-side processing', 'gratis-ai-plugin-translations'),
                    ['provider' => 'chrome_ai']
                );

            case 'yandex':
                return $this->translate_with_yandex($strings, $source_lang, $target_lang);

            case 'deepl':
                return $this->translate_with_deepl($strings, $source_lang, $target_lang);

            case 'google':
                return $this->translate_with_google($strings, $source_lang, $target_lang);

            default:
                return new \WP_Error(
                    'unknown_provider',
                    sprintf(
                        /* translators: %s: Provider name */
                        __('Unknown provider: %s', 'gratis-ai-plugin-translations'),
                        $provider
                    )
                );
        }
    }

    /**
     * Translate using our server.
     *
     * @since 1.0.0
     * @param array  $strings     Strings to translate.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @return array|\WP_Error
     */
    private function translate_with_server(array $strings, string $source_lang, string $target_lang) {
        $api_client = new Translation_API_Client();

        // This would need to be implemented on the server side
        // For now, return the strings as-is with an error
        return new \WP_Error(
            'server_translation',
            __('Server translation not yet implemented', 'gratis-ai-plugin-translations')
        );
    }

    /**
     * Translate using Yandex.
     *
     * @since 1.0.0
     * @param array  $strings     Strings to translate.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @return array|\WP_Error
     */
    private function translate_with_yandex(array $strings, string $source_lang, string $target_lang) {
        // Yandex uses widget approach, not API for free tier
        // This would need client-side implementation
        return new \WP_Error(
            'yandex_widget',
            __('Yandex requires widget-based translation', 'gratis-ai-plugin-translations'),
            ['provider' => 'yandex']
        );
    }

    /**
     * Translate using DeepL.
     *
     * @since 1.0.0
     * @param array  $strings     Strings to translate.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @return array|\WP_Error
     */
    private function translate_with_deepl(array $strings, string $source_lang, string $target_lang) {
        $api_key = get_site_option('gratis_ai_pt_deepl_api_key');

        if (empty($api_key)) {
            return new \WP_Error(
                'no_api_key',
                __('DeepL API key not configured', 'gratis-ai-plugin-translations')
            );
        }

        $endpoint = 'https://api-free.deepl.com/v2/translate';

        $texts = array_column($strings, 'text');

        $response = wp_remote_post(
            $endpoint,
            [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode([
                    'text'        => $texts,
                    'source_lang' => strtoupper($source_lang),
                    'target_lang' => strtoupper($target_lang),
                ]),
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error(
                'json_error',
                __('Failed to parse DeepL response', 'gratis-ai-plugin-translations')
            );
        }

        if (isset($data['translations'])) {
            $translated = [];
            foreach ($data['translations'] as $index => $translation) {
                $translated[$index] = [
                    'original'    => $strings[$index]['text'],
                    'translation' => $translation['text'],
                ];
            }
            return $translated;
        }

        return new \WP_Error(
            'translation_failed',
            __('DeepL translation failed', 'gratis-ai-plugin-translations')
        );
    }

    /**
     * Translate using Google Cloud.
     *
     * @since 1.0.0
     * @param array  $strings     Strings to translate.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @return array|\WP_Error
     */
    private function translate_with_google(array $strings, string $source_lang, string $target_lang) {
        $api_key = get_site_option('gratis_ai_pt_google_api_key');

        if (empty($api_key)) {
            return new \WP_Error(
                'no_api_key',
                __('Google API key not configured', 'gratis-ai-plugin-translations')
            );
        }

        $endpoint = 'https://translation.googleapis.com/language/translate/v2';

        $texts = array_column($strings, 'text');

        $response = wp_remote_post(
            add_query_arg('key', $api_key, $endpoint),
            [
                'body'    => [
                    'q'      => $texts,
                    'source' => $source_lang,
                    'target' => $target_lang,
                    'format' => 'text',
                ],
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error(
                'json_error',
                __('Failed to parse Google response', 'gratis-ai-plugin-translations')
            );
        }

        if (isset($data['data']['translations'])) {
            $translated = [];
            foreach ($data['data']['translations'] as $index => $translation) {
                $translated[$index] = [
                    'original'    => $strings[$index]['text'],
                    'translation' => $translation['translatedText'],
                ];
            }
            return $translated;
        }

        return new \WP_Error(
            'translation_failed',
            __('Google translation failed', 'gratis-ai-plugin-translations')
        );
    }

    /**
     * Filter strings before translation.
     *
     * @since 1.0.0
     * @param array $strings Strings to filter.
     * @return array Filtered strings.
     */
    public function filter_strings(array $strings): array {
        $filtered = [];

        foreach ($strings as $string) {
            // Skip empty strings
            if (empty($string['text'])) {
                continue;
            }

            // Skip strings that are just placeholders
            if ($this->is_placeholder_only($string['text'])) {
                $filtered[] = $string;
                continue;
            }

            // Extract placeholders for later restoration
            $string['placeholders'] = $this->extract_placeholders($string['text']);
            $string['filtered_text'] = $this->prepare_for_translation($string['text']);

            $filtered[] = $string;
        }

        return $filtered;
    }

    /**
     * Check if string is placeholder only.
     *
     * @since 1.0.0
     * @param string $text Text to check.
     * @return bool
     */
    private function is_placeholder_only(string $text): bool {
        // Pattern for placeholders: %s, %d, %1$s, {variable}, etc.
        $pattern = '/^(\s*[%\{][\w\d$]*[}\s]*)+$/';
        return (bool) preg_match($pattern, $text);
    }

    /**
     * Extract placeholders from text.
     *
     * @since 1.0.0
     * @param string $text Text to extract from.
     * @return array Placeholders with positions.
     */
    private function extract_placeholders(string $text): array {
        $placeholders = [];

        // WordPress style: %s, %d, %1$s, %2$d
        preg_match_all('/%\d*\$*[sd]/', $text, $wp_matches, PREG_OFFSET_CAPTURE);
        foreach ($wp_matches[0] as $match) {
            $placeholders[] = [
                'placeholder' => $match[0],
                'position'    => $match[1],
                'type'        => 'wordpress',
            ];
        }

        // Named placeholders: {variable}, {{variable}}
        preg_match_all('/\{\{?[\w\d_]+\}?\}/', $text, $named_matches, PREG_OFFSET_CAPTURE);
        foreach ($named_matches[0] as $match) {
            $placeholders[] = [
                'placeholder' => $match[0],
                'position'    => $match[1],
                'type'        => 'named',
            ];
        }

        // HTML entities
        preg_match_all('/&[\w\d#]+;/', $text, $html_matches, PREG_OFFSET_CAPTURE);
        foreach ($html_matches[0] as $match) {
            $placeholders[] = [
                'placeholder' => $match[0],
                'position'    => $match[1],
                'type'        => 'html',
            ];
        }

        return $placeholders;
    }

    /**
     * Prepare text for translation.
     *
     * @since 1.0.0
     * @param string $text Text to prepare.
     * @return string Prepared text.
     */
    private function prepare_for_translation(string $text): string {
        // Replace placeholders with markers that won't be translated
        $markers = [];
        $counter = 0;

        // WordPress placeholders
        $text = preg_replace_callback(
            '/%\d*\$*[sd]/',
            function ($matches) use (&$markers, &$counter) {
                $marker = "__PH{$counter}__";
                $markers[$marker] = $matches[0];
                $counter++;
                return $marker;
            },
            $text
        );

        // Named placeholders
        $text = preg_replace_callback(
            '/\{\{?[\w\d_]+\}?\}/',
            function ($matches) use (&$markers, &$counter) {
                $marker = "__PH{$counter}__";
                $markers[$marker] = $matches[0];
                $counter++;
                return $marker;
            },
            $text
        );

        return $text;
    }

    /**
     * Restore placeholders after translation.
     *
     * @since 1.0.0
     * @param string $translated Translated text.
     * @param array  $placeholders Original placeholders.
     * @return string Text with restored placeholders.
     */
    public function restore_placeholders(string $translated, array $placeholders): string {
        foreach ($placeholders as $index => $placeholder) {
            $marker = "__PH{$index}__";
            $translated = str_replace($marker, $placeholder['placeholder'], $translated);
        }

        return $translated;
    }
}
