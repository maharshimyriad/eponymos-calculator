<?php

namespace Eponymos\Questionnaire;

use Eponymos\Calculation\Methodology;

class Shortcode
{
    public function register()
    {
        add_shortcode('eponymos_questionnaire', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }
    public function assets()
    {
        wp_register_style('eponymos', EPONYMOS_URL . 'assets/css/questionnaire.css', [], EPONYMOS_VERSION);
        wp_register_script('eponymos', EPONYMOS_URL . 'assets/js/questionnaire.js', [], EPONYMOS_VERSION, true);
    }
    public function render($atts = [])
    {
        $r = new Repository();
        $data = $r->structure((int)($atts['id'] ?? 0));
        if (!$data) return '<p>' . esc_html__('Questionnaire unavailable.', 'eponymos') . '</p>';
        $methodology = Methodology::load();
        $testModeEnabled = (bool) get_option('eponymos_test_mode_enabled', false) && current_user_can('manage_options');
        $testMode = get_option('eponymos_test_mode', 'without_email');
        if (!in_array($testMode, ['without_email', 'with_email'], true)) {
            $testMode = 'without_email';
        }
        $testMode = $testModeEnabled ? $testMode : '';
        $testModeLabel = $testMode === 'with_email'
            ? __('TEST MODE: Email trigger enabled. Placeholder recipients only.', 'eponymos')
            : ($testMode === 'without_email' ? __('TEST MODE: No email trigger.', 'eponymos') : '');
        wp_enqueue_style('dashicons');
        wp_enqueue_style('eponymos');
        wp_enqueue_script('eponymos');
        wp_localize_script('eponymos', 'EponymosQuestionnaire', [
            'endpoint'      => rest_url('eponymos/v1/submit'),
            'stageEndpoint' => rest_url('eponymos/v1/submit-stage'),
            'maximumUsers'  => (int) $methodology->get('pricing.maximum_users'),
            'testMode'      => [
                'enabled' => $testModeEnabled,
                'mode' => $testMode,
                'nonce' => $testModeEnabled ? wp_create_nonce('wp_rest') : '',
                'placeholderEmail' => 'client@eponymos-test.invalid',
                'title' => $testMode === 'with_email' ? __('Test Estimate: Email Trigger', 'eponymos') : __('Test Estimate: No Email Trigger', 'eponymos'),
            ],
            'data'          => $data,
        ]);
        ob_start();
        include EPONYMOS_DIR . 'templates/frontend/questionnaire.php';
        return ob_get_clean();
    }
}
