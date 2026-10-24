<?php

namespace Eponymos\BigQuery {
    use PHPUnit\Framework\TestCase;

    function wp_remote_post($url, $args)
    {
        $GLOBALS['eponymos_bigquery_request'] = ['url' => $url, 'args' => $args];
        return ['response' => ['code' => 200], 'body' => '{"status":"ok","inserted":1}'];
    }

    function wp_remote_retrieve_response_code($response)
    {
        return $response['response']['code'] ?? 0;
    }

    function wp_remote_retrieve_body($response)
    {
        return $response['body'] ?? '';
    }

    function wp_json_encode($value)
    {
        return json_encode($value);
    }

    function is_wp_error($value)
    {
        return $value instanceof \WP_Error;
    }

    function get_gmt_from_date($value, $format)
    {
        return '2026-10-20T12:00:00Z';
    }

    class BigQuerySubmissionClientTest extends TestCase
    {
        public function testSendsPersistedSubmissionWithRequiredSchemaAndPrivateHeader(): void
        {
            $GLOBALS['eponymos_bigquery_request'] = null;
            $client = new SubmissionClient();

            $this->assertTrue($client->send([
                'id' => 123,
                'questionnaire_id' => 7,
                'questionnaire_name' => 'Media Pricing Questionnaire',
                'email' => 'lead@example.com',
                'answers' => ['team_size' => '50-100'],
                'resolved_values' => ['team_size' => 75],
                'calculation_inputs' => [],
                'calculation_outputs' => ['annual_savings' => 500],
                'pricing_result' => ['monthly_estimate' => 100],
                'methodology_version' => 'v1',
                'methodology_snapshot' => [],
                'created_at' => '2026-10-20 12:00:00',
                'updated_at' => '2026-10-20 12:00:00',
            ]));

            $request = $GLOBALS['eponymos_bigquery_request'];
            $payload = json_decode($request['args']['body']);
            $this->assertSame('https://submission-form-api-80153651050.us-central1.run.app', $request['url']);
            $this->assertSame('unit-test-api-key', $request['args']['headers']['X-API-Key']);
            $this->assertSame('eps_123', $payload->submission_id);
            $this->assertSame('7', $payload->questionnaire_id);
            $this->assertSame('submitted', $payload->status);
            $this->assertEquals((object) [], $payload->calculation_inputs);
            $this->assertEquals((object) [], $payload->methodology_snapshot);
            $this->assertSame('2026-10-20T12:00:00Z', $payload->created_at);
            $this->assertSame('2026-10-20T12:00:00Z', $payload->updated_at);
        }
    }
}

namespace {
    if (!defined('EPONYMOS_BIGQUERY_API_KEY')) {
        define('EPONYMOS_BIGQUERY_API_KEY', 'unit-test-api-key');
    }
}