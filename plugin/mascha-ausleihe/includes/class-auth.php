<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Magic-link login for members (no passwords).
 *
 * - A login link holds a random token; only its SHA-256 hash is stored, it
 *   works once and expires after MAUS_Settings login_link_minutes.
 * - Opening the link only shows a confirm button. Signing in needs a POST, so
 *   mail scanners that fetch links cannot use up or trigger a login.
 * - A session is a random cookie token, stored hashed, HttpOnly, SameSite=Lax.
 */
final class MAUS_Auth {

    public const COOKIE = 'maus_session';

    private static ?object $member = null;
    private static bool $resolved = false;

    /* ---------------------------- login links ---------------------------- */

    /** Always behaves the same for unknown, inactive and valid addresses. */
    /** @return string 'sent' or 'limited' */
    public static function request_link(string $email): string {
        $email = strtolower(trim($email));
        if (!is_email($email) || !self::rate_ok($email)) {
            return is_email($email) ? 'limited' : 'sent';
        }
        self::cleanup();

        $member = MAUS_Members::find_by_email($email);
        if ($member && (int) $member->active === 1) {
            $token = bin2hex(random_bytes(32));
            global $wpdb;
            $wpdb->insert(MAUS_DB::table('login_tokens'), [
                'token_hash' => hash('sha256', $token),
                'member_id'  => $member->id,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + MAUS_Settings::get('login_link_minutes') * 60),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $url = home_url('/ausleihe/login/confirm') . '?token=' . $token;
            if (!MAUS_Mail::send_login_link($member, $url)) {
                error_log('maus: login mail could not be sent to member ' . $member->id);
            }
        }
        return 'sent';
    }

    /** True if the token exists, is unused and not expired. Does not consume it. */
    public static function token_valid(string $token): bool {
        return self::token_row($token) !== null;
    }

    /** Consumes the token and starts a session. Returns false for a bad token. */
    public static function sign_in(string $token): bool {
        global $wpdb;
        $row = self::token_row($token);
        if (!$row) {
            return false;
        }
        // Atomic: only one request can claim the token.
        $claimed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . MAUS_DB::table('login_tokens') . ' SET used_at = %s WHERE id = %d AND used_at IS NULL',
            gmdate('Y-m-d H:i:s'), $row->id
        ));
        if ($claimed !== 1) {
            return false;
        }
        self::start_session((int) $row->member_id);
        return true;
    }

    /* ------------------------------ sessions ----------------------------- */

    public static function current_member(): ?object {
        global $wpdb;
        if (self::$resolved) {
            return self::$member;
        }
        self::$resolved = true;
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($raw) || !preg_match('/^[0-9a-f]{64}$/', $raw)) {
            return null;
        }
        $member = $wpdb->get_row($wpdb->prepare(
            'SELECT m.* FROM ' . MAUS_DB::table('sessions') . ' s
             JOIN ' . MAUS_DB::table('members') . ' m ON m.id = s.member_id
             WHERE s.token_hash = %s AND s.expires_at > %s AND m.active = 1',
            hash('sha256', $raw), gmdate('Y-m-d H:i:s')
        ));
        return self::$member = $member ?: null;
    }

    public static function sign_out(): void {
        global $wpdb;
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($raw) && preg_match('/^[0-9a-f]{64}$/', $raw)) {
            $wpdb->delete(MAUS_DB::table('sessions'), ['token_hash' => hash('sha256', $raw)]);
        }
        self::set_cookie('', time() - 3600);
        self::$member = null;
    }

    /** Token for state-changing forms of a signed-in member. */
    public static function csrf_token(): string {
        return hash_hmac('sha256', 'maus-csrf', (string) ($_COOKIE[self::COOKIE] ?? '') . wp_salt('auth'));
    }

    public static function csrf_ok(string $given): bool {
        return self::current_member() !== null && hash_equals(self::csrf_token(), $given);
    }

    /* ------------------------------ internals ---------------------------- */

    private static function start_session(int $member_id): void {
        global $wpdb;
        $raw = bin2hex(random_bytes(32));
        $expires = time() + MAUS_Settings::get('session_days') * DAY_IN_SECONDS;
        $wpdb->insert(MAUS_DB::table('sessions'), [
            'token_hash' => hash('sha256', $raw),
            'member_id'  => $member_id,
            'expires_at' => gmdate('Y-m-d H:i:s', $expires),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        self::set_cookie($raw, $expires);
        $_COOKIE[self::COOKIE] = $raw;
        self::$resolved = false;
    }

    private static function set_cookie(string $value, int $expires): void {
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function token_row(string $token): ?object {
        global $wpdb;
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            'SELECT t.* FROM ' . MAUS_DB::table('login_tokens') . ' t
             JOIN ' . MAUS_DB::table('members') . ' m ON m.id = t.member_id
             WHERE t.token_hash = %s AND t.used_at IS NULL AND t.expires_at > %s AND m.active = 1',
            hash('sha256', $token), gmdate('Y-m-d H:i:s')
        )) ?: null;
    }

    /** At most 3 links per address and 10 per IP in 15 minutes. */
    private static function rate_ok(string $email): bool {
        $keys = [
            'maus_rl_m_' . md5($email)                         => 3,
            'maus_rl_i_' . md5($_SERVER['REMOTE_ADDR'] ?? '') => 10,
        ];
        foreach ($keys as $key => $max) {
            if ((int) get_transient($key) >= $max) {
                return false;
            }
        }
        foreach ($keys as $key => $max) {
            set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
        }
        return true;
    }

    private static function cleanup(): void {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query($wpdb->prepare('DELETE FROM ' . MAUS_DB::table('login_tokens') . ' WHERE expires_at < %s', $now));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . MAUS_DB::table('sessions') . ' WHERE expires_at < %s', $now));
    }
}
