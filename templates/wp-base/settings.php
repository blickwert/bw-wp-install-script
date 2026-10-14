<?php
/**
 * Einstellungen für jede neue Instanz. Wird von lib/runner.php nach der Installation angewendet.
 */
return [
    // Theme-Ordnername (aus templates/wp-base/themes oder 'wporg_themes'); '' = WordPress-Standard
    'theme'        => '',
    // Themes von wordpress.org, die geladen werden sollen (Slugs)
    'wporg_themes' => [],

    'permalink_structure' => '/%postname%/',
    'timezone_string'     => 'Europe/Berlin',

    // 0 = Suchmaschinen bitten, die Seite nicht zu indexieren (Dev-Server!)
    'blog_public' => 0,

    // "Hello world!", "Beispiel-Seite" und den ersten Kommentar löschen
    'remove_sample_content'  => true,
    // Hello Dolly und Akismet löschen
    'remove_bundled_plugins' => true,
    // twenty*-Themes löschen (das aktive bleibt)
    'remove_default_themes'  => false,

    // Beliebige weitere Optionen (Reading, Discussion, Medien, ...)
    'options' => [
        'date_format'            => 'd.m.Y',
        'time_format'            => 'H:i',
        'start_of_week'          => 1,
        'default_comment_status' => 'closed',
        'default_ping_status'    => 'closed',
        'blogdescription'        => '',
        'users_can_register'     => 0,
    ],
];
