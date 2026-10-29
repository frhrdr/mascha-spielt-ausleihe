<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feedback from members (§11.9): /ausleihe/feedback, linked from the footer
 * of every page with the page the member came from. Stored, emailed to the
 * admins and listed in WP admin → Ausleihe → Feedback.
 */
final class MAUS_Feedback {

    /** Only our own pages count as "from", so the link back cannot lead elsewhere. */
    public static function safe_page(string $path): string {
        return preg_match('~^/(c/[0-9A-Z]{5}|ausleihe(/[a-z0-9/]*)?)$~', $path) ? $path : '';
    }

    /** Footer link to the form, carrying the current page. */
    public static function link_path(): string {
        $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $home = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
        if ($home !== '' && str_starts_with($path, $home)) {
            $path = substr($path, strlen($home));
        }
        $from = self::safe_page(rtrim($path, '/') ?: '/ausleihe');
        return '/ausleihe/feedback' . ($from !== '' ? '?from=' . rawurlencode($from) : '');
    }

    public static function page(bool $post): void {
        $m = MAUS_Auth::current_member();
        if (!$m) {
            MAUS_Front::go('/ausleihe/login?next=' . rawurlencode('/ausleihe/feedback'));
        }
        $l = $m->language;
        $from = self::safe_page((string) wp_unslash($_POST['from'] ?? $_GET['from'] ?? ''));
        $back = $from !== '' ? [$from => MAUS_I18n::t('feedback_back', $l)] : [];

        if ($post) {
            $text = trim((string) wp_unslash($_POST['text'] ?? ''));
            if (!MAUS_Auth::csrf_ok((string) ($_POST['csrf'] ?? '')) || $text === '') {
                MAUS_Front::go('/ausleihe/feedback' . ($from !== '' ? '?from=' . rawurlencode($from) : ''));
            }
            self::save($m, $from, $text);
            MAUS_Front::go('/ausleihe/feedback?done=1' . ($from !== '' ? '&from=' . rawurlencode($from) : ''));
        }

        if (isset($_GET['done'])) {
            MAUS_Front::page($l, MAUS_I18n::t('feedback_title', $l), '<p class="ok">' . esc_html(MAUS_I18n::t('feedback_thanks', $l)) . '</p>'
                . '<p><a href="' . esc_url(home_url('/ausleihe/feedback' . ($from !== '' ? '?from=' . rawurlencode($from) : ''))) . '">' . esc_html(MAUS_I18n::t('feedback_more', $l)) . '</a></p>', $back);
            return;
        }

        $body = '<p>' . esc_html(MAUS_I18n::t('feedback_intro', $l)) . '</p>'
            . ($from !== '' ? '<p class="muted">' . esc_html(MAUS_I18n::t('feedback_page', $l, $from)) . '</p>' : '')
            . '<form method="post" action="' . esc_url(home_url('/ausleihe/feedback')) . '">'
            . '<input type="hidden" name="csrf" value="' . esc_attr(MAUS_Auth::csrf_token()) . '">'
            . '<input type="hidden" name="from" value="' . esc_attr($from) . '">'
            . '<label for="text">' . esc_html(MAUS_I18n::t('feedback_label', $l)) . '</label>'
            . '<textarea id="text" name="text" rows="6" maxlength="5000" required autofocus></textarea>'
            . '<button>' . esc_html(MAUS_I18n::t('feedback_send', $l)) . '</button></form>';
        MAUS_Front::page($l, MAUS_I18n::t('feedback_title', $l), $body, $back);
    }

    public static function save(object $member, string $page, string $text): void {
        global $wpdb;
        $text = mb_substr($text, 0, 5000);
        $wpdb->insert(MAUS_DB::table('feedback'), [
            'member_id'   => $member->id,
            'member_name' => $member->name,
            'page'        => $page,
            'text'        => $text,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
        MAUS_Mail::feedback($member, $page !== '' ? home_url($page) : '–', $text);
    }

    /** Newest first, with the member's email while the member still exists. */
    public static function all(): array {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT f.*, m.email AS member_email FROM ' . MAUS_DB::table('feedback') . ' f
             LEFT JOIN ' . MAUS_DB::table('members') . ' m ON m.id = f.member_id ORDER BY f.created_at DESC, f.id DESC'
        );
    }
}
