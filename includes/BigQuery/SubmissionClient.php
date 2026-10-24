<?php

namespace Eponymos\BigQuery;

class SubmissionClient
{
    private const ENDPOINT = 'https://submission-form-api-80153651050.us-central1.run.app';

    public function send(array $submission): bool
    {
        $apiKey = defined('EPONYMOS_BIGQUERY_API_KEY')
            ? trim((string) EPONYMOS_BIGQUERY_API_KEY)
            : '';
        if ($apiKey === '') {
            error_log('Eponymos BigQuery sync skipped: EPONYMOS_BIGQUERY_API_KEY is not configured.');
            return false;
        }

        $payload = [
            'submission_id' => 'eps_' . (string) ($submission['id'] ?? ''),
            'questionnaire_id' => (string) ($submission['questionnaire_id'] ?? ''),
            'questionnaire_name' => (string) ($submission['questionnaire_name'] ?? ''),
            'email' => (string) ($submission['email'] ?? ''),
            'status' => 'submitted',
            'answers' => $this->jsonValue($submission['answers'] ?? null),
            'resolved_values' => $this->jsonValue($submission['resolved_values'] ?? null),
            'calculation_inputs' => $this->jsonValue($submission['calculation_inputs'] ?? null),
            'calculation_outputs' => $this->jsonValue($submission['calculation_outputs'] ?? null),
            'pricing_result' => $this->jsonValue($submission['pricing_result'] ?? null),
            'methodology_version' => (string) ($submission['methodology_version'] ?? ''),
            'methodology_snapshot' => $this->jsonValue($submission['methodology_snapshot'] ?? null),
            'created_at' => $this->timestamp((string) ($submission['created_at'] ?? '')),
            'updated_at' => $this->timestamp((string) ($submission['updated_at'] ?? '')),
        ];

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKey,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            error_log('Eponymos BigQuery sync failed: ' . $response->get_error_message());
            return false;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($statusCode < 200 || $statusCode >= 300 || ($body['status'] ?? '') !== 'ok') {
            error_log('Eponymos BigQuery sync failed with HTTP status ' . $statusCode . '.');
            return false;
        }

        return true;
    }

    private function jsonValue($value)
    {
        return $value === null || $value === [] ? (object) [] : $value;
    }

    private function timestamp(string $value): string
    {
        if ($value === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }

        return get_gmt_from_date($value, 'Y-m-d\TH:i:s\Z');
    }
}