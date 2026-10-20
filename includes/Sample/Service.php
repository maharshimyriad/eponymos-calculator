<?php

namespace Eponymos\Sample;

use Eponymos\Library\OptionRepository;
use Eponymos\Library\QuestionRepository;
use Eponymos\Calculation\Methodology;

class Service
{
    const MARKER = 'eponymos_sample_v1';

    public function reset(): bool
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        $this->delete_marked_records();
        $groups = $this->create_library_samples();
        if (!$groups) return $this->rollback();
        if (!$this->create_library_questions($groups)) return $this->rollback();
        $wpdb->query('COMMIT');
        return true;
    }

    public function delete(): bool
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        $this->delete_marked_records();
        if ($wpdb->query('COMMIT') === false) return $this->rollback();
        return true;
    }

    private function create_library_questions(array $groups): bool
    {
        $questions = new QuestionRepository();
        $items = [
            ['Active users', 'How many active users will need access to Eponymos?', 'active_users', 'number', 'active_users', 0],
            ['Usage frequency', 'How often will each person typically use Eponymos™?', 'active_days', 'single_choice', 'active_days', $groups['Usage frequency'] ?? 0],
            ['Current PR software', 'Which PR software do you currently use?', 'current_pr_software', 'single_choice', 'software_savings', $groups['PR software'] ?? 0],
        ];
        foreach ($items as $item) {
            $id = $questions->create(['label' => $item[1], 'internal_name' => $item[0], 'question_key' => $item[2], 'type' => $item[3], 'help_text' => '', 'placeholder' => '', 'validation' => ['calculation_role' => $item[4]], 'calculation_eligible' => true, 'option_group_id' => $item[5]]);
            if (!$id) return false;
        }
        return true;
    }



    private function create_library_samples(): array
    {
        $repository = new OptionRepository();
        $methodology = Methodology::load();
        $competitorCosts = (array) $methodology->get('competitor_costs', []);
        $frequencyDays = (array) $methodology->get('usage.frequency_days', []);
        $groups = [
            ['PR software', [['Cision', 'cision', $competitorCosts['cision']['value'] ?? ''], ['Muck Rack', 'muck_rack', $competitorCosts['muck_rack']['value'] ?? ''], ['Meltwater', 'meltwater', $competitorCosts['meltwater']['value'] ?? '']]],
            ['Usage frequency', [['Every working day', 'every_working_day', $frequencyDays['every_working_day'] ?? ''], ['Most days', 'most_days', $frequencyDays['most_days'] ?? ''], ['A few days a week', 'few_days_a_week', $frequencyDays['few_days_a_week'] ?? ''], ['Occasionally', 'occasionally', $frequencyDays['occasionally'] ?? '']]],
            ['Team size', [['Solo', 'solo', '1'], ['Small team', 'small_team', '5'], ['Large team', 'large_team', '20']]],
            ['Organisation type', [['Agency', 'agency', '1'], ['In-house', 'in_house', '1'], ['Non-profit', 'non_profit', '1']]],
            ['Publishing channels', [['One channel', 'one_channel', '1'], ['Two channels', 'two_channels', '2'], ['Three or more', 'three_or_more', '3']]],
            ['Content formats', [['Written', 'written', '1'], ['Visual', 'visual', '1'], ['Mixed', 'mixed', '2']]],
            ['Approval workflow', [['Informal', 'informal', '1'], ['Single approver', 'single_approver', '2'], ['Multiple approvers', 'multiple_approvers', '3']]],
            ['Reporting frequency', [['Monthly', 'monthly', '1'], ['Quarterly', 'quarterly', '3'], ['Ad hoc', 'ad_hoc', '0']]],
            ['Audience size', [['Under 1,000', 'under_1000', '500'], ['1,000–10,000', 'one_thousand_to_ten_thousand', '5500'], ['Over 10,000', 'over_10000', '10000']]],
            ['Current workflow', [['Manual', 'manual', '1'], ['Partly automated', 'partly_automated', '2'], ['Automated', 'automated', '3']]],
        ];
        foreach ($groups as $group) {
            $group_id = $repository->create_group($group[0], self::MARKER);
            if (!$group_id) return [];
            foreach ($group[1] as $option) if (!$repository->create_option($group_id, $option[0], $option[2], $option[1])) return [];
            $created[$group[0]] = $group_id;
        }
        return $created;
    }

    private function delete_marked_records(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'eponymos_';
        $question_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$prefix}library_questions WHERE validation LIKE %s", '%' . $wpdb->esc_like(self::MARKER) . '%'));
        foreach ($question_ids as $id) (new QuestionRepository())->delete((int) $id);
        $group_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$prefix}option_groups WHERE description=%s", self::MARKER));
        foreach ($group_ids as $id) (new OptionRepository())->delete_group((int) $id);
    }

    private function rollback(): bool
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        return false;
    }
}
