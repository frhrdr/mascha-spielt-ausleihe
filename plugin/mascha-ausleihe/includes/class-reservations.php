<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reservations / waiting list per game (design §5.4, D5). Any copy will do.
 *
 * waiting   → in the queue
 * held      → a returned copy (held_copy_id) is kept for the member until hold_until;
 *             only they (or an admin for them) can borrow it
 * fulfilled → the member borrowed a copy of the game
 * expired   → the hold ran out; the copy goes to the next in the queue
 * cancelled → the member or an admin withdrew it
 *
 * Methods that change state return true or an error key ('err_<key>').
 */
final class MAUS_Reservations {

    private const ACTIVE = "('waiting','held')";

    /** The member's waiting or held reservation for the game. */
    public static function active_for(int $game_id, int $member_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . MAUS_DB::table('reservations') . ' WHERE game_id = %d AND member_id = %d AND status IN ' . self::ACTIVE,
            $game_id, $member_id
        )) ?: null;
    }

    /** The hold on this copy that has not run out, with the member's name. */
    public static function hold_on(int $copy_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT r.*, m.name AS member_name FROM ' . MAUS_DB::table('reservations') . ' r
             JOIN ' . MAUS_DB::table('members') . " m ON m.id = r.member_id
             WHERE r.held_copy_id = %d AND r.status = 'held' AND r.hold_until > %s",
            $copy_id, gmdate('Y-m-d H:i:s')
        )) ?: null;
    }

    /** Waiting reservations for the game, first in line first, with member names. */
    public static function queue(int $game_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT r.*, m.name AS member_name FROM ' . MAUS_DB::table('reservations') . ' r
             JOIN ' . MAUS_DB::table('members') . " m ON m.id = r.member_id
             WHERE r.game_id = %d AND r.status = 'waiting' ORDER BY r.created_at, r.id",
            $game_id
        ));
    }

    /** 1-based place in the queue of a waiting reservation. */
    public static function position(object $reservation): int {
        global $wpdb;
        return 1 + (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . MAUS_DB::table('reservations') . "
             WHERE game_id = %d AND status = 'waiting' AND (created_at < %s OR (created_at = %s AND id < %d))",
            $reservation->game_id, $reservation->created_at, $reservation->created_at, $reservation->id
        ));
    }

    /** Somebody is waiting for the game, so loans of it cannot be renewed (§5.4). */
    public static function has_waiting(int $game_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . MAUS_DB::table('reservations') . " WHERE game_id = %d AND status = 'waiting' LIMIT 1",
            $game_id
        ));
    }

    /** Error key if a hold on the copy keeps $member_id from borrowing it, else null. */
    public static function blocks(object $copy, int $member_id): ?string {
        $hold = self::hold_on((int) $copy->id);
        return $hold && (int) $hold->member_id !== $member_id ? 'reserved' : null;
    }

    /**
     * Copies of the game that could be borrowed right now: lendable, not
     * retired, not on loan and not held for anyone.
     */
    public static function available_copies(int $game_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT c.* FROM ' . MAUS_DB::table('copies') . ' c
             WHERE c.game_id = %d AND c.retired = 0 AND c.lendable = 1
               AND NOT EXISTS (SELECT 1 FROM ' . MAUS_DB::table('loans') . ' l WHERE l.copy_id = c.id AND l.returned_at IS NULL)
               AND NOT EXISTS (SELECT 1 FROM ' . MAUS_DB::table('reservations') . " r WHERE r.held_copy_id = c.id AND r.status = 'held' AND r.hold_until > %s)",
            $game_id, gmdate('Y-m-d H:i:s')
        ));
    }

    /** Whether the member may reserve the game now; null means yes, else the error key. */
    public static function cannot_reserve(object $copy, object $member): ?string {
        global $wpdb;
        if ($copy->retired || !$copy->lendable) {
            return 'not_lendable';
        }
        if (self::active_for((int) $copy->game_id, (int) $member->id)) {
            return 'already_reserved';
        }
        $borrowing = $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . MAUS_DB::table('loans') . ' l JOIN ' . MAUS_DB::table('copies') . ' c ON c.id = l.copy_id
             WHERE c.game_id = %d AND l.member_id = %d AND l.returned_at IS NULL LIMIT 1',
            $copy->game_id, $member->id
        ));
        if ($borrowing) {
            return 'already_borrowing';
        }
        return self::available_copies((int) $copy->game_id) ? 'available_now' : null;
    }

    /** @return true|string */
    public static function reserve(object $copy, object $member) {
        global $wpdb;
        $why = self::cannot_reserve($copy, $member);
        if ($why) {
            return $why;
        }
        $ok = $wpdb->insert(MAUS_DB::table('reservations'), [
            'game_id'    => $copy->game_id,
            'member_id'  => $member->id,
            'status'     => 'waiting',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $ok ? true : 'failed';
    }

    /** The member withdraws their own, or an admin any. A held copy goes to the next in line. @return true|string */
    public static function cancel(int $reservation_id, object $by) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . MAUS_DB::table('reservations') . ' WHERE id = %d', $reservation_id));
        if (!$r || ((int) $r->member_id !== (int) $by->id && $by->role !== 'admin')) {
            return 'not_allowed';
        }
        if (!self::close($r, 'cancelled')) {
            return 'reservation_gone';
        }
        if ($r->status === 'held' && $r->held_copy_id) {
            self::assign_copy((int) $r->held_copy_id);
        }
        return true;
    }

    /**
     * Call when a copy may have become free (returned, loan cancelled, hold
     * ended). Holds it for the first in the queue and emails them.
     * Returns the new hold, or null.
     */
    public static function assign_copy(int $copy_id): ?object {
        global $wpdb;
        $copy = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . MAUS_DB::table('copies') . ' WHERE id = %d', $copy_id));
        if (!$copy || $copy->retired || !$copy->lendable || MAUS_Copies::open_loan($copy_id) || self::hold_on($copy_id)) {
            return null;
        }
        foreach (self::queue((int) $copy->game_id) as $next) {
            $now = time();
            // Claim atomically, so two runs cannot hold the same reservation twice.
            $claimed = $wpdb->query($wpdb->prepare(
                'UPDATE ' . MAUS_DB::table('reservations') . " SET status = 'held', held_copy_id = %d, hold_until = %s, notified_at = %s
                 WHERE id = %d AND status = 'waiting'",
                $copy_id, gmdate('Y-m-d H:i:s', $now + MAUS_Settings::get('reservation_hold_days') * DAY_IN_SECONDS), gmdate('Y-m-d H:i:s', $now), $next->id
            ));
            if ($claimed === 1) {
                $hold = self::hold_on($copy_id);
                $member = MAUS_Members::find((int) $next->member_id);
                if ($hold && $member) {
                    MAUS_Mail::hold_ready($member, MAUS_Copies::find_by_code($copy->qr_code), $hold);
                }
                return $hold;
            }
        }
        return null;
    }

    /**
     * After a loan was created: the borrower's reservation for the game is
     * fulfilled. If it held a different copy, that copy goes to the next in line.
     */
    public static function on_borrow(object $copy, int $member_id): void {
        $r = self::active_for((int) $copy->game_id, $member_id);
        if (!$r || !self::close($r, 'fulfilled')) {
            return;
        }
        if ($r->status === 'held' && $r->held_copy_id && (int) $r->held_copy_id !== (int) $copy->id) {
            self::assign_copy((int) $r->held_copy_id);
        }
    }

    /** Background job: holds that ran out expire and their copies move on. Tolerates late and double runs. */
    public static function expire_holds(): void {
        global $wpdb;
        $due = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . MAUS_DB::table('reservations') . " WHERE status = 'held' AND hold_until <= %s",
            gmdate('Y-m-d H:i:s')
        ));
        foreach ($due as $r) {
            if (!self::close($r, 'expired')) {
                continue; // another run got it
            }
            $member = MAUS_Members::find((int) $r->member_id);
            $game = $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . MAUS_DB::table('games') . ' WHERE id = %d', $r->game_id));
            if ($member && (int) $member->active) {
                MAUS_Mail::hold_expired($member, (string) $game);
            }
            self::assign_copy((int) $r->held_copy_id);
        }
    }

    /** The member's waiting and held reservations with game name and held copy code. */
    public static function for_member(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT r.*, g.name, c.qr_code AS held_code, c.label AS held_label FROM ' . MAUS_DB::table('reservations') . ' r
             JOIN ' . MAUS_DB::table('games') . ' g ON g.id = r.game_id
             LEFT JOIN ' . MAUS_DB::table('copies') . ' c ON c.id = r.held_copy_id
             WHERE r.member_id = %d AND r.status IN ' . self::ACTIVE . ' ORDER BY r.status = \'held\' DESC, r.created_at',
            $member_id
        ));
    }

    /** Moves an active reservation to a final status; false if it was not active any more. */
    private static function close(object $r, string $status): bool {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            'UPDATE ' . MAUS_DB::table('reservations') . ' SET status = %s, closed_at = %s WHERE id = %d AND status = %s',
            $status, gmdate('Y-m-d H:i:s'), $r->id, $r->status
        )) === 1;
    }
}
