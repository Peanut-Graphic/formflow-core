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

// --- WP HTTP/update stubs for SignedUpdateGate tests -------------------------
// Controlled per-test via $GLOBALS['__wp_http'] (url => body) and
// $GLOBALS['__wp_files'] (url => raw bytes served by download_url()).
if (!class_exists('WP_Error')) {
    class WP_Error {
        public string $code; public string $message;
        public function __construct($code = '', $message = '') { $this->code = (string) $code; $this->message = (string) $message; }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof \WP_Error; } }
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        $map = $GLOBALS['__wp_http'] ?? [];
        return array_key_exists($url, $map) ? ['body' => $map[$url]] : new \WP_Error('http_404', 'not found');
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($r) { return is_array($r) ? ($r['body'] ?? '') : ''; }
}
if (!function_exists('download_url')) {
    function download_url($url, $timeout = 300) {
        $files = $GLOBALS['__wp_files'] ?? [];
        if (!array_key_exists($url, $files)) { return new \WP_Error('download_failed', 'could not download'); }
        $tmp = tempnam(sys_get_temp_dir(), 'ffcore');
        file_put_contents($tmp, $files[$url]);
        return $tmp;
    }
}

require __DIR__ . '/../vendor/autoload.php';
