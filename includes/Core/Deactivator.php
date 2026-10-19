<?php

namespace Eponymos\Core;

class Deactivator
{
    public static function deactivate(): void
    {
        if (get_option('eponymos_delete_data_on_deactivation', false)) self::delete_data();
        delete_option('eponymos_delete_data_on_deactivation');
    }

    public static function register_ajax(): void
    {
        check_ajax_referer('eponymos_deactivate', 'nonce');
        if (!current_user_can('activate_plugins')) wp_send_json_error([], 403);
        update_option('eponymos_delete_data_on_deactivation', true, false);
        wp_send_json_success();
    }

    public static function register_request(): void
    {
        if (!empty($_POST['eponymos_delete_data']) && isset($_POST['eponymos_delete_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eponymos_delete_nonce'])), 'eponymos_deactivate') && current_user_can('activate_plugins')) update_option('eponymos_delete_data_on_deactivation', true, false);
    }

    public static function delete_data(): void
    {
        global $wpdb;
        foreach (['submission_answers', 'submissions', 'options', 'questions', 'stages', 'questionnaires', 'library_options', 'library_questions', 'option_groups', 'methodology', 'email_logs', 'flow_logs'] as $table) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eponymos_' . $table);
        }
        foreach (['eponymos_db_version', 'eponymos_allow_key_editing', 'eponymos_pricing_config', 'eponymos_submission_admin_email', 'eponymos_calculation_methodology', 'eponymos_delete_data_on_deactivation'] as $option) delete_option($option);
    }
}
