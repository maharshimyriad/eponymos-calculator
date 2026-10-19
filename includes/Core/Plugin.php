<?php

namespace Eponymos\Core;

use Eponymos\REST\Routes;
use Eponymos\Questionnaire\Shortcode;
use Eponymos\Admin\Admin;

class Plugin
{
    public static function boot()
    {
        if (get_option('eponymos_db_version') !== \Eponymos\Database\Schema::VERSION) \Eponymos\Database\Schema::install();
        \Eponymos\Database\ConfigurationMigration::run();
        if (is_admin() && current_user_can('manage_options')) {
            // Temporary one-time run; remove after verifying the tool options in wp-admin.
            \Eponymos\Database\SoftwareToolPricingMigration::run();
        }
        (new Routes())->register();
        (new Shortcode())->register();
        if (is_admin()) {
            (new Admin())->register();
            (new \Eponymos\Admin\ThemeSettingsIntegration())->register();
        }
        load_plugin_textdomain('eponymos', false, dirname(plugin_basename(EPONYMOS_FILE)) . '/languages');
    }
}

