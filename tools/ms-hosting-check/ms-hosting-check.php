<?php
/**
 * Plugin Name: MS Hosting Check
 * Description: Temporary diagnostics for the Mascha spielt Ausleihe app. Checks what this hosting plan allows (custom plugins, DB tables, outbound HTTP, mail, cron, REST, pretty URLs). Deactivate and delete after use.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

const MSHC_TABLE      = 'mshc_test';
const MSHC_CRON_HOOK  = 'mshc_cron_tick';
const MSHC_OPT_CRON   = 'mshc_cron_runs';
const MSHC_OPT_MAIL   = 'mshc_mail_result';
const MSHC_TEST_CODE  = 'K7Q2X';

/* ---------------------------------------------------------------------------
 * Lifecycle
 * ------------------------------------------------------------------------- */

add_filter('cron_schedules', function ($schedules) {
    $schedules['mshc_five_minutes'] = ['interval' => 300, 'display' => 'Every 5 minutes (MS Hosting Check)'];
    return $schedules;
});

register_activation_hook(__FILE__, function () {
    mshc_register_rewrite();
    flush_rewrite_rules();
    if (!wp_next_scheduled(MSHC_CRON_HOOK)) {
        wp_schedule_event(time() + 60, 'mshc_five_minutes', MSHC_CRON_HOOK);
    }
    update_option(MSHC_OPT_CRON, ['activated' => time(), 'runs' => []], false);
});

register_deactivation_hook(__FILE__, function () {
    global $wpdb;
    wp_clear_scheduled_hook(MSHC_CRON_HOOK);
    delete_option(MSHC_OPT_CRON);
    delete_option(MSHC_OPT_MAIL);
    remove_role('mshc_test_role');
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . MSHC_TABLE);
    // Forces WordPress to rebuild rewrite rules without ours on the next request.
    delete_option('rewrite_rules');
});

add_action(MSHC_CRON_HOOK, function () {
    $data = get_option(MSHC_OPT_CRON, ['activated' => time(), 'runs' => []]);
    $data['runs'][] = time();
    $data['runs'] = array_slice($data['runs'], -50);
    update_option(MSHC_OPT_CRON, $data, false);
});

/* ---------------------------------------------------------------------------
 * Public endpoints used by the loopback checks
 * ------------------------------------------------------------------------- */

function mshc_register_rewrite() {
    // Same shape as the planned QR URL: /c/<copy-id>
    add_rewrite_rule('^c/([A-Za-z0-9]+)/?$', 'index.php?mshc_code=$matches[1]', 'top');
}
add_action('init', 'mshc_register_rewrite');

add_filter('query_vars', function ($vars) {
    $vars[] = 'mshc_code';
    return $vars;
});

add_action('template_redirect', function () {
    $code = get_query_var('mshc_code');
    if ($code === '' || $code === null) {
        return;
    }
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    echo 'mshc-ok:' . preg_replace('/[^A-Za-z0-9]/', '', $code);
    exit;
});

add_action('rest_api_init', function () {
    register_rest_route('mshc/v1', '/ping', [
        'methods'             => 'GET',
        'callback'            => function () {
            return ['ok' => true];
        },
        'permission_callback' => '__return_true',
    ]);
});

/* ---------------------------------------------------------------------------
 * Checks. Each returns [label, status, detail]; status is ok|warn|fail|info.
 * ------------------------------------------------------------------------- */

function mshc_checks_environment() {
    $rows = [];

    $php = PHP_VERSION;
    $rows[] = ['PHP version', version_compare($php, '8.1', '>=') ? 'ok' : (version_compare($php, '7.4', '>=') ? 'warn' : 'fail'), $php];
    $rows[] = ['WordPress version', 'info', get_bloginfo('version')];
    $rows[] = ['memory_limit', 'info', ini_get('memory_limit') . ' (WP_MEMORY_LIMIT ' . WP_MEMORY_LIMIT . ')'];
    $rows[] = ['max_execution_time', 'info', ini_get('max_execution_time') . ' s'];
    $rows[] = ['upload_max_filesize', 'info', ini_get('upload_max_filesize')];
    $rows[] = ['Timezone', 'info', wp_timezone_string() . ' · server now ' . gmdate('Y-m-d H:i:s') . ' UTC'];

    $extensions = [
        'curl'      => 'HTTP requests (BGG)',
        'openssl'   => 'HTTPS',
        'simplexml' => 'parse BGG XML',
        'dom'       => 'parse BGG XML',
        'mbstring'  => 'Unicode strings',
        'intl'      => 'i18n helpers (optional)',
        'gd'        => 'QR image generation',
        'imagick'   => 'QR image generation (alternative to gd)',
        'zip'       => 'exports (optional)',
    ];
    foreach ($extensions as $ext => $why) {
        $loaded = extension_loaded($ext);
        $optional = in_array($ext, ['intl', 'imagick', 'zip'], true);
        $rows[] = ["ext: $ext", $loaded ? 'ok' : ($optional ? 'warn' : 'fail'), ($loaded ? 'loaded' : 'missing') . " · $why"];
    }

    foreach (['DISALLOW_FILE_MODS', 'DISALLOW_FILE_EDIT', 'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON'] as $const) {
        $value = defined($const) ? var_export(constant($const), true) : 'not defined';
        $rows[] = ["const $const", 'info', $value];
    }

    return $rows;
}

function mshc_check_custom_plugin() {
    return ['Custom plugin is running', 'ok', 'You are looking at its output: ' . plugin_basename(__FILE__)];
}

function mshc_check_db() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = $wpdb->prefix . MSHC_TABLE;
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        note varchar(64) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset;");

    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    $inserted = $exists && $wpdb->insert($table, ['note' => 'hello']) === 1;
    $count = $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $table") : 0;
    $error = $wpdb->last_error;
    $wpdb->query("DROP TABLE IF EXISTS $table");

    $server = $wpdb->db_server_info();
    if ($exists && $inserted && $count > 0) {
        return ['Own DB table (create/insert/select/drop)', 'ok', "works · DB server $server"];
    }
    return ['Own DB table (create/insert/select/drop)', 'fail', 'exists=' . var_export($exists, true) . ' inserted=' . var_export($inserted, true) . ' error=' . $error];
}

function mshc_check_role() {
    add_role('mshc_test_role', 'MSHC Test Role', ['read' => true]);
    $ok = get_role('mshc_test_role') !== null;
    remove_role('mshc_test_role');
    return ['Custom user role', $ok ? 'ok' : 'fail', $ok ? 'add_role/remove_role works' : 'add_role failed'];
}

function mshc_check_uploads() {
    $dir = wp_upload_dir();
    if (!empty($dir['error'])) {
        return ['Uploads dir writable', 'fail', $dir['error']];
    }
    $ok = wp_is_writable($dir['basedir']);
    return ['Uploads dir writable', $ok ? 'ok' : 'fail', $dir['basedir']];
}

/**
 * @param callable $expect function (int $code, string $body): bool
 */
function mshc_http_check($label, $url, callable $expect, $okNote = '', $loopback = false) {
    $args = ['timeout' => 20, 'redirection' => 3];
    if ($loopback) {
        $args['sslverify'] = apply_filters('https_local_ssl_verify', false);
    }
    $start = microtime(true);
    $res = wp_remote_get($url, $args);
    $ms = (int) round((microtime(true) - $start) * 1000);

    if (is_wp_error($res)) {
        return [$label, 'fail', $url . ' → ' . $res->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    $ok = $expect($code, $body);
    $detail = "$url → HTTP $code in {$ms} ms";
    if ($ok && $okNote !== '') {
        $detail .= " · $okNote";
    }
    if (!$ok) {
        $detail .= ' · body: ' . substr(trim(wp_strip_all_tags($body)), 0, 120);
    }
    return [$label, $ok ? 'ok' : 'fail', $detail];
}

function mshc_checks_http() {
    $reachable = function ($code) {
        return $code > 0;
    };
    return [
        mshc_http_check(
            'Outbound HTTPS to BGG',
            'https://boardgamegeek.com/xmlapi2/thing?id=13',
            $reachable,
            'reachable (401 is expected without an API token)'
        ),
        mshc_http_check(
            'Outbound HTTPS (general)',
            'https://www.google.com/generate_204',
            $reachable
        ),
        mshc_http_check(
            'Loopback: REST API route',
            rest_url('mshc/v1/ping'),
            function ($code, $body) {
                return $code === 200 && strpos($body, '"ok":true') !== false;
            },
            '',
            true
        ),
        mshc_http_check(
            'Loopback: pretty URL /c/<code>',
            home_url('/c/' . MSHC_TEST_CODE),
            function ($code, $body) {
                return $code === 200 && trim($body) === 'mshc-ok:' . MSHC_TEST_CODE;
            },
            'the planned QR URL shape works',
            true
        ),
        mshc_http_check(
            'Loopback: wp-cron.php',
            site_url('wp-cron.php'),
            function ($code) {
                return $code === 200;
            },
            'an external pinger can call this URL',
            true
        ),
    ];
}

function mshc_check_cron() {
    $data = get_option(MSHC_OPT_CRON, ['activated' => 0, 'runs' => []]);
    $runs = $data['runs'];
    $next = wp_next_scheduled(MSHC_CRON_HOOK);
    $fmt = function ($ts) {
        return $ts ? wp_date('Y-m-d H:i:s', $ts) : '—';
    };

    $detail = sprintf(
        'activated %s · %d run(s) · last %s · next scheduled %s',
        $fmt($data['activated']),
        count($runs),
        $fmt(end($runs) ?: 0),
        $fmt($next ?: 0)
    );
    if (count($runs) >= 2) {
        $gaps = [];
        for ($i = 1; $i < count($runs); $i++) {
            $gaps[] = $runs[$i] - $runs[$i - 1];
        }
        $detail .= sprintf(' · gap between runs: min %d s, max %d s (target 300 s)', min($gaps), max($gaps));
    }

    if (count($runs) > 0) {
        return ['WP-Cron (5-minute test job)', 'ok', $detail];
    }
    $age = time() - (int) $data['activated'];
    if ($age < 15 * MINUTE_IN_SECONDS) {
        return ['WP-Cron (5-minute test job)', 'info', $detail . ' · reload this page in ~15 minutes'];
    }
    return ['WP-Cron (5-minute test job)', 'warn', $detail . ' · no run yet: WP-Cron only fires on visits; an external pinger or a real cron job is needed'];
}

function mshc_check_mail() {
    $result = get_option(MSHC_OPT_MAIL);
    if (!$result) {
        return ['wp_mail', 'info', 'not tested yet: use the form below'];
    }
    $detail = sprintf('%s → %s at %s', $result['ok'] ? 'accepted' : 'FAILED', $result['to'], wp_date('Y-m-d H:i:s', $result['time']));
    if (!empty($result['error'])) {
        $detail .= ' · ' . $result['error'];
    }
    if ($result['ok']) {
        $detail .= ' · check that it arrived (and not in spam)';
    }
    return ['wp_mail', $result['ok'] ? 'ok' : 'fail', $detail];
}

/* ---------------------------------------------------------------------------
 * Mail test handler
 * ------------------------------------------------------------------------- */

add_action('admin_post_mshc_send_mail', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Not allowed');
    }
    check_admin_referer('mshc_send_mail');

    $to = sanitize_email(wp_unslash($_POST['mshc_to'] ?? ''));
    $error = '';
    $capture = function ($wp_error) use (&$error) {
        $error = $wp_error->get_error_message();
    };
    add_action('wp_mail_failed', $capture);
    $ok = $to !== '' && wp_mail(
        $to,
        'MS Hosting Check: test mail',
        "This is a test mail from the MS Hosting Check plugin on " . home_url() . ".\n\nIf you see this, wp_mail works."
    );
    remove_action('wp_mail_failed', $capture);

    update_option(MSHC_OPT_MAIL, ['ok' => $ok, 'to' => $to, 'time' => time(), 'error' => $to === '' ? 'invalid address' : $error], false);
    wp_safe_redirect(admin_url('tools.php?page=ms-hosting-check'));
    exit;
});

/* ---------------------------------------------------------------------------
 * Admin page: Tools → MS Hosting Check
 * ------------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_management_page('MS Hosting Check', 'MS Hosting Check', 'manage_options', 'ms-hosting-check', 'mshc_render_page');
});

function mshc_render_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $sections = [
        'Plugin & environment' => array_merge([mshc_check_custom_plugin()], mshc_checks_environment()),
        'Storage & users'      => [mshc_check_db(), mshc_check_role(), mshc_check_uploads()],
        'HTTP'                 => mshc_checks_http(),
        'Background jobs'      => [mshc_check_cron()],
        'Email'                => [mshc_check_mail()],
    ];
    $icons = ['ok' => '✅', 'warn' => '⚠️', 'fail' => '❌', 'info' => 'ℹ️'];

    $plain = 'MS Hosting Check · ' . home_url() . ' · ' . wp_date('Y-m-d H:i') . "\n";
    ?>
    <div class="wrap">
        <h1>MS Hosting Check</h1>
        <p>Temporary diagnostics for the Ausleihe app. <strong>Deactivate and delete this plugin when you are done.</strong> Deactivating removes its cron job, options and test table.</p>

        <?php foreach ($sections as $title => $rows) : ?>
            <h2><?php echo esc_html($title); ?></h2>
            <table class="widefat striped" style="max-width: 1100px">
                <tbody>
                <?php foreach ($rows as [$label, $status, $detail]) :
                    $plain .= sprintf("[%s] %s: %s\n", strtoupper($status), $label, $detail);
                    ?>
                    <tr>
                        <td style="width: 2em"><?php echo $icons[$status]; ?></td>
                        <td style="width: 22em"><strong><?php echo esc_html($label); ?></strong></td>
                        <td><code style="white-space: pre-wrap"><?php echo esc_html($detail); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>

        <h2>Send a test mail</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="mshc_send_mail">
            <?php wp_nonce_field('mshc_send_mail'); ?>
            <input type="email" name="mshc_to" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="regular-text" required>
            <?php submit_button('Send test mail', 'secondary', 'submit', false); ?>
        </form>

        <h2>Plain-text summary</h2>
        <p>Copy this and paste it back into the conversation.</p>
        <textarea readonly rows="16" style="width: 100%; max-width: 1100px; font-family: monospace" onclick="this.select()"><?php echo esc_textarea($plain); ?></textarea>
    </div>
    <?php
}
