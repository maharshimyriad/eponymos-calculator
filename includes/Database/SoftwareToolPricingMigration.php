<?php

namespace Eponymos\Database;

class SoftwareToolPricingMigration
{
    const VERSION = '1.0.3';

    public static function run(): void
    {
        if (get_option('eponymos_software_tool_pricing_version') === self::VERSION) return;

        global $wpdb;
        $prefix = $wpdb->prefix . 'eponymos_';
        $competitorCosts = \Eponymos\Calculation\Methodology::load()->get('competitor_costs', []);
        $groups = [
            22 => [
                'description' => 'Competitor monthly estimates and provenance are managed in Calculation Methodology.',
                'options' => [
                    ['Sprout Social', 'sprout_social', $competitorCosts['sprout_social']['value'] ?? ''],
                    ['Brandwatch', 'brandwatch', $competitorCosts['brandwatch']['value'] ?? ''],
                    ['Brand24', 'brand24', $competitorCosts['brand24']['value'] ?? ''],
                    ['Hootsuite', 'hootsuite', $competitorCosts['hootsuite']['value'] ?? ''],
                    ['Sprinklr', 'sprinklr', $competitorCosts['sprinklr']['value'] ?? ''],
                    ['None/Not sure', 'none_not_sure', ''],
                ],
            ],
            23 => [
                'description' => 'Competitor monthly estimates and provenance are managed in Calculation Methodology.',
                'options' => [
                    ['Brandfolder', 'brandfolder', $competitorCosts['brandfolder']['value'] ?? ''],
                    ['Bynder', 'bynder', $competitorCosts['bynder']['value'] ?? ''],
                    ['Canto', 'canto', $competitorCosts['canto']['value'] ?? ''],
                    ['Acquia DAM', 'acquia_dam', $competitorCosts['acquia_dam']['value'] ?? ''],
                    ['Frontify', 'frontify', $competitorCosts['frontify']['value'] ?? ''],
                    ['None/Not sure', 'none_not_sure', ''],
                ],
            ],
        ];

        foreach ($groups as $group_id => $group) {
            if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}option_groups WHERE id=%d", $group_id))) {
                return;
            }
        }

        foreach ($groups as $group_id => $group) {
            $wpdb->update($prefix . 'option_groups', ['description' => $group['description']], ['id' => $group_id], ['%s'], ['%d']);
            foreach ($group['options'] as $position => $option) {
                $wpdb->replace($prefix . 'library_options', [
                    'group_id' => $group_id,
                    'label' => $option[0],
                    'option_key' => $option[1],
                    'calculation_value' => (string) $option[2],
                    'status' => 'active',
                    'position' => $position,
                ], ['%d', '%s', '%s', '%s', '%s', '%d']);
            }
        }

        update_option('eponymos_software_tool_pricing_version', self::VERSION, false);
    }
}