<?php

namespace Eponymos\ACF;

class Integration
{
    public function register()
    {
        add_action('acf/init', [$this, 'fields']);
    }
    public function fields()
    {
        if (!function_exists('acf_add_local_field_group')) return;
        acf_add_local_field_group(['key' => 'group_eponymos_settings', 'title' => 'Eponymos settings', 'fields' => [['key' => 'field_eponymos_key_edit', 'label' => 'Allow editing system keys', 'name' => 'allow_key_editing', 'type' => 'true_false', 'instructions' => 'Changing keys can affect calculations, historical data and integrations.', 'default_value' => 0]], 'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'eponymos']]]]);
    }
}
