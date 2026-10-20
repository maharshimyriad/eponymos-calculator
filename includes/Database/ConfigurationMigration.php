<?php

namespace Eponymos\Database;

class ConfigurationMigration
{
    const VERSION = '1.0.8';

    public static function run(): void
    {
        if (get_option('eponymos_configuration_version') === self::VERSION) return;

        global $wpdb;
        $prefix = $wpdb->prefix . 'eponymos_';
        $option_groups_table = $prefix . 'option_groups';
        if (!$wpdb->get_var("SHOW COLUMNS FROM {$option_groups_table} LIKE 'hide_title'")) {
            $wpdb->query("ALTER TABLE {$option_groups_table} ADD hide_title tinyint(1) NOT NULL DEFAULT 0 AFTER description");
        }

        foreach (['content_approved', 'prompting_pr_software', 'drafting_press'] as $key) {
            $wpdb->update($prefix . 'questions', ['type' => 'multiple_choice'], ['question_key' => $key]);
            $wpdb->update($prefix . 'library_questions', ['type' => 'multiple_choice'], ['question_key' => $key]);
        }

        self::set_calculation_role($wpdb, $prefix . 'questions', 'pr_and_comms', 'software_spend');
        self::ensure_active_users_question($wpdb, $prefix);
        self::normalize_pricing_stage($wpdb, $prefix);
        self::normalize_savings_inputs($wpdb, $prefix);
        self::ensure_organization_question($wpdb, $prefix);

        update_option('eponymos_configuration_version', self::VERSION, false);
    }

    private static function set_calculation_role($wpdb, string $table, string $question_key, string $role): void
    {
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,validation FROM {$table} WHERE question_key=%s LIMIT 1", $question_key), ARRAY_A);
        if (!$row) return;
        $validation = json_decode($row['validation'] ?? '', true);
        $validation = is_array($validation) ? $validation : [];
        $validation['calculation_role'] = $role;
        $wpdb->update($table, ['validation' => wp_json_encode($validation)], ['id' => (int) $row['id']], ['%s'], ['%d']);
    }

    private static function ensure_active_users_question($wpdb, string $prefix): void
    {
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}questions WHERE question_key=%s LIMIT 1", 'active_users'))) return;
        $stage_id = $wpdb->get_var("SELECT id FROM {$prefix}stages WHERE status='active' ORDER BY position,id LIMIT 1");
        if (!$stage_id) return;

        $wpdb->insert($prefix . 'questions', [
            'stage_id' => (int) $stage_id,
            'label' => 'How many people will need access to Eponymos™?',
            'internal_name' => 'Active users',
            'question_key' => 'active_users',
            'type' => 'number',
            'required' => 1,
            'help_text' => '',
            'placeholder' => '',
            'validation' => wp_json_encode(['calculation_role' => 'active_users', 'min' => 1, 'max' => (int) \Eponymos\Calculation\Methodology::load()->get('pricing.maximum_users')]),
            'calculation_eligible' => 1,
            'position' => 1,
        ]);
        $question_id = (int) $wpdb->insert_id;
        if (!$question_id) return;

    }

    private static function normalize_pricing_stage($wpdb, string $prefix): void
    {
        $methodology = \Eponymos\Calculation\Methodology::load();
        $stage_id = (int) $wpdb->get_var("SELECT id FROM {$prefix}stages WHERE title='Stage 1' AND status='active' ORDER BY id LIMIT 1");
        if (!$stage_id) return;

        $active_users_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}questions WHERE stage_id=%d AND question_key=%s LIMIT 1", $stage_id, 'active_users'));
        if ($active_users_id) {
            $wpdb->update($prefix . 'questions', [
                'label' => 'How many people will need access to Eponymos™?',
                'internal_name' => 'Team size',
                'type' => 'number',
                'validation' => wp_json_encode(['calculation_role' => 'active_users', 'min' => 1, 'max' => (int) $methodology->get('pricing.maximum_users')]),
                'calculation_eligible' => 1,
                'position' => 1,
            ], ['id' => $active_users_id], ['%s', '%s', '%s', '%s', '%d', '%d'], ['%d']);
            $wpdb->delete($prefix . 'options', ['question_id' => $active_users_id], ['%d']);
        }

        $active_days_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}questions WHERE stage_id=%d AND question_key IN (%s,%s) ORDER BY question_key=%s DESC LIMIT 1", $stage_id, 'active_days', 'working_days_per_month', 'active_days'));
        $active_days_data = [
            'label' => 'How often will each person typically use Eponymos™?',
            'internal_name' => 'Usage frequency',
            'question_key' => 'active_days',
            'type' => 'single_choice',
            'required' => 1,
            'help_text' => '',
            'placeholder' => '',
            'validation' => wp_json_encode(['calculation_role' => 'active_days']),
            'calculation_eligible' => 1,
            'position' => 2,
        ];
        if ($active_days_id) {
            $wpdb->update($prefix . 'questions', $active_days_data, ['id' => $active_days_id]);
        } else {
            $active_days_data['stage_id'] = $stage_id;
            $active_days_data['library_question_id'] = 0;
            $wpdb->insert($prefix . 'questions', $active_days_data);
            $active_days_id = (int) $wpdb->insert_id;
        }
        if ($active_days_id) {
            $wpdb->delete($prefix . 'options', ['question_id' => $active_days_id], ['%d']);
            $frequencyLabels = [
                'every_working_day' => 'Every working day',
                'most_days' => 'Most days',
                'few_days_a_week' => 'A few days a week',
                'occasionally' => 'Occasionally',
            ];
            $position = 0;
            foreach ((array) $methodology->get('usage.frequency_days', []) as $optionKey => $days) {
                if (!isset($frequencyLabels[$optionKey])) continue;
                $option = [$frequencyLabels[$optionKey], $optionKey, $days];
                $wpdb->insert($prefix . 'options', ['question_id' => $active_days_id, 'label' => $option[0], 'option_key' => $option[1], 'calculation_value' => $option[2], 'position' => $position]);
                $position++;
            }
        }

        foreach (['pricing_matrix_test', 'tools_or_platforms_pr_software'] as $question_key) {
            $question_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}questions WHERE stage_id=%d AND question_key=%s LIMIT 1", $stage_id, $question_key));
            if (!$question_id) continue;
            $wpdb->delete($prefix . 'options', ['question_id' => $question_id], ['%d']);
            $wpdb->delete($prefix . 'questions', ['id' => $question_id], ['%d']);
        }

        $pitch_question_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$prefix}questions WHERE stage_id=%d AND question_key=%s", $stage_id, 'press_releases'));
        foreach ($pitch_question_ids as $question_id) {
            $wpdb->delete($prefix . 'options', ['question_id' => (int) $question_id], ['%d']);
            $wpdb->delete($prefix . 'questions', ['id' => (int) $question_id], ['%d']);
        }

        $library_question = $wpdb->get_row($wpdb->prepare("SELECT id,option_group_id FROM {$prefix}library_questions WHERE question_key=%s LIMIT 1", 'press_releases'), ARRAY_A);
        if ($library_question) {
            $wpdb->delete($prefix . 'library_questions', ['id' => (int) $library_question['id']], ['%d']);
            $group_id = (int) $library_question['option_group_id'];
            if ($group_id) {
                $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}library_questions WHERE option_group_id=%d", $group_id));
                if (!$remaining) {
                    $wpdb->delete($prefix . 'library_options', ['group_id' => $group_id], ['%d']);
                    $wpdb->delete($prefix . 'option_groups', ['id' => $group_id], ['%d']);
                }
            }
        }
    }

    private static function normalize_savings_inputs($wpdb, string $prefix): void
    {
        $tool_question_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}library_questions WHERE question_key=%s LIMIT 1", 'tools_or_platforms_pr_software'));
        if ($tool_question_id) {
            $wpdb->update($prefix . 'library_questions', [
                'type' => 'multiple_choice',
                'validation' => wp_json_encode(['calculation_role' => 'software_savings', 'option_group_ids' => [21, 22, 23]]),
                'calculation_eligible' => 1,
            ], ['id' => $tool_question_id]);
        }
        $wpdb->query($wpdb->prepare("UPDATE {$prefix}questions SET type='multiple_choice', validation=%s, calculation_eligible=1 WHERE question_key=%s", wp_json_encode(['calculation_role' => 'software_savings', 'option_group_ids' => [21, 22, 23]]), 'tools_or_platforms_pr_software'));

        $wpdb->update($prefix . 'option_groups', ['description' => 'Competitor pricing values are managed in Calculation Methodology.'], ['id' => 21], ['%s'], ['%d']);
        $wpdb->query("DELETE FROM {$prefix}library_options WHERE group_id IN (22,23) AND option_key <> 'none_not_sure'");
        $wpdb->query("DELETE FROM {$prefix}library_options WHERE group_id=21 AND option_key IN ('prowly','agility_pr')");
    }

    private static function ensure_organization_question($wpdb, string $prefix): void
    {
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}questions WHERE question_key=%s LIMIT 1", 'organization_name'))) return;
        $stage_id = $wpdb->get_var("SELECT id FROM {$prefix}stages WHERE status='active' ORDER BY position DESC,id DESC LIMIT 1");
        if (!$stage_id) return;

        $max_position = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(position),-1) FROM {$prefix}questions WHERE stage_id=%d", $stage_id));
        $wpdb->insert($prefix . 'questions', [
            'stage_id' => (int) $stage_id,
            'label' => 'What is your organisation name for the report?',
            'internal_name' => 'Organisation name',
            'question_key' => 'organization_name',
            'type' => 'text',
            'required' => 1,
            'help_text' => 'This will appear on the report cover.',
            'placeholder' => 'Organisation name',
            'validation' => wp_json_encode(['max_length' => 190]),
            'calculation_eligible' => 0,
            'position' => $max_position + 1,
        ]);
    }
}
