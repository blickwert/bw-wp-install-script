<?php
/**
 * Kopie nach config.php, ausfüllen. config.php steht in .gitignore und gehört nie ins Repo.
 * Dateirechte auf dem Server: chmod 600 config.php
 */
return [
    // --- Gemeinsame Datenbank (alle Instanzen, getrennt nur durch den Tabellenpräfix) ---
    'db_host' => 'localhost',            // auch 'host:3307' oder 'localhost:/pfad/zum.sock'
    'db_name' => 'CHANGE_ME',
    'db_user' => 'CHANGE_ME',
    'db_pass' => 'CHANGE_ME',

    // --- Ziel ---
    // Instanzen entstehen als Geschwisterordner dieses Skriptordners: <target_path>/<name>/
    // Standard: der Ordner über dem Skriptordner. Hier nur setzen, wenn es anders sein soll.
    // 'target_path' => '/pfad/zum/webroot',
    // Erreichbare Adresse von target_path. Die Instanz-URL ist base_url/<name>.
    'base_url' => 'https://dev.example.de',

    // --- Schutz ---
    // Zufälliger Schlüssel, mind. 32 Zeichen:  openssl rand -hex 32
    // Wird für Browser-Login und die HMAC-Signatur der HTTPS-API benutzt.
    'secret' => 'CHANGE_ME',

    // --- Standardwerte für neue Instanzen ---
    'prefix_base' => 'bw',               // Ordner 2606-johndow -> Präfix bw_2606_
    'admin_user'  => 'admin',
    'admin_email' => 'admin@example.de',
    'locale'      => 'de_DE',

    // --- Optional ---
    // 'self_delete'    => false,         // wp-spawn.php nach jedem erfolgreichen Anlegen löschen
    // 'wp_config_mode' => 0640,          // Dateirechte der wp-config.php
    // 'ca_bundle'      => '',            // Pfad zu einem CA-Bundle, falls curl keine Zertifikate findet
    // 'template_dir'   => __DIR__ . '/templates/wp-base',
];
