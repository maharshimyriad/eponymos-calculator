<?php

namespace Eponymos\Support;

class Keys
{
    public static function make($value)
    {
        $value = function_exists('sanitize_title') ? sanitize_title($value) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value));
        return trim(str_replace('-', '_', $value), '_') ?: 'item';
    }
    public static function unique($value, $existing)
    {
        $base = self::make($value);
        $key = $base;
        $n = 2;
        while (in_array($key, $existing, true)) $key = $base . '_' . $n++;
        return $key;
    }
}
