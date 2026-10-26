<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MAUS_Plugin {

    public static function boot(): void {
        MAUS_DB::maybe_install();
        MAUS_Front::boot();
        if (is_admin()) {
            MAUS_Admin::boot();
            MAUS_Stickers::boot();
        }
    }

    public static function activate(): void {
        MAUS_DB::install();
        MAUS_Front::register_rules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
