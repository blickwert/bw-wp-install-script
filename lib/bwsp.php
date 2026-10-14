<?php
/**
 * bw-wp-install-script – Kernlogik.
 *
 * Reine Hilfsfunktionen ohne WordPress, damit sie sich einzeln testen lassen
 * (tests/unit.php). Kompatibel mit PHP 7.4+. Alle Funktionen heißen bwsp_*,
 * damit sie nicht mit WordPress kollidieren, wenn runner.php WordPress
 * im selben Prozess lädt.
 */
declare(strict_types=1);

if (!defined('BWSP_VERSION')) {
    define('BWSP_VERSION', '0.1.0');
}
if (!defined('BWSP_ROOT')) {
    define('BWSP_ROOT', dirname(__DIR__));
}

if (!class_exists('BwspError')) {
    /** Fehler mit Meldung, die dem (authentifizierten) Aufrufer angezeigt werden darf. */
    class BwspError extends RuntimeException
    {
    }
}

/* ------------------------------------------------------------------ *
 * Namen, Präfix
 * ------------------------------------------------------------------ */

/**
 * Zerlegt "2606-johndow" in Projektnummer und Kundenkürzel.
 *
 * @return array{name:string,number:string,client:string}
 */
function bwsp_parse_name(string $name): array
{
    if (strlen($name) > 60) {
        throw new BwspError('Name zu lang (max. 60 Zeichen).');
    }
    if (!preg_match('/^(\d{2,6})-([a-z0-9]+(?:-[a-z0-9]+)*)$/D', $name, $m)) {
        throw new BwspError('Ungültiger Name. Erwartet: <Projektnummer>-<kunde>, z. B. 2606-johndow (nur a-z, 0-9, Bindestrich).');
    }
    return ['name' => $name, 'number' => $m[1], 'client' => $m[2]];
}

/** Tabellenpräfix, z. B. "bw_2606_". Der Unterstrich am Ende verhindert, dass bw_26 ein Anfang von bw_2606 ist. */
function bwsp_prefix(string $base, string $number): string
{
    if (!preg_match('/^[a-z][a-z0-9]{0,5}$/D', $base)) {
        throw new BwspError('prefix_base ungültig (a-z0-9, beginnt mit Buchstabe, max. 6 Zeichen).');
    }
    $prefix = $base . '_' . $number . '_';
    bwsp_assert_prefix($prefix);
    return $prefix;
}

function bwsp_assert_prefix(string $prefix): void
{
    if (!preg_match('/^[a-z][a-z0-9_]{2,19}_$/D', $prefix)) {
        throw new BwspError('Ungültiger Tabellenpräfix.');
    }
}

/** Maskiert %, _ und \ für LIKE ... ESCAPE '\'. */
function bwsp_like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/* ------------------------------------------------------------------ *
 * Konfiguration
 * ------------------------------------------------------------------ */

/** @return array<string,mixed> */
function bwsp_load_config(string $file): array
{
    if (!is_file($file)) {
        throw new BwspError('config.php fehlt. config.example.php kopieren und ausfüllen.');
    }
    $user = (static function (string $f) {
        return include $f;
    })($file);
    if (!is_array($user)) {
        throw new BwspError('config.php muss ein Array zurückgeben.');
    }

    $root = BWSP_ROOT;
    $cfg = array_merge([
        'db_host'        => 'localhost',
        'db_name'        => '',
        'db_user'        => '',
        'db_pass'        => '',
        'base_url'       => '',
        'target_path'    => dirname($root),
        'secret'         => '',
        'prefix_base'    => 'bw',
        'admin_user'     => 'admin',
        'admin_email'    => '',
        'locale'         => 'de_DE',
        'wp_config_mode' => 0640,
        'self_delete'    => false,
        'ca_bundle'      => '',
        'data_dir'       => $root . '/data',
        'cache_dir'      => $root . '/cache',
        'template_dir'   => $root . '/templates/wp-base',
    ], $user);

    foreach (['db_name', 'db_user', 'base_url', 'admin_email'] as $k) {
        if (!is_string($cfg[$k]) || $cfg[$k] === '') {
            throw new BwspError("config.php: '$k' fehlt.");
        }
    }
    if (!is_string($cfg['secret']) || strlen($cfg['secret']) < 32 || strpos($cfg['secret'], 'CHANGE_ME') !== false) {
        throw new BwspError("config.php: 'secret' muss ein zufälliger Schlüssel mit mind. 32 Zeichen sein (z. B. openssl rand -hex 32).");
    }
    if (!preg_match('#^https?://[^/\s]+(/[^\s]*)?$#D', $cfg['base_url'])) {
        throw new BwspError("config.php: 'base_url' muss mit http:// oder https:// beginnen.");
    }
    $cfg['base_url'] = rtrim($cfg['base_url'], '/');
    if (!filter_var($cfg['admin_email'], FILTER_VALIDATE_EMAIL)) {
        throw new BwspError("config.php: 'admin_email' ist keine gültige Adresse.");
    }
    if (!preg_match('/^[A-Za-z0-9_.@-]{3,60}$/D', (string)$cfg['admin_user'])) {
        throw new BwspError("config.php: 'admin_user' ungültig.");
    }
    bwsp_assert_locale((string)$cfg['locale']);
    bwsp_prefix((string)$cfg['prefix_base'], '0000'); // validiert die Basis

    $target = realpath((string)$cfg['target_path']);
    if ($target === false || !is_dir($target) || !is_writable($target)) {
        throw new BwspError("config.php: 'target_path' existiert nicht oder ist nicht beschreibbar.");
    }
    $cfg['target_path'] = $target;
    $cfg['wp_config_mode'] = (int)$cfg['wp_config_mode'];
    $cfg['self_delete'] = (bool)$cfg['self_delete'];
    return $cfg;
}

function bwsp_assert_locale(string $locale): void
{
    if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/D', $locale)) {
        throw new BwspError('Ungültiges Locale (z. B. de_DE).');
    }
}

/** Legt ein Verzeichnis an und sperrt es für den Webzugriff. */
function bwsp_ensure_dir(string $dir, int $mode = 0700): void
{
    if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
        throw new BwspError('Verzeichnis nicht anlegbar: ' . basename($dir));
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    if (!is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
}

/* ------------------------------------------------------------------ *
 * Zufall, Salts, wp-config.php
 * ------------------------------------------------------------------ */

function bwsp_random_string(int $length, string $alphabet): string
{
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** Zufälliges Passwort ohne leicht verwechselbare Zeichen. */
function bwsp_random_password(int $length = 24): string
{
    return bwsp_random_string($length, 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-_.!');
}

/** @return array<string,string> */
function bwsp_salts(): array
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_=+!@#%^&*()[]{}<>~.,:;?|';
    $salts = [];
    foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $k) {
        $salts[$k] = bwsp_random_string(64, $alphabet);
    }
    return $salts;
}

/** @param array<string,mixed> $cfg */
function bwsp_wp_config(array $cfg, string $prefix, array $salts): string
{
    bwsp_assert_prefix($prefix);
    $e = static function ($v): string {
        return var_export($v, true);
    };
    $out = "<?php\n// Erzeugt von bw-wp-install-script " . BWSP_VERSION . "\n";
    $out .= "define('DB_NAME', " . $e($cfg['db_name']) . ");\n";
    $out .= "define('DB_USER', " . $e($cfg['db_user']) . ");\n";
    $out .= "define('DB_PASSWORD', " . $e($cfg['db_pass']) . ");\n";
    $out .= "define('DB_HOST', " . $e($cfg['db_host']) . ");\n";
    $out .= "define('DB_CHARSET', 'utf8mb4');\ndefine('DB_COLLATE', '');\n\n";
    foreach ($salts as $k => $v) {
        $out .= "define('$k', " . $e($v) . ");\n";
    }
    $out .= "\n\$table_prefix = " . $e($prefix) . ";\n\n";
    $out .= "define('WP_DEBUG', false);\ndefine('DISALLOW_FILE_EDIT', true);\ndefine('FS_METHOD', 'direct');\n\n";
    $out .= "if (!defined('ABSPATH')) {\n\tdefine('ABSPATH', __DIR__ . '/');\n}\nrequire_once ABSPATH . 'wp-settings.php';\n";
    return $out;
}

/* ------------------------------------------------------------------ *
 * Datenbank
 * ------------------------------------------------------------------ */

/** @param array<string,mixed> $cfg */
function bwsp_db_connect(array $cfg): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $host = (string)$cfg['db_host'];
    $port = null;
    $socket = null;
    if (preg_match('/^(.*):(\d+)$/D', $host, $m)) {
        $host = $m[1];
        $port = (int)$m[2];
    } elseif (preg_match('#^(.*?):(/.*)$#', $host, $m)) {
        $host = $m[1];
        $socket = $m[2];
    }
    try {
        $db = new mysqli($host, (string)$cfg['db_user'], (string)$cfg['db_pass'], (string)$cfg['db_name'], $port, $socket);
    } catch (mysqli_sql_exception $e) {
        throw new BwspError('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
    }
    $db->set_charset('utf8mb4');
    return $db;
}

/**
 * Alle Tabellen der Datenbank, die mit dem Präfix beginnen.
 *
 * @return string[]
 */
function bwsp_tables_with_prefix(mysqli $db, string $prefix): array
{
    bwsp_assert_prefix($prefix);
    $like = bwsp_like_escape($prefix) . '%';
    $st = $db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ESCAPE '\\\\' ORDER BY TABLE_NAME");
    $st->bind_param('s', $like);
    $st->execute();
    $st->bind_result($name);
    $tables = [];
    while ($st->fetch()) {
        // zweite Prüfung in PHP, falls LIKE/Collation überraschend matcht
        if (strncmp((string)$name, $prefix, strlen($prefix)) === 0 && preg_match('/^[A-Za-z0-9_]+$/D', (string)$name)) {
            $tables[] = (string)$name;
        }
    }
    $st->close();
    return $tables;
}

/**
 * Löscht genau die übergebenen Tabellen – jede muss mit dem Präfix beginnen.
 *
 * @param string[] $tables
 */
function bwsp_drop_tables(mysqli $db, string $prefix, array $tables): int
{
    bwsp_assert_prefix($prefix);
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $n = 0;
    foreach ($tables as $t) {
        if (strncmp($t, $prefix, strlen($prefix)) !== 0 || !preg_match('/^[A-Za-z0-9_]+$/D', $t)) {
            throw new BwspError('Abbruch: Tabelle gehört nicht zum Präfix.');
        }
        $db->query('DROP TABLE IF EXISTS `' . $t . '`');
        $n++;
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    return $n;
}

/* ------------------------------------------------------------------ *
 * Registry und Sperre
 * ------------------------------------------------------------------ */

const BWSP_REGISTRY_GUARD = "<?php http_response_code(404); exit; ?>\n";

/** @return array<string,array<string,mixed>> */
function bwsp_registry_read(array $cfg): array
{
    $file = $cfg['data_dir'] . '/registry.php';
    if (!is_file($file)) {
        return [];
    }
    $raw = (string)file_get_contents($file);
    if (strpos($raw, BWSP_REGISTRY_GUARD) !== 0) {
        throw new BwspError('Registry beschädigt (Kopfzeile fehlt).');
    }
    $data = json_decode(substr($raw, strlen(BWSP_REGISTRY_GUARD)), true);
    if (!is_array($data)) {
        throw new BwspError('Registry beschädigt (kein gültiges JSON).');
    }
    return $data;
}

function bwsp_registry_write(array $cfg, array $data): void
{
    bwsp_ensure_dir($cfg['data_dir']);
    $file = $cfg['data_dir'] . '/registry.php';
    $tmp = $file . '.tmp';
    ksort($data);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($tmp, BWSP_REGISTRY_GUARD . $json . "\n", LOCK_EX) === false) {
        throw new BwspError('Registry nicht schreibbar.');
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $file)) {
        throw new BwspError('Registry nicht ersetzbar.');
    }
}

/**
 * Verhindert parallele Läufe. Das Handle muss der Aufrufer bis zum Ende behalten.
 *
 * @return resource
 */
function bwsp_lock(array $cfg)
{
    bwsp_ensure_dir($cfg['data_dir']);
    $h = fopen($cfg['data_dir'] . '/lock', 'c');
    if ($h === false || !flock($h, LOCK_EX | LOCK_NB)) {
        throw new BwspError('Es läuft bereits ein anderer Vorgang. Bitte kurz warten.');
    }
    return $h;
}

/* ------------------------------------------------------------------ *
 * Signatur (Aufruf per HTTPS, z. B. aus WordPress)
 * ------------------------------------------------------------------ */

/** HMAC-SHA256 über "<timestamp>.<rohbody>". */
function bwsp_sign(string $secret, string $timestamp, string $body): string
{
    return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

function bwsp_verify_signature(string $secret, string $timestamp, string $body, string $signature, int $now, int $window = 300): bool
{
    if (!ctype_digit($timestamp) || abs($now - (int)$timestamp) > $window) {
        return false;
    }
    return hash_equals(bwsp_sign($secret, $timestamp, $body), strtolower($signature));
}

/** Merkt sich verwendete Signaturen, damit ein abgefangener Aufruf nicht wiederholt werden kann. */
function bwsp_nonce_seen(array $cfg, string $signature, int $now, int $ttl = 900): bool
{
    if (!preg_match('/^[0-9a-f]{64}$/D', $signature)) {
        return true;
    }
    $dir = $cfg['data_dir'] . '/nonces';
    bwsp_ensure_dir($dir);
    foreach ((array)glob($dir . '/*.n') as $f) {
        if ($now - (int)@filemtime($f) > $ttl) {
            @unlink($f);
        }
    }
    $f = $dir . '/' . $signature . '.n';
    if (is_file($f)) {
        return true;
    }
    touch($f);
    return false;
}

/* ------------------------------------------------------------------ *
 * Downloads und ZIP
 * ------------------------------------------------------------------ */

/** @param array<string,mixed>|null $set */
function bwsp_http_config(?array $set = null): array
{
    static $conf = ['ca_bundle' => ''];
    if ($set !== null) {
        $conf = array_merge($conf, $set);
    }
    return $conf;
}

/** Lädt eine https-URL, entweder als String oder direkt in eine Datei. */
function bwsp_http_get(string $url, ?string $dest = null, int $timeout = 180): ?string
{
    if (strncmp($url, 'https://', 8) !== 0) {
        throw new BwspError('Nur https-Downloads erlaubt.');
    }
    $conf = bwsp_http_config();
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_USERAGENT      => 'bw-wp-install-script/' . BWSP_VERSION,
            CURLOPT_FAILONERROR    => true,
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($conf['ca_bundle'] !== '') {
            $opts[CURLOPT_CAINFO] = $conf['ca_bundle'];
        }
        $fh = null;
        if ($dest !== null) {
            $fh = fopen($dest, 'wb');
            if ($fh === false) {
                throw new BwspError('Zieldatei nicht schreibbar.');
            }
            $opts[CURLOPT_FILE] = $fh;
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($fh) {
            fclose($fh);
        }
        if ($res === false) {
            if ($dest !== null) {
                @unlink($dest);
            }
            throw new BwspError('Download fehlgeschlagen (' . parse_url($url, PHP_URL_HOST) . '): ' . $err);
        }
        return $dest !== null ? null : (string)$res;
    }

    $ctx = ['http' => ['timeout' => $timeout, 'user_agent' => 'bw-wp-install-script/' . BWSP_VERSION, 'follow_location' => 1]];
    if ($conf['ca_bundle'] !== '') {
        $ctx['ssl'] = ['cafile' => $conf['ca_bundle']];
    }
    $data = @file_get_contents($url, false, stream_context_create($ctx));
    if ($data === false) {
        throw new BwspError('Download fehlgeschlagen (' . parse_url($url, PHP_URL_HOST) . ').');
    }
    if ($dest !== null) {
        file_put_contents($dest, $data);
        return null;
    }
    return $data;
}

function bwsp_zip_ok(string $file, string $mustContain = ''): bool
{
    if (!is_file($file) || filesize($file) < 100) {
        return false;
    }
    $z = new ZipArchive();
    if ($z->open($file, ZipArchive::CHECKCONS) !== true) {
        return false;
    }
    $ok = $mustContain === '' || $z->locateName($mustContain) !== false;
    $z->close();
    return $ok;
}

/** Lädt eine ZIP in den Cache (atomar) und prüft sie; nutzt den Cache, solange er jünger als $ttl ist. */
function bwsp_cached_download(string $url, string $file, int $ttl, string $mustContain = ''): string
{
    if (is_file($file) && (time() - (int)filemtime($file)) < $ttl && bwsp_zip_ok($file, $mustContain)) {
        return $file;
    }
    bwsp_ensure_dir(dirname($file));
    $part = $file . '.part';
    bwsp_http_get($url, $part);
    if (!bwsp_zip_ok($part, $mustContain)) {
        @unlink($part);
        throw new BwspError('Heruntergeladene ZIP ist ungültig: ' . basename($file));
    }
    rename($part, $file);
    return $file;
}

/**
 * Pfad zur WordPress-ZIP (Locale-Paket) im Cache; lädt bei Bedarf neu.
 * Ist wordpress.org nicht erreichbar, wird die neueste gecachte Version genutzt.
 *
 * @param array<string,mixed> $cfg
 */
function bwsp_core_package(array $cfg): string
{
    $locale = (string)$cfg['locale'];
    bwsp_assert_locale($locale);
    $dir = $cfg['cache_dir'];
    bwsp_ensure_dir($dir);
    try {
        $json = bwsp_http_get('https://api.wordpress.org/core/version-check/1.7/?locale=' . rawurlencode($locale), null, 30);
        $d = json_decode((string)$json, true);
        $offer = $d['offers'][0] ?? null;
        if (!is_array($offer) || empty($offer['download']) || empty($offer['version'])) {
            throw new BwspError('Unerwartete Antwort der WordPress-API.');
        }
        $host = (string)parse_url((string)$offer['download'], PHP_URL_HOST);
        if (!preg_match('/(^|\.)wordpress\.org$/', $host) || !preg_match('/^[0-9][0-9A-Za-z.\-]*$/D', (string)$offer['version'])) {
            throw new BwspError('WordPress-API lieferte unerwartete Daten.');
        }
        $file = $dir . '/wordpress-' . $locale . '-' . $offer['version'] . '.zip';
        if (!bwsp_zip_ok($file, 'wordpress/wp-load.php')) {
            @unlink($file);
            bwsp_cached_download((string)$offer['download'], $file, 0, 'wordpress/wp-load.php');
        }
        return $file;
    } catch (Throwable $e) {
        $found = (array)glob($dir . '/wordpress-' . $locale . '-*.zip');
        usort($found, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        foreach ($found as $f) {
            if (bwsp_zip_ok($f, 'wordpress/wp-load.php')) {
                return $f;
            }
        }
        throw new BwspError('WordPress konnte nicht geladen werden und es gibt keinen Cache: ' . $e->getMessage());
    }
}

function bwsp_assert_safe_rel(string $name): void
{
    if ($name === '' || $name[0] === '/' || strpos($name, "\0") !== false || strpos($name, ':') !== false) {
        throw new BwspError('Unsicherer Pfad in ZIP: ' . $name);
    }
    foreach (explode('/', $name) as $seg) {
        if ($seg === '..') {
            throw new BwspError('Unsicherer Pfad in ZIP: ' . $name);
        }
    }
}

/**
 * Entpackt eine ZIP nach $dest. $strip entfernt ein Präfix (z. B. "wordpress/").
 * Schützt vor Pfaden mit "..", absoluten Pfaden und überlangen Archiven; legt keine Symlinks an.
 *
 * @return int Anzahl der Dateien
 */
function bwsp_unzip(string $zip, string $dest, string $strip = '', int $maxBytes = 536870912): int
{
    $z = new ZipArchive();
    if ($z->open($zip) !== true) {
        throw new BwspError('ZIP nicht lesbar: ' . basename($zip));
    }
    if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
        throw new BwspError('Zielverzeichnis nicht anlegbar.');
    }
    $total = 0;
    $files = 0;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i);
        $name = str_replace('\\', '/', (string)$st['name']);
        if ($strip !== '') {
            if (strncmp($name, $strip, strlen($strip)) !== 0) {
                continue;
            }
            $name = substr($name, strlen($strip));
        }
        if ($name === '' || strncmp($name, '__MACOSX/', 9) === 0) {
            continue;
        }
        bwsp_assert_safe_rel($name);
        $target = rtrim($dest, '/') . '/' . $name;
        if (substr($name, -1) === '/') {
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                throw new BwspError('Verzeichnis nicht anlegbar: ' . $name);
            }
            continue;
        }
        $total += (int)$st['size'];
        if ($total > $maxBytes) {
            throw new BwspError('ZIP ist entpackt zu groß.');
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new BwspError('Verzeichnis nicht anlegbar: ' . dirname($name));
        }
        $in = $z->getStream((string)$st['name']);
        $out = fopen($target, 'wb');
        if ($in === false || $out === false) {
            throw new BwspError('Datei nicht entpackbar: ' . $name);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        @chmod($target, 0644);
        $files++;
    }
    $z->close();
    return $files;
}

/** Name des einzigen Top-Level-Ordners einer ZIP, sonst "". */
function bwsp_zip_toplevel(string $zip): string
{
    $z = new ZipArchive();
    if ($z->open($zip) !== true) {
        return '';
    }
    $tops = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = str_replace('\\', '/', (string)$z->getNameIndex($i));
        if ($name === '' || strncmp($name, '__MACOSX/', 9) === 0) {
            continue;
        }
        $pos = strpos($name, '/');
        $tops[$pos === false ? $name : substr($name, 0, $pos)] = $pos !== false;
    }
    $z->close();
    return count($tops) === 1 && current($tops) ? (string)key($tops) : '';
}

/* ------------------------------------------------------------------ *
 * Dateisystem
 * ------------------------------------------------------------------ */

/** Kopiert einen Ordner rekursiv. Symlinks, .git und .gitkeep werden übersprungen. */
function bwsp_copy_tree(string $src, string $dst): void
{
    if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
        throw new BwspError('Verzeichnis nicht anlegbar: ' . basename($dst));
    }
    foreach ((array)scandir($src) as $item) {
        if ($item === '.' || $item === '..' || $item === '.git' || $item === '.gitkeep' || $item === '.DS_Store') {
            continue;
        }
        $from = $src . '/' . $item;
        $to = $dst . '/' . $item;
        if (is_link($from)) {
            continue;
        }
        if (is_dir($from)) {
            bwsp_copy_tree($from, $to);
        } elseif (!copy($from, $to)) {
            throw new BwspError('Datei nicht kopierbar: ' . $item);
        }
    }
}

/** Löscht rekursiv; Symlinks werden nur entfernt, nie verfolgt. */
function bwsp_rrmdir(string $dir): void
{
    if (is_link($dir) || is_file($dir)) {
        @unlink($dir);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    foreach ((array)scandir($dir) as $item) {
        if ($item !== '.' && $item !== '..') {
            bwsp_rrmdir($dir . '/' . $item);
        }
    }
    @rmdir($dir);
}

/** @return array{files:int,bytes:int} */
function bwsp_dir_stats(string $dir): array
{
    $files = 0;
    $bytes = 0;
    foreach ((array)scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $p = $dir . '/' . $item;
        if (is_link($p)) {
            continue;
        }
        if (is_dir($p)) {
            $s = bwsp_dir_stats($p);
            $files += $s['files'];
            $bytes += $s['bytes'];
        } else {
            $files++;
            $bytes += (int)@filesize($p);
        }
    }
    return ['files' => $files, 'bytes' => $bytes];
}

/**
 * Ist $dir wirklich ein direktes Unterverzeichnis von target_path mit passendem Namen (und kein Symlink)?
 *
 * @param array<string,mixed> $cfg
 */
function bwsp_instance_path_ok(array $cfg, string $name, string $dir): bool
{
    if (!preg_match('/^\d{2,6}-[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name) || is_link($dir) || !is_dir($dir)) {
        return false;
    }
    $real = realpath($dir);
    return $real !== false
        && dirname($real) === $cfg['target_path']
        && basename($real) === $name;
}

/* ------------------------------------------------------------------ *
 * Vorlage (templates/wp-base)
 * ------------------------------------------------------------------ */

/**
 * Parst plugins.txt. Zeilen: "<wp.org-slug>", "zip:<datei.zip>", "dir:<ordner>", optional gefolgt von "inactive".
 *
 * @return array<int,array{type:string,ref:string,activate:bool}>
 */
function bwsp_parse_plugins_txt(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) as $n => $line) {
        $line = trim((string)preg_replace('/\s+#.*$/', '', trim($line)));
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = preg_split('/\s+/', $line);
        $ref = $parts[0];
        $activate = !(isset($parts[1]) && strtolower($parts[1]) === 'inactive');
        if (isset($parts[1]) && strtolower($parts[1]) !== 'inactive') {
            throw new BwspError('plugins.txt Zeile ' . ($n + 1) . ': unbekannte Option "' . $parts[1] . '".');
        }
        if (strncmp($ref, 'zip:', 4) === 0 || strncmp($ref, 'dir:', 4) === 0) {
            $type = substr($ref, 0, 3);
            $ref = substr($ref, 4);
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $ref)) {
                throw new BwspError('plugins.txt Zeile ' . ($n + 1) . ': ungültiger Dateiname.');
            }
        } else {
            $type = 'wporg';
            if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', $ref)) {
                throw new BwspError('plugins.txt Zeile ' . ($n + 1) . ': ungültiger Plugin-Slug.');
            }
        }
        $out[] = ['type' => $type, 'ref' => $ref, 'activate' => $activate];
    }
    return $out;
}

/** @return array<string,mixed> */
function bwsp_load_settings(array $cfg): array
{
    $defaults = [
        'theme'                  => '',
        'wporg_themes'           => [],
        'permalink_structure'    => '/%postname%/',
        'timezone_string'        => 'Europe/Berlin',
        'blog_public'            => 0,
        'remove_sample_content'  => true,
        'remove_bundled_plugins' => true,
        'remove_default_themes'  => false,
        'options'                => [],
    ];
    $file = $cfg['template_dir'] . '/settings.php';
    if (!is_file($file)) {
        return $defaults;
    }
    $user = (static function (string $f) {
        return include $f;
    })($file);
    if (!is_array($user)) {
        throw new BwspError('settings.php muss ein Array zurückgeben.');
    }
    return array_merge($defaults, $user);
}

/**
 * Kopiert Themes und Plugins aus der Vorlage bzw. lädt sie von wordpress.org.
 * mu-plugins werden erst nach der Installation kopiert (runner.php), weil sie schon beim Laden von WordPress laufen.
 *
 * @param array<string,mixed> $cfg
 * @param array<string,mixed> $settings
 * @return array{plugins:array<int,array{folder:string,activate:bool}>,themes:string[]}
 */
function bwsp_apply_template(array $cfg, string $wpDir, array $settings): array
{
    $tpl = $cfg['template_dir'];
    $content = $wpDir . '/wp-content';
    $themesDir = $content . '/themes';
    $pluginsDir = $content . '/plugins';
    $installedThemes = [];
    $plugins = [];

    // Themes: Ordner und ZIPs aus templates/wp-base/themes
    if (is_dir($tpl . '/themes')) {
        foreach ((array)scandir($tpl . '/themes') as $item) {
            if ($item[0] === '.' || is_link($tpl . '/themes/' . $item)) {
                continue;
            }
            $p = $tpl . '/themes/' . $item;
            if (is_dir($p)) {
                bwsp_copy_tree($p, $themesDir . '/' . $item);
                $installedThemes[] = $item;
            } elseif (substr($item, -4) === '.zip') {
                bwsp_unzip($p, $themesDir);
                $top = bwsp_zip_toplevel($p);
                if ($top !== '') {
                    $installedThemes[] = $top;
                }
            }
        }
    }
    foreach ((array)$settings['wporg_themes'] as $slug) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', (string)$slug)) {
            throw new BwspError('settings.php: ungültiger Theme-Slug.');
        }
        $zip = bwsp_cached_download('https://downloads.wordpress.org/theme/' . $slug . '.zip', $cfg['cache_dir'] . '/themes/' . $slug . '.zip', 86400);
        bwsp_unzip($zip, $themesDir);
        $installedThemes[] = $slug;
    }

    // Plugins laut plugins.txt
    $list = is_file($tpl . '/plugins.txt') ? bwsp_parse_plugins_txt((string)file_get_contents($tpl . '/plugins.txt')) : [];
    foreach ($list as $entry) {
        $folder = '';
        if ($entry['type'] === 'wporg') {
            $zip = bwsp_cached_download('https://downloads.wordpress.org/plugin/' . $entry['ref'] . '.zip', $cfg['cache_dir'] . '/plugins/' . $entry['ref'] . '.zip', 86400);
            bwsp_unzip($zip, $pluginsDir);
            $folder = $entry['ref'];
        } elseif ($entry['type'] === 'zip') {
            $zip = $tpl . '/plugins/' . $entry['ref'];
            if (!is_file($zip)) {
                throw new BwspError('plugins.txt: ' . $entry['ref'] . ' fehlt in templates/wp-base/plugins/.');
            }
            bwsp_unzip($zip, $pluginsDir);
            $folder = bwsp_zip_toplevel($zip);
        } else {
            $src = $tpl . '/plugins/' . $entry['ref'];
            if (!is_dir($src)) {
                throw new BwspError('plugins.txt: Ordner ' . $entry['ref'] . ' fehlt in templates/wp-base/plugins/.');
            }
            bwsp_copy_tree($src, $pluginsDir . '/' . $entry['ref']);
            $folder = $entry['ref'];
        }
        if ($folder === '') {
            throw new BwspError('Plugin ' . $entry['ref'] . ': ZIP braucht genau einen Top-Level-Ordner.');
        }
        $plugins[] = ['folder' => $folder, 'activate' => $entry['activate']];
    }
    return ['plugins' => $plugins, 'themes' => $installedThemes];
}

/* ------------------------------------------------------------------ *
 * Zustand für Aufräumen bei Fehlern
 * ------------------------------------------------------------------ */

/** @param array<string,mixed>|null $set */
function bwsp_state(?array $set = null): array
{
    static $state = [];
    if ($set !== null) {
        $state = array_merge($state, $set);
    }
    return $state;
}

/**
 * Räumt eine halb angelegte Instanz auf. Löscht nur, was dieser Lauf selbst angelegt hat:
 * das Verzeichnis (wenn der Lauf es erzeugt hat) und Tabellen mit dem Präfix (das vorher nachweislich leer war).
 */
function bwsp_rollback(): void
{
    $s = bwsp_state();
    if (empty($s['active'])) {
        return;
    }
    bwsp_state(['active' => false]);
    $cfg = $s['cfg'];
    try {
        if (!empty($s['own_prefix'])) {
            $db = bwsp_db_connect($cfg);
            bwsp_drop_tables($db, $s['prefix'], bwsp_tables_with_prefix($db, $s['prefix']));
            $db->close();
        }
    } catch (Throwable $e) {
        error_log('bw-wp-install-script: Rollback Tabellen: ' . $e->getMessage());
    }
    if (!empty($s['created_dir']) && bwsp_instance_path_ok($cfg, $s['name'], $s['dir'])) {
        bwsp_rrmdir($s['dir']);
    }
}

/* ------------------------------------------------------------------ *
 * Aktionen
 * ------------------------------------------------------------------ */

/**
 * Bereitet eine neue Instanz vor: prüft, legt Verzeichnis an, lädt WordPress, schreibt wp-config.php.
 * Die eigentliche Installation (WordPress laden) passiert danach in runner.php im globalen Scope.
 *
 * @param array<string,mixed> $cfg
 * @param array<string,mixed> $params name, optional title, admin_email, locale
 * @return array<string,mixed> Job für runner.php
 */
function bwsp_create_prepare(array $cfg, array $params): array
{
    $p = bwsp_parse_name((string)($params['name'] ?? ''));
    $name = $p['name'];
    if (!empty($params['locale'])) {
        bwsp_assert_locale((string)$params['locale']);
        $cfg['locale'] = (string)$params['locale'];
    }
    $email = (string)($params['admin_email'] ?? $cfg['admin_email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new BwspError('Ungültige Admin-Mail.');
    }
    $title = trim(strip_tags((string)($params['title'] ?? '')));
    if ($title === '') {
        $title = ucfirst($p['client']);
    }
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 100) : substr($title, 0, 100);

    $prefix = bwsp_prefix((string)$cfg['prefix_base'], $p['number']);
    $dir = $cfg['target_path'] . '/' . $name;
    $url = $cfg['base_url'] . '/' . $name;

    foreach (['mysqli', 'zip'] as $ext) {
        if (!extension_loaded($ext)) {
            throw new BwspError("PHP-Erweiterung '$ext' fehlt.");
        }
    }
    if (!is_dir($cfg['template_dir'])) {
        throw new BwspError('Vorlagenverzeichnis fehlt: templates/wp-base');
    }

    // Registry und Konflikte prüfen
    $registry = bwsp_registry_read($cfg);
    if (isset($registry[$name])) {
        throw new BwspError("Instanz '$name' ist bereits registriert.");
    }
    foreach ($registry as $other) {
        if (($other['prefix'] ?? '') === $prefix) {
            throw new BwspError("Projektnummer {$p['number']} ist schon vergeben (Instanz '{$other['name']}', Präfix $prefix).");
        }
    }
    if (file_exists($dir) || is_link($dir)) {
        throw new BwspError("Verzeichnis '$name' existiert bereits.");
    }
    $db = bwsp_db_connect($cfg);
    $existing = bwsp_tables_with_prefix($db, $prefix);
    $db->close();
    if ($existing) {
        throw new BwspError('In der Datenbank gibt es schon ' . count($existing) . " Tabellen mit dem Präfix $prefix (z. B. {$existing[0]}). Abbruch.");
    }

    // Ab hier gibt es etwas aufzuräumen
    bwsp_state(['active' => true, 'cfg' => $cfg, 'name' => $name, 'dir' => $dir, 'prefix' => $prefix, 'created_dir' => false, 'own_prefix' => true]);
    if (!mkdir($dir, 0755)) {
        bwsp_state(['active' => false]);
        throw new BwspError("Verzeichnis '$name' nicht anlegbar.");
    }
    bwsp_state(['created_dir' => true]);

    @set_time_limit(300);
    $zip = bwsp_core_package($cfg);
    bwsp_unzip($zip, $dir, 'wordpress/');
    if (!is_file($dir . '/wp-load.php')) {
        throw new BwspError('WordPress-Paket unvollständig (wp-load.php fehlt).');
    }

    $conf = bwsp_wp_config($cfg, $prefix, bwsp_salts());
    $confFile = $dir . '/wp-config.php';
    if (file_put_contents($confFile, $conf) === false) {
        throw new BwspError('wp-config.php nicht schreibbar.');
    }
    @chmod($confFile, $cfg['wp_config_mode']);

    $settings = bwsp_load_settings($cfg);
    $tpl = bwsp_apply_template($cfg, $dir, $settings);

    return [
        'name'           => $name,
        'prefix'         => $prefix,
        'dir'            => $dir,
        'url'            => $url,
        'title'          => $title,
        'locale'         => (string)$cfg['locale'],
        'admin_user'     => (string)$cfg['admin_user'],
        'admin_email'    => $email,
        'admin_password' => bwsp_random_password(),
        'settings'       => $settings,
        'plugins'        => $tpl['plugins'],
        'themes'         => $tpl['themes'],
        'mu_src'         => $cfg['template_dir'] . '/mu-plugins',
        'warnings'       => [],
    ];
}

/**
 * Schließt eine Instanz ab: Registry-Eintrag, Aufräumen deaktivieren, Ergebnis für den Aufrufer.
 *
 * @param array<string,mixed> $cfg
 * @param array<string,mixed> $job Job inkl. Ergebnis aus runner.php
 * @return array<string,mixed>
 */
function bwsp_create_finish(array $cfg, array $job): array
{
    $registry = bwsp_registry_read($cfg);
    $registry[$job['name']] = [
        'name'        => $job['name'],
        'prefix'      => $job['prefix'],
        'path'        => $job['dir'],
        'url'         => $job['url'],
        'created'     => gmdate('c'),
        'admin_user'  => $job['admin_user'],
        'admin_email' => $job['admin_email'],
    ];
    bwsp_registry_write($cfg, $registry);
    bwsp_state(['active' => false]);

    return [
        'name'           => $job['name'],
        'prefix'         => $job['prefix'],
        'path'           => $job['dir'],
        'url'            => $job['url'],
        'login_url'      => $job['url'] . '/wp-login.php',
        'admin_user'     => $job['admin_user'],
        'admin_email'    => $job['admin_email'],
        'admin_password' => $job['admin_password'],
        'password_note'  => 'Wird nur dieses eine Mal angezeigt und nirgends gespeichert.',
        'warnings'       => $job['warnings'],
    ];
}

/**
 * Listet die registrierten Instanzen.
 *
 * @return array<int,array<string,mixed>>
 */
function bwsp_list(array $cfg): array
{
    $out = [];
    foreach (bwsp_registry_read($cfg) as $e) {
        $e['dir_exists'] = is_dir((string)($e['path'] ?? ''));
        $out[] = $e;
    }
    return $out;
}

/**
 * Entfernt eine Instanz (Tabellen mit dem Präfix + Verzeichnis).
 * Ohne $confirm (== Name) oder mit $dryRun wird nur gezeigt, was betroffen wäre.
 *
 * @param array<string,mixed> $cfg
 * @return array<string,mixed>
 */
function bwsp_remove(array $cfg, string $name, ?string $confirm, bool $dryRun = false): array
{
    $p = bwsp_parse_name($name);
    $registry = bwsp_registry_read($cfg);
    if (!isset($registry[$name])) {
        throw new BwspError("Instanz '$name' ist nicht in der Registry – es wird nichts gelöscht.");
    }
    $entry = $registry[$name];
    $prefix = bwsp_prefix((string)$cfg['prefix_base'], $p['number']);
    $dir = $cfg['target_path'] . '/' . $name;

    // Eigene Prüfungen: Registry, Name, Pfad und wp-config.php müssen zusammenpassen
    if (($entry['prefix'] ?? '') !== $prefix) {
        throw new BwspError('Abbruch: Präfix in der Registry passt nicht zum Namen.');
    }
    if (!bwsp_instance_path_ok($cfg, $name, $dir)) {
        throw new BwspError('Abbruch: Verzeichnis ist kein direktes, echtes Unterverzeichnis von target_path.');
    }
    $conf = is_file($dir . '/wp-config.php') ? (string)file_get_contents($dir . '/wp-config.php') : '';
    if (!preg_match('/\$table_prefix\s*=\s*\'' . preg_quote($prefix, '/') . '\'\s*;/', $conf)) {
        throw new BwspError('Abbruch: wp-config.php des Verzeichnisses hat nicht den erwarteten Präfix.');
    }
    if (!preg_match('/define\(\s*\'DB_NAME\'\s*,\s*\'' . preg_quote((string)$cfg['db_name'], '/') . '\'\s*\)/', $conf)) {
        throw new BwspError('Abbruch: wp-config.php gehört zu einer anderen Datenbank.');
    }
    foreach ($registry as $other) {
        $op = (string)($other['prefix'] ?? '');
        if ($op !== $prefix && strncmp($op, $prefix, strlen($prefix)) === 0) {
            throw new BwspError("Abbruch: Präfix überschneidet sich mit {$other['name']}.");
        }
    }

    $db = bwsp_db_connect($cfg);
    $tables = bwsp_tables_with_prefix($db, $prefix);
    $stats = bwsp_dir_stats($dir);
    $preview = [
        'name'        => $name,
        'prefix'      => $prefix,
        'path'        => $dir,
        'tables'      => $tables,
        'table_count' => count($tables),
        'files'       => $stats['files'],
        'bytes'       => $stats['bytes'],
    ];
    if ($dryRun || $confirm === null || $confirm !== $name) {
        $db->close();
        $preview['removed'] = false;
        $preview['note'] = $dryRun
            ? 'Probelauf: es wurde nichts gelöscht.'
            : 'Nichts gelöscht. Zum Löschen den Namen "' . $name . '" als Bestätigung angeben.';
        return $preview;
    }

    bwsp_drop_tables($db, $prefix, $tables);
    $db->close();
    bwsp_rrmdir($dir);
    unset($registry[$name]);
    bwsp_registry_write($cfg, $registry);
    $preview['removed'] = true;
    return $preview;
}
