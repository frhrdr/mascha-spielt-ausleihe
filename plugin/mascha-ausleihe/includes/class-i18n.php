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
        'home_scan'     => ['de' => 'Zum Ausleihen oder Zurückgeben scanne den QR-Code auf der Spieleschachtel mit der Kamera deines Handys.', 'en' => 'To borrow or return a game, scan the QR code on its box with your phone camera.'],
        'my_loans'      => ['de' => 'Deine ausgeliehenen Spiele', 'en' => 'Your borrowed games'],
        'no_loans'      => ['de' => 'Du hast gerade keine Spiele ausgeliehen.', 'en' => 'You have no games borrowed right now.'],
        'due_by'        => ['de' => 'Rückgabe bis %s', 'en' => 'Due back by %s'],
        'due_overdue'   => ['de' => 'Überfällig seit %s', 'en' => 'Overdue since %s'],
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
        'borrow'               => ['de' => 'Ausleihen', 'en' => 'Borrow'],
        'borrow_hint'          => ['de' => 'Rückgabe bis %s.', 'en' => 'Due back by %s.'],
        'return'               => ['de' => 'Zurückgeben', 'en' => 'Return'],
        'renew'                => ['de' => 'Verlängern (noch %d×)', 'en' => 'Renew (%d left)'],
        'no_renewals_left'     => ['de' => 'Du kannst diese Ausleihe nicht mehr verlängern.', 'en' => 'This loan cannot be renewed any more.'],
        'found_hint'           => ['de' => 'Steht das Spiel im Regal? Dann wurde die Rückgabe vergessen.', 'en' => 'Is the game on the shelf? Then someone forgot to record the return.'],
        'found'                => ['de' => 'Ich habe es im Regal gefunden', 'en' => 'I found it on the shelf'],
        'found_confirm'        => ['de' => 'Als zurückgegeben markieren? Das Ausleihe-Team wird informiert.', 'en' => 'Mark it as returned? The lending team will be told.'],
        'complete_question'    => ['de' => 'Ist das Spiel vollständig?', 'en' => 'Is the game complete?'],
        'complete_yes'         => ['de' => 'Ja, vollständig', 'en' => 'Yes, complete'],
        'complete_no'          => ['de' => 'Nein, es fehlt etwas oder ist kaputt', 'en' => 'No, something is missing or damaged'],
        'complete_skip'        => ['de' => 'Überspringen', 'en' => 'Skip'],
        'report_link'          => ['de' => 'Fehlt etwas oder ist etwas kaputt?', 'en' => 'Something missing or damaged?'],
        'report_title'         => ['de' => 'Problem melden', 'en' => 'Report a problem'],
        'report_label'         => ['de' => 'Was fehlt oder ist kaputt?', 'en' => 'What is missing or damaged?'],
        'report_send'          => ['de' => 'Melden', 'en' => 'Send report'],
        'cancel'               => ['de' => 'Abbrechen', 'en' => 'Cancel'],
        'done_borrowed'        => ['de' => 'Ausgeliehen. Viel Spaß!', 'en' => 'Borrowed. Have fun!'],
        'done_renewed'         => ['de' => 'Verlängert.', 'en' => 'Renewed.'],
        'done_returned'        => ['de' => 'Zurückgegeben. Danke!', 'en' => 'Returned. Thank you!'],
        'done_thanks'          => ['de' => 'Danke!', 'en' => 'Thank you!'],
        'done_reported'        => ['de' => 'Danke für die Meldung. Das Ausleihe-Team kümmert sich darum.', 'en' => 'Thanks for the report. The lending team will look into it.'],
        'err_not_allowed'      => ['de' => 'Das darfst du hier nicht. Bitte wende dich an das Ausleihe-Team.', 'en' => 'You cannot do that here. Please ask the lending team.'],
        'err_not_lendable'     => ['de' => 'Dieses Spiel kann nicht ausgeliehen werden.', 'en' => 'This game cannot be borrowed.'],
        'err_limit'            => ['de' => 'Du hast schon %d Spiele ausgeliehen, mehr geht gleichzeitig nicht.', 'en' => 'You already have %d games borrowed, which is the limit.'],
        'err_taken'            => ['de' => 'Jemand anderes hat das Spiel gerade ausgeliehen.', 'en' => 'Someone else has just borrowed this game.'],
        'err_no_renewals'      => ['de' => 'Du kannst diese Ausleihe nicht mehr verlängern.', 'en' => 'This loan cannot be renewed any more.'],
        'err_already_returned' => ['de' => 'Diese Ausleihe ist schon beendet.', 'en' => 'This loan has already ended.'],
        'err_empty_report'     => ['de' => 'Bitte beschreibe kurz, was fehlt oder kaputt ist.', 'en' => 'Please describe briefly what is missing or damaged.'],
        'err_failed'           => ['de' => 'Das hat nicht geklappt. Bitte versuche es noch einmal.', 'en' => 'That did not work. Please try again.'],
        'borrow_via_team'      => ['de' => 'Zum Ausleihen wende dich bitte an das Ausleihe-Team.', 'en' => 'To borrow it, please ask the lending team.'],
        'mail_found_subject' => ['de' => 'Im Regal gefunden: %s', 'en' => 'Found on the shelf: %s'],
        'mail_found_body'    => [
            'de' => "%1\$s (Code %2\$s) war an %4\$s ausgeliehen. %3\$s hat es im Regal gefunden und als zurückgegeben markiert.\n\n%5\$s",
            'en' => "%1\$s (code %2\$s) was on loan to %4\$s. %3\$s found it on the shelf and marked it as returned.\n\n%5\$s",
        ],
        'mail_issue_subject' => ['de' => 'Problem gemeldet: %s', 'en' => 'Problem reported: %s'],
        'mail_issue_body'    => [
            'de' => "%3\$s hat ein Problem mit %1\$s (Code %2\$s) gemeldet:\n\n%4\$s\n\n%5\$s",
            'en' => "%3\$s reported a problem with %1\$s (code %2\$s):\n\n%4\$s\n\n%5\$s",
        ],
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
