<?php

namespace Eponymos\Questionnaire;

use Eponymos\Support\Keys;

class Repository
{
    private function table($name)
    {
        global $wpdb;
        return $wpdb->prefix . 'eponymos_' . $name;
    }
    public function questionnaire($id = 0)
    {
        global $wpdb;
        $t = $this->table('questionnaires');
        return $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d AND status='active'", $id), ARRAY_A) : $wpdb->get_row("SELECT * FROM $t WHERE status='active' ORDER BY id ASC LIMIT 1", ARRAY_A);
    }
    public function admin_questionnaire($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('questionnaires') . ' WHERE id=%d', (int)$id), ARRAY_A);
    }
    public function admin_questionnaires()
    {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . $this->table('questionnaires') . ' ORDER BY updated_at DESC,id DESC', ARRAY_A);
    }
    public function structure($id = 0)
    {
        global $wpdb;
        $q = $this->questionnaire($id);
        if (!$q) return null;
        return $this->build_structure($q, true);
    }
    public function admin_structure($id)
    {
        $q = $this->admin_questionnaire($id);
        return $q ? $this->build_structure($q, false) : null;
    }
    private function build_structure($q, $active_only)
    {
        global $wpdb;
        $p = $wpdb->prefix . 'eponymos_';
        $where = $active_only ? " AND status='active'" : '';
        $st = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}stages WHERE questionnaire_id=%d{$where} ORDER BY position,id", $q['id']), ARRAY_A);
        if (!$st) return ['questionnaire' => $q, 'stages' => []];
        $stage_ids = array_map('absint', wp_list_pluck($st, 'id'));
        $stage_placeholders = implode(',', array_fill(0, count($stage_ids), '%d'));
        $questions = $wpdb->get_results($wpdb->prepare("SELECT q.*, lq.id AS library_question_id FROM {$p}questions q LEFT JOIN {$p}library_questions lq ON lq.question_key=q.question_key WHERE q.stage_id IN ($stage_placeholders) ORDER BY q.position,q.id", $stage_ids), ARRAY_A);
        $question_ids = array_map('absint', wp_list_pluck($questions, 'id'));
        $options = [];
        if ($question_ids) {
            $question_placeholders = implode(',', array_fill(0, count($question_ids), '%d'));
            $options = $wpdb->get_results($wpdb->prepare("SELECT id,question_id,label,option_key,calculation_value,position FROM {$p}options WHERE question_id IN ($question_placeholders) ORDER BY position,id", $question_ids), ARRAY_A);
        }
        $options_by_question = [];
        foreach ($options as $option) $options_by_question[(int)$option['question_id']][] = $option;
        $questions_by_stage = [];
        foreach ($questions as &$x) {
            $x['validation'] = json_decode($x['validation'], true) ?: [];
            if (isset($x['validation']['calculation_role'])) $x['calculation_role'] = $x['validation']['calculation_role'];
            $x['options'] = $options_by_question[(int)$x['id']] ?? [];
            if (!empty($x['library_question_id'])) {
                $library = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}library_questions WHERE id=%d", (int)$x['library_question_id']), ARRAY_A);
                if ($library) {
                    $library['validation'] = json_decode($library['validation'], true) ?: [];
                    $x = array_merge($x, $library, ['id' => $x['id'], 'stage_id' => $x['stage_id'], 'position' => $x['position'], 'options' => $x['options'], 'library_question_id' => $library['id']]);
                    $option_group_ids = array_values(array_unique(array_filter(array_map('absint', (array) ($library['validation']['option_group_ids'] ?? [])))));
                    $primary_option_group_id = absint($library['option_group_id'] ?? 0);
                    if ($primary_option_group_id && !in_array($primary_option_group_id, $option_group_ids, true)) {
                        $option_group_ids[] = $primary_option_group_id;
                    }
                    if ($option_group_ids) {
                        $x['option_groups'] = [];
                        foreach ($option_group_ids as $group_id) {
                            $group_options = $this->library_options($group_id);
                            foreach ($group_options as &$group_option) {
                                $group_option['option_key'] = (string) $group_option['option_key'];
                                $group_option['calculation_value'] = (string) $group_option['calculation_value'];
                            }
                            unset($group_option);
                            $group = $wpdb->get_row($wpdb->prepare("SELECT id,name,hide_title FROM {$p}option_groups WHERE id=%d", $group_id), ARRAY_A);
                            $x['option_groups'][] = [
                                'id' => $group_id,
                                'label' => $group ? $group['name'] : '',
                                'hide_title' => !empty($library['validation']['hide_option_group_titles']),
                                'options' => $group_options,
                            ];
                        }
                        $x['options'] = $this->library_options_many($option_group_ids);
                        foreach ($x['options'] as &$option) {
                            $option['option_key'] = (string) $option['option_key'];
                            $option['calculation_value'] = (string) $option['calculation_value'];
                        }
                        unset($option);
                    } elseif (isset($library['option_group_id']) && $library['option_group_id']) {
                        $x['option_groups'] = [[
                            'id' => (int) $library['option_group_id'],
                            'label' => '',
                            'hide_title' => !empty($library['validation']['hide_option_group_titles']),
                            'options' => $this->library_options((int) $library['option_group_id']),
                        ]];
                        $x['options'] = $this->library_options((int)$library['option_group_id']);
                    } elseif (!empty($library['type']) && in_array($library['type'], ['single_choice', 'multiple_choice', 'yes_no'], true)) {
                        $x['options'] = $this->library_options_for_question_key((string) $library['question_key']);
                    }
                }
            }
            $questions_by_stage[(int)$x['stage_id']][] = $x;
        }
        foreach ($st as &$stage) {
            $stage['settings'] = json_decode($stage['settings'], true) ?: [];
            $stage['questions'] = $questions_by_stage[(int)$stage['id']] ?? [];
        }
        return ['questionnaire' => $q, 'stages' => $st];
    }
    public function create_questionnaire($name, $description)
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert($this->table('questionnaires'), ['name' => $name, 'description' => $description, 'status' => 'inactive', 'created_at' => $now, 'updated_at' => $now], ['%s', '%s', '%s', '%s', '%s']);
        return (int)$wpdb->insert_id;
    }
    public function update_questionnaire($id, $data)
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        return $wpdb->update($this->table('questionnaires'), $data, ['id' => (int)$id], null, ['%d']);
    }
    public function duplicate_questionnaire($id)
    {
        $source = $this->admin_structure($id);
        if (!$source) return 0;
        $new = $this->create_questionnaire($source['questionnaire']['name'] . ' (Copy)', $source['questionnaire']['description']);
        $this->update_questionnaire($new, ['status' => 'inactive']);
        foreach ($source['stages'] as $stage) {
            $sid = $this->create_stage($new, $stage['title'], $stage['description'], $stage['status'], $stage['settings']);
            foreach ($stage['questions'] as $q) {
                $qid = $this->create_question($sid, $q);
                foreach ($q['options'] as $o) $this->create_option($qid, $o['label'], $o['calculation_value'], $o['option_key']);
            }
        }
        return $new;
    }
    public function delete_questionnaire($id)
    {
        global $wpdb;
        $p = $wpdb->prefix . 'eponymos_';
        $st = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}stages WHERE questionnaire_id=%d", $id));
        foreach ($st as $sid) $this->delete_stage($sid);
        return $wpdb->delete($p . 'questionnaires', ['id' => (int)$id], ['%d']);
    }
    public function create_stage($qid, $title, $description = '', $status = 'active', $settings = [])
    {
        global $wpdb;
        $max = $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM ' . $this->table('stages') . ' WHERE questionnaire_id=%d', $qid));
        $wpdb->insert($this->table('stages'), ['questionnaire_id' => (int)$qid, 'title' => $title, 'description' => $description, 'status' => $status, 'position' => (int)$max, 'settings' => wp_json_encode($settings)]);
        return (int)$wpdb->insert_id;
    }
    public function update_stage($id, $data)
    {
        global $wpdb;
        if (isset($data['settings'])) $data['settings'] = wp_json_encode($data['settings']);
        return $wpdb->update($this->table('stages'), $data, ['id' => (int)$id]);
    }
    public function delete_stage($id)
    {
        global $wpdb;
        $p = $wpdb->prefix . 'eponymos_';
        $qs = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}questions WHERE stage_id=%d", $id));
        foreach ($qs as $qid) $this->delete_question($qid);
        return $wpdb->delete($p . 'stages', ['id' => (int)$id], ['%d']);
    }
    public function reorder_stages($qid, $ids)
    {
        return $this->reorder($this->table('stages'), 'questionnaire_id', $qid, $ids);
    }
    private function library_options(int $group_id): array
    {
        global $wpdb;
        // Existing library options may have been created before statuses were
        // introduced. Do not hide those options from the public questionnaire.
        return $wpdb->get_results($wpdb->prepare('SELECT id,group_id AS question_id,group_id AS option_group_id,label,option_key,calculation_value,position FROM ' . $this->table('library_options') . ' WHERE group_id=%d AND (status=%s OR status IS NULL OR status=%s OR status=%s) ORDER BY position,id', $group_id, 'active', '', 'inactive'), ARRAY_A);
    }

    private function library_options_many(array $group_ids): array
    {
        global $wpdb;
        $group_ids = array_values(array_unique(array_filter(array_map('absint', $group_ids))));
        if (!$group_ids) return [];
        $placeholders = implode(',', array_fill(0, count($group_ids), '%d'));
        $order_placeholders = implode(',', array_fill(0, count($group_ids), '%d'));
        $sql = "SELECT o.id,o.group_id AS question_id,o.group_id AS option_group_id,g.name AS option_group_name,o.label,o.option_key,o.calculation_value,o.position
             FROM " . $this->table('library_options') . " o
             INNER JOIN " . $this->table('option_groups') . " g ON g.id=o.group_id
             WHERE o.group_id IN ($placeholders)
             ORDER BY FIELD(o.group_id,$order_placeholders),o.position,o.id";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($group_ids, $group_ids)), ARRAY_A);
        if (!is_array($rows)) return [];
        $seen = [];
        foreach ($rows as &$row) {
            $key = (string) $row['option_key'];
            if (isset($seen[$key])) {
                $key = sanitize_key(($row['option_group_name'] ?? 'group') . '_' . $key);
            }
            $seen[$key] = true;
            $row['option_key'] = $key;
            $row['calculation_value'] = (string) $row['calculation_value'];
        }
        return $rows;
    }

    private function library_options_for_question_key(string $key): array
    {
        global $wpdb;
        return [];
    }

    public function find_stage_question_by_key(int $stage_id, string $question_key): int
    {
        global $wpdb;
        $question_key = (string) $question_key;
        if ($question_key === '') {
            return 0;
        }
        $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $this->table('questions') . ' WHERE stage_id=%d AND question_key=%s LIMIT 1', $stage_id, $question_key));
        return $id ? (int) $id : 0;
    }

    public function create_question($sid, $data)
    {
        global $wpdb;
        $existing = $wpdb->get_col($wpdb->prepare('SELECT question_key FROM ' . $this->table('questions') . ' WHERE stage_id=%d', $sid));
        $key = !empty($data['question_key']) ? $data['question_key'] : Keys::unique($data['label'], $existing);
        $max = $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM ' . $this->table('questions') . ' WHERE stage_id=%d', $sid));
        $validation = (array)($data['validation'] ?? []);
        if (!empty($data['calculation_role'])) $validation['calculation_role'] = sanitize_key($data['calculation_role']);
        $inserted = $wpdb->insert($this->table('questions'), ['stage_id' => (int)$sid, 'label' => $data['label'], 'internal_name' => ($data['internal_name'] ?? $key), 'question_key' => $key, 'type' => $data['type'], 'required' => 1, 'help_text' => $data['help_text'] ?? '', 'placeholder' => $data['placeholder'] ?? '', 'validation' => wp_json_encode($validation), 'calculation_eligible' => empty($data['calculation_eligible']) ? 0 : 1, 'position' => (int)$max]);
        if ($inserted === false) return 0;
        $id = (int)$wpdb->insert_id;
        if ($data['type'] === 'yes_no') {
            $this->create_option($id, 'No', '0', 'no');
            $this->create_option($id, 'Yes', '1', 'yes');
        }
        return $id;
    }
    public function update_question($id, $data)
    {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare('SELECT stage_id,question_key,validation FROM ' . $this->table('questions') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$existing) return 0;
        if (isset($data['question_key']) && $data['question_key'] !== $existing['question_key']) {
            if (!get_option('eponymos_allow_key_editing')) unset($data['question_key']);
            else {
                $duplicate = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $this->table('questions') . ' WHERE stage_id=%d AND question_key=%s AND id!=%d', $existing['stage_id'], $data['question_key'], $id));
                if ($duplicate) unset($data['question_key']);
            }
        }
        if (array_key_exists('validation', $data) || array_key_exists('calculation_role', $data)) {
            $validation = array_key_exists('validation', $data) ? (array) $data['validation'] : (json_decode($existing['validation'], true) ?: []);
            if (array_key_exists('calculation_role', $data)) {
                $role = sanitize_key($data['calculation_role']);
                if ($role === '') unset($validation['calculation_role']);
                else $validation['calculation_role'] = $role;
            }
            $data['validation'] = wp_json_encode($validation);
            unset($data['calculation_role']);
        }
        return $wpdb->update($this->table('questions'), $data, ['id' => (int)$id]);
    }
    public function delete_question($id)
    {
        global $wpdb;
        $wpdb->delete($this->table('options'), ['question_id' => (int)$id], ['%d']);
        return $wpdb->delete($this->table('questions'), ['id' => (int)$id], ['%d']);
    }
    public function reorder_questions($sid, $ids)
    {
        return $this->reorder($this->table('questions'), 'stage_id', $sid, $ids);
    }
    public function create_option($qid, $label, $value = '', $key = '')
    {
        global $wpdb;
        $existing = $wpdb->get_col($wpdb->prepare('SELECT option_key FROM ' . $this->table('options') . ' WHERE question_id=%d', $qid));
        $key = $key ?: Keys::unique($label, $existing);
        $inserted = $wpdb->insert($this->table('options'), ['question_id' => (int)$qid, 'label' => $label, 'option_key' => $key, 'calculation_value' => (string)$value, 'position' => count($existing)]);
        if ($inserted === false) return 0;
        return (int)$wpdb->insert_id;
    }
    public function update_option($id, $data)
    {
        global $wpdb;
        if (isset($data['option_key'])) {
            if (!get_option('eponymos_allow_key_editing')) unset($data['option_key']);
            else {
                $parent = $wpdb->get_var($wpdb->prepare('SELECT question_id FROM ' . $this->table('options') . ' WHERE id=%d', $id));
                $duplicate = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $this->table('options') . ' WHERE question_id=%d AND option_key=%s AND id!=%d', $parent, $data['option_key'], $id));
                if ($duplicate) unset($data['option_key']);
            }
        }
        return $wpdb->update($this->table('options'), $data, ['id' => (int)$id]);
    }
    public function delete_option($id)
    {
        global $wpdb;
        return $wpdb->delete($this->table('options'), ['id' => (int)$id], ['%d']);
    }
    private function reorder($table, $parent, $parent_id, $ids)
    {
        global $wpdb;
        $allowed = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE $parent=%d", $parent_id));
        $ids = array_values(array_intersect(array_map('absint', (array)$ids), array_map('absint', $allowed)));
        foreach ($ids as $position => $id) $wpdb->update($table, ['position' => $position], ['id' => $id], ['%d'], ['%d']);
        return true;
    }
}
