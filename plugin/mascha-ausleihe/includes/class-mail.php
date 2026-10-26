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
}
