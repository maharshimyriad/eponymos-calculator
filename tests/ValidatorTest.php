<?php

namespace Eponymos\Tests;

use Eponymos\Questionnaire\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    /**
     * @dataProvider invalidActiveUserCounts
     */
    public function testActiveUserCountMustBeAPositiveSafeInteger($value): void
    {
        [, $errors] = (new Validator())->validate($this->structure(), ['active_users' => $value], '', false);

        $this->assertArrayHasKey('active_users', $errors);
    }

    public static function invalidActiveUserCounts(): array
    {
        return [
            'negative' => [-5],
            'zero' => [0],
            'fractional' => [2.5],
            'unsafe magnitude' => ['1e20'],
            'nonnumeric' => ['twelve'],
            'blank' => [''],
        ];
    }

    public function testActiveUserCountCanExceedSelfServeCapForContactRouting(): void
    {
        [, $errors] = (new Validator())->validate($this->structure(), ['active_users' => '501'], '', false);

        $this->assertSame([], $errors);
    }

    private function structure(): array
    {
        return [
            'stages' => [[
                'questions' => [[
                    'question_key' => 'active_users',
                    'type' => 'number',
                    'required' => true,
                    'validation' => [
                        'calculation_role' => 'active_users',
                        'min' => 1,
                        'max' => 500,
                    ],
                ]],
            ]],
        ];
    }
}