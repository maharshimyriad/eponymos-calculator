<?php

namespace Eponymos\Tests;

use Eponymos\REST\Routes;
use Eponymos\Support\Keys;

/**
 * PHPUnit-compatible focused tests. Load WordPress test bootstrap and the plugin
 * autoloader in the host project's test suite.
 */
class KeysTest extends \PHPUnit\Framework\TestCase
{
    public function testStableKeyGeneration()
    {
        $this->assertSame('monthly_activity', Keys::make('Monthly Activity'));
    }
    public function testDuplicateKeysAreSuffixed()
    {
        $this->assertSame('choice_2', Keys::unique('Choice', ['choice']));
    }

    public function testNormalizeRecipientsAddsCustomerEmailWithoutDuplicates()
    {
        $this->assertSame(
            ['admin@example.com', 'customer@example.com'],
            Routes::normalize_email_recipients([
                'admin@example.com, admin@example.com',
                'customer@example.com',
                'bad-email',
            ], 'customer@example.com')
        );
    }

    public function testGroupedSingleChoiceAllowsOneSelectionPerGroup()
    {
        $validator = new \Eponymos\Questionnaire\Validator();
        $structure = [
            'stages' => [[
                'questions' => [[
                    'question_key' => 'software_stack',
                    'type' => 'single_choice',
                    'required' => true,
                    'options' => [],
                    'option_groups' => [
                        ['label' => 'PR tools', 'options' => [['option_key' => 'pr_a', 'label' => 'PR A']]],
                        ['label' => 'Social tools', 'options' => [['option_key' => 'social_b', 'label' => 'Social B']]],
                    ],
                ]],
            ]],
        ];

        [$clean, $errors] = $validator->validate($structure, ['software_stack' => ['pr_a', 'social_b']], '', false);

        $this->assertSame(['pr_a', 'social_b'], $clean['software_stack']);
        $this->assertSame([], $errors);
    }
}
