<?php

namespace Eponymos\Support;

class EmailLog
{
    public static function record(int $submission_id, string $recipient, bool $sent): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'eponymos_email_logs',
            [
                'submission_id' => $submission_id,
                'recipient' => sanitize_email($recipient),
                'status' => $sent ? 'accepted' : 'failed',
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s']
        );
    }

    public static function all(): array
    {
        global $wpdb;

        $logs_table = $wpdb->prefix . 'eponymos_email_logs';
        $submissions_table = $wpdb->prefix . 'eponymos_submissions';
        $sql = "SELECT l.id, l.submission_id, l.recipient, l.status, l.created_at,
                    s.email AS submitter_email, s.questionnaire_name
                FROM {$logs_table} l
                LEFT JOIN {$submissions_table} s ON s.id = l.submission_id
                ORDER BY l.id DESC
                LIMIT 200";

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function delete_all(): int
    {
        global $wpdb;
        return (int) $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'eponymos_email_logs');
    }
}

