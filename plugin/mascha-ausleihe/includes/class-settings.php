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
        'reminder_days_before_due'  => 3,
        'overdue_reminder_days'     => 7,
    ];

    /** @return int */
    public static function get(string $key) {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException("Unknown setting: $key");
        }
        return self::DEFAULTS[$key];
    }
}
