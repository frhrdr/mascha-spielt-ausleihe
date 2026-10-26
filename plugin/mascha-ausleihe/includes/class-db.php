<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MAUS_DB {

    private const VERSION = '2';
    private const OPTION  = 'maus_db_version';

    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'maus_' . $name;
    }

    public static function maybe_install(): void {
        if (get_option(self::OPTION) !== self::VERSION) {
            self::install();
        }
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $t = fn(string $n) => self::table($n);

        // Datetimes are stored in UTC.
        $schemas = [];

        $schemas[] = "CREATE TABLE {$t('games')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  bgg_id bigint(20) unsigned NOT NULL,
  name varchar(255) NOT NULL,
  year smallint(6) DEFAULT NULL,
  image_url varchar(500) DEFAULT NULL,
  type varchar(10) NOT NULL DEFAULT 'base',
  min_players smallint(6) DEFAULT NULL,
  max_players smallint(6) DEFAULT NULL,
  playtime smallint(6) DEFAULT NULL,
  weight decimal(4,2) DEFAULT NULL,
  removed_from_bgg tinyint(1) NOT NULL DEFAULT 0,
  synced_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY bgg_id (bgg_id),
  KEY name (name(100))
) $charset;";

        $schemas[] = "CREATE TABLE {$t('game_links')} (
  expansion_id bigint(20) unsigned NOT NULL,
  base_game_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (expansion_id,base_game_id)
) $charset;";

        $schemas[] = "CREATE TABLE {$t('copies')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  game_id bigint(20) unsigned NOT NULL,
  bgg_collid bigint(20) unsigned DEFAULT NULL,
  qr_code varchar(8) NOT NULL,
  label varchar(20) NOT NULL DEFAULT '',
  lendable tinyint(1) NOT NULL DEFAULT 1,
  location varchar(100) NOT NULL DEFAULT '',
  condition_notes text,
  retired tinyint(1) NOT NULL DEFAULT 0,
  sticker_printed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY qr_code (qr_code),
  UNIQUE KEY bgg_collid (bgg_collid),
  KEY game_id (game_id)
) $charset;";

        $schemas[] = "CREATE TABLE {$t('members')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(150) NOT NULL,
  email varchar(190) NOT NULL,
  role varchar(10) NOT NULL DEFAULT 'new',
  language varchar(5) NOT NULL DEFAULT 'de',
  active tinyint(1) NOT NULL DEFAULT 1,
  external_id varchar(100) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email)
) $charset;";

        $schemas[] = "CREATE TABLE {$t('loans')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  copy_id bigint(20) unsigned NOT NULL,
  member_id bigint(20) unsigned NOT NULL,
  checked_out_at datetime NOT NULL,
  checked_out_by bigint(20) unsigned DEFAULT NULL,
  due_at datetime NOT NULL,
  extended_count smallint(6) NOT NULL DEFAULT 0,
  returned_at datetime DEFAULT NULL,
  returned_by bigint(20) unsigned DEFAULT NULL,
  return_note text,
  complete tinyint(1) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY copy_open (copy_id,returned_at),
  KEY member_open (member_id,returned_at)
) $charset;";

        $schemas[] = "CREATE TABLE {$t('reservations')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  game_id bigint(20) unsigned NOT NULL,
  member_id bigint(20) unsigned NOT NULL,
  status varchar(12) NOT NULL DEFAULT 'waiting',
  created_at datetime NOT NULL,
  notified_at datetime DEFAULT NULL,
  hold_until datetime DEFAULT NULL,
  held_copy_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY game_status (game_id,status),
  KEY member_id (member_id)
) $charset;";

        $schemas[] = "CREATE TABLE {$t('issues')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  copy_id bigint(20) unsigned NOT NULL,
  reported_by bigint(20) unsigned DEFAULT NULL,
  reported_at datetime NOT NULL,
  text text NOT NULL,
  resolved_at datetime DEFAULT NULL,
  resolved_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY copy_id (copy_id)
) $charset;";

        // Only hashes of tokens are stored, never the tokens themselves.
        $schemas[] = "CREATE TABLE {$t('login_tokens')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  member_id bigint(20) unsigned NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY expires_at (expires_at)
) $charset;";

        $schemas[] = "CREATE TABLE {$t('sessions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  member_id bigint(20) unsigned NOT NULL,
  expires_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY member_id (member_id),
  KEY expires_at (expires_at)
) $charset;";

        foreach ($schemas as $sql) {
            dbDelta($sql);
        }
        update_option(self::OPTION, self::VERSION, false);
    }
}
