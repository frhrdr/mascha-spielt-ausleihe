<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Member management for admin members (§4, §5.5) under /ausleihe/members:
 * list, add, edit, deactivate, welcome emails. On the member pages rather
 * than in WP admin, because lending-team volunteers have no WordPress login.
 */
final class MAUS_Front_Members {

    private const ERRORS = ['member_fields', 'email_taken', 'own_role', 'own_account', 'welcome_failed', 'failed'];
    private const DONE   = ['saved', 'welcome_sent', 'deactivated', 'activated'];

    /** $sub is '' (list), 'new' or a member id. */
    public static function route(string $sub, bool $post): void {
        $admin = MAUS_Auth::current_member();
        if (!$admin) {
            MAUS_Front::go('/ausleihe/login?next=' . rawurlencode('/ausleihe/members'));
        }
        if ($admin->role !== 'admin') {
            MAUS_Front::go('/ausleihe');
        }
        if ($post && !MAUS_Auth::csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            MAUS_Front::go('/ausleihe/members' . ($sub !== '' ? '/' . $sub : ''));
        }
        if ($sub === '') {
            $post ? self::welcome_all($admin) : self::list_page($admin);
            return;
        }
        $member = $sub === 'new' ? null : MAUS_Members::find((int) $sub);
        if ($sub !== 'new' && !$member) {
            status_header(404);
            MAUS_Front::page($admin->language, '404', '<p>' . esc_html(MAUS_I18n::t('not_found', $admin->language)) . '</p>' . self::back_link($admin->language));
            return;
        }
        $post ? self::member_post($member, $admin) : self::form_page($member, $admin);
    }

    private static function list_page(object $admin): void {
        $l = $admin->language;
        $notice = self::notice($l);
        if (($_GET['done'] ?? '') === 'welcomed_all') {
            $notice = '<p class="' . ((int) ($_GET['failed'] ?? 0) ? 'warn' : 'ok') . '">'
                . esc_html(MAUS_I18n::t('done_welcomed_all', $l, (int) ($_GET['sent'] ?? 0), (int) ($_GET['failed'] ?? 0))) . '</p>';
        }

        $unwelcomed = count(MAUS_Members::unwelcomed());
        $welcome_box = $unwelcomed
            ? '<div class="warn"><p>' . esc_html(MAUS_I18n::t('unwelcomed_box', $l, $unwelcomed)) . '</p>'
                . MAUS_Front::button_form('/ausleihe/members', 'welcome_all', MAUS_I18n::t('welcome_all', $l, $unwelcomed), [], 'secondary', MAUS_I18n::t('welcome_all_confirm', $l, $unwelcomed))
                . '</div>'
            : '';

        $members = MAUS_Members::with_loan_counts(false);
        $list = '<ul class="loans" id="members">';
        foreach ($members as $mem) {
            $meta = [$mem->email, MAUS_I18n::t('role_' . $mem->role, $l), MAUS_I18n::t('language_' . $mem->language, $l)];
            if ((int) $mem->open_loans) {
                $meta[] = MAUS_I18n::t('n_borrowed', $l, $mem->open_loans);
            }
            $flags = !(int) $mem->active ? MAUS_I18n::t('inactive', $l) : ($mem->welcomed_at ? '' : MAUS_I18n::t('not_welcomed', $l));
            $list .= '<li data-search="' . esc_attr(mb_strtolower($mem->name . ' ' . $mem->email)) . '">'
                . '<a href="' . esc_url(home_url('/ausleihe/members/' . (int) $mem->id)) . '">' . esc_html($mem->name) . '</a>'
                . ($flags !== '' ? ' <span class="late">' . esc_html($flags) . '</span>' : '')
                . '<br><span class="muted">' . esc_html(implode(' · ', $meta)) . '</span></li>';
        }
        $list .= '</ul>';

        // Client-side filter; the list is short (< 100 members).
        $filter = '<input type="search" placeholder="' . esc_attr(MAUS_I18n::t('filter_placeholder', $l)) . '" aria-label="' . esc_attr(MAUS_I18n::t('filter_placeholder', $l)) . '"'
            . ' oninput="var q=this.value.toLowerCase();document.querySelectorAll(\'#members li\').forEach(function(li){li.hidden=li.dataset.search.indexOf(q)<0})">';

        $body = $notice . $welcome_box
            . '<p><a href="' . esc_url(home_url('/ausleihe/members/new')) . '">' . esc_html(MAUS_I18n::t('add_member', $l)) . '</a></p>'
            . $filter . $list . MAUS_Front::home_link($l);
        MAUS_Front::page($l, MAUS_I18n::t('members_title', $l, count($members)), $body);
    }

    /** "Send to all who have none yet", e.g. after the CSV import. */
    private static function welcome_all(object $admin): void {
        if (($_POST['do'] ?? '') !== 'welcome_all') {
            MAUS_Front::go('/ausleihe/members');
        }
        $sent = 0;
        $failed = 0;
        foreach (MAUS_Members::unwelcomed() as $mem) {
            MAUS_Members::send_welcome($mem) ? $sent++ : $failed++;
        }
        MAUS_Front::go('/ausleihe/members?done=welcomed_all&sent=' . $sent . '&failed=' . $failed);
    }

    /** Add form ($member null) or edit page. $input and $error re-show a rejected form. */
    private static function form_page(?object $member, object $admin, array $input = [], string $error = ''): void {
        $l = $admin->language;
        $v = fn(string $f, string $default = '') => (string) ($input[$f] ?? ($member->$f ?? $default));
        $path = '/ausleihe/members/' . ($member ? (int) $member->id : 'new');

        $options = function (string $name, array $values, string $current) use ($l): string {
            $html = '<select id="' . $name . '" name="' . $name . '">';
            foreach ($values as $value) {
                $html .= '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html(MAUS_I18n::t($name . '_' . $value, $l)) . '</option>';
            }
            return $html . '</select>';
        };

        $body = ($error !== '' ? '<p class="warn">' . esc_html(MAUS_I18n::t('err_' . $error, $l)) . '</p>' : self::notice($l))
            . '<form method="post" action="' . esc_url(home_url($path)) . '">'
            . '<input type="hidden" name="csrf" value="' . esc_attr(MAUS_Auth::csrf_token()) . '">'
            . '<input type="hidden" name="do" value="save">'
            . '<label for="name">' . esc_html(MAUS_I18n::t('name_label', $l)) . '</label>'
            . '<input id="name" name="name" required maxlength="150" value="' . esc_attr($v('name')) . '">'
            . '<label for="email">' . esc_html(MAUS_I18n::t('email_label', $l)) . '</label>'
            . '<input id="email" type="email" name="email" required inputmode="email" value="' . esc_attr($v('email')) . '">'
            . '<label for="role">' . esc_html(MAUS_I18n::t('role_label', $l)) . '</label>' . $options('role', MAUS_Members::ROLES, $v('role', 'new'))
            . '<label for="language">' . esc_html(MAUS_I18n::t('language_label', $l)) . '</label>' . $options('language', MAUS_Members::LANGUAGES, $v('language', 'de'))
            . (!$member ? '<label class="check"><input type="checkbox" name="welcome" value="1"' . (($input['welcome'] ?? '1') ? ' checked' : '') . '> '
                . esc_html(MAUS_I18n::t('send_welcome_now', $l)) . '</label>' : '')
            . '<button>' . esc_html(MAUS_I18n::t('save', $l)) . '</button></form>';

        if ($member) {
            $body .= self::member_extras($member, $admin, $path);
        }
        $body .= self::back_link($l);
        MAUS_Front::page($l, $member ? $member->name : MAUS_I18n::t('add_member_title', $l), $body);
    }

    /** Welcome email, open loans and (de)activation on the edit page. */
    private static function member_extras(object $member, object $admin, string $path): string {
        $l = $admin->language;
        $html = '<h2>' . esc_html(MAUS_I18n::t('welcome_heading', $l)) . '</h2>';
        if (!(int) $member->active) {
            $html .= '<p class="muted">' . esc_html(MAUS_I18n::t('inactive_no_mail', $l)) . '</p>';
        } else {
            $html .= '<p class="muted">' . esc_html($member->welcomed_at
                    ? MAUS_I18n::t('welcomed_on', $l, MAUS_Front::date($member->welcomed_at, $l))
                    : MAUS_I18n::t('not_welcomed_long', $l)) . '</p>'
                . MAUS_Front::button_form($path, 'welcome', MAUS_I18n::t($member->welcomed_at ? 'welcome_resend' : 'welcome_send', $l), [], 'secondary');
        }

        $loans = MAUS_Loans::open_for_member((int) $member->id);
        if ($loans) {
            $html .= '<h2>' . esc_html(MAUS_I18n::t('member_loans', $l)) . '</h2><ul class="loans">';
            foreach ($loans as $loan) {
                $html .= '<li><a href="' . esc_url(home_url('/c/' . $loan->qr_code)) . '">' . esc_html(trim($loan->name . ' ' . $loan->label)) . '</a><br>'
                    . '<span class="muted">' . esc_html(MAUS_I18n::t('due_by', $l, MAUS_Front::date($loan->due_at, $l))) . '</span></li>';
            }
            $html .= '</ul>';
        }

        if ((int) $member->id !== (int) $admin->id) {
            $html .= '<h2>' . esc_html(MAUS_I18n::t('access_heading', $l)) . '</h2>';
            if ((int) $member->active) {
                $confirm = MAUS_I18n::t('deactivate_confirm', $l, $member->name)
                    . ($loans ? ' ' . MAUS_I18n::t('deactivate_confirm_loans', $l, count($loans)) : '');
                $html .= '<p class="muted">' . esc_html(MAUS_I18n::t('deactivate_hint', $l)) . '</p>'
                    . MAUS_Front::button_form($path, 'deactivate', MAUS_I18n::t('deactivate', $l), [], 'secondary', $confirm);
            } else {
                $html .= '<p class="warn">' . esc_html(MAUS_I18n::t('inactive_long', $l)) . '</p>'
                    . MAUS_Front::button_form($path, 'activate', MAUS_I18n::t('activate', $l), [], 'secondary');
            }
        }
        return $html;
    }

    private static function member_post(?object $member, object $admin): void {
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'save') {
            $input = wp_unslash($_POST);
            $result = MAUS_Members::save($member, $input, $admin);
            if (!is_int($result)) {
                self::form_page($member, $admin, $input + ['welcome' => ''], $result);
                return;
            }
            $done = 'saved';
            if (!$member && !empty($input['welcome'])) {
                $done = MAUS_Members::send_welcome(MAUS_Members::find($result)) ? 'welcome_sent' : 'err';
            }
            MAUS_Front::go('/ausleihe/members/' . $result . ($done === 'err' ? '?err=welcome_failed' : '?done=' . $done));
        }
        if (!$member) {
            MAUS_Front::go('/ausleihe/members');
        }
        $path = '/ausleihe/members/' . (int) $member->id;
        switch ($do) {
            case 'welcome':
                $result = MAUS_Members::send_welcome($member) ? true : 'welcome_failed';
                $done = 'welcome_sent';
                break;
            case 'deactivate':
            case 'activate':
                $result = MAUS_Members::set_active($member, $do === 'activate', $admin);
                $done = $do . 'd';
                break;
            default:
                MAUS_Front::go($path);
        }
        MAUS_Front::go($path . ($result === true ? '?done=' . $done : '?err=' . $result));
    }

    private static function notice(string $l): string {
        if (in_array($_GET['err'] ?? '', self::ERRORS, true)) {
            return '<p class="warn">' . esc_html(MAUS_I18n::t('err_' . $_GET['err'], $l)) . '</p>';
        }
        if (in_array($_GET['done'] ?? '', self::DONE, true)) {
            return '<p class="ok">' . esc_html(MAUS_I18n::t('done_' . $_GET['done'], $l)) . '</p>';
        }
        return '';
    }

    private static function back_link(string $l): string {
        return '<p><a href="' . esc_url(home_url('/ausleihe/members')) . '">' . esc_html(MAUS_I18n::t('to_members', $l)) . '</a></p>';
    }
}
