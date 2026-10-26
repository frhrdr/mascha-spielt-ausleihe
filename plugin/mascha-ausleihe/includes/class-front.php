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

    /** Error keys from MAUS_Loans that the copy page shows as 'err_<key>'. */
    private const ERRORS = ['not_allowed', 'not_lendable', 'limit', 'taken', 'no_renewals', 'too_early', 'already_returned', 'already_resolved', 'empty_note', 'empty_report', 'no_member', 'bad_date', 'failed'];

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
                self::copy_page($post);
                break;
            case 'issues':
                self::issues_page($post);
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
            . self::my_loans($m)
            . ($m->role === 'admin' ? '<p><a href="' . esc_url(home_url('/ausleihe/issues')) . '">' . esc_html(MAUS_I18n::t('open_issues', $l, MAUS_Issues::count_open())) . '</a></p>' : '')
            . '<p>' . esc_html(MAUS_I18n::t('home_scan', $l)) . '</p>'
            . '<form method="post" action="' . esc_url(home_url('/ausleihe/logout')) . '">'
            . '<input type="hidden" name="csrf" value="' . esc_attr(MAUS_Auth::csrf_token()) . '">'
            . '<button class="secondary">' . esc_html(MAUS_I18n::t('sign_out', $l)) . '</button></form>';
        self::page($l, MAUS_I18n::t('hello', $l, $m->name), $body);
    }

    /** Sticker URL /c/<code>: the copy, its status and what the member can do (design §5.2). */
    private static function copy_page(bool $post): void {
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
        if ($post) {
            self::copy_action($copy, $m);
        }
        $title = trim($copy->name . ' ' . $copy->label);
        $done = (string) ($_GET['done'] ?? '');

        // Right after a return: "Is the game complete?" (D7). Skipping is fine.
        $answer_loan = $done === 'returned' ? MAUS_Loans::awaiting_answer((int) ($_GET['loan'] ?? 0), $m) : null;
        if ($answer_loan) {
            $body = '<p class="ok">' . esc_html(MAUS_I18n::t('done_returned', $l)) . '</p>'
                . '<p>' . esc_html(MAUS_I18n::t('complete_question', $l)) . '</p>'
                . self::action_form($code, 'complete', MAUS_I18n::t('complete_yes', $l), ['loan' => $answer_loan->id])
                . '<p><a href="' . esc_url(home_url('/c/' . $code . '?report=1&loan=' . $answer_loan->id)) . '">' . esc_html(MAUS_I18n::t('complete_no', $l)) . '</a></p>'
                . '<p><a href="' . esc_url(home_url('/c/' . $code)) . '">' . esc_html(MAUS_I18n::t('complete_skip', $l)) . '</a></p>';
            self::page($l, $title, $body);
            return;
        }

        if (isset($_GET['report']) && !$copy->retired) {
            $report_loan = MAUS_Loans::awaiting_answer((int) ($_GET['loan'] ?? 0), $m);
            $body = '<form method="post" action="' . esc_url(home_url('/c/' . $code)) . '">'
                . '<input type="hidden" name="csrf" value="' . esc_attr(MAUS_Auth::csrf_token()) . '">'
                . '<input type="hidden" name="do" value="report">'
                . ($report_loan ? '<input type="hidden" name="loan" value="' . (int) $report_loan->id . '">' : '')
                . '<label for="text">' . esc_html(MAUS_I18n::t('report_label', $l)) . '</label>'
                . '<textarea id="text" name="text" rows="5" maxlength="2000" required autofocus></textarea>'
                . '<button>' . esc_html(MAUS_I18n::t('report_send', $l)) . '</button></form>'
                . '<p><a href="' . esc_url(home_url('/c/' . $code)) . '">' . esc_html(MAUS_I18n::t('cancel', $l)) . '</a></p>';
            self::page($l, MAUS_I18n::t('report_title', $l), '<p class="muted">' . esc_html($title) . '</p>' . $body);
            return;
        }

        // Admin correction of the open loan (§5.3).
        $is_admin = $m->role === 'admin';
        $edit_loan = isset($_GET['edit']) && $is_admin && !$copy->retired ? MAUS_Copies::open_loan((int) $copy->id) : null;
        if ($edit_loan) {
            $body = '<form method="post" action="' . esc_url(home_url('/c/' . $code)) . '">'
                . '<input type="hidden" name="csrf" value="' . esc_attr(MAUS_Auth::csrf_token()) . '">'
                . '<input type="hidden" name="do" value="edit"><input type="hidden" name="loan" value="' . (int) $edit_loan->id . '">'
                . '<label for="member">' . esc_html(MAUS_I18n::t('borrower', $l)) . '</label>' . self::member_select((int) $edit_loan->member_id, $l)
                . '<label for="due">' . esc_html(MAUS_I18n::t('due_date', $l)) . '</label>'
                . '<input id="due" type="date" name="due" required value="' . esc_attr(wp_date('Y-m-d', strtotime($edit_loan->due_at . ' UTC'))) . '">'
                . '<button>' . esc_html(MAUS_I18n::t('save', $l)) . '</button></form>'
                . self::action_form($code, 'cancel', MAUS_I18n::t('cancel_loan', $l), ['loan' => $edit_loan->id], 'secondary', MAUS_I18n::t('cancel_loan_confirm', $l))
                . '<p><a href="' . esc_url(home_url('/c/' . $code)) . '">' . esc_html(MAUS_I18n::t('cancel', $l)) . '</a></p>';
            self::page($l, MAUS_I18n::t('edit_title', $l), '<p class="muted">' . esc_html($title) . '</p>' . $body);
            return;
        }
        $edit_link = '<p><a href="' . esc_url(home_url('/c/' . $code . '?edit=1')) . '">' . esc_html(MAUS_I18n::t('edit_loan', $l)) . '</a></p>';

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

        $notice = '';
        $err = (string) ($_GET['err'] ?? '');
        if (in_array($err, self::ERRORS, true)) {
            $notice = '<p class="warn">' . esc_html(MAUS_I18n::t('err_' . $err, $l, MAUS_Settings::get('max_concurrent_loans'))) . '</p>';
        } elseif (in_array($done, ['borrowed', 'renewed', 'returned', 'thanks', 'reported', 'corrected', 'cancelled', 'resolved'], true)) {
            $notice = '<p class="ok">' . esc_html(MAUS_I18n::t('done_' . $done, $l)) . '</p>';
        }

        $loan = $copy->retired ? null : MAUS_Copies::open_loan((int) $copy->id);
        $actions = '';
        if ($copy->retired) {
            $status = '<p class="warn">' . esc_html(MAUS_I18n::t('copy_retired', $l)) . '</p>';
        } elseif ($loan && (int) $loan->member_id === (int) $m->id) {
            $overdue = strtotime($loan->due_at . ' UTC') < time();
            $status = '<p class="' . ($overdue ? 'warn' : 'ok') . '">' . esc_html(MAUS_I18n::t($overdue ? 'status_mine_overdue' : 'status_mine', $l, self::date($loan->due_at, $l))) . '</p>';
            $actions = self::action_form($code, 'return', MAUS_I18n::t('return', $l), ['loan' => $loan->id]);
            $left = MAUS_Settings::get('max_renewals') - (int) $loan->extended_count;
            $opens = MAUS_Loans::renew_opens_at($loan);
            if ($left <= 0) {
                $actions .= '<p class="muted">' . esc_html(MAUS_I18n::t('no_renewals_left', $l)) . '</p>';
            } elseif (time() < $opens) {
                $actions .= '<p class="muted">' . esc_html(MAUS_I18n::t('renew_from', $l, self::date(gmdate('Y-m-d H:i:s', $opens), $l))) . '</p>';
            } else {
                $actions .= self::action_form($code, 'renew', MAUS_I18n::t('renew', $l, $left), ['loan' => $loan->id], 'secondary');
            }
            $actions .= $is_admin ? $edit_link : '';
        } elseif ($loan) {
            // Only admins see who has a game (D3).
            $status = '<p class="warn">' . esc_html($m->role === 'admin'
                ? MAUS_I18n::t('status_on_loan_admin', $l, $loan->member_name, self::date($loan->due_at, $l))
                : MAUS_I18n::t('status_on_loan', $l)) . '</p>';
            if ($is_admin) {
                $actions = self::action_form($code, 'return', MAUS_I18n::t('record_return', $l), ['loan' => $loan->id], '', MAUS_I18n::t('record_return_confirm', $l, $loan->member_name))
                    . $edit_link;
            } elseif (MAUS_Loans::can_self_serve($m)) {
                $actions = '<p>' . esc_html(MAUS_I18n::t('found_hint', $l)) . '</p>'
                    . self::action_form($code, 'return', MAUS_I18n::t('found', $l), ['loan' => $loan->id], 'secondary', MAUS_I18n::t('found_confirm', $l));
            }
        } elseif (!$copy->lendable) {
            $status = '<p class="warn">' . esc_html(MAUS_I18n::t('status_onsite', $l)) . '</p>';
        } else {
            $status = '<p class="ok">' . esc_html(MAUS_I18n::t('status_available', $l)) . '</p>';
            $actions = MAUS_Loans::can_self_serve($m)
                ? self::action_form($code, 'borrow', MAUS_I18n::t('borrow', $l))
                    . '<p class="muted">' . esc_html(MAUS_I18n::t('borrow_hint', $l, self::date(gmdate('Y-m-d H:i:s', time() + MAUS_Settings::get('loan_period_days') * DAY_IN_SECONDS), $l))) . '</p>'
                : '<p>' . esc_html(MAUS_I18n::t('borrow_via_team', $l)) . '</p>';
            if ($is_admin) {
                $actions .= '<form method="post" action="' . esc_url(home_url('/c/' . $code)) . '">'
                    . '<input type="hidden" name="csrf" value="' . esc_attr(MAUS_Auth::csrf_token()) . '">'
                    . '<input type="hidden" name="do" value="borrow_for">'
                    . '<label for="member">' . esc_html(MAUS_I18n::t('borrow_for_label', $l)) . '</label>' . self::member_select(0, $l)
                    . '<button class="secondary">' . esc_html(MAUS_I18n::t('borrow_for', $l)) . '</button></form>';
            }
        }

        // Admins see and resolve this copy's open reports here.
        $issues = '';
        if ($is_admin && ($open = MAUS_Issues::open((int) $copy->id))) {
            $issues = '<h2>' . esc_html(MAUS_I18n::t('issues_title', $l)) . '</h2><ul class="loans">';
            foreach ($open as $issue) {
                $issues .= self::issue_item($issue, $l, '/c/' . $code, false);
            }
            $issues .= '</ul>';
        }

        $body = $notice
            . ($copy->image_url ? '<img src="' . esc_url($copy->image_url) . '" alt="">' : '')
            . ($facts ? '<p class="muted">' . esc_html(implode(' · ', $facts)) . '</p>' : '')
            . '<p class="muted">' . esc_html(MAUS_I18n::t('copy_code', $l, $code)) . '</p>'
            . $status . $actions . $issues
            . ($copy->retired ? '' : '<p><a href="' . esc_url(home_url('/c/' . $code . '?report=1')) . '">' . esc_html(MAUS_I18n::t('report_link', $l)) . '</a></p>')
            . self::home_link($l);
        self::page($l, $title, $body);
    }

    /** Handles a button on the copy page, then redirects back to it (POST → redirect → GET). */
    private static function copy_action(object $copy, object $m): void {
        $back = '/c/' . $copy->qr_code;
        if (!MAUS_Auth::csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            self::go($back);
        }
        $do = (string) ($_POST['do'] ?? '');
        $loan_id = (int) ($_POST['loan'] ?? 0);
        // The posted loan must still be the open one, so a stale page cannot close a newer loan.
        $loan = MAUS_Copies::open_loan((int) $copy->id);
        if ($loan && (int) $loan->id !== $loan_id) {
            $loan = null;
        }
        $query = '';
        switch ($do) {
            case 'borrow':
                $result = MAUS_Loans::borrow($copy, $m);
                $query = 'done=borrowed';
                break;
            case 'borrow_for':
                $result = MAUS_Loans::borrow_for($copy, (int) ($_POST['member'] ?? 0), $m);
                $query = 'done=borrowed';
                break;
            case 'edit':
                $result = $loan ? MAUS_Loans::correct($loan, $m, (int) ($_POST['member'] ?? 0), (string) ($_POST['due'] ?? '')) : 'already_returned';
                $query = 'done=corrected';
                break;
            case 'cancel':
                $result = $loan ? MAUS_Loans::cancel($loan, $m) : 'already_returned';
                $query = 'done=cancelled';
                break;
            case 'resolve':
                $result = MAUS_Issues::resolve((int) ($_POST['issue'] ?? 0), $m, (string) wp_unslash($_POST['note'] ?? ''));
                $query = 'done=resolved';
                break;
            case 'renew':
                $result = $loan ? MAUS_Loans::renew($loan, $m) : 'already_returned';
                $query = 'done=renewed';
                break;
            case 'return':
                $result = $loan ? MAUS_Loans::return_loan($loan, $m) : 'already_returned';
                $query = 'done=returned&loan=' . $loan_id;
                break;
            case 'complete':
                $answered = MAUS_Loans::awaiting_answer($loan_id, $m);
                if ($answered) {
                    MAUS_Loans::mark_complete($answered);
                }
                $result = true;
                $query = 'done=thanks';
                break;
            case 'report':
                $answered = $loan_id ? MAUS_Loans::awaiting_answer($loan_id, $m) : null;
                $result = MAUS_Loans::report_issue($copy, $m, (string) wp_unslash($_POST['text'] ?? ''), $answered);
                $query = 'done=reported';
                break;
            default:
                self::go($back);
        }
        self::go($back . '?' . ($result === true ? $query : 'err=' . $result));
    }

    /** Active members with their open loan count, for the desk flow. */
    private static function member_select(int $selected, string $l): string {
        $html = '<select id="member" name="member" required>' . ($selected ? '' : '<option value="">' . esc_html(MAUS_I18n::t('choose_member', $l)) . '</option>');
        foreach (MAUS_Members::active_with_loan_counts() as $mem) {
            $html .= '<option value="' . (int) $mem->id . '"' . selected($selected, (int) $mem->id, false) . '>'
                . esc_html(MAUS_I18n::t('member_option', $l, $mem->name, $mem->email, $mem->open_loans)) . '</option>';
        }
        return $html . '</select>';
    }

    /** All open reports, for admin members (§4). */
    private static function issues_page(bool $post): void {
        $m = MAUS_Auth::current_member();
        if (!$m) {
            self::go('/ausleihe/login?next=' . rawurlencode('/ausleihe/issues'));
        }
        if ($m->role !== 'admin') {
            self::go('/ausleihe');
        }
        $l = $m->language;
        if ($post) {
            $result = MAUS_Auth::csrf_ok((string) ($_POST['csrf'] ?? ''))
                ? MAUS_Issues::resolve((int) ($_POST['issue'] ?? 0), $m, (string) wp_unslash($_POST['note'] ?? '')) : 'failed';
            self::go('/ausleihe/issues?' . ($result === true ? 'done=resolved' : 'err=' . $result));
        }
        $notice = '';
        if (in_array($_GET['err'] ?? '', self::ERRORS, true)) {
            $notice = '<p class="warn">' . esc_html(MAUS_I18n::t('err_' . $_GET['err'], $l)) . '</p>';
        } elseif (($_GET['done'] ?? '') === 'resolved') {
            $notice = '<p class="ok">' . esc_html(MAUS_I18n::t('done_resolved', $l)) . '</p>';
        }
        $open = MAUS_Issues::open();
        $list = $open ? '<ul class="loans">' : '<p>' . esc_html(MAUS_I18n::t('no_issues', $l)) . '</p>';
        foreach ($open as $issue) {
            $list .= self::issue_item($issue, $l, '/ausleihe/issues', true);
        }
        self::page($l, MAUS_I18n::t('issues_title', $l), $notice . $list . ($open ? '</ul>' : '') . self::home_link($l));
    }

    /**
     * One report with its Resolve button. The button opens a dialog that
     * asks what was done; only its Submit button posts to $path.
     */
    private static function issue_item(object $issue, string $l, string $path, bool $with_game): string {
        $meta = self::date($issue->reported_at, $l) . ($issue->reporter ? ' · ' . $issue->reporter : '');
        $id = 'resolve-' . (int) $issue->id;
        return '<li>'
            . ($with_game ? '<a href="' . esc_url(home_url('/c/' . $issue->qr_code)) . '">' . esc_html(trim($issue->name . ' ' . $issue->label)) . '</a><br>' : '')
            . '<span class="muted">' . esc_html($meta) . '</span><br>' . nl2br(esc_html($issue->text))
            . '<button type="button" class="secondary" onclick="document.getElementById(\'' . $id . '\').showModal()">' . esc_html(MAUS_I18n::t('resolve', $l)) . '</button>'
            . '<dialog id="' . $id . '"><form method="post" action="' . esc_url(home_url($path)) . '">'
            . '<input type="hidden" name="csrf" value="' . esc_attr(MAUS_Auth::csrf_token()) . '">'
            . '<input type="hidden" name="do" value="resolve"><input type="hidden" name="issue" value="' . (int) $issue->id . '">'
            . '<p><strong>' . esc_html(MAUS_I18n::t('resolve_title', $l)) . '</strong></p>'
            . '<p class="muted">' . nl2br(esc_html($issue->text)) . '</p>'
            . '<label for="' . $id . '-note">' . esc_html(MAUS_I18n::t('resolve_note', $l)) . '</label>'
            . '<textarea id="' . $id . '-note" name="note" rows="4" maxlength="2000" required></textarea>'
            . '<button>' . esc_html(MAUS_I18n::t('resolve_submit', $l)) . '</button>'
            . '<button type="button" class="secondary" onclick="this.closest(\'dialog\').close()">' . esc_html(MAUS_I18n::t('cancel', $l)) . '</button>'
            . '</form></dialog>'
            . '</li>';
    }

    /** One button in its own small form on the copy page. */
    private static function action_form(string $code, string $do, string $label, array $fields = [], string $class = '', string $confirm = ''): string {
        return self::button_form('/c/' . $code, $do, $label, $fields, $class, $confirm);
    }

    /** One button in its own small form with the CSRF token. */
    private static function button_form(string $path, string $do, string $label, array $fields = [], string $class = '', string $confirm = ''): string {
        $html = '<form method="post" action="' . esc_url(home_url($path)) . '"'
            . ($confirm !== '' ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ')"' : '') . '>'
            . '<input type="hidden" name="csrf" value="' . esc_attr(MAUS_Auth::csrf_token()) . '">'
            . '<input type="hidden" name="do" value="' . esc_attr($do) . '">';
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        }
        return $html . '<button' . ($class !== '' ? ' class="' . esc_attr($class) . '"' : '') . '>' . esc_html($label) . '</button></form>';
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

    private static function my_loans(object $m): string {
        $l = $m->language;
        $loans = MAUS_Loans::open_for_member((int) $m->id);
        if (!$loans) {
            return '<p>' . esc_html(MAUS_I18n::t('no_loans', $l)) . '</p>';
        }
        $html = '<h2>' . esc_html(MAUS_I18n::t('my_loans', $l)) . '</h2><ul class="loans">';
        foreach ($loans as $loan) {
            $overdue = strtotime($loan->due_at . ' UTC') < time();
            $html .= '<li><a href="' . esc_url(home_url('/c/' . $loan->qr_code)) . '">' . esc_html(trim($loan->name . ' ' . $loan->label)) . '</a><br>'
                . '<span class="' . ($overdue ? 'late' : 'muted') . '">' . esc_html(MAUS_I18n::t($overdue ? 'due_overdue' : 'due_by', $l, self::date($loan->due_at, $l))) . '</span></li>';
        }
        return $html . '</ul>';
    }

    /** Where to land after sign-in: only our own pages, never an outside URL. */
    private static function next_path(): string {
        $next = (string) wp_unslash($_POST['next'] ?? $_GET['next'] ?? '');
        return preg_match('~^/(c/[0-9A-Z]{5}|ausleihe(/issues)?)$~', $next) ? $next : '/ausleihe';
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
            . 'input,textarea,select{width:100%;box-sizing:border-box;font:inherit;padding:.6rem;border:1px solid #888;border-radius:6px}'
            . 'button{margin-top:1rem;width:100%;font:inherit;padding:.7rem;border:0;border-radius:6px;background:#2c5d4a;color:#fff}'
            . 'button.secondary{background:#fff;color:#2c5d4a;border:1px solid #2c5d4a}'
            . '.warn{background:#fff3cd;padding:.6rem;border-radius:6px}a{color:#2c5d4a}'
            . '.ok{background:#e1efe8;padding:.6rem;border-radius:6px}.muted{color:#666;margin:.25rem 0}'
            . 'dialog{width:min(26rem,calc(100% - 2rem));box-sizing:border-box;border:0;border-radius:8px;padding:1rem}dialog::backdrop{background:rgba(0,0,0,.4)}'
            . 'h2{font-size:1.1rem;margin-top:1.5rem}.loans{padding:0;list-style:none}.loans li{margin:.6rem 0}.late{color:#a33}'
            . 'img{display:block;max-width:100%;max-height:14rem;margin:0 auto 1rem;border-radius:6px}'
            . '</style></head><body><main><h1>' . esc_html($title) . '</h1>' . $body . '</main></body></html>';
    }
}
