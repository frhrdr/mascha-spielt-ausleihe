<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loan rules (design §5.4, D5, D9). One central place; only the developer
 * changes these, there is no admin UI for them.
 */
final class MAUS_Settings {

    private const DEFAULTS = [
        'loan_period_days'          => 28,
        'max_renewals'              => 2,
        'max_concurrent_loans'      => 10,
        'reservation_hold_days'     => 7,
        // The reminder goes out this many days before the due date, and renewing opens at the same time.
        'reminder_days_before_due'  => 7,
        'overdue_reminder_days'     => 7,
        // Member emails from the background job only go out between these hours (local time).
        'mail_from_hour'            => 8,
        'mail_until_hour'           => 20,
        'login_link_minutes'        => 15,
        'session_days'              => 30,
        // The sign-in link in the welcome email; longer than login_link_minutes, as it may sit unread.
        'welcome_link_days'         => 7,
        // Printed into the QR codes. Always the live site, never the local dev URL.
        'qr_base_url'               => 'https://machbarschaft-suedstadt.de',
    ];

    /** @return int|string */
    public static function get(string $key) {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException("Unknown setting: $key");
        }
        return self::DEFAULTS[$key];
    }
}
