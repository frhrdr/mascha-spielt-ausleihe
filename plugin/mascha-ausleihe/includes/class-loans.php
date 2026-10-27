<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Borrow, renew and return by scan (design §5.2, §5.4). Every method returns
 * true or an error key that MAUS_I18n can show as 'err_<key>'.
 *
 * Reservations (MAUS_Reservations): a held copy can only be borrowed by the
 * member it is held for, nobody can renew while someone waits for the game,
 * and a copy that becomes free goes to the first in the queue.
 */
final class MAUS_Loans {

    /** Trusted members and admins borrow by scan; new members borrow through an admin (§4). */
    public static function can_self_serve(object $member): bool {
        return in_array($member->role, ['trusted', 'admin'], true);
    }

    /** @return true|string */
    public static function borrow(object $copy, object $member) {
        global $wpdb;
        if (!self::can_self_serve($member)) {
            return 'not_allowed';
        }
        if ($copy->retired || !$copy->lendable) {
            return 'not_lendable';
        }
        if (count(self::open_for_member((int) $member->id)) >= MAUS_Settings::get('max_concurrent_loans')) {
            return 'limit';
        }
        return self::create($copy, $member, $member);
    }

    /**
     * Desk flow (§5.3): an admin borrows for any active member, also a new
     * one. The loan limit does not apply; admins may override it (§4), and
     * the page shows each member's current count. @return true|string
     */
    public static function borrow_for(object $copy, int $member_id, object $admin) {
        if ($admin->role !== 'admin') {
            return 'not_allowed';
        }
        $member = MAUS_Members::find($member_id);
        if (!$member || !(int) $member->active) {
            return 'no_member';
        }
        if ($copy->retired || !$copy->lendable) {
            return 'not_lendable';
        }
        return self::create($copy, $member, $admin);
    }

    /** @return true|string */
    private static function create(object $copy, object $member, object $by) {
        global $wpdb;
        $blocked = MAUS_Reservations::blocks($copy, (int) $member->id);
        if ($blocked) {
            return $blocked;
        }
        // Lock the copy row so two people tapping at once cannot both borrow it.
        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare('SELECT id FROM ' . MAUS_DB::table('copies') . ' WHERE id = %d FOR UPDATE', $copy->id));
        if (MAUS_Copies::open_loan((int) $copy->id)) {
            $wpdb->query('ROLLBACK');
            return 'taken';
        }
        $now = time();
        $ok = $wpdb->insert(MAUS_DB::table('loans'), [
            'copy_id'        => $copy->id,
            'member_id'      => $member->id,
            'checked_out_at' => gmdate('Y-m-d H:i:s', $now),
            'checked_out_by' => $by->id,
            'due_at'         => gmdate('Y-m-d H:i:s', $now + MAUS_Settings::get('loan_period_days') * DAY_IN_SECONDS),
        ]);
        $wpdb->query($ok ? 'COMMIT' : 'ROLLBACK');
        if (!$ok) {
            return 'failed';
        }
        MAUS_Reservations::on_borrow($copy, (int) $member->id);
        return true;
    }

    /**
     * Admin correction: another borrower and/or due date (local date, due at
     * the end of that day). @return true|string
     */
    public static function correct(object $loan, object $admin, int $member_id, string $due_date) {
        global $wpdb;
        if ($admin->role !== 'admin') {
            return 'not_allowed';
        }
        $member = MAUS_Members::find($member_id);
        if (!$member || !(int) $member->active) {
            return 'no_member';
        }
        $due = DateTime::createFromFormat('!Y-m-d', $due_date, wp_timezone());
        if (!$due || $due->format('Y-m-d') !== $due_date) {
            return 'bad_date';
        }
        $due->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'));
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . MAUS_DB::table('loans') . ' SET member_id = %d, due_at = %s, reminded_before_at = NULL, reminded_due_at = NULL, reminded_overdue_at = NULL WHERE id = %d AND returned_at IS NULL',
            $member->id, $due->format('Y-m-d H:i:s'), $loan->id
        ));
        return $updated === false ? 'failed' : true; // 0 rows is fine: nothing changed
    }

    /**
     * Admin correction: a loan that was recorded by mistake is deleted, not
     * returned, so it does not count in the history. @return true|string
     */
    public static function cancel(object $loan, object $admin) {
        global $wpdb;
        if ($admin->role !== 'admin') {
            return 'not_allowed';
        }
        $deleted = $wpdb->delete(MAUS_DB::table('loans'), ['id' => $loan->id, 'returned_at' => null]);
        if ($deleted !== 1) {
            return 'already_returned';
        }
        MAUS_Reservations::assign_copy((int) $loan->copy_id);
        return true;
    }

    /** Renewing opens reminder_days_before_due days before the due date (also when overdue). */
    public static function renew_opens_at(object $loan): int {
        return strtotime($loan->due_at . ' UTC') - MAUS_Settings::get('reminder_days_before_due') * DAY_IN_SECONDS;
    }

    /**
     * One more loan period, counted from the due date (from today when
     * overdue), up to max_renewals times. @return true|string
     */
    public static function renew(object $loan, object $member) {
        global $wpdb;
        if ((int) $loan->member_id !== (int) $member->id) {
            return 'not_allowed';
        }
        if ((int) $loan->extended_count >= MAUS_Settings::get('max_renewals')) {
            return 'no_renewals';
        }
        if (time() < self::renew_opens_at($loan)) {
            return 'too_early';
        }
        if (MAUS_Reservations::has_waiting((int) $loan->game_id)) {
            return 'reserved_renew';
        }
        $from = max(time(), strtotime($loan->due_at . ' UTC'));
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . MAUS_DB::table('loans') . ' SET due_at = %s, extended_count = extended_count + 1, reminded_before_at = NULL, reminded_due_at = NULL, reminded_overdue_at = NULL
             WHERE id = %d AND returned_at IS NULL AND extended_count = %d',
            gmdate('Y-m-d H:i:s', $from + MAUS_Settings::get('loan_period_days') * DAY_IN_SECONDS),
            $loan->id, $loan->extended_count
        ));
        return $updated === 1 ? true : 'failed';
    }

    /**
     * Closes a loan. The borrower returns their own; any trusted member or
     * admin may close someone else's loan when they find the game on the
     * shelf, which is recorded and reported to the admins. An admin doing it
     * is the desk flow and needs no report. @return true|string
     */
    public static function return_loan(object $loan, object $member) {
        global $wpdb;
        $own = (int) $loan->member_id === (int) $member->id;
        if (!$own && !self::can_self_serve($member)) {
            return 'not_allowed';
        }
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . MAUS_DB::table('loans') . ' SET returned_at = %s, returned_by = %d WHERE id = %d AND returned_at IS NULL',
            gmdate('Y-m-d H:i:s'), $member->id, $loan->id
        ));
        if ($updated !== 1) {
            return 'already_returned';
        }
        if (!$own && $member->role !== 'admin') {
            MAUS_Mail::found_on_shelf(self::find((int) $loan->id), $member);
        }
        MAUS_Reservations::assign_copy((int) $loan->copy_id);
        return true;
    }

    /** The completeness answer is open for the person who returned it, for one hour. */
    public static function awaiting_answer(int $loan_id, object $member): ?object {
        $loan = self::find($loan_id);
        if (!$loan || $loan->returned_at === null || $loan->complete !== null
            || (int) $loan->returned_by !== (int) $member->id
            || strtotime($loan->returned_at . ' UTC') < time() - HOUR_IN_SECONDS) {
            return null;
        }
        return $loan;
    }

    public static function mark_complete(object $loan): void {
        global $wpdb;
        $wpdb->update(MAUS_DB::table('loans'), ['complete' => 1], ['id' => $loan->id]);
    }

    /**
     * Report of missing parts or damage. With $loan (the completeness answer
     * "no") it also marks that return as incomplete. @return true|string
     */
    public static function report_issue(object $copy, object $member, string $text, ?object $loan = null) {
        global $wpdb;
        $text = trim($text);
        if ($text === '') {
            return 'empty_report';
        }
        $ok = $wpdb->insert(MAUS_DB::table('issues'), [
            'copy_id'     => $copy->id,
            'reported_by' => $member->id,
            'reported_at' => gmdate('Y-m-d H:i:s'),
            'text'        => mb_substr($text, 0, 2000),
        ]);
        if (!$ok) {
            return 'failed';
        }
        if ($loan) {
            $wpdb->update(MAUS_DB::table('loans'), ['complete' => 0], ['id' => $loan->id]);
        }
        MAUS_Mail::issue_reported($copy, $member, $text);
        return true;
    }

    /** Open loans of a member with copy and game, soonest due first. */
    public static function open_for_member(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT l.*, c.qr_code, c.label, g.name
             FROM ' . MAUS_DB::table('loans') . ' l
             JOIN ' . MAUS_DB::table('copies') . ' c ON c.id = l.copy_id
             JOIN ' . MAUS_DB::table('games') . ' g ON g.id = c.game_id
             WHERE l.member_id = %d AND l.returned_at IS NULL
             ORDER BY l.due_at',
            $member_id
        ));
    }

    /** Loan with borrower name, copy code and game name. */
    public static function find(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT l.*, m.name AS member_name, c.qr_code, c.label, c.game_id, g.name
             FROM ' . MAUS_DB::table('loans') . ' l
             JOIN ' . MAUS_DB::table('members') . ' m ON m.id = l.member_id
             JOIN ' . MAUS_DB::table('copies') . ' c ON c.id = l.copy_id
             JOIN ' . MAUS_DB::table('games') . ' g ON g.id = c.game_id
             WHERE l.id = %d',
            $id
        )) ?: null;
    }
}
