<?php

namespace Eponymos\Support;

class FlowLog
{
    public static function record(string $event, string $status, string $route = '', string $message = '', array $context = []): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'eponymos_flow_logs',
            [
                'request_id' => self::request_id(),
                'event' => sanitize_key($event),
                'status' => sanitize_key($status),
                'route' => sanitize_text_field($route),
                'message' => sanitize_text_field($message),
                'context' => wp_json_encode($context),
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public static function all(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'eponymos_flow_logs';
        return $wpdb->get_results("SELECT id, request_id, event, status, route, message, context, created_at FROM {$table} ORDER BY id DESC LIMIT 300", ARRAY_A) ?: [];
    }

    public static function delete_all(): int
    {
        global $wpdb;
        return (int) $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'eponymos_flow_logs');
    }

    public static function request_id(): string
    {
        static $id;
        if (!$id) {
            $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('eponymos_', true);
        }
        return $id;
    }
}
