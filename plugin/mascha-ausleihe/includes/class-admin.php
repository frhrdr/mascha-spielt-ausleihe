<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MAUS_Admin {

    private const CAP = 'manage_options';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_maus_feedback_csv', [self::class, 'feedback_csv']);
    }

    public static function menu(): void {
        add_menu_page('Ausleihe', 'Ausleihe', self::CAP, 'maus', [self::class, 'render_home'], 'dashicons-book-alt');
        add_submenu_page('maus', 'Catalog', 'Catalog', self::CAP, 'maus-catalog', [self::class, 'render_catalog']);
        add_submenu_page('maus', 'Loans', 'Loans', self::CAP, 'maus-loans', [self::class, 'render_loans']);
        add_submenu_page('maus', 'Reservations', 'Reservations', self::CAP, 'maus-reservations', [self::class, 'render_reservations']);
        add_submenu_page('maus', 'Reported issues', 'Issues', self::CAP, 'maus-issues', [self::class, 'render_issues']);
        add_submenu_page('maus', 'QR stickers', 'Stickers', self::CAP, 'maus-stickers', ['MAUS_Stickers', 'render_page']);
        add_submenu_page('maus', 'Members', 'Members', self::CAP, 'maus-members', [self::class, 'render_members']);
        add_submenu_page('maus', 'BGG CSV import', 'CSV import', self::CAP, 'maus-import', [self::class, 'render_import']);
        add_submenu_page('maus', 'Feedback', 'Feedback', self::CAP, 'maus-feedback', [self::class, 'render_feedback']);
        add_submenu_page('maus', 'Reset after the trial', 'Reset', self::CAP, 'maus-reset', [self::class, 'render_reset']);
    }

    public static function render_home(): void {
        global $wpdb;
        $count = fn(string $t, string $where = '1=1') => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . MAUS_DB::table($t) . " WHERE $where");
        echo '<div class="wrap"><h1>Ausleihe</h1>';
        printf(
            '<p>Plugin version %s. %d games, %d active copies.</p>',
            esc_html(MAUS_VERSION),
            $count('games', 'removed_from_bgg = 0'),
            $count('copies', 'retired = 0')
        );
        printf(
            '<p><a href="%s">%d open loans</a>, <a href="%s">%d overdue</a>, <a href="%s">%d open issues</a>.</p>',
            esc_url(admin_url('admin.php?page=maus-loans')),
            $count('loans', 'returned_at IS NULL'),
            esc_url(admin_url('admin.php?page=maus-loans&filter=overdue')),
            $count('loans', $wpdb->prepare('returned_at IS NULL AND due_at < %s', gmdate('Y-m-d H:i:s'))),
            esc_url(admin_url('admin.php?page=maus-issues')),
            $count('issues', 'resolved_at IS NULL')
        );

        // Background job (reminders, holds): shows whether the cron-job.org pinger works.
        echo '<h2>Background job</h2>';
        if (isset($_POST['maus_run_jobs']) && check_admin_referer('maus_run_jobs')) {
            $r = MAUS_Jobs::run(true);
            echo '<div class="notice notice-success"><p>' . esc_html(isset($r['skipped'])
                ? 'Skipped: ' . $r['skipped'] . '.'
                : sprintf('Done. Reminders sent: %d. Admin summary sent: %s.', $r['reminders'], $r['digest'] ? 'yes' : 'no')) . '</p></div>';
        }
        $last = (int) get_option(MAUS_Jobs::LAST_RUN);
        $next = wp_next_scheduled(MAUS_Jobs::HOOK);
        printf(
            '<p>Last run: %s. Next run due: %s. It runs hourly via WP-Cron; on the live site cron-job.org must call <code>%s</code>.</p>',
            $last ? esc_html(wp_date('Y-m-d H:i', $last)) . ($last < time() - 3 * HOUR_IN_SECONDS ? ' <strong style="color:#b32d2e">(more than 3 hours ago: is the pinger running?)</strong>' : '') : '<strong>never</strong>',
            $next ? esc_html(wp_date('Y-m-d H:i', $next)) : 'not scheduled',
            esc_html(site_url('wp-cron.php'))
        );
        printf('<p>Scheduled runs send emails between %d:00 and %d:00 only; <em>Run now</em> sends them at any time.</p>', MAUS_Settings::get('mail_from_hour'), MAUS_Settings::get('mail_until_hour'));
        echo '<form method="post">';
        wp_nonce_field('maus_run_jobs');
        submit_button('Run now', 'secondary', 'maus_run_jobs', false);
        echo '</form>';
        echo '</div>';
    }

    /** Read-only list of all copies, to inspect what the import produced. */
    public static function render_catalog(): void {
        global $wpdb;
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.');
        }
        $g = MAUS_DB::table('games');
        $c = MAUS_DB::table('copies');
        $filter = sanitize_key($_GET['filter'] ?? 'all');
        $search = trim(sanitize_text_field(wp_unslash($_GET['s'] ?? '')));
        $per_page = 100;
        $paged = max(1, (int) ($_GET['paged'] ?? 1));

        $where = ['1=1'];
        $args = [];
        if ($filter === 'expansions') {
            $where[] = "g.type = 'expansion'";
        } elseif ($filter === 'duplicates') {
            $where[] = "c.retired = 0 AND (SELECT COUNT(*) FROM $c c2 WHERE c2.game_id = c.game_id AND c2.retired = 0) > 1";
        } elseif ($filter === 'retired') {
            $where[] = 'c.retired = 1';
        }
        if ($search !== '') {
            $where[] = '(g.name LIKE %s OR c.qr_code LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($args, $like, $like);
        }
        $where_sql = implode(' AND ', $where);
        $from = "FROM $c c JOIN $g g ON g.id = c.game_id WHERE $where_sql";

        $total_sql = "SELECT COUNT(*) $from";
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare($total_sql, $args) : $total_sql);
        $rows_sql = "SELECT c.id, c.qr_code, c.label, c.retired, c.lendable, g.name, g.type, g.year, g.min_players, g.max_players, g.playtime, g.bgg_id
            $from ORDER BY g.name, c.id LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, array_merge($args, [$per_page, ($paged - 1) * $per_page])));

        $base = admin_url('admin.php?page=maus-catalog');
        echo '<div class="wrap"><h1>Catalog</h1><p>';
        $links = [];
        foreach (['all' => 'All', 'expansions' => 'Expansions', 'duplicates' => 'Duplicates', 'retired' => 'Retired'] as $key => $label) {
            $links[] = $key === $filter ? '<strong>' . esc_html($label) . '</strong>'
                : '<a href="' . esc_url(add_query_arg('filter', $key, $base)) . '">' . esc_html($label) . '</a>';
        }
        echo implode(' | ', $links) . '</p>';
        echo '<form method="get"><input type="hidden" name="page" value="maus-catalog"><input type="hidden" name="filter" value="' . esc_attr($filter) . '">';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Name or code"> ';
        submit_button('Search', 'secondary', '', false);
        printf('</form><p>%d copies</p>', $total);

        echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" target="_blank">';
        echo '<input type="hidden" name="action" value="maus_stickers"><input type="hidden" name="scope" value="ids"><input type="hidden" name="guides" value="1">';
        wp_nonce_field('maus_stickers', '_wpnonce', false);
        echo '<p>Tick copies to reprint their stickers: <input type="number" name="skip" value="0" min="0" max="26" style="width:4em" title="Labels to skip on the first sheet"> labels skipped on the first sheet ';
        submit_button('Print selected stickers', 'secondary', '', false);
        echo '</p>';
        echo '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll(\'.maus-pick\').forEach(function(b){b.checked=this.checked}.bind(this))"></td><th>Code</th><th>Name</th><th>Copy</th><th>Type</th><th>Year</th><th>Players</th><th>Min.</th><th>Lendable</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $players = $r->min_players ? $r->min_players . ($r->max_players && $r->max_players !== $r->min_players ? '–' . $r->max_players : '') : '';
            printf(
                '<tr><th class="check-column"><input type="checkbox" class="maus-pick" name="ids[]" value="%d"></th><td><code>%s</code></td><td><a href="https://boardgamegeek.com/boardgame/%d" target="_blank" rel="noopener">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                (int) $r->id, esc_html($r->qr_code), (int) $r->bgg_id, esc_html($r->name), esc_html($r->label), esc_html($r->type),
                esc_html($r->year), esc_html($players), esc_html($r->playtime), $r->lendable ? 'yes' : 'no', $r->retired ? 'retired' : 'active'
            );
        }
        echo '</tbody></table></form>';

        $pages = (int) ceil($total / $per_page);
        if ($pages > 1) {
            echo '<p>' . paginate_links([
                'base'    => add_query_arg('paged', '%#%', add_query_arg(['filter' => $filter, 's' => $search], $base)),
                'format'  => '',
                'current' => $paged,
                'total'   => $pages,
            ]) . '</p>';
        }
        echo '</div>';
    }

    /** Loans: open (default), overdue, or the latest returned ones. Changes happen on /c/<code>. */
    public static function render_loans(): void {
        global $wpdb;
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.');
        }
        $filter = sanitize_key($_GET['filter'] ?? 'open');
        $now = gmdate('Y-m-d H:i:s');
        $where = [
            'open'     => 'l.returned_at IS NULL',
            'overdue'  => $wpdb->prepare('l.returned_at IS NULL AND l.due_at < %s', $now),
            'returned' => 'l.returned_at IS NOT NULL',
        ][$filter] ?? 'l.returned_at IS NULL';
        $order = $filter === 'returned' ? 'l.returned_at DESC' : 'l.due_at';
        $rows = $wpdb->get_results(
            'SELECT l.*, c.qr_code, c.label, g.name, m.name AS member_name, r.name AS returned_by_name
             FROM ' . MAUS_DB::table('loans') . ' l
             JOIN ' . MAUS_DB::table('copies') . ' c ON c.id = l.copy_id
             JOIN ' . MAUS_DB::table('games') . ' g ON g.id = c.game_id
             JOIN ' . MAUS_DB::table('members') . ' m ON m.id = l.member_id
             LEFT JOIN ' . MAUS_DB::table('members') . " r ON r.id = l.returned_by
             WHERE $where ORDER BY $order LIMIT 500"
        );

        echo '<div class="wrap"><h1>Loans</h1>';
        self::filter_links('maus-loans', $filter, ['open' => 'Open', 'overdue' => 'Overdue', 'returned' => 'Returned (latest 500)']);
        echo '<p>To return or correct a loan, open the copy (code link) while signed in as an admin member.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Code</th><th>Game</th><th>Member</th><th>Borrowed</th><th>Due</th><th>Renewals</th><th>Returned</th><th>Returned by</th><th>Complete</th></tr></thead><tbody>';
        $date = fn(?string $utc) => $utc ? wp_date('Y-m-d', strtotime($utc . ' UTC')) : '';
        foreach ($rows as $r) {
            $late = $r->returned_at === null && $r->due_at < $now;
            printf(
                '<tr><td><a href="%s"><code>%s</code></a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_url(home_url('/c/' . $r->qr_code)), esc_html($r->qr_code), esc_html(trim($r->name . ' ' . $r->label)), esc_html($r->member_name),
                esc_html($date($r->checked_out_at)), ($late ? '<strong style="color:#b32d2e">' : '') . esc_html($date($r->due_at)) . ($late ? '</strong>' : ''),
                (int) $r->extended_count, esc_html($date($r->returned_at)),
                esc_html($r->returned_by && (int) $r->returned_by !== (int) $r->member_id ? (string) $r->returned_by_name : ''),
                $r->complete === null ? '' : ($r->complete ? 'yes' : '<strong>no</strong>')
            );
        }
        echo '</tbody></table>';
        if (!$rows) {
            echo '<p>None.</p>';
        }
        echo '</div>';
    }

    /** Feedback from testers, newest first, with a CSV download. */
    public static function render_feedback(): void {
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.');
        }
        $rows = MAUS_Feedback::all();
        echo '<div class="wrap"><h1>Feedback</h1>';
        printf('<p>%d entries. Members give feedback with the link in the footer of every member page. ', count($rows));
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=maus_feedback_csv'), 'maus_feedback_csv')) . '">Download CSV</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Member</th><th>Page</th><th>Feedback</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html(wp_date('Y-m-d H:i', strtotime($r->created_at . ' UTC'))),
                esc_html($r->member_name) . ($r->member_email ? '<br><small>' . esc_html($r->member_email) . '</small>' : ''),
                $r->page !== '' ? '<a href="' . esc_url(home_url($r->page)) . '">' . esc_html($r->page) . '</a>' : '',
                nl2br(esc_html($r->text))
            );
        }
        echo '</tbody></table>';
        if (!$rows) {
            echo '<p>None yet.</p>';
        }
        echo '</div>';
    }

    /** Semicolon-separated with BOM, so German Excel opens it correctly. */
    public static function feedback_csv(): void {
        if (!current_user_can(self::CAP) || !check_admin_referer('maus_feedback_csv')) {
            wp_die('Not allowed.');
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="feedback-' . wp_date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['date', 'member', 'email', 'page', 'feedback'], ';', '"', '');
        foreach (MAUS_Feedback::all() as $r) {
            fputcsv($out, [wp_date('Y-m-d H:i', strtotime($r->created_at . ' UTC')), $r->member_name, (string) $r->member_email, $r->page, $r->text], ';', '"', '');
        }
        fclose($out);
        exit;
    }

    /** Deletes all usage data after the trial; keeps the catalog and copy codes. */
    public static function render_reset(): void {
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.');
        }
        echo '<div class="wrap"><h1>Reset after the trial</h1>';
        $with_feedback = !empty($_POST['with_feedback']);
        if (isset($_POST['maus_reset'])) {
            check_admin_referer('maus_reset');
            if (trim((string) wp_unslash($_POST['confirm'] ?? '')) !== 'RESET') {
                echo '<div class="notice notice-error"><p>Nothing was deleted: type RESET in capitals to confirm.</p></div>';
            } else {
                try {
                    $deleted = MAUS_DB::reset_usage_data($with_feedback);
                    $parts = [];
                    foreach ($deleted as $table => $n) {
                        $parts[] = "$n $table";
                    }
                    echo '<div class="notice notice-success"><p>Reset done. Deleted: ' . esc_html(implode(', ', $parts)) . '. '
                        . 'Next: import the members (Ausleihe → Members), then sign in at /ausleihe as an admin member and send the welcome emails. '
                        . 'Set <code>trial_mode</code> to false in <code>class-settings.php</code> and deploy, if the trial is over.</p></div>';
                } catch (Throwable $e) {
                    echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . ' Nothing was deleted.</p></div>';
                }
            }
        }
        $counts = MAUS_DB::usage_counts(true);
        echo '<p>This deletes <strong>all usage data</strong> for a fresh start after the trial. It cannot be undone, so make a database backup first (IONOS control panel).</p>';
        echo '<table class="widefat" style="max-width:32rem"><thead><tr><th>Deleted</th><th>Rows now</th></tr></thead><tbody>';
        $labels = ['loans' => 'Loans (with their history)', 'reservations' => 'Reservations', 'issues' => 'Reported issues',
            'members' => 'Members (re-import them afterwards)', 'sessions' => 'Sign-in sessions (everyone is signed out)', 'login_tokens' => 'Unused sign-in links', 'feedback' => 'Feedback (only if ticked below)'];
        foreach ($counts as $table => $n) {
            printf('<tr><td>%s</td><td>%d</td></tr>', esc_html($labels[$table] ?? $table), $n);
        }
        echo '</tbody></table>';
        echo '<p><strong>Kept:</strong> games, copies and their codes (printed stickers keep working), sticker print dates, WordPress users and settings.</p>';
        echo '<form method="post">';
        wp_nonce_field('maus_reset');
        echo '<p><label><input type="checkbox" name="with_feedback" value="1"> Also delete the feedback (download it first under Ausleihe → Feedback)</label></p>';
        echo '<p><label>Type <code>RESET</code> to confirm: <input name="confirm" autocomplete="off" style="width:8em"></label></p>';
        submit_button('Delete all usage data', 'delete', 'maus_reset', false);
        echo '</form></div>';
    }

    /** Waiting and held reservations, read-only; members and admin members change them on /c/<code>. */
    public static function render_reservations(): void {
        global $wpdb;
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.');
        }
        $rows = $wpdb->get_results(
            'SELECT r.*, g.name, m.name AS member_name, c.qr_code AS held_code,
                    (SELECT c2.qr_code FROM ' . MAUS_DB::table('copies') . ' c2 WHERE c2.game_id = r.game_id AND c2.retired = 0 ORDER BY c2.id LIMIT 1) AS any_code
             FROM ' . MAUS_DB::table('reservations') . ' r
             JOIN ' . MAUS_DB::table('games') . ' g ON g.id = r.game_id
             JOIN ' . MAUS_DB::table('members') . ' m ON m.id = r.member_id
             LEFT JOIN ' . MAUS_DB::table('copies') . " c ON c.id = r.held_copy_id
             WHERE r.status IN ('waiting','held') ORDER BY g.name, r.status = 'held' DESC, r.created_at, r.id"
        );
        echo '<div class="wrap"><h1>Reservations</h1>';
        echo '<p>Open the game (code link) as an admin member to remove a reservation or borrow a held copy for the member.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Game</th><th>Member</th><th>Status</th><th>Reserved</th><th>Held copy</th><th>Held until</th></tr></thead><tbody>';
        $date = fn(?string $utc) => $utc ? wp_date('Y-m-d', strtotime($utc . ' UTC')) : '';
        foreach ($rows as $r) {
            $code = $r->held_code ?: $r->any_code;
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $code ? '<a href="' . esc_url(home_url('/c/' . $code)) . '">' . esc_html($r->name) . '</a>' : esc_html($r->name),
                esc_html($r->member_name), esc_html($r->status), esc_html($date($r->created_at)),
                $r->held_code ? '<code>' . esc_html($r->held_code) . '</code>' : '', esc_html($date($r->hold_until))
            );
        }
        echo '</tbody></table>';
        if (!$rows) {
            echo '<p>None.</p>';
        }
        echo '</div>';
    }

    /** Reports of missing parts or damage. Admin members resolve them on /ausleihe/issues or the copy page. */
    public static function render_issues(): void {
        global $wpdb;
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.');
        }
        echo '<div class="wrap"><h1>Reported issues</h1>';
        $filter = sanitize_key($_GET['filter'] ?? 'open');
        $resolved = $filter === 'resolved';
        $rows = $wpdb->get_results(
            'SELECT i.*, c.qr_code, c.label, g.name, m.name AS reporter, r.name AS resolver
             FROM ' . MAUS_DB::table('issues') . ' i
             JOIN ' . MAUS_DB::table('copies') . ' c ON c.id = i.copy_id
             JOIN ' . MAUS_DB::table('games') . ' g ON g.id = c.game_id
             LEFT JOIN ' . MAUS_DB::table('members') . ' m ON m.id = i.reported_by
             LEFT JOIN ' . MAUS_DB::table('members') . ' r ON r.id = i.resolved_by
             WHERE i.resolved_at IS ' . ($resolved ? 'NOT NULL ORDER BY i.resolved_at DESC' : 'NULL ORDER BY i.reported_at') . ' LIMIT 500'
        );
        self::filter_links('maus-issues', $resolved ? 'resolved' : 'open', ['open' => 'Open', 'resolved' => 'Resolved (latest 500)']);
        echo '<p>Admin members mark reports as resolved on their phone: <a href="' . esc_url(home_url('/ausleihe/issues')) . '">/ausleihe/issues</a> or the copy page.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Reported</th><th>Code</th><th>Game</th><th>By</th><th>Report</th>' . ($resolved ? '<th>Resolved</th><th>Resolved by</th><th>What was done</th>' : '') . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            printf(
                '<tr><td>%s</td><td><a href="%s"><code>%s</code></a></td><td>%s</td><td>%s</td><td>%s</td>%s</tr>',
                esc_html(wp_date('Y-m-d', strtotime($r->reported_at . ' UTC'))), esc_url(home_url('/c/' . $r->qr_code)), esc_html($r->qr_code),
                esc_html(trim($r->name . ' ' . $r->label)), esc_html((string) $r->reporter), nl2br(esc_html($r->text)),
                $resolved ? '<td>' . esc_html(wp_date('Y-m-d', strtotime($r->resolved_at . ' UTC'))) . '</td><td>' . esc_html((string) $r->resolver) . '</td><td>' . nl2br(esc_html((string) $r->resolution_note)) . '</td>' : ''
            );
        }
        echo '</tbody></table>';
        if (!$rows) {
            echo '<p>None.</p>';
        }
        echo '</div>';
    }

    private static function filter_links(string $page, string $current, array $filters): void {
        $links = [];
        foreach ($filters as $key => $label) {
            $links[] = $key === $current ? '<strong>' . esc_html($label) . '</strong>'
                : '<a href="' . esc_url(admin_url('admin.php?page=' . $page . '&filter=' . $key)) . '">' . esc_html($label) . '</a>';
        }
        echo '<p>' . implode(' | ', $links) . '</p>';
    }

    /** Member list plus its CSV import (preview first, then apply). */
    public static function render_members(): void {
        global $wpdb;
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.');
        }
        echo '<div class="wrap"><h1>Members</h1>';
        try {
            if (isset($_POST['maus_member_apply'])) {
                check_admin_referer('maus_members_apply');
                $key = 'maus_members_' . get_current_user_id() . '_' . sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
                $rows = get_transient($key);
                if (!$rows) {
                    throw new RuntimeException('The preview has expired. Please upload the file again.');
                }
                $r = MAUS_Members::apply($rows);
                delete_transient($key);
                printf('<div class="notice notice-success"><p>Import done: %d new, %d updated.</p></div>', $r['new'], $r['updated']);
            } elseif (isset($_POST['maus_member_upload'])) {
                check_admin_referer('maus_members_upload');
                self::members_preview();
                echo '</div>';
                return;
            }
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }

        echo '<h2>Import from CSV</h2><p>Columns: <code>name, email, role, language, external_id</code>. Role is <code>new</code>, <code>trusted</code> or <code>admin</code> (default new), language <code>de</code> or <code>en</code> (default de). Comma or semicolon separated. Members are matched by email. The import adds and updates only, it never removes anyone and sends no emails.</p>';
        echo '<p>Adding single members, deactivating, and sending welcome emails (also to everyone imported here) happen on the member pages for admin members: <a href="' . esc_url(home_url('/ausleihe/members')) . '">/ausleihe/members</a>.</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('maus_members_upload');
        echo '<input type="file" name="csv" accept=".csv,text/csv" required> ';
        submit_button('Preview import', 'primary', 'maus_member_upload', false);
        echo '</form>';

        $members = $wpdb->get_results('SELECT * FROM ' . MAUS_DB::table('members') . ' ORDER BY name');
        printf('<h2>%d members</h2>', count($members));
        echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Language</th><th>External ID</th><th>Active</th><th>Welcomed</th></tr></thead><tbody>';
        foreach ($members as $m) {
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($m->name), esc_html($m->email), esc_html($m->role), esc_html($m->language), esc_html((string) $m->external_id), $m->active ? 'yes' : 'no',
                $m->welcomed_at ? esc_html(wp_date('Y-m-d', strtotime($m->welcomed_at . ' UTC'))) : '');
        }
        echo '</tbody></table></div>';
    }

    private static function members_preview(): void {
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            throw new RuntimeException('No file received.');
        }
        $parsed = MAUS_Members::parse_csv((string) file_get_contents($_FILES['csv']['tmp_name']));
        if (!$parsed['rows']) {
            throw new RuntimeException(implode(' ', $parsed['errors']) ?: 'No usable rows found.');
        }
        $plan = MAUS_Members::plan($parsed['rows']);
        $token = wp_generate_password(20, false);
        set_transient('maus_members_' . get_current_user_id() . '_' . $token, $parsed['rows'], HOUR_IN_SECONDS);

        printf('<h2>Report</h2><ul><li><strong>%d</strong> new members</li><li><strong>%d</strong> updated, %d unchanged</li></ul>',
            count($plan['new']), count($plan['updated']), $plan['unchanged']);
        self::name_list('New', array_map(fn($r) => ['name' => $r['name'] . ' <' . $r['email'] . '>'], $plan['new']));
        foreach ($plan['updated'] as $u) {
            echo '<p>' . esc_html($u['email'] . ': ' . implode(', ', $u['changes'])) . '</p>';
        }
        if ($parsed['errors']) {
            echo '<div class="notice notice-warning inline"><p>' . implode('<br>', array_map('esc_html', $parsed['errors'])) . '</p></div>';
        }
        echo '<form method="post">';
        wp_nonce_field('maus_members_apply');
        echo '<input type="hidden" name="token" value="' . esc_attr($token) . '">';
        submit_button('Apply import', 'primary', 'maus_member_apply', false);
        echo ' <a href="' . esc_url(admin_url('admin.php?page=maus-members')) . '">Cancel</a></form>';
    }

    public static function render_import(): void {
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.');
        }
        echo '<div class="wrap"><h1>BGG collection import (CSV)</h1>';

        try {
            if (isset($_POST['maus_apply'])) {
                check_admin_referer('maus_import_apply');
                self::do_apply();
            } elseif (isset($_POST['maus_upload'])) {
                check_admin_referer('maus_import_upload');
                self::do_preview();
            } else {
                self::upload_form();
            }
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
            self::upload_form();
        }
        echo '</div>';
    }

    private static function upload_form(): void {
        echo '<p>On BoardGameGeek: <em>Collection → Export</em> (CSV). Upload that file here. Nothing is changed until you confirm the report.</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('maus_import_upload');
        echo '<input type="file" name="csv" accept=".csv,text/csv" required> ';
        submit_button('Preview import', 'primary', 'maus_upload', false);
        echo '</form>';
    }

    private static function do_preview(): void {
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            throw new RuntimeException('No file received.');
        }
        $parsed = MAUS_CSV_Import::parse((string) file_get_contents($_FILES['csv']['tmp_name']));
        if (!$parsed['rows']) {
            throw new RuntimeException(implode(' ', $parsed['errors']) ?: 'No usable rows found.');
        }
        $plan = MAUS_CSV_Import::plan($parsed['rows']);

        $token = wp_generate_password(20, false);
        set_transient('maus_import_' . get_current_user_id() . '_' . $token, $parsed['rows'], HOUR_IN_SECONDS);

        printf('<h2>Report</h2><ul><li><strong>%d</strong> new copies (will get QR codes)</li><li><strong>%d</strong> games updated, %d unchanged</li><li><strong>%d</strong> copies no longer in the collection (will be retired)</li></ul>',
            count($plan['new']), $plan['updated'], $plan['unchanged'], count($plan['retire']));

        self::name_list('New', $plan['new']);
        self::name_list('To be retired', $plan['retire']);
        if ($plan['blocked']) {
            echo '<div class="notice notice-warning inline"><p><strong>Needs attention, not retired because still on loan:</strong> ';
            echo esc_html(implode(', ', array_map(fn($c) => "{$c['name']} ({$c['qr_code']})", $plan['blocked']))) . '</p></div>';
        }
        if ($parsed['errors']) {
            echo '<div class="notice notice-warning inline"><p>' . implode('<br>', array_map('esc_html', $parsed['errors'])) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('maus_import_apply');
        echo '<input type="hidden" name="token" value="' . esc_attr($token) . '">';
        submit_button('Apply import', 'primary', 'maus_apply', false);
        echo ' <a href="' . esc_url(admin_url('admin.php?page=maus-import')) . '">Cancel</a></form>';
    }

    private static function do_apply(): void {
        $key = 'maus_import_' . get_current_user_id() . '_' . sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $rows = get_transient($key);
        if (!$rows) {
            throw new RuntimeException('The preview has expired. Please upload the file again.');
        }
        $result = MAUS_CSV_Import::apply($rows);
        delete_transient($key);
        printf('<div class="notice notice-success"><p>Import done: %d new, %d updated, %d retired.</p></div>', $result['new'], $result['updated'], $result['retired']);
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=maus')) . '">Back to overview</a></p>';
    }

    private static function name_list(string $title, array $items): void {
        if (!$items) {
            return;
        }
        $names = array_map(fn($i) => $i['name'], array_slice($items, 0, 20));
        $more = count($items) > 20 ? sprintf(' … and %d more', count($items) - 20) : '';
        echo '<p><strong>' . esc_html($title) . ':</strong> ' . esc_html(implode(', ', $names) . $more) . '</p>';
    }
}
