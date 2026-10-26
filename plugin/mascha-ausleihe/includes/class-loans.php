<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Borrow, renew and return by scan (design §5.2, §5.4). Every method returns
 * true or an error key that MAUS_I18n can show as 'err_<key>'.
 *
 * Reservations are not checked yet: nothing creates them so far. Borrow and
 * renew must start checking them when reservations are built.
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
            'checked_out_by' => $member->id,
            'due_at'         => gmdate('Y-m-d H:i:s', $now + MAUS_Settings::get('loan_period_days') * DAY_IN_SECONDS),
        ]);
        $wpdb->query($ok ? 'COMMIT' : 'ROLLBACK');
        return $ok ? true : 'failed';
    }

    /** A new loan period from today, up to max_renewals times. @return true|string */
    public static function renew(object $loan, object $member) {
        global $wpdb;
        if ((int) $loan->member_id !== (int) $member->id) {
            return 'not_allowed';
        }
        if ((int) $loan->extended_count >= MAUS_Settings::get('max_renewals')) {
            return 'no_renewals';
        }
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . MAUS_DB::table('loans') . ' SET due_at = %s, extended_count = extended_count + 1
             WHERE id = %d AND returned_at IS NULL AND extended_count = %d',
            gmdate('Y-m-d H:i:s', time() + MAUS_Settings::get('loan_period_days') * DAY_IN_SECONDS),
            $loan->id, $loan->extended_count
        ));
        return $updated === 1 ? true : 'failed';
    }

    /**
     * Closes a loan. The borrower returns their own; any trusted member or
     * admin may close someone else's loan when they find the game on the
     * shelf, which is recorded and reported to the admins. @return true|string
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
        if (!$own) {
            MAUS_Mail::found_on_shelf(self::find((int) $loan->id), $member);
        }
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
            'SELECT l.*, m.name AS member_name, c.qr_code, c.label, g.name
             FROM ' . MAUS_DB::table('loans') . ' l
             JOIN ' . MAUS_DB::table('members') . ' m ON m.id = l.member_id
             JOIN ' . MAUS_DB::table('copies') . ' c ON c.id = l.copy_id
             JOIN ' . MAUS_DB::table('games') . ' g ON g.id = c.game_id
             WHERE l.id = %d',
            $id
        )) ?: null;
    }
}
