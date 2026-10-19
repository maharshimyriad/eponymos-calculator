<?php

namespace Eponymos\Tests {
    use Eponymos\Submission\Repository;
    use PHPUnit\Framework\TestCase;

    class SubmissionRepositoryTest extends TestCase
    {
        protected function setUp(): void
        {
            global $wpdb;

            $wpdb = new class {
                public $insert_id = 101;
                public $deleteCalls = 0;

                public function query($sql)
                {
                    return true;
                }

                public function prepare($query, ...$args)
                {
                    return $query;
                }

                public function insert($table, $data, $format = null)
                {
                    $this->insert_id = 101;
                    return true;
                }

                public function update($table, $data, $where, $format = null, $where_format = null)
                {
                    return true;
                }

                public function delete($table, $where, $where_format = null)
                {
                    $this->deleteCalls++;
                    return 1;
                }

                public function get_var($sql)
                {
                    return 0;
                }

                public function get_row($sql, $output = null)
                {
                    return null;
                }

                public function get_results($sql, $output = null)
                {
                    return [];
                }
            };
        }

        public function testSaveDoesNotDeleteInsertedAnswersWhenNoExistingSubmissionIdIsPassed(): void
        {
            global $wpdb;

            $repository = new Repository();
            $id = $repository->save([
                'questionnaire_id' => 3,
                'questionnaire_name' => 'Test Questionnaire',
                'email' => 'client@example.com',
                'answers' => ['demo' => 'yes'],
                'resolved_values' => ['demo' => 'yes'],
                'calculation_inputs' => ['demo' => 'yes'],
                'calculation_outputs' => ['demo' => 'yes'],
                'pricing_result' => ['plan' => 'monthly'],
                'methodology_version' => 'v1',
                'methodology_snapshot' => [],
                'submission_id' => 0,
                'structure' => [
                    'stages' => [[
                        'id' => 10,
                        'title' => 'Stage 1',
                        'questions' => [[
                            'id' => 55,
                            'question_key' => 'demo',
                            'label' => 'Demo question',
                            'options' => [],
                        ]],
                    ]],
                ],
            ]);

            $this->assertSame(101, $id);
            $this->assertSame(0, $wpdb->deleteCalls);
        }
    }
}

namespace Eponymos\Submission {
    function absint($value)
    {
        return (int) $value;
    }

    function current_time($type = '')
    {
        return '2024-01-01 00:00:00';
    }

    function sanitize_email($email)
    {
        return trim((string) $email);
    }

    function sanitize_text_field($text)
    {
        return trim((string) $text);
    }

    function sanitize_key($key)
    {
        return strtolower(str_replace(' ', '_', trim((string) $key)));
    }

    function wp_json_encode($value)
    {
        return json_encode($value);
    }
}

namespace {
    function absint($value)
    {
        return (int) $value;
    }

    function current_time($type = '')
    {
        return '2024-01-01 00:00:00';
    }

    function sanitize_email($email)
    {
        return trim((string) $email);
    }

    function sanitize_text_field($text)
    {
        return trim((string) $text);
    }

    function sanitize_key($key)
    {
        return strtolower(str_replace(' ', '_', trim((string) $key)));
    }

    function wp_json_encode($value)
    {
        return json_encode($value);
    }
}
