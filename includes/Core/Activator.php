<?php

namespace Eponymos\Core;

use Eponymos\Database\Schema;

class Activator
{
    public static function activate()
    {
        ob_start();
        Schema::install();
        \Eponymos\Calculation\Methodology::load();
        $output = trim((string) ob_get_clean());

        if ($output !== '') {
            \Eponymos\Support\FlowLog::record(
                'activation',
                'warning',
                'plugin_activation',
                'Unexpected output was emitted during plugin activation.',
                ['output' => sanitize_textarea_field($output)]
            );
        }
    }
    public static function deactivate()
    {
        Deactivator::deactivate();
    }
}
