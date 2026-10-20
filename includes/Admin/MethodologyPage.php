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
            $saved = Methodology::save($this->sanitize($raw));
            $methodology = Methodology::load();
            echo $saved
                ? '<div class="notice notice-success"><p>Calculation methodology saved.</p></div>'
                : '<div class="notice notice-error"><p>Methodology was not saved. Check that every benchmark and competitor cost has a valid source URL and date.</p></div>';
        }
        $fields = [
            'usage.price_per_active_user_day' => 'K1 Casual rate per active user day',
            'usage.working_days_per_month' => 'K2 Default working days per month',
            'usage.frequency_days.every_working_day' => 'Every working day: days per month',
            'usage.frequency_days.most_days' => 'Most days: days per month',
            'usage.frequency_days.few_days_a_week' => 'A few days a week: days per month',
            'usage.frequency_days.occasionally' => 'Occasionally: days per month',
            'productivity.current_hours_per_pitch' => 'Current workflow hours per pitch (internal estimate)',
            'productivity.new_hours_per_pitch' => 'Eponymos hours per pitch (internal estimate)',
            'productivity.hourly_rate' => 'Productivity value per hour (internal estimate)',
            'productivity.source' => 'Productivity assumptions source',
            'productivity.source_date' => 'Productivity assumptions source date',
            'pricing.months_per_year' => 'K3 Months per year',
            'pricing.maximum_users' => 'Maximum self-serve users',
            'pricing.agency_networked_client_threshold' => 'Agency client threshold for Networked',
            'pricing.agency_networked_nudge_min_clients' => 'Agency Networked nudge minimum clients',
            'pricing.agency_networked_nudge_max_clients' => 'Agency Networked nudge maximum clients',
            'pricing.casual_nudge_users_before_trigger' => 'Casual upgrade nudge distance (users)',
            'benchmarks.legacy_small_annual' => 'K5 Legacy benchmark: small team annual',
            'benchmarks.legacy_small_annual_source_url' => 'Small-team benchmark source URL',
            'benchmarks.legacy_small_annual_date_checked' => 'Small-team benchmark date checked',
            'benchmarks.legacy_mid_annual' => 'K6 Legacy average: mid-market annual',
            'benchmarks.legacy_mid_annual_source_url' => 'Mid-market benchmark source URL',
            'benchmarks.legacy_mid_annual_date_checked' => 'Mid-market benchmark date checked',
            'benchmarks.legacy_enterprise_annual' => 'K7 Legacy average: enterprise annual',
            'benchmarks.legacy_enterprise_annual_source_url' => 'Enterprise benchmark source URL',
            'benchmarks.legacy_enterprise_annual_date_checked' => 'Enterprise benchmark date checked',
            'plans.professional_included_users' => 'K9 Professional included users',
            'plans.professional_additional_user_annual' => 'K10 Professional additional user annual price',
            'plans.professional_annual_base' => 'Professional annual base price',
            'plans.enterprise_included_users' => 'K12 Enterprise included users',
            'plans.enterprise_annual_base' => 'Enterprise annual base price',
            'plans.enterprise_switch_point_users' => 'Professional-to-Enterprise switch point (users)',
            'plans.enterprise_nudge_users_before_trigger' => 'Enterprise switch nudge distance (users)',
            'plans.enterprise_contact_nudge_users' => 'Enterprise contact nudge distance (seats)',
            'plans.enterprise_additional_user_annual' => 'Enterprise additional user annual price (D2)',
            'benchmarks.legacy_small_user_limit' => 'K13 Legacy small-team upper limit',
            'benchmarks.legacy_mid_user_limit' => 'K14 Legacy mid-market upper limit',
            'benchmarks.legacy_enterprise_anchor_users' => 'Legacy enterprise benchmark user anchor',
            'pricing.commitment_months' => 'K15 Tier commitment (months)',
            'pricing.networked_monthly_price' => 'Networked monthly price',
            'roi.annual_periods' => 'Annual periods',
            'roi.monthly_price' => 'Eponymos monthly price',
            'pricing.monthly_price_per_user' => 'Eponymos monthly price per user',
                        'software_categories.pr_software' => 'PR Software monthly value',
                        'software_categories.social_media_tools' => 'Social Media Tools monthly value',
                        'software_categories.digital_asset_management' => 'Digital Asset Management monthly value',
        ];
        echo '<div class="wrap"><h1>Calculation Methodology</h1><p>Configure calculation assumptions. Productivity inputs, source, and date are included in the final report and can be changed here.</p><form method="post">';
        wp_nonce_field('eponymos_save_methodology', 'eponymos_methodology_nonce');
        foreach ($fields as $path => $label) {
            $value = esc_attr((string)$methodology->get($path, ''));
            $type = strpos($path, 'source_url') !== false ? 'url' : (strpos($path, 'date_checked') !== false || strpos($path, 'source_date') !== false ? 'date' : (is_numeric($methodology->get($path)) || strpos($path, '_annual') !== false ? 'number' : 'text'));
            $step = $type === 'number' ? ' step="any"' : '';
            echo '<p><label><strong>' . esc_html($label) . '</strong><br><input class="regular-text" type="' . esc_attr($type) . '"' . $step . ' name="methodology[' . esc_attr(str_replace('.', '][', $path)) . ']" value="' . $value . '"></label></p>';
        }
        echo '<h2>Competitor tool costs</h2><table class="widefat striped"><thead><tr><th>Tool</th><th>Monthly value</th><th>Source URL</th><th>Date checked</th></tr></thead><tbody>';
        foreach ((array) $methodology->get('competitor_costs', []) as $key => $cost) {
            echo '<tr><td>' . esc_html(ucwords(str_replace('_', ' ', $key))) . '</td>';
            foreach (['value', 'source_url', 'date_checked'] as $field) {
                $type = $field === 'value' ? 'number' : ($field === 'source_url' ? 'url' : 'date');
                $step = $field === 'value' ? ' step="0.01" min="0"' : '';
                echo '<td><input class="regular-text" type="' . esc_attr($type) . '"' . $step . ' name="methodology[competitor_costs][' . esc_attr($key) . '][' . esc_attr($field) . ']" value="' . esc_attr((string) ($cost[$field] ?? '')) . '"></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        submit_button('Save methodology'); echo '</form></div>';
    }

    private function sanitize(array $value): array
    {
        foreach ($value as $key => &$item) {
            if (is_array($item)) $item = $this->sanitize($item);
            elseif ($key === 'source_url') $item = esc_url_raw($item);
            elseif (strpos((string)$key, 'key') !== false || strpos((string)$key, 'mapping') !== false) $item = sanitize_key($item);
            else $item = is_numeric($item) ? (float)$item : sanitize_text_field($item);
        }
        return $value;
    }
}
