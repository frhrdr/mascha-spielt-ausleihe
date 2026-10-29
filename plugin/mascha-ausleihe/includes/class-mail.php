<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MAUS_Mail {

    public static function send_login_link(object $member, string $url): bool {
        $lang = $member->language;
        return self::send($member, MAUS_I18n::t('mail_subject', $lang),
            MAUS_I18n::t('mail_body', $lang, $member->name, $url, MAUS_Settings::get('login_link_minutes')));
    }

    public static function send_welcome(object $member, string $url): bool {
        $lang = $member->language;
        return self::send($member, MAUS_I18n::t('welcome_subject', $lang),
            MAUS_I18n::t('welcome_body', $lang, $member->name, $url, MAUS_Settings::get('welcome_link_days')));
    }

    public static function feedback(object $from, string $page, string $text): void {
        self::to_admins('mail_feedback_subject', [$from->name], 'mail_feedback_body', [$from->name, $text, $page, admin_url('admin.php?page=maus-feedback')]);
    }

    /** $kind: 'before', 'due' or 'overdue' (MAUS_Jobs). */
    public static function reminder(object $member, object $loan, string $kind): bool {
        $l = $member->language;
        $game = trim($loan->name . ' ' . $loan->label);
        return self::send($member, MAUS_I18n::t('remind_' . $kind . '_subject', $l, $game),
            MAUS_I18n::t('remind_' . $kind . '_body', $l, $member->name, $game, MAUS_Front::date($loan->due_at, $l), home_url('/c/' . $loan->qr_code)));
    }

    /** A returned copy is now held for the member (§5.4). */
    public static function hold_ready(object $member, object $copy, object $hold): bool {
        $l = $member->language;
        $game = trim($copy->name . ' ' . $copy->label);
        return self::send($member, MAUS_I18n::t('hold_subject', $l, $game),
            MAUS_I18n::t($member->role === 'new' ? 'hold_body_new' : 'hold_body', $l, $member->name, $game, MAUS_Front::date($hold->hold_until, $l), home_url('/c/' . $copy->qr_code)));
    }

    public static function hold_expired(object $member, string $game): bool {
        $l = $member->language;
        return self::send($member, MAUS_I18n::t('hold_expired_subject', $l, $game), MAUS_I18n::t('hold_expired_body', $l, $member->name, $game));
    }

    /** Weekly list of overdue loans for every active admin, in their language. */
    public static function overdue_digest(array $loans): void {
        global $wpdb;
        $admins = $wpdb->get_results("SELECT * FROM " . MAUS_DB::table('members') . " WHERE role = 'admin' AND active = 1");
        foreach ($admins as $a) {
            $lines = array_map(fn($loan) => '- ' . trim($loan->name . ' ' . $loan->label) . ' (' . $loan->qr_code . '): '
                . $loan->member_name . ', ' . MAUS_I18n::t('due_overdue', $a->language, MAUS_Front::date($loan->due_at, $a->language)), $loans);
            self::send($a, MAUS_I18n::t('digest_subject', $a->language, count($loans)),
                MAUS_I18n::t('digest_body', $a->language, $a->name, implode("\n", $lines), admin_url('admin.php?page=maus-loans&filter=overdue')));
        }
    }

    /** Every email goes through here; in trial mode it is marked as a test. */
    private static function send(object $member, string $subject, string $body): bool {
        if (MAUS_Settings::get('trial_mode')) {
            $subject = '[Test] ' . $subject;
            $body = MAUS_I18n::t('trial_mail_note', $member->language) . "\n\n" . $body;
        }
        $ok = wp_mail($member->email, $subject, $body);
        if (!$ok) {
            error_log('maus: mail could not be sent to member ' . $member->id);
        }
        return $ok;
    }

    /** Someone closed another member's loan because the game was back on the shelf. */
    public static function found_on_shelf(object $loan, object $finder): void {
        $game = trim($loan->name . ' ' . $loan->label);
        self::to_admins('mail_found_subject', [$game], 'mail_found_body', [
            $game, $loan->qr_code, $finder->name, $loan->member_name, home_url('/c/' . $loan->qr_code),
        ]);
    }

    public static function issue_reported(object $copy, object $reporter, string $text): void {
        $game = trim($copy->name . ' ' . $copy->label);
        self::to_admins('mail_issue_subject', [$game], 'mail_issue_body', [
            $game, $copy->qr_code, $reporter->name, $text, home_url('/c/' . $copy->qr_code),
        ]);
    }

    private static function to_admins(string $subject_key, array $subject_args, string $body_key, array $body_args): void {
        global $wpdb;
        $admins = $wpdb->get_results("SELECT * FROM " . MAUS_DB::table('members') . " WHERE role = 'admin' AND active = 1");
        foreach ($admins as $a) {
            self::send($a, MAUS_I18n::t($subject_key, $a->language, ...$subject_args), MAUS_I18n::t($body_key, $a->language, ...$body_args));
        }
    }
}
