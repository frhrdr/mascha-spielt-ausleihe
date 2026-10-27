<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Background job, hourly through WP-Cron. On the live site WP-Cron only runs
 * when cron-job.org calls wp-cron.php (DISABLE_WP_CRON is true there), so a
 * run can be late, missing or doubled. Every step therefore works from the
 * current state and claims each email in the database before sending it.
 *
 * - expire holds that ran out (any time of day)
 * - reminders (§5.4): reminder_days_before_due before the due date, on the
 *   due date, then every overdue_reminder_days while overdue
 * - weekly overdue summary for admins, on Mondays
 *
 * Emails go out only between mail_from_hour and mail_until_hour (local
 * time), except when an admin presses "Run now".
 */
final class MAUS_Jobs {

    public const HOOK = 'maus_jobs';
    private const LOCK = 'maus_jobs_lock';
    public const LAST_RUN = 'maus_jobs_last_run';

    public static function boot(): void {
        add_action(self::HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'schedule']);
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** @return array what was done, for the admin "Run now" button */
    public static function run(bool $any_time = false): array {
        // One run at a time; a lock older than 10 minutes is from a crashed run.
        if (!add_option(self::LOCK, time(), '', false)) {
            if ((int) get_option(self::LOCK) > time() - 10 * MINUTE_IN_SECONDS) {
                return ['skipped' => 'another run is in progress'];
            }
            update_option(self::LOCK, time(), false);
        }
        try {
            MAUS_Reservations::expire_holds();
            $done = ['reminders' => 0, 'digest' => false];
            if ($any_time || self::mail_hours()) {
                $done['reminders'] = self::reminders();
                $done['digest'] = self::digest();
            }
            update_option(self::LAST_RUN, time(), false);
            return $done;
        } finally {
            delete_option(self::LOCK);
        }
    }

    public static function mail_hours(): bool {
        $hour = (int) wp_date('G');
        return $hour >= MAUS_Settings::get('mail_from_hour') && $hour < MAUS_Settings::get('mail_until_hour');
    }

    /** @return int emails sent */
    private static function reminders(): int {
        global $wpdb;
        $t = MAUS_DB::table('loans');
        $now = time();
        $now_sql = gmdate('Y-m-d H:i:s', $now);
        // End of today in local time, as UTC: "due today" means due before then.
        $end_of_today = (new DateTime('today 23:59:59', wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $before = gmdate('Y-m-d H:i:s', $now + MAUS_Settings::get('reminder_days_before_due') * DAY_IN_SECONDS);
        $every = gmdate('Y-m-d H:i:s', $now - MAUS_Settings::get('overdue_reminder_days') * DAY_IN_SECONDS);

        $kinds = [
            // A week ahead (renewing opens now). Skipped if it is due today already.
            'before'  => ['reminded_before_at', $wpdb->prepare('reminded_before_at IS NULL AND reminded_due_at IS NULL AND due_at > %s AND due_at <= %s', $end_of_today, $before)],
            // On the due date; also the first overdue mail if runs were missed.
            'due'     => ['reminded_due_at', $wpdb->prepare('reminded_due_at IS NULL AND due_at <= %s', $end_of_today)],
            // Every overdue_reminder_days after the due-date mail.
            'overdue' => ['reminded_overdue_at', $wpdb->prepare('reminded_due_at IS NOT NULL AND due_at < %s AND COALESCE(reminded_overdue_at, reminded_due_at) <= %s', $now_sql, $every)],
        ];
        $sent = 0;
        foreach ($kinds as $kind => [$column, $where]) {
            $ids = $wpdb->get_col("SELECT id FROM $t WHERE returned_at IS NULL AND $where");
            foreach ($ids as $id) {
                // Claim first, so a parallel or repeated run cannot send the same mail again.
                $claimed = $wpdb->query($wpdb->prepare(
                    "UPDATE $t SET $column = %s WHERE id = %d AND returned_at IS NULL AND $where", $now_sql, $id
                ));
                if ($claimed !== 1) {
                    continue;
                }
                $loan = MAUS_Loans::find((int) $id);
                $member = $loan ? MAUS_Members::find((int) $loan->member_id) : null;
                if ($member && (int) $member->active && MAUS_Mail::reminder($member, $loan, $kind)) {
                    $sent++;
                }
            }
        }
        return $sent;
    }

    /** Overdue loans to the admins once a week, on Monday. @return bool sent */
    private static function digest(): bool {
        global $wpdb;
        $week = wp_date('o-W');
        if ((int) wp_date('N') !== 1 || get_option('maus_digest_week') === $week) {
            return false;
        }
        update_option('maus_digest_week', $week, false);
        $loans = $wpdb->get_results($wpdb->prepare(
            'SELECT l.*, m.name AS member_name, c.qr_code, c.label, g.name
             FROM ' . MAUS_DB::table('loans') . ' l
             JOIN ' . MAUS_DB::table('members') . ' m ON m.id = l.member_id
             JOIN ' . MAUS_DB::table('copies') . ' c ON c.id = l.copy_id
             JOIN ' . MAUS_DB::table('games') . ' g ON g.id = c.game_id
             WHERE l.returned_at IS NULL AND l.due_at < %s ORDER BY l.due_at',
            gmdate('Y-m-d H:i:s')
        ));
        if (!$loans) {
            return false;
        }
        MAUS_Mail::overdue_digest($loans);
        return true;
    }
}
