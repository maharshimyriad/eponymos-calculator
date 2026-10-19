<?php

namespace Eponymos\Admin;

/**
 * Registers the plugin's settings on its own Eponymos → Settings page.
 */
class ThemeSettingsIntegration
{
    const PAGE  = 'eponymos-settings';
    const GROUP = 'eponymos_settings_group';
    const SECTION = 'eponymos_plugin_section';

    public function register(): void
    {
        add_action('admin_init', [$this, 'add_section']);
        add_action('admin_menu', [$this, 'menu']);
    }

    public function add_section(): void
    {
        register_setting(self::GROUP, 'eponymos_pricing_config', [
            'sanitize_callback' => [$this, 'sanitize_pricing'],
        ]);
        register_setting(self::GROUP, 'eponymos_allow_key_editing', [
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
        register_setting(self::GROUP, 'eponymos_submission_admin_email', [
            'sanitize_callback' => [$this, 'sanitize_submission_admin_email'],
        ]);

        // Add the plugin settings section.
        add_settings_section(
            self::SECTION,
            __('Questionnaire Plugin', 'eponymos'),
            '__return_empty_string',
            self::PAGE
        );

        // ── Pricing fields ────────────────────────────────────────────────

        add_settings_field(
            'eponymos_price_per_user_day',
            __('Price per active user / day', 'eponymos'),
            [$this, 'field_price_per_user_day'],
            self::PAGE,
            self::SECTION
        );

        add_settings_field(
            'eponymos_usage_days',
            __('Usage period (days)', 'eponymos'),
            [$this, 'field_usage_days'],
            self::PAGE,
            self::SECTION
        );

        add_settings_field(
            'eponymos_user_question_key',
            __('Users question key', 'eponymos'),
            [$this, 'field_user_question_key'],
            self::PAGE,
            self::SECTION
        );

        // ── Advanced field ────────────────────────────────────────────────

        add_settings_field(
            'eponymos_allow_key_editing',
            __('Key editing', 'eponymos'),
            [$this, 'field_allow_key_editing'],
            self::PAGE,
            self::SECTION
        );

        add_settings_field(
            'eponymos_submission_admin_email',
            __('Submission notification email', 'eponymos'),
            [$this, 'field_submission_admin_email'],
            self::PAGE,
            self::SECTION
        );
    }

    public function menu(): void
    {
        add_submenu_page(
            'eponymos',
            __('Settings - Eponymos', 'eponymos'),
            __('Settings', 'eponymos'),
            'manage_options',
            self::PAGE,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'eponymos'));
        }

        echo '<div class="wrap"><h1>' . esc_html__('Eponymos Settings', 'eponymos') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        do_settings_sections(self::PAGE);
        submit_button();
        echo '</form></div>';
    }

    // ── Field renderers ───────────────────────────────────────────────────────

    public function field_price_per_user_day(): void
    {
        $c = $this->pricing_config();
        echo '<input type="number" step="0.01" min="0" name="eponymos_pricing_config[price_per_user_day]" value="' . esc_attr($c['price_per_user_day']) . '" class="regular-text">';
    }

    public function field_usage_days(): void
    {
        $c = $this->pricing_config();
        echo '<input type="number" min="1" name="eponymos_pricing_config[usage_days]" value="' . esc_attr($c['usage_days']) . '" class="small-text">';
    }

    public function field_user_question_key(): void
    {
        $c = $this->pricing_config();
        echo '<input type="text" name="eponymos_pricing_config[user_question_key]" value="' . esc_attr($c['user_question_key']) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('The question key whose resolved value is used as the active-user count for pricing.', 'eponymos') . '</p>';
    }

    public function field_allow_key_editing(): void
    {
        $allow = (bool) get_option('eponymos_allow_key_editing', false);
        echo '<label>';
        echo '<input type="hidden" name="eponymos_allow_key_editing" value="0">';
        echo '<input type="checkbox" name="eponymos_allow_key_editing" value="1"' . checked($allow, true, false) . '>';
        echo ' ' . esc_html__('Allow editing system keys in the builder', 'eponymos');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Changing keys can affect calculations, historical data and integrations.', 'eponymos') . '</p>';
    }

    public function field_submission_admin_email(): void
    {
        $email = get_option('eponymos_submission_admin_email', get_option('admin_email'));
        echo '<textarea name="eponymos_submission_admin_email" rows="4" class="large-text" placeholder="admin@example.com, manager@example.com">' . esc_textarea($email) . '</textarea>';
        echo '<p class="description">' . esc_html__('Completed questionnaire submissions will be sent to these addresses. Separate multiple email addresses with commas or put one address per line.', 'eponymos') . '</p>';
    }

    // ── Sanitizers ────────────────────────────────────────────────────────────

    public function sanitize_pricing($v): array
    {
        $v = is_array($v) ? $v : [];
        return [
            'price_per_user_day' => max(0, (float)($v['price_per_user_day'] ?? 5)),
            'usage_days'         => max(1, (int)($v['usage_days'] ?? 30)),
            'user_question_key'  => sanitize_key($v['user_question_key'] ?? 'users_access'),
        ];
    }

    public function sanitize_submission_admin_email($value): string
    {
        $emails = preg_split('/[,\r\n]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $emails = array_map('trim', $emails ?: []);
        $valid = [];
        foreach ($emails as $email) {
            $email = sanitize_email($email);
            if ($email && is_email($email) && !in_array($email, $valid, true))
                $valid[] = $email;
        }
        return implode(', ', $valid);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function pricing_config(): array
    {
        return get_option('eponymos_pricing_config', [
            'price_per_user_day' => 5,
            'usage_days'         => 30,
            'user_question_key'  => 'users_access',
        ]);
    }

}

