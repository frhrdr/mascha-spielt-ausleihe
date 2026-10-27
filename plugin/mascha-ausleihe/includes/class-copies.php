<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Lookups for physical copies and their loans. */
final class MAUS_Copies {

    /** Copy plus its game's fields, or null. Retired copies are included. */
    public static function find_by_code(string $code): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT c.*, g.name, g.type, g.year, g.min_players, g.max_players, g.playtime, g.image_url, g.bgg_id
             FROM ' . MAUS_DB::table('copies') . ' c
             JOIN ' . MAUS_DB::table('games') . ' g ON g.id = c.game_id
             WHERE c.qr_code = %s',
            $code
        )) ?: null;
    }

    /** The copy's loan that is not returned yet, with the borrower's name and the game id. */
    public static function open_loan(int $copy_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT l.*, m.name AS member_name, c.game_id
             FROM ' . MAUS_DB::table('loans') . ' l
             JOIN ' . MAUS_DB::table('members') . ' m ON m.id = l.member_id
             JOIN ' . MAUS_DB::table('copies') . ' c ON c.id = l.copy_id
             WHERE l.copy_id = %d AND l.returned_at IS NULL
             ORDER BY l.checked_out_at DESC LIMIT 1',
            $copy_id
        )) ?: null;
    }
}
