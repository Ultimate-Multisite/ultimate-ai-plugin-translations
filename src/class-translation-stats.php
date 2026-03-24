<?php
/**
 * Translation Statistics class
 *
 * Tracks translation usage and statistics.
 *
 * @package GratisAIPluginTranslations
 */

declare(strict_types=1);

namespace GratisAIPluginTranslations;

/**
 * Translation Statistics class.
 *
 * @since 1.0.0
 */
class Translation_Stats {

    /**
     * Instance of this class.
     *
     * @since 1.0.0
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Stats option name.
     *
     * @since 1.0.0
     * @var string
     */
    private string $stats_option = 'gratis_ai_pt_stats';

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
     * Record a translation.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $locale     Target locale.
     * @param int    $char_count Character count.
     * @param string $provider   Provider used.
     * @return void
     */
    public function record_translation(string $textdomain, string $locale, int $char_count, string $provider): void {
        $stats = $this->get_stats();

        // Update totals.
        $stats['total_translations'] = ($stats['total_translations'] ?? 0) + 1;
        $stats['total_characters']   = ($stats['total_characters'] ?? 0) + $char_count;

        // Update provider stats.
        if (!isset($stats['by_provider'][$provider])) {
            $stats['by_provider'][$provider] = [
                'count'      => 0,
                'characters' => 0,
            ];
        }
        $stats['by_provider'][$provider]['count']++;
        $stats['by_provider'][$provider]['characters'] += $char_count;

        // Update locale stats.
        if (!isset($stats['by_locale'][$locale])) {
            $stats['by_locale'][$locale] = [
                'count'      => 0,
                'characters' => 0,
            ];
        }
        $stats['by_locale'][$locale]['count']++;
        $stats['by_locale'][$locale]['characters'] += $char_count;

        // Update plugin stats.
        if (!isset($stats['by_plugin'][$textdomain])) {
            $stats['by_plugin'][$textdomain] = [
                'count'      => 0,
                'characters' => 0,
                'locales'    => [],
            ];
        }
        $stats['by_plugin'][$textdomain]['count']++;
        $stats['by_plugin'][$textdomain]['characters'] += $char_count;
        if (!in_array($locale, $stats['by_plugin'][$textdomain]['locales'], true)) {
            $stats['by_plugin'][$textdomain]['locales'][] = $locale;
        }

        // Update daily stats.
        $today = date('Y-m-d');
        if (!isset($stats['by_day'][$today])) {
            $stats['by_day'][$today] = [
                'count'      => 0,
                'characters' => 0,
            ];
        }
        $stats['by_day'][$today]['count']++;
        $stats['by_day'][$today]['characters'] += $char_count;

        // Save stats.
        update_site_option($this->stats_option, $stats);
    }

    /**
     * Get all statistics.
     *
     * @since 1.0.0
     * @return array
     */
    public function get_stats(): array {
        return get_site_option($this->stats_option, []);
    }

    /**
     * Get summary statistics.
     *
     * @since 1.0.0
     * @return array
     */
    public function get_summary(): array {
        $stats = $this->get_stats();

        return [
            'total_translations' => $stats['total_translations'] ?? 0,
            'total_characters'   => $stats['total_characters'] ?? 0,
            'unique_plugins'     => count($stats['by_plugin'] ?? []),
            'unique_locales'     => count($stats['by_locale'] ?? []),
            'today_count'        => $stats['by_day'][date('Y-m-d')]['count'] ?? 0,
            'today_characters'   => $stats['by_day'][date('Y-m-d')]['characters'] ?? 0,
        ];
    }

    /**
     * Get provider usage statistics.
     *
     * @since 1.0.0
     * @return array
     */
    public function get_provider_stats(): array {
        $stats = $this->get_stats();
        return $stats['by_provider'] ?? [];
    }

    /**
     * Get locale statistics.
     *
     * @since 1.0.0
     * @return array
     */
    public function get_locale_stats(): array {
        $stats = $this->get_stats();
        return $stats['by_locale'] ?? [];
    }

    /**
     * Get plugin statistics.
     *
     * @since 1.0.0
     * @return array
     */
    public function get_plugin_stats(): array {
        $stats = $this->get_stats();
        return $stats['by_plugin'] ?? [];
    }

    /**
     * Get daily statistics for chart.
     *
     * @since 1.0.0
     * @param int $days Number of days.
     * @return array
     */
    public function get_daily_stats(int $days = 30): array {
        $stats = $this->get_stats();
        $daily = $stats['by_day'] ?? [];

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $result[$date] = $daily[$date] ?? [
                'count'      => 0,
                'characters' => 0,
            ];
        }

        return $result;
    }

    /**
     * Reset statistics.
     *
     * @since 1.0.0
     * @return void
     */
    public function reset_stats(): void {
        delete_site_option($this->stats_option);
    }

    /**
     * Clean old daily stats.
     *
     * @since 1.0.0
     * @param int $days_keep Days to keep.
     * @return void
     */
    public function cleanup_old_stats(int $days_keep = 90): void {
        $stats = $this->get_stats();

        if (!isset($stats['by_day'])) {
            return;
        }

        $cutoff = date('Y-m-d', strtotime("-{$days_keep} days"));

        foreach ($stats['by_day'] as $date => $data) {
            if ($date < $cutoff) {
                unset($stats['by_day'][$date]);
            }
        }

        update_site_option($this->stats_option, $stats);
    }

    /**
     * Get top plugins by translation count.
     *
     * @since 1.0.0
     * @param int $limit Number of plugins.
     * @return array
     */
    public function get_top_plugins(int $limit = 10): array {
        $plugins = $this->get_plugin_stats();

        uasort($plugins, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return array_slice($plugins, 0, $limit, true);
    }

    /**
     * Get top locales by translation count.
     *
     * @since 1.0.0
     * @param int $limit Number of locales.
     * @return array
     */
    public function get_top_locales(int $limit = 10): array {
        $locales = $this->get_locale_stats();

        uasort($locales, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return array_slice($locales, 0, $limit, true);
    }
}
