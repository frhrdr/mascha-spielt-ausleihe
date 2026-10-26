<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Member-facing pages under /ausleihe/. They bypass the WordPress theme and
 * render a small standalone mobile page.
 */
final class MAUS_Front {

    private const REWRITE_VERSION = '1';

    public static function boot(): void {
        add_action('init', [self::class, 'register_rules']);
        add_action('init', [self::class, 'maybe_flush_rules'], 99);
        add_filter('query_vars', fn($v) => array_merge($v, ['maus_route']));
        add_action('template_redirect', [self::class, 'dispatch']);
    }

    public static function register_rules(): void {
        add_rewrite_rule('^ausleihe/?$', 'index.php?maus_route=home', 'top');
        add_rewrite_rule('^ausleihe/(.+?)/?$', 'index.php?maus_route=$matches[1]', 'top');
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

    private static function login_form(string $notice = ''): void {
        if (MAUS_Auth::current_member()) {
            self::go('/ausleihe');
        }
        $l = MAUS_I18n::detect();
        $body = '<p>' . esc_html(MAUS_I18n::t('login_intro', $l)) . '</p>' . $notice
            . '<form method="post" action="' . esc_url(home_url('/ausleihe/login')) . '">'
            . wp_nonce_field('maus_login', '_wpnonce', true, false)
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
        $result = MAUS_Auth::request_link((string) wp_unslash($_POST['email'] ?? ''));
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
            . '<button>' . esc_html(MAUS_I18n::t('login_title', $l)) . '</button></form>';
        self::page($l, MAUS_I18n::t('confirm_title', $l), $body);
    }

    private static function confirm_post(): void {
        $l = MAUS_I18n::detect();
        if (wp_verify_nonce($_POST['_wpnonce'] ?? '', 'maus_confirm') && MAUS_Auth::sign_in((string) ($_POST['token'] ?? ''))) {
            self::go('/ausleihe');
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
            . '</style></head><body><main><h1>' . esc_html($title) . '</h1>' . $body . '</main></body></html>';
    }
}
