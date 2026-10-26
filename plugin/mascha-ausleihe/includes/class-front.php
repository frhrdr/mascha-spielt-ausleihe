<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Member-facing pages under /ausleihe/ and the sticker URLs /c/<code>. They
 * bypass the WordPress theme and render a small standalone mobile page.
 */
final class MAUS_Front {

    private const REWRITE_VERSION = '2';

    public static function boot(): void {
        add_action('init', [self::class, 'register_rules']);
        add_action('init', [self::class, 'maybe_flush_rules'], 99);
        add_filter('query_vars', fn($v) => array_merge($v, ['maus_route', 'maus_code']));
        add_action('template_redirect', [self::class, 'dispatch']);
    }

    public static function register_rules(): void {
        add_rewrite_rule('^ausleihe/?$', 'index.php?maus_route=home', 'top');
        add_rewrite_rule('^ausleihe/(.+?)/?$', 'index.php?maus_route=$matches[1]', 'top');
        add_rewrite_rule('^c/([^/]+)/?$', 'index.php?maus_route=copy&maus_code=$matches[1]', 'top');
    }

    public static function maybe_flush_rules(): void {
        if (get_option('maus_rewrite_version') !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('maus_rewrite_version', self::REWRITE_VERSION, false);
        }
    }

    public static function dispatch(): void {
        $route = (string) get_query_var('maus_route');
        if ($route === '') {
            return;
        }
        nocache_headers();
        header('X-Robots-Tag: noindex');
        header('Referrer-Policy: no-referrer'); // login tokens travel in the URL
        $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

        switch ($route) {
            case 'home':
                self::home();
                break;
            case 'copy':
                self::copy_page();
                break;
            case 'login':
                $post ? self::login_post() : self::login_form();
                break;
            case 'login/confirm':
                $post ? self::confirm_post() : self::confirm_form();
                break;
            case 'logout':
                $post ? self::logout() : self::go('/ausleihe');
                break;
            default:
                status_header(404);
                self::page(MAUS_I18n::detect(), '404', '<p>' . esc_html(MAUS_I18n::t('not_found', MAUS_I18n::detect())) . '</p>');
        }
        exit;
    }

    /* ------------------------------- pages ------------------------------- */

    private static function home(): void {
        $m = MAUS_Auth::current_member();
        if (!$m) {
            self::go('/ausleihe/login');
        }
        $l = $m->language;
        $role = MAUS_I18n::t('role_' . $m->role, $l);
        $body = '<p>' . esc_html(MAUS_I18n::t('role_line', $l, $role)) . '</p>'
            . '<p>' . esc_html(MAUS_I18n::t('home_todo', $l)) . '</p>'
            . '<form method="post" action="' . esc_url(home_url('/ausleihe/logout')) . '">'
            . '<input type="hidden" name="csrf" value="' . esc_attr(MAUS_Auth::csrf_token()) . '">'
            . '<button class="secondary">' . esc_html(MAUS_I18n::t('sign_out', $l)) . '</button></form>';
        self::page($l, MAUS_I18n::t('hello', $l, $m->name), $body);
    }

    /** Sticker URL /c/<code>: the copy and its status (design §5.2). */
    private static function copy_page(): void {
        $raw = (string) get_query_var('maus_code');
        $code = MAUS_Copy_ID::parse($raw);
        if ($code === null) {
            $l = MAUS_Auth::current_member()?->language ?? MAUS_I18n::detect();
            status_header(404);
            self::page($l, MAUS_I18n::t('code_invalid_title', $l), '<p>' . esc_html(MAUS_I18n::t('code_invalid_text', $l)) . '</p>' . self::home_link($l));
            return;
        }
        if ($code !== $raw) {
            self::go('/c/' . $code); // typed in lower case or with O/I/L
        }
        $m = MAUS_Auth::current_member();
        if (!$m) {
            self::go('/ausleihe/login?next=' . rawurlencode('/c/' . $code));
        }
        $l = $m->language;

        $copy = MAUS_Copies::find_by_code($code);
        if (!$copy) {
            status_header(404);
            self::page($l, $code, '<p>' . esc_html(MAUS_I18n::t('copy_unknown', $l)) . '</p>' . self::home_link($l));
            return;
        }

        $facts = [];
        if ($copy->type === 'expansion') {
            $facts[] = MAUS_I18n::t('expansion', $l);
        }
        if ($copy->min_players) {
            $players = $copy->min_players . ($copy->max_players && $copy->max_players !== $copy->min_players ? '–' . $copy->max_players : '');
            $facts[] = MAUS_I18n::t('players', $l, $players);
        }
        if ($copy->playtime) {
            $facts[] = MAUS_I18n::t('minutes', $l, $copy->playtime);
        }
        if ($copy->year) {
            $facts[] = $copy->year;
        }

        $loan = $copy->retired ? null : MAUS_Copies::open_loan((int) $copy->id);
        if ($copy->retired) {
            $status = '<p class="warn">' . esc_html(MAUS_I18n::t('copy_retired', $l)) . '</p>';
        } elseif ($loan && (int) $loan->member_id === (int) $m->id) {
            $overdue = strtotime($loan->due_at . ' UTC') < time();
            $status = '<p class="' . ($overdue ? 'warn' : 'ok') . '">' . esc_html(MAUS_I18n::t($overdue ? 'status_mine_overdue' : 'status_mine', $l, self::date($loan->due_at, $l))) . '</p>';
        } elseif ($loan) {
            // Only admins see who has a game (D3).
            $status = '<p class="warn">' . esc_html($m->role === 'admin'
                ? MAUS_I18n::t('status_on_loan_admin', $l, $loan->member_name, self::date($loan->due_at, $l))
                : MAUS_I18n::t('status_on_loan', $l)) . '</p>';
        } elseif (!$copy->lendable) {
            $status = '<p class="warn">' . esc_html(MAUS_I18n::t('status_onsite', $l)) . '</p>';
        } else {
            $status = '<p class="ok">' . esc_html(MAUS_I18n::t('status_available', $l)) . '</p>'
                . '<p>' . esc_html(MAUS_I18n::t($m->role === 'new' ? 'borrow_via_team' : 'borrow_soon', $l)) . '</p>';
        }

        $body = ($copy->image_url ? '<img src="' . esc_url($copy->image_url) . '" alt="">' : '')
            . ($facts ? '<p class="muted">' . esc_html(implode(' · ', $facts)) . '</p>' : '')
            . '<p class="muted">' . esc_html(MAUS_I18n::t('copy_code', $l, $code)) . '</p>'
            . $status
            . self::home_link($l);
        self::page($l, trim($copy->name . ' ' . $copy->label), $body);
    }

    private static function login_form(string $notice = ''): void {
        $next = self::next_path();
        if (MAUS_Auth::current_member()) {
            self::go($next);
        }
        $l = MAUS_I18n::detect();
        $body = '<p>' . esc_html(MAUS_I18n::t('login_intro', $l)) . '</p>' . $notice
            . '<form method="post" action="' . esc_url(home_url('/ausleihe/login')) . '">'
            . wp_nonce_field('maus_login', '_wpnonce', true, false)
            . '<input type="hidden" name="next" value="' . esc_attr($next) . '">'
            . '<label for="email">' . esc_html(MAUS_I18n::t('email_label', $l)) . '</label>'
            . '<input id="email" type="email" name="email" required autocomplete="email" inputmode="email" autofocus>'
            . '<button>' . esc_html(MAUS_I18n::t('send_link', $l)) . '</button></form>';
        self::page($l, MAUS_I18n::t('login_title', $l), $body);
    }

    private static function login_post(): void {
        $l = MAUS_I18n::detect();
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'maus_login')) {
            self::login_form();
            return;
        }
        $result = MAUS_Auth::request_link((string) wp_unslash($_POST['email'] ?? ''), self::next_path());
        if ($result === 'limited') {
            self::login_form('<p class="warn">' . esc_html(MAUS_I18n::t('limited_text', $l)) . '</p>');
            return;
        }
        self::page($l, MAUS_I18n::t('sent_title', $l), '<p>' . esc_html(MAUS_I18n::t('sent_text', $l, MAUS_Settings::get('login_link_minutes'))) . '</p>');
    }

    private static function confirm_form(): void {
        $l = MAUS_I18n::detect();
        $token = (string) ($_GET['token'] ?? '');
        if (!MAUS_Auth::token_valid($token)) {
            self::invalid($l);
            return;
        }
        $body = '<p>' . esc_html(MAUS_I18n::t('confirm_text', $l)) . '</p>'
            . '<form method="post" action="' . esc_url(home_url('/ausleihe/login/confirm')) . '">'
            . wp_nonce_field('maus_confirm', '_wpnonce', true, false)
            . '<input type="hidden" name="token" value="' . esc_attr($token) . '">'
            . '<input type="hidden" name="next" value="' . esc_attr(self::next_path()) . '">'
            . '<button>' . esc_html(MAUS_I18n::t('login_title', $l)) . '</button></form>';
        self::page($l, MAUS_I18n::t('confirm_title', $l), $body);
    }

    private static function confirm_post(): void {
        $l = MAUS_I18n::detect();
        if (wp_verify_nonce($_POST['_wpnonce'] ?? '', 'maus_confirm') && MAUS_Auth::sign_in((string) ($_POST['token'] ?? ''))) {
            self::go(self::next_path());
        }
        self::invalid($l);
    }

    private static function logout(): void {
        if (MAUS_Auth::csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            MAUS_Auth::sign_out();
        }
        self::go('/ausleihe/login');
    }

    private static function invalid(string $l): void {
        status_header(400);
        $body = '<p>' . esc_html(MAUS_I18n::t('invalid_text', $l)) . '</p>'
            . '<p><a href="' . esc_url(home_url('/ausleihe/login')) . '">' . esc_html(MAUS_I18n::t('new_link', $l)) . '</a></p>';
        self::page($l, MAUS_I18n::t('invalid_title', $l), $body);
    }

    /* ------------------------------ helpers ------------------------------ */

    /** Where to land after sign-in: only our own pages, never an outside URL. */
    private static function next_path(): string {
        $next = (string) wp_unslash($_POST['next'] ?? $_GET['next'] ?? '');
        return preg_match('~^/(c/[0-9A-Z]{5}|ausleihe)$~', $next) ? $next : '/ausleihe';
    }

    private static function date(string $utc, string $lang): string {
        return wp_date($lang === 'de' ? 'd.m.Y' : 'd/m/Y', strtotime($utc . ' UTC'));
    }

    private static function home_link(string $lang): string {
        return '<p><a href="' . esc_url(home_url('/ausleihe')) . '">' . esc_html(MAUS_I18n::t('to_home', $lang)) . '</a></p>';
    }

    private static function go(string $path): void {
        wp_safe_redirect(home_url($path));
        exit;
    }

    private static function page(string $lang, string $title, string $body): void {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="' . esc_attr($lang) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . esc_html($title) . ' – Mascha spielt</title>'
            . '<style>'
            . 'body{margin:0;font:16px/1.5 system-ui,sans-serif;background:#f6f4ef;color:#222}'
            . 'main{max-width:26rem;margin:0 auto;padding:1.5rem 1rem}'
            . 'h1{font-size:1.4rem}label{display:block;margin:1rem 0 .25rem}'
            . 'input{width:100%;box-sizing:border-box;font:inherit;padding:.6rem;border:1px solid #888;border-radius:6px}'
            . 'button{margin-top:1rem;width:100%;font:inherit;padding:.7rem;border:0;border-radius:6px;background:#2c5d4a;color:#fff}'
            . 'button.secondary{background:#fff;color:#2c5d4a;border:1px solid #2c5d4a}'
            . '.warn{background:#fff3cd;padding:.6rem;border-radius:6px}a{color:#2c5d4a}'
            . '.ok{background:#e1efe8;padding:.6rem;border-radius:6px}.muted{color:#666;margin:.25rem 0}'
            . 'img{display:block;max-width:100%;max-height:14rem;margin:0 auto 1rem;border-radius:6px}'
            . '</style></head><body><main><h1>' . esc_html($title) . '</h1>' . $body . '</main></body></html>';
    }
}
