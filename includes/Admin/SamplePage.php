<?php

namespace Eponymos\Admin;

use Eponymos\Sample\Service;

class SamplePage
{
    public function register()
    {
        return add_submenu_page('eponymos', 'Sample Data — Eponymos', 'Sample Data', 'manage_options', 'eponymos-samples', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to access this page.', 'eponymos'));
        $message = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eponymos_samples_nonce']) && wp_verify_nonce($_POST['eponymos_samples_nonce'], 'eponymos_create_samples')) {
            $result = !empty($_POST['sample_action']) && $_POST['sample_action'] === 'delete' ? (new Service())->delete() : (new Service())->reset();
            $message = $result ? (!empty($_POST['sample_action']) && $_POST['sample_action'] === 'delete' ? 'Sample library data deleted.' : 'Sample questions, options, and library groups were recreated.') : 'Sample data could not be updated.';
        }
        echo '<div class="wrap"><h1>Sample Data</h1><p>Create or reset only Eponymos sample records. Existing administrator-created questions, options, questionnaires, and library groups are preserved.</p>';
        if ($message) echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        echo '<form method="post">';
        wp_nonce_field('eponymos_create_samples', 'eponymos_samples_nonce');
        echo '<input type="hidden" name="sample_action" value="reset">';
        submit_button('Delete and recreate sample data', 'primary', 'submit', false);
        echo '</form><form method="post" style="margin-top:12px">';
        wp_nonce_field('eponymos_create_samples', 'eponymos_samples_nonce');
        echo '<input type="hidden" name="sample_action" value="delete">';
        submit_button('Delete sample data only', 'delete', 'submit', false);
        echo '</form></div>';
    }
}
