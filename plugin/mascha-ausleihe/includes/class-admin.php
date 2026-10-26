<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MAUS_Admin {

    private const CAP = 'manage_options';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu(): void {
        add_menu_page('Ausleihe', 'Ausleihe', self::CAP, 'maus', [self::class, 'render_home'], 'dashicons-book-alt');
        add_submenu_page('maus', 'Catalog', 'Catalog', self::CAP, 'maus-catalog', [self::class, 'render_catalog']);
        add_submenu_page('maus', 'QR stickers', 'Stickers', self::CAP, 'maus-stickers', ['MAUS_Stickers', 'render_page']);
        add_submenu_page('maus', 'Members', 'Members', self::CAP, 'maus-members', [self::class, 'render_members']);
        add_submenu_page('maus', 'BGG CSV import', 'CSV import', self::CAP, 'maus-import', [self::class, 'render_import']);
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
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('maus_members_upload');
        echo '<input type="file" name="csv" accept=".csv,text/csv" required> ';
        submit_button('Preview import', 'primary', 'maus_member_upload', false);
        echo '</form>';

        $members = $wpdb->get_results('SELECT * FROM ' . MAUS_DB::table('members') . ' ORDER BY name');
        printf('<h2>%d members</h2>', count($members));
        echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Language</th><th>External ID</th><th>Active</th></tr></thead><tbody>';
        foreach ($members as $m) {
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($m->name), esc_html($m->email), esc_html($m->role), esc_html($m->language), esc_html((string) $m->external_id), $m->active ? 'yes' : 'no');
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
