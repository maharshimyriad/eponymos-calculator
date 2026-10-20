<?php

namespace Eponymos\Database;

class Schema
{
    const VERSION = '1.9.0';
    public static function tables_exist()
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'eponymos_';
        foreach (['questionnaires', 'stages', 'questions', 'options', 'submissions', 'submission_answers', 'option_groups', 'library_options', 'library_questions', 'methodology', 'email_logs', 'flow_logs'] as $table) {
            $name = $prefix . $table;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) return false;
        }
        return true;
    }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'eponymos_';
        $sql = [
            "CREATE TABLE {$p}questionnaires (id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(190) NOT NULL, description text NOT NULL, status varchar(20) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), KEY status (status)) $charset",
            "CREATE TABLE {$p}stages (id bigint unsigned NOT NULL AUTO_INCREMENT, questionnaire_id bigint unsigned NOT NULL, title varchar(190) NOT NULL, description text NOT NULL, status varchar(20) NOT NULL DEFAULT 'active', position int unsigned NOT NULL DEFAULT 0, settings longtext NOT NULL, PRIMARY KEY (id), KEY questionnaire_position (questionnaire_id,position)) $charset",
            "CREATE TABLE {$p}questions (id bigint unsigned NOT NULL AUTO_INCREMENT, stage_id bigint unsigned NOT NULL, label text NOT NULL, internal_name varchar(190) NOT NULL, question_key varchar(190) NOT NULL, type varchar(30) NOT NULL, required tinyint(1) NOT NULL DEFAULT 1, help_text text NOT NULL, placeholder varchar(190) NOT NULL, validation longtext NOT NULL, calculation_eligible tinyint(1) NOT NULL DEFAULT 0, position int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY stage_key (stage_id,question_key), KEY stage_position (stage_id,position)) $charset",
            "CREATE TABLE {$p}options (id bigint unsigned NOT NULL AUTO_INCREMENT, question_id bigint unsigned NOT NULL, label varchar(190) NOT NULL, option_key varchar(190) NOT NULL, calculation_value text NOT NULL, position int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY question_key (question_id,option_key), KEY question_position (question_id,position)) $charset",
            "CREATE TABLE {$p}submissions (id bigint unsigned NOT NULL AUTO_INCREMENT, questionnaire_id bigint unsigned NOT NULL, questionnaire_name varchar(190) NOT NULL DEFAULT '', email varchar(190) NOT NULL, status varchar(20) NOT NULL DEFAULT 'completed', answers longtext NOT NULL, resolved_values longtext NOT NULL, calculation_inputs longtext NOT NULL, calculation_outputs longtext NOT NULL, pricing_result longtext NOT NULL, methodology_version varchar(50) NOT NULL, methodology_snapshot longtext NOT NULL, test_mode varchar(20) NOT NULL DEFAULT '', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), KEY email (email), KEY questionnaire_created (questionnaire_id,created_at)) $charset",
            "CREATE TABLE {$p}submission_answers (id bigint unsigned NOT NULL AUTO_INCREMENT, submission_id bigint unsigned NOT NULL, question_id bigint unsigned NOT NULL, question_key varchar(190) NOT NULL, question_label text NOT NULL, stage_title varchar(190) NOT NULL, answer longtext NOT NULL, resolved_value longtext NOT NULL, answer_label longtext NOT NULL, PRIMARY KEY (id), KEY submission (submission_id), KEY question_key (question_key)) $charset",
            "CREATE TABLE {$p}option_groups (id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(190) NOT NULL, description text NOT NULL, hide_title tinyint(1) NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), KEY status_updated (status,updated_at)) $charset",
            "CREATE TABLE {$p}library_options (id bigint unsigned NOT NULL AUTO_INCREMENT, group_id bigint unsigned NOT NULL, label varchar(190) NOT NULL, option_key varchar(190) NOT NULL, calculation_value text NOT NULL, status varchar(20) NOT NULL DEFAULT 'active', position int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY group_key (group_id,option_key), KEY group_position (group_id,position)) $charset",
            "CREATE TABLE {$p}library_questions (id bigint unsigned NOT NULL AUTO_INCREMENT, label text NOT NULL, internal_name varchar(190) NOT NULL, question_key varchar(190) NOT NULL, type varchar(30) NOT NULL, required tinyint(1) NOT NULL DEFAULT 1, help_text text NOT NULL, placeholder varchar(190) NOT NULL, validation longtext NOT NULL, calculation_eligible tinyint(1) NOT NULL DEFAULT 0, option_group_id bigint unsigned NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY question_key (question_key), KEY group_status (option_group_id,status), KEY updated (updated_at)) $charset",
            "CREATE TABLE {$p}methodology (id bigint unsigned NOT NULL AUTO_INCREMENT, methodology_key varchar(100) NOT NULL, methodology_version varchar(50) NOT NULL, settings longtext NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY methodology_key (methodology_key)) $charset",
            "CREATE TABLE {$p}email_logs (id bigint unsigned NOT NULL AUTO_INCREMENT, submission_id bigint unsigned NOT NULL, recipient varchar(190) NOT NULL, status varchar(20) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (id), KEY submission (submission_id), KEY status_created (status,created_at)) $charset",
            "CREATE TABLE {$p}flow_logs (id bigint unsigned NOT NULL AUTO_INCREMENT, request_id varchar(36) NOT NULL, event varchar(50) NOT NULL, status varchar(20) NOT NULL, route varchar(190) NOT NULL, message text NOT NULL, context longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (id), KEY request_id (request_id), KEY status_created (status,created_at)) $charset"
        ];
        foreach ($sql as $statement) dbDelta($statement);
        self::migrate_submission_columns();
        self::migrate_library_links();
        update_option('eponymos_db_version', self::VERSION);
    }

    private static function migrate_library_links()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'eponymos_questions';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!in_array('library_question_id', $columns, true)) $wpdb->query("ALTER TABLE {$table} ADD library_question_id bigint unsigned NOT NULL DEFAULT 0 AFTER stage_id");
    }

    private static function migrate_submission_columns()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'eponymos_submissions';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!in_array('questionnaire_name', $columns, true)) $wpdb->query("ALTER TABLE {$table} ADD questionnaire_name varchar(190) NOT NULL DEFAULT '' AFTER questionnaire_id");
        if (!in_array('methodology_snapshot', $columns, true)) $wpdb->query("ALTER TABLE {$table} ADD methodology_snapshot longtext NOT NULL AFTER methodology_version");
        if (!in_array('test_mode', $columns, true)) $wpdb->query("ALTER TABLE {$table} ADD test_mode varchar(20) NOT NULL DEFAULT '' AFTER methodology_snapshot");
    }
}
