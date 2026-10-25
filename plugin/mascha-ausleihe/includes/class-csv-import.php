<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Imports the BGG collection CSV export (design §5.6 fallback).
 *
 * Two steps, like the API sync will be: plan() computes a report without
 * touching the DB, apply() performs it. A copy is identified by its BGG
 * collection id (collid), so duplicate boxes of one game stay separate.
 */
final class MAUS_CSV_Import {

    /** @return array{rows: array[], errors: string[]} */
    public static function parse(string $csv): array {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);

        $header = fgetcsv($h, 0, ',', '"', '');
        if (!$header) {
            fclose($h);
            return ['rows' => [], 'errors' => ['The file is empty.']];
        }
        $header = array_map(fn($c) => strtolower(trim((string) $c)), $header);
        foreach (['objectid', 'objectname', 'collid'] as $col) {
            if (!in_array($col, $header, true)) {
                fclose($h);
                return ['rows' => [], 'errors' => ["Column \"$col\" is missing. Is this the BGG collection export?"]];
            }
        }

        $rows = [];
        $errors = [];
        $seen = [];
        $line = 1;
        while (($cells = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            $r = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));

            if (isset($r['own']) && trim($r['own']) === '0') {
                continue;
            }
            $collid = (int) $r['collid'];
            $bgg_id = (int) $r['objectid'];
            $name   = trim($r['objectname']);
            if ($collid <= 0 || $bgg_id <= 0 || $name === '') {
                $errors[] = "Line $line skipped: collid, objectid or name missing.";
                continue;
            }
            if (isset($seen[$collid])) {
                $errors[] = "Line $line skipped: collid $collid appears twice.";
                continue;
            }
            $seen[$collid] = true;

            $kind = strtolower(($r['itemtype'] ?? '') . ' ' . ($r['subtype'] ?? '') . ' ' . ($r['objecttype'] ?? ''));
            $rows[] = [
                'collid'      => $collid,
                'bgg_id'      => $bgg_id,
                'name'        => $name,
                'type'        => str_contains($kind, 'expansion') ? 'expansion' : 'base',
                'year'        => self::num($r['yearpublished'] ?? '', 'int'),
                'min_players' => self::num($r['minplayers'] ?? '', 'int'),
                'max_players' => self::num($r['maxplayers'] ?? '', 'int'),
                'playtime'    => self::num($r['playingtime'] ?? '', 'int'),
                'weight'      => self::num($r['avgweight'] ?? '', 'float'),
            ];
        }
        fclose($h);
        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Report of what apply() would do. Touches nothing.
     *
     * @return array{new: array[], updated: int, unchanged: int, retire: array[], blocked: array[]}
     */
    public static function plan(array $rows): array {
        global $wpdb;
        $copies = self::existing_copies();
        $games  = self::existing_games();

        $new = [];
        $updated = 0;
        $unchanged = 0;
        $in_file = [];
        foreach ($rows as $row) {
            $in_file[$row['collid']] = true;
            if (!isset($copies[$row['collid']])) {
                $new[] = $row;
                continue;
            }
            $g = $games[$row['bgg_id']] ?? null;
            $changed = !$g || (int) $copies[$row['collid']]->retired === 1 || self::game_differs($g, $row);
            $changed ? $updated++ : $unchanged++;
        }

        $retire = [];
        $blocked = [];
        $on_loan = array_flip($wpdb->get_col(
            'SELECT copy_id FROM ' . MAUS_DB::table('loans') . ' WHERE returned_at IS NULL'
        ));
        foreach ($copies as $collid => $c) {
            if (isset($in_file[$collid]) || (int) $c->retired === 1) {
                continue;
            }
            $entry = ['copy_id' => (int) $c->id, 'name' => $c->name, 'qr_code' => $c->qr_code];
            if (isset($on_loan[$c->id])) {
                $blocked[] = $entry;
            } else {
                $retire[] = $entry;
            }
        }
        return compact('new', 'updated', 'unchanged', 'retire', 'blocked');
    }

    /** @return array{new: int, updated: int, retired: int} */
    public static function apply(array $rows): array {
        global $wpdb;
        if (!$rows) {
            throw new RuntimeException('Refusing to import an empty collection.');
        }
        $plan = self::plan($rows);
        $now = gmdate('Y-m-d H:i:s');
        $games_t  = MAUS_DB::table('games');
        $copies_t = MAUS_DB::table('copies');

        $wpdb->query('START TRANSACTION');
        try {
            $games = self::existing_games();
            $copies = self::existing_copies();
            $touched = [];

            foreach ($rows as $row) {
                $fields = [
                    'name'             => $row['name'],
                    'type'             => $row['type'],
                    'year'             => $row['year'],
                    'min_players'      => $row['min_players'],
                    'max_players'      => $row['max_players'],
                    'playtime'         => $row['playtime'],
                    'weight'           => $row['weight'],
                    'removed_from_bgg' => 0,
                    'synced_at'        => $now,
                ];
                if (isset($games[$row['bgg_id']])) {
                    $game_id = (int) $games[$row['bgg_id']]->id;
                    self::must($wpdb->update($games_t, $fields, ['id' => $game_id]));
                } else {
                    $fields += ['bgg_id' => $row['bgg_id'], 'created_at' => $now];
                    self::must($wpdb->insert($games_t, $fields));
                    $game_id = (int) $wpdb->insert_id;
                    $games[$row['bgg_id']] = (object) (['id' => $game_id] + $fields);
                }
                $touched[$game_id] = true;

                if (isset($copies[$row['collid']])) {
                    self::must($wpdb->update($copies_t, ['game_id' => $game_id, 'retired' => 0], ['id' => $copies[$row['collid']]->id]));
                } else {
                    self::must($wpdb->insert($copies_t, [
                        'game_id'    => $game_id,
                        'bgg_collid' => $row['collid'],
                        'qr_code'    => self::unique_code(),
                        'created_at' => $now,
                    ]));
                }
            }

            foreach ($plan['retire'] as $c) {
                self::must($wpdb->update($copies_t, ['retired' => 1], ['id' => $c['copy_id']]));
            }

            self::refresh_removed_flags();
            self::label_duplicates(array_keys($touched));
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        return ['new' => count($plan['new']), 'updated' => $plan['updated'], 'retired' => count($plan['retire'])];
    }

    /* ------------------------------------------------------------------ */

    private static function existing_copies(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT c.id, c.bgg_collid, c.qr_code, c.retired, g.name FROM ' . MAUS_DB::table('copies') . ' c
             JOIN ' . MAUS_DB::table('games') . ' g ON g.id = c.game_id
             WHERE c.bgg_collid IS NOT NULL'
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->bgg_collid] = $r;
        }
        return $map;
    }

    private static function existing_games(): array {
        global $wpdb;
        $map = [];
        foreach ($wpdb->get_results('SELECT * FROM ' . MAUS_DB::table('games')) as $g) {
            $map[(int) $g->bgg_id] = $g;
        }
        return $map;
    }

    private static function game_differs(object $g, array $row): bool {
        foreach (['name', 'type', 'year', 'min_players', 'max_players', 'playtime', 'weight'] as $f) {
            if ((string) $g->$f !== (string) ($row[$f] ?? '')) {
                // DECIMAL columns come back as "2.50", compare numerically.
                if ($f === 'weight' && $g->weight !== null && $row['weight'] !== null && (float) $g->weight === (float) $row['weight']) {
                    continue;
                }
                return true;
            }
        }
        return (int) $g->removed_from_bgg === 1;
    }

    private static function unique_code(): string {
        global $wpdb;
        do {
            $code = MAUS_Copy_ID::generate();
            $taken = $wpdb->get_var($wpdb->prepare(
                'SELECT 1 FROM ' . MAUS_DB::table('copies') . ' WHERE qr_code = %s', $code
            ));
        } while ($taken);
        return $code;
    }

    /** A game without any active copy is no longer in the BGG collection. */
    private static function refresh_removed_flags(): void {
        global $wpdb;
        $g = MAUS_DB::table('games');
        $c = MAUS_DB::table('copies');
        $wpdb->query("UPDATE $g SET removed_from_bgg = IF(EXISTS (SELECT 1 FROM $c WHERE $c.game_id = $g.id AND $c.retired = 0), 0, 1)");
    }

    /** Games with several active boxes get labels "#1", "#2", ... */
    private static function label_duplicates(array $game_ids): void {
        global $wpdb;
        $c = MAUS_DB::table('copies');
        foreach ($game_ids as $game_id) {
            $copies = $wpdb->get_results($wpdb->prepare(
                "SELECT id, label FROM $c WHERE game_id = %d AND retired = 0 ORDER BY id", $game_id
            ));
            if (count($copies) < 2) {
                continue;
            }
            foreach ($copies as $i => $copy) {
                if ($copy->label === '') {
                    $wpdb->update($c, ['label' => '#' . ($i + 1)], ['id' => $copy->id]);
                }
            }
        }
    }

    private static function must($result): void {
        global $wpdb;
        if ($result === false) {
            throw new RuntimeException('Database error: ' . $wpdb->last_error);
        }
    }

    private static function num($value, string $as) {
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value) || (float) $value <= 0) {
            return null;
        }
        return $as === 'int' ? (int) $value : round((float) $value, 2);
    }
}
