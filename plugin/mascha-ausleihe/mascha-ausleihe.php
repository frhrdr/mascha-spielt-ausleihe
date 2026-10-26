<?php
/**
 * Plugin Name: Mascha spielt – Ausleihe
 * Description: Lending library for the club's board games: catalog, copies, QR codes, loans.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * Text Domain: mascha-ausleihe
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

const MAUS_VERSION = '0.1.0';
const MAUS_FILE    = __FILE__;
const MAUS_DIR     = __DIR__;

foreach (['settings', 'db', 'copy-id', 'csv-import', 'copies', 'loans', 'stickers', 'members', 'i18n', 'mail', 'auth', 'front', 'admin', 'plugin'] as $maus_file) {
    require_once MAUS_DIR . "/includes/class-$maus_file.php";
}

register_activation_hook(__FILE__, ['MAUS_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['MAUS_Plugin', 'deactivate']);

add_action('plugins_loaded', ['MAUS_Plugin', 'boot']);
