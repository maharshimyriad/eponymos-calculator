<?php

namespace Eponymos\Calculation;

use WP_Error;

/** Persistent, versioned global assumptions used by calculation formulas. */
class Methodology
{
    const OPTION = 'eponymos_calculation_methodology';
    const TABLE_KEY = 'calculation';
    const VERSION = '5';

    public static function defaults(): array
    {
        return [
            'version' => self::VERSION,
            'usage' => ['price_per_active_user_day' => 5, 'working_days_per_month' => 21],
            'productivity' => [
                'current_hours_per_pitch' => 3,
                'new_hours_per_pitch' => 1.2,
                'hourly_rate' => 65,
                'source' => 'Eponymos internal planning assumption; not independently benchmarked',
                'source_date' => '2026-09-30',
            ],
            'roi' => ['annual_periods' => 12, 'monthly_price' => 500],
            'pricing' => [
                'months_per_year' => 12,
                'tier_price_percent_of_legacy' => 0.5,
                'commitment_months' => 12,
                'networked_monthly_price' => null,
                'monthly_price_per_user' => null,
                'current_software_cost_per_user' => null,
            ],
            'benchmarks' => [
                'legacy_small_annual' => 10000,
                'legacy_mid_annual' => 16700,
                'legacy_enterprise_annual' => 69100,
                'legacy_small_user_limit' => 5,
                'legacy_mid_user_limit' => 15,
            ],
            'plans' => [
                'professional_included_users' => 10,
                'professional_additional_user_annual' => 1000,
                'enterprise_included_users' => 100,
            ],
            'software_categories' => ['pr_software' => 0, 'social_media_tools' => 0, 'digital_asset_management' => 0],
        ];
    }

    public function __construct(private array $settings = [])
    {
        $this->settings = self::merge(self::defaults(), $settings);
    }

    public static function load(): self
    {
        global $wpdb;
        $table = $wpdb->prefix . 'eponymos_methodology';
        $row = $wpdb->get_row($wpdb->prepare("SELECT settings, methodology_version FROM {$table} WHERE methodology_key=%s", self::TABLE_KEY), ARRAY_A);
        if ($row) {
            $settings = json_decode($row['settings'], true);
            if (is_array($settings)) {
                $settings['version'] = self::VERSION;
                return new self($settings);
            }
        }
        $methodology = new self(self::defaults());
        $methodology->persist();
        return $methodology;
    }

    public function all(): array { return $this->settings; }

    public function get(string $path, $default = null)
    {
        $value = $this->settings;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return $default;
            $value = $value[$part];
        }
        return $value;
    }

    public function version(): string { return (string) $this->get('version', self::VERSION); }

    public function validate(): ?WP_Error
    {
        foreach (['roi.annual_periods', 'usage.price_per_active_user_day', 'usage.working_days_per_month', 'productivity.current_hours_per_pitch', 'productivity.new_hours_per_pitch', 'productivity.hourly_rate', 'pricing.months_per_year', 'pricing.tier_price_percent_of_legacy', 'pricing.commitment_months', 'benchmarks.legacy_small_annual', 'benchmarks.legacy_mid_annual', 'benchmarks.legacy_enterprise_annual', 'benchmarks.legacy_small_user_limit', 'benchmarks.legacy_mid_user_limit', 'plans.professional_included_users', 'plans.professional_additional_user_annual', 'plans.enterprise_included_users'] as $path) {
            $value = $this->get($path);
            if ($value === null || $value === '' || !is_numeric($value) || (float) $value < 0) return new WP_Error('calculation_configuration_missing', 'Calculation methodology is incomplete.', ['path' => $path, 'status' => 500]);
        }
        if ((float) $this->get('roi.annual_periods') <= 0) return new WP_Error('calculation_configuration_invalid', 'Calculation methodology contains an invalid ROI period.', ['status' => 500]);
        return null;
    }

    public static function save(array $settings): bool
    {
        $methodology = new self(self::merge(self::defaults(), self::global_settings($settings)));
        return $methodology->persist();
    }

    private function persist(): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'eponymos_methodology';
        $now = current_time('mysql');
        $data = ['methodology_key' => self::TABLE_KEY, 'methodology_version' => self::VERSION, 'settings' => wp_json_encode($this->settings), 'updated_at' => $now];
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE methodology_key=%s", self::TABLE_KEY));
        if ($existing) return false !== $wpdb->update($table, $data, ['id' => (int) $existing], ['%s', '%s', '%s', '%s'], ['%d']);
        $data['created_at'] = $now;
        return false !== $wpdb->insert($table, $data, ['%s', '%s', '%s', '%s', '%s']);
    }

    private static function global_settings(array $settings): array
    {
        return [
            'usage' => [
                'price_per_active_user_day' => $settings['usage']['price_per_active_user_day'] ?? null,
                'working_days_per_month' => $settings['usage']['working_days_per_month'] ?? null,
            ],
            'productivity' => [
                'current_hours_per_pitch' => $settings['productivity']['current_hours_per_pitch'] ?? null,
                'new_hours_per_pitch' => $settings['productivity']['new_hours_per_pitch'] ?? null,
                'hourly_rate' => $settings['productivity']['hourly_rate'] ?? null,
                'source' => $settings['productivity']['source'] ?? null,
                'source_date' => $settings['productivity']['source_date'] ?? null,
            ],
            'roi' => [
                'annual_periods' => $settings['roi']['annual_periods'] ?? null,
                'monthly_price' => $settings['roi']['monthly_price'] ?? null,
            ],
            'pricing' => [
                'months_per_year' => $settings['pricing']['months_per_year'] ?? null,
                'tier_price_percent_of_legacy' => $settings['pricing']['tier_price_percent_of_legacy'] ?? null,
                'commitment_months' => $settings['pricing']['commitment_months'] ?? null,
                'networked_monthly_price' => $settings['pricing']['networked_monthly_price'] ?? null,
                'monthly_price_per_user' => $settings['pricing']['monthly_price_per_user'] ?? null,
                'current_software_cost_per_user' => $settings['pricing']['current_software_cost_per_user'] ?? null,
            ],
            'benchmarks' => [
                'legacy_small_annual' => $settings['benchmarks']['legacy_small_annual'] ?? null,
                'legacy_mid_annual' => $settings['benchmarks']['legacy_mid_annual'] ?? null,
                'legacy_enterprise_annual' => $settings['benchmarks']['legacy_enterprise_annual'] ?? null,
                'legacy_small_user_limit' => $settings['benchmarks']['legacy_small_user_limit'] ?? null,
                'legacy_mid_user_limit' => $settings['benchmarks']['legacy_mid_user_limit'] ?? null,
            ],
            'plans' => [
                'professional_included_users' => $settings['plans']['professional_included_users'] ?? null,
                'professional_additional_user_annual' => $settings['plans']['professional_additional_user_annual'] ?? null,
                'enterprise_included_users' => $settings['plans']['enterprise_included_users'] ?? null,
            ],
            'software_categories' => [
                'pr_software' => $settings['software_categories']['pr_software'] ?? 0,
                'social_media_tools' => $settings['software_categories']['social_media_tools'] ?? 0,
                'digital_asset_management' => $settings['software_categories']['digital_asset_management'] ?? 0,
            ],
        ];
    }

    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key]) ? self::merge($base[$key], $value) : $value;
        return $base;
    }
}
