<?php

namespace Eponymos\Database;

class SoftwareToolPricingMigration
{
    const VERSION = '1.0.2';

    public static function run(): void
    {
        if (get_option('eponymos_software_tool_pricing_version') === self::VERSION) return;

        global $wpdb;
        $prefix = $wpdb->prefix . 'eponymos_';
        $groups = [
            22 => [
                'description' => 'Illustrative monthly estimates, dated 2026-09-30; not vendor quotes or independently verified.',
                'options' => [
                    ['Sprout Social', 'sprout_social', 200],
                    ['Brandwatch', 'brandwatch', 1000],
                    ['Brand24', 'brand24', 150],
                    ['Hootsuite', 'hootsuite', 100],
                    ['Sprinklr', 'sprinklr', 1000],
                    ['None/Not sure', 'none_not_sure', ''],
                ],
            ],
            23 => [
                'description' => 'Illustrative monthly estimates, dated 2026-09-30; not vendor quotes or independently verified.',
                'options' => [
                    ['Brandfolder', 'brandfolder', 1000],
                    ['Bynder', 'bynder', 1000],
                    ['Canto', 'canto', 500],
                    ['Acquia DAM', 'acquia_dam', 1000],
                    ['Frontify', 'frontify', 500],
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

        $spend_group_id = (int) $wpdb->get_var($wpdb->prepare("SELECT option_group_id FROM {$prefix}library_questions WHERE question_key=%s LIMIT 1", 'pr_and_comms'));
        if ($spend_group_id) {
            $wpdb->update($prefix . 'library_options', ['calculation_value' => '250'], ['group_id' => $spend_group_id, 'option_key' => 'under_500'], ['%s'], ['%d', '%s']);
        }

        update_option('eponymos_software_tool_pricing_version', self::VERSION, false);
    }
}