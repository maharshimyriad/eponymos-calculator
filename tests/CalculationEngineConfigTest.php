<?php

namespace Eponymos\Calculation;

function sanitize_key($key)
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
}

function is_wp_error($value): bool
{
    return $value instanceof \WP_Error;
}

namespace Eponymos\Tests;

use Eponymos\Calculation\CalculationEngine;
use Eponymos\Calculation\Methodology;
use PHPUnit\Framework\TestCase;

class CalculationEngineConfigTest extends TestCase
{
    public function testConfiguredRateAndUsageValuesOverrideQuestionOptionValues(): void
    {
        $methodology = new Methodology([
            'usage' => [
                'price_per_active_user_day' => 6,
                'frequency_days' => ['most_days' => 15],
            ],
            'competitor_costs' => [
                'cision' => ['value' => 77],
            ],
        ]);
        $structure = [
            'stages' => [[
                'questions' => [
                    [
                        'question_key' => 'active_users',
                        'type' => 'number',
                        'required' => true,
                        'calculation_role' => 'active_users',
                        'calculation_eligible' => true,
                        'validation' => ['calculation_role' => 'active_users'],
                    ],
                    [
                        'question_key' => 'active_days',
                        'type' => 'single_choice',
                        'required' => true,
                        'calculation_role' => 'active_days',
                        'calculation_eligible' => true,
                        'validation' => ['calculation_role' => 'active_days'],
                        'options' => [['option_key' => 'most_days', 'calculation_value' => 999]],
                    ],
                    [
                        'question_key' => 'selected_tools',
                        'type' => 'multiple_choice',
                        'required' => false,
                        'calculation_role' => 'software_savings',
                        'calculation_eligible' => true,
                        'validation' => ['calculation_role' => 'software_savings'],
                        'options' => [['option_key' => 'cision', 'calculation_value' => 1]],
                        'option_groups' => [[
                            'label' => 'PR software',
                            'options' => [['option_key' => 'cision', 'calculation_value' => 1]],
                        ]],
                    ],
                ],
            ]],
        ];

        $result = (new CalculationEngine())->resolve($structure, [
            'active_users' => '12',
            'active_days' => 'most_days',
            'selected_tools' => ['cision'],
        ], $methodology);

        $this->assertSame(15.0, $result['calculation_inputs']['active_days']);
        $this->assertSame(1080.0, $result['calculation_inputs']['pricing_result']['casual_monthly']);
        $this->assertSame([77], $result['resolved_values']['selected_tools']);
        $this->assertSame(77.0, $result['calculation_inputs']['current_monthly_software_cost']);
    }

    public function testFourthSoftwareGroupUsesItsLibraryValuesInSpendAndCategoryResults(): void
    {
        $structure = [
            'stages' => [[
                'questions' => [
                    [
                        'question_key' => 'active_users',
                        'type' => 'number',
                        'required' => true,
                        'calculation_role' => 'active_users',
                        'calculation_eligible' => true,
                    ],
                    [
                        'question_key' => 'active_days',
                        'type' => 'single_choice',
                        'required' => true,
                        'calculation_role' => 'active_days',
                        'calculation_eligible' => true,
                        'options' => [['option_key' => 'most_days', 'calculation_value' => 15]],
                    ],
                    [
                        'question_key' => 'selected_tools',
                        'type' => 'multiple_choice',
                        'required' => false,
                        'calculation_role' => 'software_savings',
                        'calculation_eligible' => true,
                        'option_groups' => [
                            [
                                'label' => 'PR Software',
                                'options' => [['option_key' => 'cision', 'calculation_value' => 77]],
                            ],
                            [
                                'label' => 'Newswires',
                                'options' => [['option_key' => 'prnewswire_cision', 'calculation_value' => 580]],
                            ],
                        ],
                    ],
                ],
            ]],
        ];

        $result = (new CalculationEngine())->resolve($structure, [
            'active_users' => '12',
            'active_days' => 'most_days',
            'selected_tools' => ['prnewswire_cision'],
        ], new Methodology());

        $this->assertSame([580], $result['resolved_values']['selected_tools']);
        $this->assertSame(580.0, $result['calculation_inputs']['current_monthly_software_cost']);
        $this->assertContains('Newswires', $result['calculation_inputs']['selected_software_categories']);
    }
}