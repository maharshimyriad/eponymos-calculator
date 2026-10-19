<?php

namespace Eponymos\Questionnaire;

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
        wp_enqueue_style('dashicons');
        wp_enqueue_style('eponymos');
        wp_enqueue_script('eponymos');
        wp_localize_script('eponymos', 'EponymosQuestionnaire', [
            'endpoint'      => rest_url('eponymos/v1/submit'),
            'stageEndpoint' => rest_url('eponymos/v1/submit-stage'),
            'data'          => $data,
        ]);
        ob_start();
        include EPONYMOS_DIR . 'templates/frontend/questionnaire.php';
        return ob_get_clean();
    }
}
