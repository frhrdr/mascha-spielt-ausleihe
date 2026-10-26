<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * QR sticker sheets (design §5.1). Prints plain A4 sticker paper on an inkjet
 * printer: a grid of labels, cut out by hand along the hairlines.
 *
 * The QR codes are drawn in the browser by assets/qrcode.js (no PHP library,
 * no Composer on the server). Layout numbers are in millimetres.
 */
final class MAUS_Stickers {

    private const CAP = 'manage_options';

    // Label and sheet geometry. Change here to try other sizes.
    private const LABEL_W = 60;
    private const LABEL_H = 30;
    private const COLS    = 3;
    private const ROWS    = 9;
    private const QR_SIZE = 22;   // QR code incl. its blank border, mm

    public static function boot(): void {
        add_action('admin_post_maus_stickers', [self::class, 'render_sheets']);
        add_action('admin_post_maus_stickers_mark', [self::class, 'mark_printed']);
    }

    public static function render_page(): void {
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.');
        }
        $per_sheet = self::COLS * self::ROWS;
        $unprinted = count(self::select('unprinted'));
        $all = count(self::select('all'));

        echo '<div class="wrap"><h1>QR stickers</h1>';
        if (isset($_GET['marked'])) {
            printf('<div class="notice notice-success"><p>%d copies marked as printed.</p></div>', (int) $_GET['marked']);
        }
        printf(
            '<p>%d active copies, <strong>%d</strong> without a printed sticker. Labels are %d × %d mm, %d per A4 sheet (%d sheets for the unprinted ones).</p>',
            $all, $unprinted, self::LABEL_W, self::LABEL_H, $per_sheet, (int) ceil($unprinted / $per_sheet)
        );
        printf('<p>QR codes point to <code>%s/c/&lt;code&gt;</code>.</p>', esc_html(rtrim((string) MAUS_Settings::get('qr_base_url'), '/')));

        echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" target="_blank">';
        echo '<input type="hidden" name="action" value="maus_stickers">';
        wp_nonce_field('maus_stickers', '_wpnonce', false);
        echo '<p><label><input type="radio" name="scope" value="unprinted" checked> Only copies without a printed sticker</label><br>';
        echo '<label><input type="radio" name="scope" value="all"> All active copies (reprint everything)</label></p>';
        echo '<p><label>Skip the first <input type="number" name="skip" value="0" min="0" max="' . ($per_sheet - 1) . '" style="width:4em"> labels on the first sheet</label> ';
        echo '<span class="description">(to reuse a partly used sheet)</span></p>';
        echo '<p><label><input type="checkbox" name="guides" value="1" checked> Draw light cutting lines</label></p>';
        submit_button('Open printable sheets', 'primary', '', false);
        echo '</form>';
        echo '<p class="description">In the print dialog: paper size A4, scale 100% (not "fit to page"), margins "None", and the printer\'s plain or sticker paper profile. After printing, press "Mark as printed" on the sheet page.</p>';
        echo '</div>';
    }

    public static function render_sheets(): void {
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.');
        }
        check_admin_referer('maus_stickers');
        $scope  = ($_GET['scope'] ?? '') === 'all' ? 'all' : 'unprinted';
        $skip   = max(0, min(self::COLS * self::ROWS - 1, (int) ($_GET['skip'] ?? 0)));
        $guides = !empty($_GET['guides']);
        $copies = self::select($scope);
        $base   = rtrim((string) MAUS_Settings::get('qr_base_url'), '/');

        // Empty cells at the start, so the first sheet can be a partly used one.
        $cells = array_merge(array_fill(0, $skip, null), $copies);
        $sheets = array_chunk($cells, self::COLS * self::ROWS);

        $left = (210 - self::COLS * self::LABEL_W) / 2;
        $top  = (297 - self::ROWS * self::LABEL_H) / 2;
        $text_w = self::LABEL_W - self::QR_SIZE - 2;

        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><title>QR stickers</title>
<style>
@page { size: A4; margin: 0; }
* { box-sizing: border-box; }
body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #ddd; }
.bar { position: sticky; top: 0; z-index: 2; background: #fff; padding: 10px 16px; border-bottom: 1px solid #aaa; display: flex; gap: 16px; align-items: center; }
.bar form { margin: 0; }
.sheet { position: relative; width: 210mm; height: 297mm; margin: 10px auto; background: #fff; page-break-after: always; break-after: page; overflow: hidden; }
.label { position: absolute; width: <?php echo self::LABEL_W; ?>mm; height: <?php echo self::LABEL_H; ?>mm; display: flex; align-items: center; }
.guides .label { outline: 0.15mm solid #bbb; outline-offset: -0.075mm; }
.qr { width: <?php echo self::QR_SIZE; ?>mm; height: <?php echo self::QR_SIZE; ?>mm; margin-left: 1mm; flex: none; }
.qr svg { width: 100%; height: 100%; display: block; shape-rendering: crispEdges; }
.txt { width: <?php echo $text_w; ?>mm; padding-left: 1.5mm; padding-right: 1mm; overflow: hidden; }
.id { font: bold 13pt "Courier New", monospace; letter-spacing: 0.5pt; line-height: 1.1; }
.copy { font-size: 9pt; font-weight: bold; line-height: 1.1; min-height: 1.1em; }
.name { font-size: 7.5pt; line-height: 1.15; margin-top: 0.8mm; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
@media print { body { background: none; } .bar { display: none; } .sheet { margin: 0; } }
</style></head>
<body class="<?php echo $guides ? 'guides' : ''; ?>">
<div class="bar">
  <button onclick="window.print()">Print</button>
  <span><?php echo count($copies); ?> labels on <?php echo count($sheets); ?> sheet(s). Print at 100% scale, margins none.</span>
  <?php if ($copies) : ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="maus_stickers_mark">
    <?php wp_nonce_field('maus_stickers_mark'); ?>
    <input type="hidden" name="ids" value="<?php echo esc_attr(implode(',', array_map(fn($c) => (int) $c->id, $copies))); ?>">
    <button type="submit" onclick="return confirm('Only press this after the stickers were printed correctly. Continue?')">Mark these as printed</button>
  </form>
  <?php endif; ?>
</div>
<?php foreach ($sheets as $cells_on_sheet) : ?>
<div class="sheet">
<?php foreach ($cells_on_sheet as $i => $c) :
    if ($c === null) { continue; }
    $x = $left + ($i % self::COLS) * self::LABEL_W;
    $y = $top + intdiv($i, self::COLS) * self::LABEL_H;
?>
  <div class="label" style="left:<?php echo $x; ?>mm; top:<?php echo $y; ?>mm">
    <div class="qr" data-url="<?php echo esc_attr($base . '/c/' . $c->qr_code); ?>"></div>
    <div class="txt">
      <div class="id"><?php echo esc_html($c->qr_code); ?></div>
      <div class="copy"><?php echo esc_html($c->label); ?></div>
      <div class="name"><?php echo esc_html($c->name); ?></div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
<script src="<?php echo esc_url(plugins_url('assets/qrcode.js', MAUS_FILE)); ?>"></script>
<script>
document.querySelectorAll('.qr').forEach(function (el) {
  var qr = qrcode(0, 'M');
  qr.addData(el.dataset.url);
  qr.make();
  // 4 modules of blank border around the code, as the QR standard asks.
  el.innerHTML = qr.createSvgTag({cellSize: 1, margin: 4, scalable: true});
});
</script>
</body></html><?php
        exit;
    }

    public static function mark_printed(): void {
        global $wpdb;
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.');
        }
        check_admin_referer('maus_stickers_mark');
        $ids = array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
        if ($ids) {
            $in = implode(',', $ids);
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . MAUS_DB::table('copies') . " SET sticker_printed_at = %s WHERE id IN ($in)",
                gmdate('Y-m-d H:i:s')
            ));
        }
        wp_safe_redirect(admin_url('admin.php?page=maus-stickers&marked=' . count($ids)));
        exit;
    }

    /** @return object[] active copies in shelf order */
    private static function select(string $scope): array {
        global $wpdb;
        $where = $scope === 'all' ? '' : 'AND c.sticker_printed_at IS NULL';
        return $wpdb->get_results(
            'SELECT c.id, c.qr_code, c.label, g.name FROM ' . MAUS_DB::table('copies') . ' c
             JOIN ' . MAUS_DB::table('games') . " g ON g.id = c.game_id
             WHERE c.retired = 0 $where ORDER BY g.name, c.id"
        );
    }
}
