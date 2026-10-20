<?php

namespace Eponymos\Submission;

class Repository
{
    private function table($name)
    {
        global $wpdb;
        return $wpdb->prefix . 'eponymos_' . $name;
    }
    private function json($value)
    {
        return wp_json_encode($value === null ? [] : $value);
    }

    public function save($data)
    {
        global $wpdb;
        $now = current_time('mysql');
        $existing_submission_id = array_key_exists('submission_id', $data) ? absint($data['submission_id']) : 0;
        $submission_id = $existing_submission_id;
        $test_mode = in_array($data['test_mode'] ?? '', ['without_email', 'with_email'], true) ? $data['test_mode'] : '';
        if ($test_mode !== '') {
            return new \WP_Error('test_submission_not_persisted', 'Test mode submissions are not stored.', ['status' => 400]);
        }
        $wpdb->query('START TRANSACTION');
        $submission_data = [
            'questionnaire_id' => absint($data['questionnaire_id']),
            'questionnaire_name' => sanitize_text_field($data['questionnaire_name'] ?? ''),
            'email' => sanitize_email($data['email']),
            'answers' => $this->json($data['answers']),
            'resolved_values' => $this->json($data['resolved_values']),
            'calculation_inputs' => $this->json($data['calculation_inputs']),
            'calculation_outputs' => $this->json($data['calculation_outputs']),
            'pricing_result' => $this->json($data['pricing_result']),
            'methodology_version' => sanitize_text_field($data['methodology_version'] ?? 'unknown'),
            'methodology_snapshot' => $this->json($data['methodology_snapshot'] ?? []),
            'test_mode' => $test_mode,
            'updated_at' => $now,
        ];
        if ($submission_id) {
            $existing = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . $this->table('submissions') . ' WHERE id=%d AND questionnaire_id=%d AND test_mode=%s',
                $submission_id,
                absint($data['questionnaire_id']),
                $test_mode
            ));
            if (!$existing) {
                $wpdb->query('ROLLBACK');
                return new \WP_Error('submission_not_found', 'Could not find the existing submission.', ['status' => 404]);
            }
            $ok = $wpdb->update($this->table('submissions'), $submission_data, [
                'id' => $submission_id,
                'questionnaire_id' => absint($data['questionnaire_id']),
            ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'], ['%d', '%d']);
        } else {
            $submission_data['created_at'] = $now;
            $ok = $wpdb->insert($this->table('submissions'), $submission_data, ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
            $submission_id = (int) $wpdb->insert_id;
        }
        if ($ok === false) {
            $wpdb->query('ROLLBACK');
            error_log('Eponymos submission save failed: ' . $wpdb->last_error);
            return new \WP_Error('submission_save_failed', 'Could not store the submission.', ['status' => 500]);
        }
        if ($existing_submission_id > 0)
            $wpdb->delete($this->table('submission_answers'), ['submission_id' => $submission_id], ['%d']);

        $structure = isset($data['structure']) && is_array($data['structure']) ? $data['structure'] : [];
        $questions = [];
        foreach (($structure['stages'] ?? []) as $stage) {
            $stage_titles[(int) $stage['id']] = $stage['title'];
            foreach (($stage['questions'] ?? []) as $question) {
                $question['_stage_title'] = $stage['title'];
                $questions[$question['question_key']] = $question;
            }
        }
        foreach ((array) ($data['answers'] ?? []) as $key => $answer) {
            $question = $questions[$key] ?? [];
            if (!$question) continue;
            $labels = $this->answer_labels($question, $answer);
            $resolved = ($data['resolved_values'][$key] ?? null);
            $answer_ok = $wpdb->insert($this->table('submission_answers'), [
                'submission_id' => $submission_id,
                'question_id' => absint($question['id'] ?? 0),
                'question_key' => sanitize_key($key),
                'question_label' => sanitize_text_field($question['label'] ?? $key),
                'stage_title' => sanitize_text_field($question['_stage_title'] ?? ''),
                'answer' => $this->json($answer),
                'answer_label' => $this->json($labels),
                'resolved_value' => $this->json($resolved),
            ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s']);
            if (!$answer_ok) {
                $wpdb->query('ROLLBACK');
                error_log('Eponymos submission answer insert failed: ' . $wpdb->last_error);
                return new \WP_Error('submission_answer_insert_failed', 'Could not store the submission answers.', ['status' => 500]);
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            error_log('Eponymos submission transaction commit failed: ' . $wpdb->last_error);
            return new \WP_Error('submission_commit_failed', 'Could not store the submission.', ['status' => 500]);
        }
        return $submission_id;
    }

    private function answer_labels($question, $answer)
    {
        $options = [];
        foreach (($question['options'] ?? []) as $option) $options[$option['option_key']] = $option['label'];
        foreach (($question['option_groups'] ?? []) as $group) {
            foreach (($group['options'] ?? []) as $option) $options[$option['option_key']] = $option['label'];
        }
        if (is_array($answer)) return array_values(array_map(function ($value) use ($options) {
            return $options[$value] ?? (string) $value;
        }, $answer));
        return isset($options[$answer]) ? $options[$answer] : $answer;
    }

    public function find($id)
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('submissions') . ' WHERE id=%d', absint($id)), ARRAY_A);
        if (!$row) return null;
        foreach (['answers', 'resolved_values', 'calculation_inputs', 'calculation_outputs', 'pricing_result', 'methodology_snapshot'] as $field) $row[$field] = json_decode($row[$field] ?? '[]', true) ?: [];
        $row['answers_detail'] = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $this->table('submission_answers') . ' WHERE submission_id=%d ORDER BY id ASC', absint($id)), ARRAY_A);
        foreach ($row['answers_detail'] as &$answer) {
            $answer['answer'] = json_decode($answer['answer'], true);
            $answer['answer_label'] = isset($answer['answer_label']) ? (json_decode($answer['answer_label'], true) ?? []) : [];
            $answer['resolved_value'] = json_decode($answer['resolved_value'], true);
        }
        return $row;
    }

    public function find_latest_by_email(int $questionnaire_id, string $email): ?array
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $this->table('submissions') . ' WHERE questionnaire_id=%d AND email=%s AND test_mode=%s ORDER BY updated_at DESC, id DESC LIMIT 1',
            $questionnaire_id,
            sanitize_email($email),
            ''
        ));
        return $id ? $this->find((int) $id) : null;
    }

    public function query($args = [])
    {
        global $wpdb;
        $where = ["s.test_mode=''"];
        $values = [];
        if (!empty($args['search'])) {
            $where[] = '(s.email LIKE %s OR q.name LIKE %s)';
            $term = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $values[] = $term;
            $values[] = $term;
        }
        if (!empty($args['questionnaire_id'])) {
            $where[] = 's.questionnaire_id=%d';
            $values[] = absint($args['questionnaire_id']);
        }
        if (!empty($args['status'])) {
            $where[] = 's.status=%s';
            $values[] = sanitize_key($args['status']);
        }
        $limit = min(100, max(1, absint($args['per_page'] ?? 20)));
        $page = max(1, absint($args['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $join = ' FROM ' . $this->table('submissions') . ' s LEFT JOIN ' . $this->table('questionnaires') . ' q ON q.id=s.questionnaire_id WHERE ' . implode(' AND ', $where);
        $count_sql = 'SELECT COUNT(*)' . $join;
        $total = (int) ($values ? $wpdb->get_var($wpdb->prepare($count_sql, $values)) : $wpdb->get_var($count_sql));
        $sql = 'SELECT s.id,s.email,s.status,s.questionnaire_id,s.questionnaire_name,s.pricing_result,s.methodology_version,s.created_at,s.updated_at' . $join . ' ORDER BY s.created_at DESC,s.id DESC LIMIT %d OFFSET %d';
        $query_values = array_merge($values, [$limit, $offset]);
        $items = $wpdb->get_results($wpdb->prepare($sql, $query_values), ARRAY_A);
        foreach ($items as &$item) $item['pricing_result'] = json_decode($item['pricing_result'], true) ?: [];
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $limit, 'pages' => (int) ceil($total / $limit)];
    }

    public function delete($id)
    {
        global $wpdb;
        $id = absint($id);
        if (!$id) return false;
        $wpdb->query('START TRANSACTION');
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $this->table('submissions') . ' WHERE id=%d', $id));
        if (!$exists) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        $answers = $wpdb->delete($this->table('submission_answers'), ['submission_id' => $id], ['%d']);
        $submission = $wpdb->delete($this->table('submissions'), ['id' => $id], ['%d']);
        if ($submission !== 1 || $answers === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        $wpdb->query('COMMIT');
        return true;
    }
}

