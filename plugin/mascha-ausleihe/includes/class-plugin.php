<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MAUS_Plugin {

    public static function boot(): void {
        MAUS_DB::maybe_install();
        if (is_admin()) {
            MAUS_Admin::boot();
        }
    }

    public static function activate(): void {
        MAUS_DB::install();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
