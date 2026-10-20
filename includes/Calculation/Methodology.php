<?php

namespace Eponymos\Calculation;

use WP_Error;

/** Persistent, versioned global assumptions used by calculation formulas. */
class Methodology
{
    const OPTION = 'eponymos_calculation_methodology';
    const TABLE_KEY = 'calculation';
    const VERSION = '6';

    public static function defaults(): array
    {
        return [
            'version' => self::VERSION,
            'usage' => [
                'price_per_active_user_day' => 5,
                'working_days_per_month' => 21,
                'frequency_days' => [
                    'every_working_day' => 21,
                    'most_days' => 15,
                    'few_days_a_week' => 8,
                    'occasionally' => 4,
                ],
            ],
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
                'commitment_months' => 12,
                'networked_monthly_price' => null,
                'monthly_price_per_user' => null,
                'maximum_users' => 500,
                'agency_networked_client_threshold' => 26,
                'agency_networked_nudge_min_clients' => 11,
                'agency_networked_nudge_max_clients' => 25,
                'casual_nudge_users_before_trigger' => 2,
            ],
            'benchmarks' => [
                'legacy_small_annual' => 10000,
                'legacy_mid_annual' => 16700,
                'legacy_enterprise_annual' => 69100,
                'legacy_small_user_limit' => 5,
                'legacy_mid_user_limit' => 15,
                'legacy_enterprise_anchor_users' => 50,
                'legacy_small_annual_source_url' => 'https://www.vendr.com/marketplace/',
                'legacy_small_annual_date_checked' => '2026-02-28',
                'legacy_mid_annual_source_url' => 'https://www.vendr.com/marketplace/',
                'legacy_mid_annual_date_checked' => '2026-02-28',
                'legacy_enterprise_annual_source_url' => 'https://www.vendr.com/marketplace/',
                'legacy_enterprise_annual_date_checked' => '2026-02-28',
            ],
            'plans' => [
                'professional_annual_base' => 8350,
                'professional_included_users' => 10,
                'professional_additional_user_annual' => 1000,
                'enterprise_annual_base' => 34550,
                'enterprise_included_users' => 40,
                'enterprise_switch_point_users' => 37,
                'enterprise_nudge_users_before_trigger' => 5,
                'enterprise_contact_nudge_users' => 10,
                'enterprise_additional_user_annual' => null,
            ],
            'competitor_costs' => [
                'cision' => ['value' => 1052, 'source_url' => 'https://www.vendr.com/marketplace/', 'date_checked' => '2026-02-28'],
                'muck_rack' => ['value' => 1083, 'source_url' => 'https://www.vendr.com/marketplace/', 'date_checked' => '2026-02-28'],
                'meltwater' => ['value' => 2150, 'source_url' => 'https://www.vendr.com/marketplace/', 'date_checked' => '2026-02-28'],
                'sprout_social' => ['value' => 200, 'source_url' => 'https://sproutsocial.com/pricing/', 'date_checked' => '2026-09-30'],
                'brandwatch' => ['value' => 1000, 'source_url' => 'https://www.brandwatch.com/', 'date_checked' => '2026-09-30'],
                'brand24' => ['value' => 150, 'source_url' => 'https://brand24.com/pricing/', 'date_checked' => '2026-09-30'],
                'hootsuite' => ['value' => 100, 'source_url' => 'https://www.hootsuite.com/plans', 'date_checked' => '2026-09-30'],
                'sprinklr' => ['value' => 1000, 'source_url' => 'https://www.sprinklr.com/pricing/', 'date_checked' => '2026-09-30'],
                'brandfolder' => ['value' => 1000, 'source_url' => 'https://brandfolder.com/', 'date_checked' => '2026-09-30'],
                'bynder' => ['value' => 1000, 'source_url' => 'https://www.bynder.com/en/pricing/', 'date_checked' => '2026-09-30'],
                'canto' => ['value' => 500, 'source_url' => 'https://www.canto.com/pricing/', 'date_checked' => '2026-09-30'],
                'acquia_dam' => ['value' => 1000, 'source_url' => 'https://www.acquia.com/products/digital-asset-management', 'date_checked' => '2026-09-30'],
                'frontify' => ['value' => 500, 'source_url' => 'https://www.frontify.com/en/pricing/', 'date_checked' => '2026-09-30'],
            ],
            'software_categories' => ['pr_software' => 0, 'social_media_tools' => 0, 'digital_asset_management' => 0],
        ];
    }

    public function __construct(private array $settings = [])
    {
        $legacyTierPercent = $settings['pricing']['tier_price_percent_of_legacy'] ?? null;
        $this->settings = self::merge(self::defaults(), $settings);
        if (is_numeric($legacyTierPercent)) {
            if (!array_key_exists('professional_annual_base', $settings['plans'] ?? [])) {
                $this->settings['plans']['professional_annual_base'] = (float) ($settings['benchmarks']['legacy_mid_annual'] ?? self::defaults()['benchmarks']['legacy_mid_annual']) * (float) $legacyTierPercent;
            }
            if (!array_key_exists('enterprise_annual_base', $settings['plans'] ?? [])) {
                $this->settings['plans']['enterprise_annual_base'] = (float) ($settings['benchmarks']['legacy_enterprise_annual'] ?? self::defaults()['benchmarks']['legacy_enterprise_annual']) * (float) $legacyTierPercent;
            }
        }
        unset($this->settings['pricing']['current_software_cost_per_user']);
        unset($this->settings['pricing']['tier_price_percent_of_legacy']);
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
        foreach (['roi.annual_periods', 'usage.price_per_active_user_day', 'usage.working_days_per_month', 'usage.frequency_days.every_working_day', 'usage.frequency_days.most_days', 'usage.frequency_days.few_days_a_week', 'usage.frequency_days.occasionally', 'productivity.current_hours_per_pitch', 'productivity.new_hours_per_pitch', 'productivity.hourly_rate', 'pricing.months_per_year', 'pricing.commitment_months', 'pricing.maximum_users', 'pricing.agency_networked_client_threshold', 'pricing.agency_networked_nudge_min_clients', 'pricing.agency_networked_nudge_max_clients', 'pricing.casual_nudge_users_before_trigger', 'benchmarks.legacy_small_annual', 'benchmarks.legacy_mid_annual', 'benchmarks.legacy_enterprise_annual', 'benchmarks.legacy_small_user_limit', 'benchmarks.legacy_mid_user_limit', 'benchmarks.legacy_enterprise_anchor_users', 'plans.professional_annual_base', 'plans.professional_included_users', 'plans.professional_additional_user_annual', 'plans.enterprise_annual_base', 'plans.enterprise_included_users', 'plans.enterprise_switch_point_users', 'plans.enterprise_nudge_users_before_trigger', 'plans.enterprise_contact_nudge_users'] as $path) {
            $value = $this->get($path);
            if ($value === null || $value === '' || !is_numeric($value) || (float) $value < 0) return new WP_Error('calculation_configuration_missing', 'Calculation methodology is incomplete.', ['path' => $path, 'status' => 500]);
        }
        foreach (['usage.frequency_days.every_working_day', 'usage.frequency_days.most_days', 'usage.frequency_days.few_days_a_week', 'usage.frequency_days.occasionally', 'pricing.maximum_users', 'pricing.agency_networked_client_threshold', 'pricing.agency_networked_nudge_min_clients', 'pricing.agency_networked_nudge_max_clients', 'pricing.casual_nudge_users_before_trigger', 'benchmarks.legacy_small_user_limit', 'benchmarks.legacy_mid_user_limit', 'benchmarks.legacy_enterprise_anchor_users', 'plans.professional_included_users', 'plans.enterprise_included_users', 'plans.enterprise_switch_point_users', 'plans.enterprise_nudge_users_before_trigger', 'plans.enterprise_contact_nudge_users'] as $path) {
            if (floor((float) $this->get($path)) !== (float) $this->get($path)) return new WP_Error('calculation_configuration_invalid', 'Whole-number limits are required.', ['path' => $path, 'status' => 500]);
        }
        $enterpriseExtra = $this->get('plans.enterprise_additional_user_annual');
        if ($enterpriseExtra !== null && $enterpriseExtra !== '' && (!is_numeric($enterpriseExtra) || (float) $enterpriseExtra < 0)) {
            return new WP_Error('calculation_configuration_invalid', 'Enterprise extra-seat pricing must be a non-negative number.', ['status' => 500]);
        }
        if ((float) $this->get('roi.annual_periods') <= 0) return new WP_Error('calculation_configuration_invalid', 'Calculation methodology contains an invalid ROI period.', ['status' => 500]);
        if ((float) $this->get('benchmarks.legacy_mid_user_limit') <= (float) $this->get('benchmarks.legacy_small_user_limit')
            || (float) $this->get('benchmarks.legacy_enterprise_anchor_users') <= (float) $this->get('benchmarks.legacy_mid_user_limit')) {
            return new WP_Error('calculation_configuration_invalid', 'Legacy benchmark user anchors must be in ascending order.', ['status' => 500]);
        }
        foreach (['legacy_small_annual', 'legacy_mid_annual', 'legacy_enterprise_annual'] as $key) {
            if (filter_var($this->get('benchmarks.' . $key . '_source_url'), FILTER_VALIDATE_URL) === false
                || trim((string) $this->get('benchmarks.' . $key . '_date_checked')) === '') {
                return new WP_Error('calculation_configuration_invalid', 'Legacy benchmark source details are incomplete.', ['benchmark' => $key, 'status' => 500]);
            }
        }
        foreach ($this->get('competitor_costs', []) as $key => $cost) {
            if (!is_array($cost) || !is_numeric($cost['value'] ?? null) || (float) $cost['value'] < 0
                || filter_var($cost['source_url'] ?? '', FILTER_VALIDATE_URL) === false
                || trim((string) ($cost['date_checked'] ?? '')) === '') {
                return new WP_Error('calculation_configuration_invalid', 'Competitor cost source details are incomplete.', ['competitor' => $key, 'status' => 500]);
            }
        }
        return null;
    }

    public static function save(array $settings): bool
    {
        $methodology = new self(self::merge(self::defaults(), self::global_settings($settings)));
        if ($methodology->validate() !== null) return false;
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
                'frequency_days' => $settings['usage']['frequency_days'] ?? [],
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
                'commitment_months' => $settings['pricing']['commitment_months'] ?? null,
                'networked_monthly_price' => $settings['pricing']['networked_monthly_price'] ?? null,
                'monthly_price_per_user' => $settings['pricing']['monthly_price_per_user'] ?? null,
                'maximum_users' => $settings['pricing']['maximum_users'] ?? null,
                'agency_networked_client_threshold' => $settings['pricing']['agency_networked_client_threshold'] ?? null,
                'agency_networked_nudge_min_clients' => $settings['pricing']['agency_networked_nudge_min_clients'] ?? null,
                'agency_networked_nudge_max_clients' => $settings['pricing']['agency_networked_nudge_max_clients'] ?? null,
                'casual_nudge_users_before_trigger' => $settings['pricing']['casual_nudge_users_before_trigger'] ?? null,
            ],
            'benchmarks' => [
                'legacy_small_annual' => $settings['benchmarks']['legacy_small_annual'] ?? null,
                'legacy_mid_annual' => $settings['benchmarks']['legacy_mid_annual'] ?? null,
                'legacy_enterprise_annual' => $settings['benchmarks']['legacy_enterprise_annual'] ?? null,
                'legacy_small_user_limit' => $settings['benchmarks']['legacy_small_user_limit'] ?? null,
                'legacy_mid_user_limit' => $settings['benchmarks']['legacy_mid_user_limit'] ?? null,
                'legacy_enterprise_anchor_users' => $settings['benchmarks']['legacy_enterprise_anchor_users'] ?? null,
                'legacy_small_annual_source_url' => $settings['benchmarks']['legacy_small_annual_source_url'] ?? null,
                'legacy_small_annual_date_checked' => $settings['benchmarks']['legacy_small_annual_date_checked'] ?? null,
                'legacy_mid_annual_source_url' => $settings['benchmarks']['legacy_mid_annual_source_url'] ?? null,
                'legacy_mid_annual_date_checked' => $settings['benchmarks']['legacy_mid_annual_date_checked'] ?? null,
                'legacy_enterprise_annual_source_url' => $settings['benchmarks']['legacy_enterprise_annual_source_url'] ?? null,
                'legacy_enterprise_annual_date_checked' => $settings['benchmarks']['legacy_enterprise_annual_date_checked'] ?? null,
            ],
            'plans' => [
                'professional_annual_base' => $settings['plans']['professional_annual_base'] ?? null,
                'professional_included_users' => $settings['plans']['professional_included_users'] ?? null,
                'professional_additional_user_annual' => $settings['plans']['professional_additional_user_annual'] ?? null,
                'enterprise_annual_base' => $settings['plans']['enterprise_annual_base'] ?? null,
                'enterprise_included_users' => $settings['plans']['enterprise_included_users'] ?? null,
                'enterprise_switch_point_users' => $settings['plans']['enterprise_switch_point_users'] ?? null,
                'enterprise_nudge_users_before_trigger' => $settings['plans']['enterprise_nudge_users_before_trigger'] ?? null,
                'enterprise_contact_nudge_users' => $settings['plans']['enterprise_contact_nudge_users'] ?? null,
                'enterprise_additional_user_annual' => $settings['plans']['enterprise_additional_user_annual'] ?? null,
            ],
            'competitor_costs' => $settings['competitor_costs'] ?? [],
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
