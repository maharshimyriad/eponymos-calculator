<?php

namespace Eponymos\Admin;

use Eponymos\Calculation\Methodology;

class MethodologyPage
{
    public function register()
    {
        return add_submenu_page(
            'eponymos',
            'Calculation Methodology — Eponymos',
            'Methodology',
            'manage_options',
            'eponymos-methodology',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to access this page.', 'eponymos'));
        $methodology = Methodology::load();
        if (isset($_POST['eponymos_methodology_nonce']) && wp_verify_nonce($_POST['eponymos_methodology_nonce'], 'eponymos_save_methodology')) {
            $raw = is_array($_POST['methodology'] ?? null) ? wp_unslash($_POST['methodology']) : [];
            Methodology::save($this->sanitize($raw));
            $methodology = Methodology::load();
            echo '<div class="notice notice-success"><p>Calculation methodology saved.</p></div>';
        }
        $fields = [
            'usage.price_per_active_user_day' => 'K1 Casual rate per active user day',
            'usage.working_days_per_month' => 'K2 Default working days per month',
            'productivity.current_hours_per_pitch' => 'Current workflow hours per pitch (internal estimate)',
            'productivity.new_hours_per_pitch' => 'Eponymos hours per pitch (internal estimate)',
            'productivity.hourly_rate' => 'Productivity value per hour (internal estimate)',
            'productivity.source' => 'Productivity assumptions source',
            'productivity.source_date' => 'Productivity assumptions source date',
            'pricing.months_per_year' => 'K3 Months per year',
            'pricing.tier_price_percent_of_legacy' => 'K4 Tier price as a fraction of legacy average',
            'benchmarks.legacy_small_annual' => 'K5 Legacy benchmark: small team annual',
            'benchmarks.legacy_mid_annual' => 'K6 Legacy average: mid-market annual',
            'benchmarks.legacy_enterprise_annual' => 'K7 Legacy average: enterprise annual',
            'plans.professional_included_users' => 'K9 Professional included users',
            'plans.professional_additional_user_annual' => 'K10 Professional additional user annual price',
            'plans.enterprise_included_users' => 'K12 Enterprise included users',
            'benchmarks.legacy_small_user_limit' => 'K13 Legacy small-team upper limit',
            'benchmarks.legacy_mid_user_limit' => 'K14 Legacy mid-market upper limit',
            'pricing.commitment_months' => 'K15 Tier commitment (months)',
            'pricing.networked_monthly_price' => 'Networked monthly price',
            'roi.annual_periods' => 'Annual periods',
            'roi.monthly_price' => 'Eponymos monthly price',
            'pricing.monthly_price_per_user' => 'Eponymos monthly price per user',
            'pricing.current_software_cost_per_user' => 'Current software cost per user',
                        'software_categories.pr_software' => 'PR Software monthly value',
                        'software_categories.social_media_tools' => 'Social Media Tools monthly value',
                        'software_categories.digital_asset_management' => 'Digital Asset Management monthly value',
        ];
        echo '<div class="wrap"><h1>Calculation Methodology</h1><p>Configure calculation assumptions. Productivity inputs, source, and date are included in the final report and can be changed here.</p><form method="post">';
        wp_nonce_field('eponymos_save_methodology', 'eponymos_methodology_nonce');
        foreach ($fields as $path => $label) {
            $value = esc_attr((string)$methodology->get($path, ''));
            echo '<p><label><strong>' . esc_html($label) . '</strong><br><input class="regular-text" name="methodology[' . esc_attr(str_replace('.', '][', $path)) . ']" value="' . $value . '"></label></p>';
        }
        $professionalAnnual = (float) $methodology->get('benchmarks.legacy_mid_annual', 0) * (float) $methodology->get('pricing.tier_price_percent_of_legacy', 0);
        $enterpriseAnnual = (float) $methodology->get('benchmarks.legacy_enterprise_annual', 0) * (float) $methodology->get('pricing.tier_price_percent_of_legacy', 0);
        echo '<p><strong>K8 Professional annual price (derived)</strong><br><input class="regular-text" value="' . esc_attr(number_format_i18n($professionalAnnual, 2)) . '" readonly></p>';
        echo '<p><strong>K11 Enterprise annual price (derived)</strong><br><input class="regular-text" value="' . esc_attr(number_format_i18n($enterpriseAnnual, 2)) . '" readonly></p>';
        submit_button('Save methodology'); echo '</form></div>';
    }

    private function sanitize(array $value): array
    {
        foreach ($value as $key => &$item) {
            if (is_array($item)) $item = $this->sanitize($item);
            elseif (strpos((string)$key, 'key') !== false || strpos((string)$key, 'mapping') !== false) $item = sanitize_key($item);
            else $item = is_numeric($item) ? (float)$item : sanitize_text_field($item);
        }
        return $value;
    }
}
