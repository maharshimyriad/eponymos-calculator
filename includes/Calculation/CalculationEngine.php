<?php

    namespace Eponymos\Calculation;

    use Eponymos\Pricing\PricingService;
    use WP_Error;

    class CalculationEngine
    {
        public function resolve(array $structure, array $answers, ?Methodology $methodology = null)
        {
            $methodology = $methodology ?: Methodology::load();
            $configuration_error = $methodology->validate();
            if (is_wp_error($configuration_error)) return $configuration_error;

            $resolved = [];
            $inputs = [];
            foreach ($structure['stages'] as $stage) {
                foreach ($stage['questions'] as $question) {
                    $key = (string) ($question['question_key'] ?? '');
                    if ($key === '' || !array_key_exists($key, $answers)) continue;
                    $role = sanitize_key((string) ($question['calculation_role'] ?? ($question['validation']['calculation_role'] ?? '')));
                    $configuredOptionValues = [];
                    if ($role === 'active_days') {
                        $configuredOptionValues = (array) $methodology->get('usage.frequency_days', []);
                    } elseif ($role === 'software_savings') {
                        foreach ((array) $methodology->get('competitor_costs', []) as $toolKey => $cost) {
                            if (is_array($cost) && isset($cost['value'])) $configuredOptionValues[$toolKey] = $cost['value'];
                        }
                    }
                    $value = $answers[$key];
                    if (is_array($value)) {
                        $flattened = [];
                        array_walk_recursive($value, static function ($item) use (&$flattened) {
                            if (is_scalar($item) || (is_object($item) && method_exists($item, '__toString'))) {
                                $normalized = trim((string) $item);
                                if ($normalized !== '') {
                                    $flattened[] = $normalized;
                                }
                            }
                        });
                        $value = array_values(array_unique($flattened));
                    }
                    $options = [];
                    foreach (($question['options'] ?? []) as $option) {
                        $optionKey = (string) ($option['option_key'] ?? '');
                        $calculationValue = $option['calculation_value'] ?? null;
                        if ($role === 'active_days') {
                            $calculationValue = $configuredOptionValues[$optionKey] ?? '';
                        } elseif ($role === 'software_savings') {
                            $calculationValue = $configuredOptionValues[$optionKey] ?? $calculationValue ?? '';
                        }
                        if ($calculationValue === '' || $calculationValue === null) continue;
                        $options[$optionKey] = $calculationValue;
                    }
                    foreach (($question['option_groups'] ?? []) as $group) {
                        foreach (($group['options'] ?? []) as $option) {
                            $optionKey = (string) ($option['option_key'] ?? '');
                            $calculationValue = $option['calculation_value'] ?? null;
                            if ($role === 'active_days') {
                                $calculationValue = $configuredOptionValues[$optionKey] ?? '';
                            } elseif ($role === 'software_savings') {
                                $calculationValue = $configuredOptionValues[$optionKey] ?? $calculationValue ?? '';
                            }
                            if ($calculationValue === '' || $calculationValue === null) continue;
                            $options[$optionKey] = $calculationValue;
                        }
                    }
                    foreach ($options as $option_key => $calculation_value) {
                        $options[(string) $option_key] = $calculation_value;
                    }
                    if ($question['type'] === 'multiple_choice') {
                        $resolved[$key] = array_values(array_filter(array_map(function ($option_key) use ($options) {
                            if (!is_scalar($option_key) && !(is_object($option_key) && method_exists($option_key, '__toString'))) {
                                return null;
                            }
                            $option_key = (string) $option_key;
                            return $option_key !== '' && array_key_exists($option_key, $options) ? $options[$option_key] : null;
                        }, (array) $value), static function ($value) { return $value !== null; }));
                    } elseif (in_array($question['type'], ['single_choice', 'yes_no'], true)) {
                        if (is_array($value)) {
                            $resolved[$key] = array_values(array_filter(array_map(function ($option_key) use ($options) {
                                if (!is_scalar($option_key) && !(is_object($option_key) && method_exists($option_key, '__toString'))) {
                                    return null;
                                }
                                $option_key = (string) $option_key;
                                return $option_key !== '' && array_key_exists($option_key, $options) ? $options[$option_key] : null;
                            }, $value), static function ($value) { return $value !== null; }));
                        } else {
                            $lookup_key = is_scalar($value) || $value === null ? (string) $value : '';
                            $resolved[$key] = $lookup_key !== '' && array_key_exists($lookup_key, $options)
                                ? $options[$lookup_key]
                                : $value;
                        }
                    } else {
                        $resolved[$key] = $value;
                    }
                    if (!empty($question['calculation_eligible']) || in_array($role, ['pitches_per_month', 'active_users', 'active_days', 'software_savings', 'organization_type', 'agency_clients'], true)) {
                        $inputs[$key] = $resolved[$key];
                    }
                }
            }

            $methodology_inputs = $this->build_methodology_inputs($structure, $inputs, $methodology, $answers);
            if (is_wp_error($methodology_inputs)) return $methodology_inputs;
            $outputs = (new ReportMethodology())->calculate($methodology_inputs);
            if (is_wp_error($outputs)) return $outputs;

            return [
                'resolved_values' => $resolved,
                'calculation_inputs' => $methodology_inputs,
                'calculation_outputs' => $outputs,
                'methodology' => $methodology->all(),
                'methodology_version' => $methodology->version(),
            ];
        }

        private function build_methodology_inputs(array $structure, array $inputs, Methodology $methodology, array $answers = [])
        {
            $roles = $this->configured_roles($structure);

            foreach (['active_users'] as $required_role) {
                if ($roles[$required_role] === '' || !array_key_exists($roles[$required_role], $inputs)) {
                    return new WP_Error(
                        'calculation_input_missing',
                        'A required calculation input is missing.',
                        ['role' => $required_role, 'status' => 422]
                    );
                }
            }

            $users   = ($roles['active_users'] !== '' && array_key_exists($roles['active_users'], $inputs))
                ? ($this->numeric_value($inputs[$roles['active_users']]) ?? 0.0)
                : 0.0;
            $maximum_self_serve_users = (int) $methodology->get('pricing.maximum_users');
            $working_days = ($roles['active_days'] !== '' && array_key_exists($roles['active_days'], $inputs))
                ? ($this->numeric_value($inputs[$roles['active_days']]) ?? (float) $methodology->get('usage.working_days_per_month'))
                : (float) $methodology->get('usage.working_days_per_month');
            $pitches = ($roles['pitches_per_month'] !== '' && array_key_exists($roles['pitches_per_month'], $inputs))
                ? $this->numeric_value($inputs[$roles['pitches_per_month']])
                : null;
            $organization_type = ($roles['organization_type'] !== '' && array_key_exists($roles['organization_type'], $inputs))
                ? strtolower((string) $inputs[$roles['organization_type']])
                : '';
            $agency_clients = ($roles['agency_clients'] !== '' && array_key_exists($roles['agency_clients'], $inputs))
                ? $this->client_count_value($inputs[$roles['agency_clients']])
                : 0.0;

            $software = 0.0;
            $software_source = 'none';
            if ($roles['software_savings'] !== '' && array_key_exists($roles['software_savings'], $inputs)) {
                $software = $this->numeric_total($inputs[$roles['software_savings']]) ?? 0.0;
                if ($software > 0) $software_source = 'based on typical pricing for the tools you selected';
            }

            $category_values = [
                'pr_software' => (float) $methodology->get('software_categories.pr_software', 0),
                'social_media_tools' => (float) $methodology->get('software_categories.social_media_tools', 0),
                'digital_asset_management' => (float) $methodology->get('software_categories.digital_asset_management', 0),
            ];
            $selected_categories = $this->selected_software_categories($structure, $answers, $roles['software_savings'], $methodology);
            $pricing = (new PricingService())->calculate([
                'active_users' => $users,
                'maximum_self_serve_users' => $maximum_self_serve_users,
                'active_days'             => $working_days,
                'organization_type' => $organization_type,
                'clients' => $agency_clients,
            ], $methodology);
            if (is_wp_error($pricing)) return $pricing;
            $eponymos_monthly_price = $pricing['monthly_estimate'];

            $monthly_software_savings = is_numeric($eponymos_monthly_price) ? max(0.0, $software - $eponymos_monthly_price) : 0.0;

            return [
                'selected_software_categories' => $selected_categories,
                'eponymos_monthly_price' => $eponymos_monthly_price,
                'software_category_values' => $category_values,
                'current_monthly_software_cost' => $software,
                'stage_2_benchmark_monthly' => $software,
                'stage_2_benchmark_source' => $software_source,
                'active_users'             => $users,
                'maximum_self_serve_users' => $maximum_self_serve_users,
                'active_days'              => $working_days,
                'pitches_per_month'        => $pitches,
                'current_hours_per_pitch'  => (float) $methodology->get('productivity.current_hours_per_pitch'),
                'new_hours_per_pitch'      => (float) $methodology->get('productivity.new_hours_per_pitch'),
                'hourly_rate'              => (float) $methodology->get('productivity.hourly_rate'),
                'productivity_source'      => (string) $methodology->get('productivity.source', ''),
                'productivity_source_date' => (string) $methodology->get('productivity.source_date', ''),
                'organization_type'        => $organization_type,
                'agency_clients'           => $agency_clients,
                'monthly_software_savings' => $monthly_software_savings,
                'monthly_price'            => $eponymos_monthly_price,
                'price_per_active_user_day'=> (float) $methodology->get('usage.price_per_active_user_day'),
                'pricing_result'           => $pricing,
                'annual_periods'           => (float) $methodology->get('roi.annual_periods'),
            ];
        }

        /**
         * Resolve which question key serves each calculation role.
         * Roles are stored in the question's validation JSON as calculation_role.
        * active_users and software_savings are optional — default to '' (unmapped).
        */
        private function selected_software_categories(array $structure, array $answers, string $software_key, Methodology $methodology): array
        {
            if ($software_key === '' || !array_key_exists($software_key, $answers)) return [];
            $selected = array_map('strval', (array) $answers[$software_key]);
            $categories = [];
            foreach (($structure['stages'] ?? []) as $stage) {
                foreach (($stage['questions'] ?? []) as $question) {
                    if (($question['question_key'] ?? '') !== $software_key) continue;
                    foreach ((array)($question['option_groups'] ?? []) as $group) {
                        $category = trim((string) ($group['label'] ?? ''));
                        if ($category === '') continue;
                        foreach ((array)($group['options'] ?? []) as $option) {
                            $option_key = (string) ($option['option_key'] ?? '');
                            $calculation_value = $methodology->get('competitor_costs.' . $option_key . '.value', $option['calculation_value'] ?? null);
                            if (is_numeric($calculation_value)
                                && (float) $calculation_value > 0
                                && in_array((string)($option['option_key'] ?? ''), $selected, true)) {
                                $categories[$category] = true;
                            }
                        }
                    }
                }
            }
            return array_keys($categories);
        }

        private function configured_roles(array $structure): array
        {
            $roles = [
                'pitches_per_month' => '', // optional
                'organization_type' => '', // optional
                'agency_clients' => '', // optional
                'active_users'      => '',   // optional
                'active_days'       => '', // optional
                'software_savings'  => '',   // optional
            ];
            foreach ($structure['stages'] as $stage) {
                foreach ($stage['questions'] as $question) {
                    $role = isset($question['calculation_role']) ? sanitize_key($question['calculation_role']) : '';
                    if ($role !== '' && array_key_exists($role, $roles)) {
                        $roles[$role] = (string) $question['question_key'];
                    }
                }
            }
            return $roles;
        }

        private function numeric_value($value): ?float
        {
            if (is_array($value) || $value === '' || $value === null || !is_numeric($value)) return null;
            return (float) $value;
        }

        private function numeric_total($value): ?float
        {
            if (!is_array($value)) return $this->numeric_value($value);
            $total = 0.0;
            $found = false;
            foreach ($value as $item) {
                if ($item === '' || $item === null || !is_numeric($item)) continue;
                $total += (float) $item;
                $found = true;
            }
            return $found ? $total : null;
        }

        private function client_count_value($value): float
        {
            $numeric = $this->numeric_value($value);
            if ($numeric !== null) return max(0.0, $numeric);
            if (!is_scalar($value)) return 0.0;
            return preg_match('/\d+/', (string) $value, $matches) ? (float) $matches[0] : 0.0;
        }
    }
