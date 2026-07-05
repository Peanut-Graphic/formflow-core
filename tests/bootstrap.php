<?php
// The extracted core retains WP's ABSPATH guard + calls a few WP hook fns.
// Stub the minimum so the pure register/resolve logic is testable without WP.
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('add_action'))   { function add_action(...$a) { return true; } }
if (!function_exists('do_action'))    { function do_action(...$a) { return null; } }
if (!function_exists('add_filter'))   { function add_filter(...$a) { return true; } }
if (!function_exists('apply_filters')){ function apply_filters($tag, $value, ...$rest) { return $value; } }
if (!function_exists('__'))           { function __($t, $d = null) { return $t; } }
if (!function_exists('esc_html__'))   { function esc_html__($t, $d = null) { return $t; } }
require __DIR__ . '/../vendor/autoload.php';
