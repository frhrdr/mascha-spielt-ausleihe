<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Reported missing parts or damage. Admin members resolve them on the member pages. */
final class MAUS_Issues {

    /** Open issues, oldest first, optionally of one copy. With copy code, game name and reporter. */
    public static function open(?int $copy_id = null): array {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT i.*, c.qr_code, c.label, g.name, m.name AS reporter
             FROM ' . MAUS_DB::table('issues') . ' i
             JOIN ' . MAUS_DB::table('copies') . ' c ON c.id = i.copy_id
             JOIN ' . MAUS_DB::table('games') . ' g ON g.id = c.game_id
             LEFT JOIN ' . MAUS_DB::table('members') . ' m ON m.id = i.reported_by
             WHERE i.resolved_at IS NULL' . ($copy_id ? $wpdb->prepare(' AND i.copy_id = %d', $copy_id) : '') . '
             ORDER BY i.reported_at'
        );
    }

    public static function count_open(): int {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . MAUS_DB::table('issues') . ' WHERE resolved_at IS NULL');
    }

    /** The resolving admin must say what was done. @return true|string */
    public static function resolve(int $issue_id, object $admin, string $note) {
        global $wpdb;
        if ($admin->role !== 'admin') {
            return 'not_allowed';
        }
        $note = trim($note);
        if ($note === '') {
            return 'empty_note';
        }
        $updated = $wpdb->update(
            MAUS_DB::table('issues'),
            ['resolved_at' => gmdate('Y-m-d H:i:s'), 'resolved_by' => $admin->id, 'resolution_note' => mb_substr($note, 0, 2000)],
            ['id' => $issue_id, 'resolved_at' => null]
        );
        return $updated === 1 ? true : 'already_resolved';
    }
}
