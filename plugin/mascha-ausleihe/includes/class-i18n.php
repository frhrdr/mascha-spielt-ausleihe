<?php

if (!defined('ABSPATH')) {
    exit;
}

/** German + English UI strings for member-facing pages and emails (design §7). */
final class MAUS_I18n {

    private const STRINGS = [
        'login_title'   => ['de' => 'Anmelden', 'en' => 'Sign in'],
        'login_intro'   => ['de' => 'Gib deine E-Mail-Adresse ein. Wir schicken dir einen Link zum Anmelden.', 'en' => 'Enter your email address. We will send you a sign-in link.'],
        'email_label'   => ['de' => 'E-Mail-Adresse', 'en' => 'Email address'],
        'send_link'     => ['de' => 'Link senden', 'en' => 'Send link'],
        'sent_title'    => ['de' => 'Prüfe dein Postfach', 'en' => 'Check your inbox'],
        'sent_text'     => ['de' => 'Falls die Adresse bei uns registriert ist, haben wir dir einen Link geschickt. Er ist %d Minuten gültig.', 'en' => 'If that address is registered, we have sent you a link. It is valid for %d minutes.'],
        'limited_text'  => ['de' => 'Zu viele Versuche. Bitte warte ein paar Minuten und versuche es dann erneut.', 'en' => 'Too many attempts. Please wait a few minutes and try again.'],
        'confirm_title' => ['de' => 'Anmeldung bestätigen', 'en' => 'Confirm sign-in'],
        'confirm_text'  => ['de' => 'Tippe auf den Button, um dich anzumelden.', 'en' => 'Tap the button to sign in.'],
        'invalid_title' => ['de' => 'Link ungültig', 'en' => 'Link not valid'],
        'invalid_text'  => ['de' => 'Dieser Link ist ungültig, abgelaufen oder wurde schon benutzt. Bitte fordere einen neuen an.', 'en' => 'This link is invalid, expired or has already been used. Please request a new one.'],
        'new_link'      => ['de' => 'Neuen Link anfordern', 'en' => 'Request a new link'],
        'hello'         => ['de' => 'Hallo %s', 'en' => 'Hello %s'],
        'role_line'     => ['de' => 'Du bist angemeldet als: %s.', 'en' => 'You are signed in as: %s.'],
        'role_new'      => ['de' => 'neues Mitglied', 'en' => 'new member'],
        'role_trusted'  => ['de' => 'Mitglied', 'en' => 'member'],
        'role_admin'    => ['de' => 'Ausleihe-Team', 'en' => 'lending team'],
        'home_todo'     => ['de' => 'Der Katalog und das Ausleihen folgen bald.', 'en' => 'The catalog and borrowing will follow soon.'],
        'sign_out'      => ['de' => 'Abmelden', 'en' => 'Sign out'],
        'not_found'     => ['de' => 'Seite nicht gefunden.', 'en' => 'Page not found.'],
        'to_home'       => ['de' => 'Zur Übersicht', 'en' => 'To the overview'],
        'code_invalid_title' => ['de' => 'Code ungültig', 'en' => 'Code not valid'],
        'code_invalid_text'  => ['de' => 'Diesen Code gibt es nicht. Bitte prüfe ihn auf dem Aufkleber und tippe ihn erneut ein.', 'en' => 'This code does not exist. Please check it on the sticker and type it again.'],
        'copy_unknown'  => ['de' => 'Zu diesem Code gibt es kein Spiel. Bitte sag dem Ausleihe-Team Bescheid.', 'en' => 'There is no game for this code. Please tell the lending team.'],
        'copy_retired'  => ['de' => 'Dieses Spiel ist nicht mehr in unserer Sammlung.', 'en' => 'This game is no longer in our collection.'],
        'copy_code'     => ['de' => 'Code %s', 'en' => 'Code %s'],
        'expansion'     => ['de' => 'Erweiterung', 'en' => 'Expansion'],
        'players'       => ['de' => '%s Personen', 'en' => '%s players'],
        'minutes'       => ['de' => '%d Min.', 'en' => '%d min'],
        'status_available'     => ['de' => 'Verfügbar', 'en' => 'Available'],
        'status_mine'          => ['de' => 'Du hast dieses Spiel ausgeliehen. Rückgabe bis %s.', 'en' => 'You have borrowed this game. Due back by %s.'],
        'status_mine_overdue'  => ['de' => 'Du hast dieses Spiel ausgeliehen. Es war am %s fällig – bitte bring es zurück.', 'en' => 'You have borrowed this game. It was due on %s – please bring it back.'],
        'status_on_loan'       => ['de' => 'Gerade ausgeliehen.', 'en' => 'Currently on loan.'],
        'status_on_loan_admin' => ['de' => 'Ausgeliehen von %1$s, Rückgabe bis %2$s.', 'en' => 'On loan to %1$s, due back by %2$s.'],
        'status_onsite'        => ['de' => 'Nur vor Ort spielbar, nicht zum Ausleihen.', 'en' => 'On-site only, not for lending.'],
        'borrow_soon'          => ['de' => 'Ausleihen per Scan kommt bald.', 'en' => 'Borrowing by scan is coming soon.'],
        'borrow_via_team'      => ['de' => 'Zum Ausleihen wende dich bitte an das Ausleihe-Team.', 'en' => 'To borrow it, please ask the lending team.'],
        'mail_subject'  => ['de' => 'Dein Anmelde-Link für die Spieleausleihe', 'en' => 'Your sign-in link for the game library'],
        'mail_body'     => [
            'de' => "Hallo %1\$s,\n\nmit diesem Link meldest du dich bei der Spieleausleihe an (gültig für %3\$d Minuten, nur einmal benutzbar):\n\n%2\$s\n\nWenn du das nicht angefordert hast, kannst du diese E-Mail ignorieren.\n\nMascha spielt",
            'en' => "Hello %1\$s,\n\nuse this link to sign in to the game library (valid for %3\$d minutes, works once):\n\n%2\$s\n\nIf you did not request this, you can ignore this email.\n\nMascha spielt",
        ],
    ];

    public static function t(string $key, string $lang, ...$args): string {
        $text = self::STRINGS[$key][$lang] ?? self::STRINGS[$key]['de'] ?? $key;
        return $args ? vsprintf($text, $args) : $text;
    }

    /** Language from the browser, German by default. */
    public static function detect(): string {
        if (in_array($_GET['lang'] ?? '', MAUS_Members::LANGUAGES, true)) {
            return $_GET['lang'];
        }
        return preg_match('/^\s*en/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') ? 'en' : 'de';
    }
}
