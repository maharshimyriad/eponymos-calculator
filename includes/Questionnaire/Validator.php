<?php

namespace Eponymos\Questionnaire;

class Validator
{
    /**
     * @param array  $structure
     * @param array  $answers
     * @param string $email         Pass empty string '' to skip email validation.
     * @param bool   $validate_email Whether to validate the email field. Default true.
     */
    public function validate($structure, $answers, $email, $validate_email = true)
    {
        $errors = [];
        $clean = [];
        $questions = [];
        foreach (($structure['stages'] ?? []) as $stage) foreach (($stage['questions'] ?? []) as $q) $questions[$q['question_key']] = $q;
        foreach ($answers as $key => $value) {
            if (!isset($questions[$key])) $errors[$key] = 'This question is not part of the submitted questionnaire stage.';
        }
        foreach ($questions as $key => $q) {
            $present = array_key_exists($key, $answers);
            $v = $present ? $answers[$key] : null;
            $calculation_role = $q['calculation_role'] ?? ($q['validation']['calculation_role'] ?? '');
            $allowed = array_column((array) ($q['options'] ?? []), 'option_key');
            if ($q['type'] === 'multiple_choice') {
                if ($present && !is_array($v)) $errors[$key] = 'Please provide a list of options.';
                $v = $present && is_array($v) ? array_values(array_unique($v)) : [];
                if (count(array_diff($v, $allowed)) > 0) $errors[$key] = 'Please choose valid options.';
                if ($q['required'] && !$v) $errors[$key] = 'Please select at least one option.';
                $min = $q['validation']['min'] ?? null; $max = $q['validation']['max'] ?? null;
                if ($min !== null && count($v) < (int)$min) $errors[$key] = 'Please select more options.';
                if ($max !== null && count($v) > (int)$max) $errors[$key] = 'Please select fewer options.';
            } elseif ($q['type'] === 'single_choice' || $q['type'] === 'yes_no') {
                $grouped = !empty($q['option_groups']) && count((array) ($q['option_groups'])) > 1;
                if ($grouped) {
                    if ($present && !is_array($v)) $errors[$key] = 'Please choose a valid option for each group.';
                    $v = is_array($v) ? array_values(array_map('strval', $v)) : [];
                    $group_count = count((array) ($q['option_groups'] ?? []));
                    while (count($v) < $group_count) $v[] = '';
                    $v = array_slice($v, 0, $group_count);

                    foreach ((array) ($q['option_groups'] ?? []) as $index => $group) {
                        $group_allowed = array_column((array) ($group['options'] ?? []), 'option_key');
                        $choice = $v[$index] ?? '';
                        if ($choice !== '' && !in_array($choice, $group_allowed, true)) {
                            $errors[$key] = 'Please choose a valid option.';
                        }
                    }
                    if ($q['required'] && !array_filter($v, static fn($choice) => $choice !== '')) {
                        $errors[$key] = 'This field is required.';
                    }
                } else {
                    if ($present && !is_string($v)) $errors[$key] = 'Please choose a valid option.';
                    $v = is_string($v) ? $v : '';
                    if ($q['required'] && $v === '') $errors[$key] = 'This field is required.';
                    elseif ($v !== '' && !in_array($v, $allowed, true)) $errors[$key] = 'Please choose a valid option.';
                }
            } elseif ($q['type'] === 'email') {
                $v = sanitize_email((string)$v);
                if (!$v || !is_email($v)) $errors[$key] = 'Please enter a valid email address.';
            } elseif ($q['type'] === 'number') {
                $v = is_string($v) ? trim($v) : $v;
                if ($calculation_role === 'active_users' && (!$present || $v === '' || $v === null)) {
                    $errors[$key] = 'Please enter a valid number, e.g. 1, 2, 32.';
                } elseif ($present && $v !== '' && $v !== null && !is_numeric($v)) {
                    $errors[$key] = 'Please enter a number.';
                } elseif ($calculation_role === 'active_users' && $present) {
                    $user_count = (float) $v;
                    if (!is_finite($user_count) || $user_count < 1 || floor($user_count) !== $user_count || $user_count > 9007199254740991) {
                        $errors[$key] = 'Please enter a valid number, e.g. 1, 2, 32.';
                    }
                } elseif ($q['required'] && ($v === '' || $v === null)) {
                    $errors[$key] = 'This field is required.';
                }
            } else {
                if ($present && !is_string($v) && !is_numeric($v)) $errors[$key] = 'Please provide a valid answer.';
                $v = is_string($v) ? sanitize_textarea_field($v) : $v;
                if ($q['required'] && ($v === '' || $v === null)) $errors[$key] = 'This field is required.';
                $len = is_string($v) ? strlen($v) : 0;
                if (isset($q['validation']['min_length']) && $len < (int)$q['validation']['min_length']) $errors[$key] = 'This answer is too short.';
                if (isset($q['validation']['max_length']) && $len > (int)$q['validation']['max_length']) $errors[$key] = 'This answer is too long.';
            }
            if ($present) $clean[$key] = $v;
        }
        if ($validate_email) {
            $email = sanitize_email($email);
            if (!$email || !is_email($email)) $errors['_email'] = 'Please enter a valid work email address.';
        }
        return [$clean, $errors];
    }
}
