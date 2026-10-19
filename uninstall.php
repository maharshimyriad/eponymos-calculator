<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
// Data is intentionally retained by default; destructive cleanup is not safe for historical submissions.
if (get_option('eponymos_delete_data_on_uninstall', false)) {
    global $wpdb;
    foreach (['submission_answers', 'submissions', 'options', 'questions', 'stages', 'questionnaires', 'library_options', 'library_questions', 'option_groups', 'methodology', 'email_logs', 'flow_logs'] as $table) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eponymos_' . $table);
    }
    delete_option('eponymos_db_version');
    delete_option('eponymos_allow_key_editing');
    delete_option('eponymos_pricing_config');
    delete_option('eponymos_submission_admin_email');
    delete_option('eponymos_calculation_methodology');
    delete_option('eponymos_delete_data_on_deactivation');
}

