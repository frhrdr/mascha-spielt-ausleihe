<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MAUS_Mail {

    public static function send_login_link(object $member, string $url): bool {
        $lang = $member->language;
        return wp_mail(
            $member->email,
            MAUS_I18n::t('mail_subject', $lang),
            MAUS_I18n::t('mail_body', $lang, $member->name, $url, MAUS_Settings::get('login_link_minutes'))
        );
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
            $ok = wp_mail(
                $a->email,
                MAUS_I18n::t($subject_key, $a->language, ...$subject_args),
                MAUS_I18n::t($body_key, $a->language, ...$body_args)
            );
            if (!$ok) {
                error_log('maus: admin mail could not be sent to member ' . $a->id);
            }
        }
    }
}
