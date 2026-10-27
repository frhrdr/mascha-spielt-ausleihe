<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Members (design §5.5): the app's own minimal list, not WordPress users.
 * Includes the CSV import that fills it until there is a member database.
 */
final class MAUS_Members {

    public const ROLES     = ['new', 'trusted', 'admin'];
    public const LANGUAGES = ['de', 'en'];

    public static function find_by_email(string $email): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . MAUS_DB::table('members') . ' WHERE email = %s', strtolower(trim($email))
        )) ?: null;
    }

    public static function find(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . MAUS_DB::table('members') . ' WHERE id = %d', $id
        )) ?: null;
    }

    /** Members by name, each with open_loans (count). A subquery, not GROUP BY, so MariaDB's strict mode is fine. */
    public static function with_loan_counts(bool $active_only = true): array {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT m.*, (SELECT COUNT(*) FROM ' . MAUS_DB::table('loans') . ' l WHERE l.member_id = m.id AND l.returned_at IS NULL) AS open_loans
             FROM ' . MAUS_DB::table('members') . ' m' . ($active_only ? ' WHERE m.active = 1' : '') . ' ORDER BY m.active DESC, m.name'
        );
    }

    /**
     * Adds ($member null) or updates a member from the admin form. An admin
     * cannot take the admin role away from themselves, so the club always
     * keeps one. @return int|string member id, or an error key
     */
    public static function save(?object $member, array $input, object $admin) {
        global $wpdb;
        $row = [
            'name'     => trim((string) ($input['name'] ?? '')),
            'email'    => strtolower(trim((string) ($input['email'] ?? ''))),
            'role'     => (string) ($input['role'] ?? ''),
            'language' => (string) ($input['language'] ?? ''),
        ];
        if ($row['name'] === '' || mb_strlen($row['name']) > 150 || !is_email($row['email'])
            || !in_array($row['role'], self::ROLES, true) || !in_array($row['language'], self::LANGUAGES, true)) {
            return 'member_fields';
        }
        $other = self::find_by_email($row['email']);
        if ($other && (!$member || (int) $other->id !== (int) $member->id)) {
            return 'email_taken';
        }
        if ($member && (int) $member->id === (int) $admin->id && $row['role'] !== 'admin') {
            return 'own_role';
        }
        if ($member) {
            return $wpdb->update(MAUS_DB::table('members'), $row, ['id' => $member->id]) === false ? 'failed' : (int) $member->id;
        }
        $ok = $wpdb->insert(MAUS_DB::table('members'), $row + ['active' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
        return $ok ? (int) $wpdb->insert_id : 'failed';
    }

    /**
     * Deactivating keeps the member and their loan history but ends their
     * sessions and unused links; they can no longer sign in. @return true|string
     */
    public static function set_active(object $member, bool $active, object $admin) {
        global $wpdb;
        if (!$active && (int) $member->id === (int) $admin->id) {
            return 'own_account';
        }
        if ($wpdb->update(MAUS_DB::table('members'), ['active' => $active ? 1 : 0], ['id' => $member->id]) === false) {
            return 'failed';
        }
        if (!$active) {
            MAUS_Auth::revoke((int) $member->id);
        }
        return true;
    }

    /** Welcome email with a sign-in link (§5.5); records when it went out. */
    public static function send_welcome(object $member): bool {
        global $wpdb;
        if (!(int) $member->active) {
            return false;
        }
        $url = MAUS_Auth::issue_link($member, MAUS_Settings::get('welcome_link_days') * DAY_IN_SECONDS);
        if (!MAUS_Mail::send_welcome($member, $url)) {
            error_log('maus: welcome mail could not be sent to member ' . $member->id);
            return false;
        }
        $wpdb->update(MAUS_DB::table('members'), ['welcomed_at' => gmdate('Y-m-d H:i:s')], ['id' => $member->id]);
        return true;
    }

    /** Active members who never got a welcome email, e.g. after a CSV import. */
    public static function unwelcomed(): array {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . MAUS_DB::table('members') . ' WHERE active = 1 AND welcomed_at IS NULL ORDER BY name');
    }

    /**
     * Accepts comma or semicolon separated files (German Excel writes
     * semicolons). Columns: name, email, role, language, external_id.
     *
     * @return array{rows: array[], errors: string[]}
     */
    public static function parse_csv(string $csv): array {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $first = strtok($csv, "\r\n") ?: '';
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $header = fgetcsv($h, 0, $sep, '"', '');
        if (!$header) {
            fclose($h);
            return ['rows' => [], 'errors' => ['The file is empty.']];
        }
        $header = array_map(fn($c) => strtolower(trim((string) $c)), $header);
        foreach (['name', 'email'] as $col) {
            if (!in_array($col, $header, true)) {
                fclose($h);
                return ['rows' => [], 'errors' => ["Column \"$col\" is missing. Expected: name, email, role, language, external_id."]];
            }
        }

        $rows = [];
        $errors = [];
        $seen = [];
        $line = 1;
        while (($cells = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            $r = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $name  = trim($r['name']);
            $email = strtolower(trim($r['email']));
            $role  = strtolower(trim($r['role'] ?? '')) ?: 'new';
            $lang  = strtolower(trim($r['language'] ?? '')) ?: 'de';

            if ($name === '' || !is_email($email)) {
                $errors[] = "Line $line skipped: name missing or email invalid.";
            } elseif (!in_array($role, self::ROLES, true)) {
                $errors[] = "Line $line skipped: role \"$role\" must be one of " . implode(', ', self::ROLES) . '.';
            } elseif (!in_array($lang, self::LANGUAGES, true)) {
                $errors[] = "Line $line skipped: language \"$lang\" must be de or en.";
            } elseif (isset($seen[$email])) {
                $errors[] = "Line $line skipped: $email appears twice.";
            } else {
                $seen[$email] = true;
                $rows[] = [
                    'name'        => $name,
                    'email'       => $email,
                    'role'        => $role,
                    'language'    => $lang,
                    'external_id' => trim($r['external_id'] ?? '') ?: null,
                ];
            }
        }
        fclose($h);
        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Members are matched by email. The import only adds and updates: it
     * never deactivates or deletes anyone who is missing from the file.
     *
     * @return array{new: array[], updated: array[], unchanged: int}
     */
    public static function plan(array $rows): array {
        global $wpdb;
        $existing = [];
        foreach ($wpdb->get_results('SELECT * FROM ' . MAUS_DB::table('members')) as $m) {
            $existing[$m->email] = $m;
        }
        $new = [];
        $updated = [];
        $unchanged = 0;
        foreach ($rows as $row) {
            $m = $existing[$row['email']] ?? null;
            if (!$m) {
                $new[] = $row;
                continue;
            }
            $changes = [];
            foreach (['name', 'role', 'language', 'external_id'] as $f) {
                if ((string) $m->$f !== (string) $row[$f]) {
                    $changes[] = $f === 'role' ? "role {$m->role} → {$row['role']}" : $f;
                }
            }
            if ($changes) {
                $updated[] = ['email' => $row['email'], 'changes' => $changes];
            } else {
                $unchanged++;
            }
        }
        return compact('new', 'updated', 'unchanged');
    }

    /** @return array{new: int, updated: int} */
    public static function apply(array $rows): array {
        global $wpdb;
        if (!$rows) {
            throw new RuntimeException('Refusing to import an empty member list.');
        }
        $plan = self::plan($rows);
        $t = MAUS_DB::table('members');
        $now = gmdate('Y-m-d H:i:s');
        $fields = fn(array $r) => array_intersect_key($r, array_flip(['name', 'role', 'language', 'external_id']));

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($rows as $row) {
                $m = self::find_by_email($row['email']);
                $ok = $m
                    ? $wpdb->update($t, $fields($row), ['id' => $m->id])
                    : $wpdb->insert($t, $fields($row) + ['email' => $row['email'], 'created_at' => $now]);
                if ($ok === false) {
                    throw new RuntimeException('Database error: ' . $wpdb->last_error);
                }
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        return ['new' => count($plan['new']), 'updated' => count($plan['updated'])];
    }
}
