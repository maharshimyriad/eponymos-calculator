<?php

/**
 * Plugin Name: Eponymos Questionnaire
 * Description: Modular, data-driven Eponymos questionnaires, calculations, pricing and reports.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Text Domain: eponymos
 */
if (!defined('ABSPATH')) {
    exit;
}
define('EPONYMOS_VERSION', '2.4.9');
define('EPONYMOS_FILE', __FILE__);
define('EPONYMOS_DIR', plugin_dir_path(__FILE__));
define('EPONYMOS_URL', plugin_dir_url(__FILE__));

$composer_autoload = EPONYMOS_DIR . 'vendor/autoload.php';
if (is_readable($composer_autoload)) {
    require_once $composer_autoload;
}

spl_autoload_register(function ($class) {
    $prefix = 'Eponymos\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = EPONYMOS_DIR . 'includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) require_once $file;
});


// Use the Eponymos-specific SparkPost bounce domain only for questionnaire notifications.
add_filter('postman_sparkpost_transmission_body', function ($body, $message) {
    if (!is_array($body) || !is_object($message) || !method_exists($message, 'getSubject')) {
        return $body;
    }

    $subject = (string) $message->getSubject();

    $is_eponymos_notification = preg_match(
        '/(?:New questionnaire submission|Stage 2 completed\s*-\s*submission|We received your submission|Your report is ready\s*-\s*submission)\s*#?\d+/i',
        $subject
    );

    if ($is_eponymos_notification) {
        $body['return_path'] = 'bounce@send.discover.eponymos.com';
    }

    return $body;
}, 10, 2);

register_activation_hook(__FILE__, ['Eponymos\\Core\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Eponymos\\Core\\Activator', 'deactivate']);
add_action('wp_ajax_eponymos_delete_data', ['Eponymos\\Core\\Deactivator', 'register_ajax']);
add_action('admin_init', ['Eponymos\\Core\\Deactivator', 'register_request']);
add_action('admin_footer-plugins.php', ['Eponymos\\Admin\\Admin', 'deactivation_prompt_static']);
add_action('plugins_loaded', function () {
    \Eponymos\Core\Plugin::boot();
});
