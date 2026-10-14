<?php
/**
 * Unit-Tests für lib/bwsp.php (ohne Datenbank und ohne WordPress).
 * Aufruf: php tests/unit.php
 */
declare(strict_types=1);

define('BWSP_ROOT', dirname(__DIR__));
require BWSP_ROOT . '/lib/bwsp.php';
require BWSP_ROOT . '/lib/io.php';

$GLOBALS['fails'] = 0;
$GLOBALS['count'] = 0;

function ok(bool $cond, string $label): void
{
    $GLOBALS['count']++;
    if (!$cond) {
        $GLOBALS['fails']++;
        echo "FAIL: $label\n";
    }
}

function throws(callable $fn, string $label): void
{
    try {
        $fn();
        ok(false, $label . ' (keine Exception)');
    } catch (BwspError $e) {
        ok(true, $label);
    }
}

$tmp = sys_get_temp_dir() . '/bwsp-unit-' . bin2hex(random_bytes(4));
mkdir($tmp);

// --- Namen und Präfix ---
$p = bwsp_parse_name('2606-johndow');
ok($p['number'] === '2606' && $p['client'] === 'johndow', 'Name 2606-johndow');
ok(bwsp_parse_name('12-a-b-c')['client'] === 'a-b-c', 'Name mit mehreren Bindestrichen');
foreach (['', 'johndow', '2606', '2606-', '2606-John', '2606_john', '2606-jo hn', '../2606-x', '2606-x/../y', '1-x', '1234567-x', "2606-x\n", '2606--x', '2606-x-', str_repeat('1', 5) . '-' . str_repeat('a', 60)] as $bad) {
    throws(function () use ($bad) {
        bwsp_parse_name($bad);
    }, 'Name abgelehnt: ' . json_encode($bad));
}
ok(bwsp_prefix('bw', '2606') === 'bw_2606_', 'Präfix bw_2606_');
throws(function () {
    bwsp_prefix('BW', '1234');
}, 'prefix_base nur klein');
throws(function () {
    bwsp_prefix('bw-x', '1234');
}, 'prefix_base ohne Bindestrich');
throws(function () {
    bwsp_assert_prefix('bw_2606');
}, 'Präfix muss auf _ enden');
throws(function () {
    bwsp_assert_prefix("bw_2606_'; DROP TABLE x; --_");
}, 'Präfix ohne SQL-Zeichen');
ok(strncmp('bw_2606_posts', bwsp_prefix('bw', '26'), 6) !== 0, 'bw_26_ ist kein Anfang von bw_2606_');
ok(bwsp_like_escape('bw_26%\\_') === 'bw\\_26\\%\\\\\\_', 'LIKE-Escape');

// --- Zufall, Salts, wp-config ---
$pw = bwsp_random_password();
ok(strlen($pw) === 24 && !preg_match('/[0O1lI]/', $pw), 'Passwort 24 Zeichen ohne Verwechslungszeichen');
ok(bwsp_random_password() !== bwsp_random_password(), 'Passwörter unterscheiden sich');
$salts = bwsp_salts();
ok(count($salts) === 8 && strlen($salts['AUTH_KEY']) === 64, '8 Salts à 64 Zeichen');
ok($salts['AUTH_KEY'] !== $salts['NONCE_KEY'], 'Salts unterscheiden sich');
$cfg = ['db_name' => "n'ame", 'db_user' => 'u', 'db_pass' => "p\\w'd\"\$x", 'db_host' => 'localhost:3307'];
$code = bwsp_wp_config($cfg, 'bw_2606_', $salts);
file_put_contents("$tmp/wp-config.php", $code);
exec('php -l ' . escapeshellarg("$tmp/wp-config.php") . ' 2>&1', $out, $rc);
ok($rc === 0, 'wp-config.php ist gültiges PHP (Sonderzeichen im Passwort)');
$defs = [];
(static function ($file) use (&$defs) {
    // nur die define()-Zeilen auswerten, ohne wp-settings.php zu laden
    $lines = array_filter(explode("\n", (string)file_get_contents($file)), static function ($l) {
        return strpos($l, 'define(') === 0 || strpos($l, '$table_prefix') === 0;
    });
    $table_prefix = '';
    foreach ($lines as $l) {
        eval($l);
    }
    $defs = ['prefix' => $table_prefix, 'pass' => defined('DB_PASSWORD') ? DB_PASSWORD : null, 'name' => defined('DB_NAME') ? DB_NAME : null];
})("$tmp/wp-config.php");
ok($defs['prefix'] === 'bw_2606_' && $defs['pass'] === "p\\w'd\"\$x" && $defs['name'] === "n'ame", 'wp-config enthält Präfix und Zugangsdaten unverändert');

// --- Signatur ---
$secret = str_repeat('k', 40);
$now = 1_800_000_000;
$body = '{"action":"create","name":"2606-x"}';
$sig = bwsp_sign($secret, (string)$now, $body);
ok(bwsp_verify_signature($secret, (string)$now, $body, $sig, $now), 'Signatur gültig');
ok(bwsp_verify_signature($secret, (string)$now, $body, strtoupper($sig), $now), 'Signatur gültig (Großbuchstaben)');
ok(!bwsp_verify_signature($secret, (string)$now, $body . ' ', $sig, $now), 'Signatur: Body geändert');
ok(!bwsp_verify_signature('x' . $secret, (string)$now, $body, $sig, $now), 'Signatur: falscher Schlüssel');
ok(!bwsp_verify_signature($secret, (string)$now, $body, $sig, $now + 301), 'Signatur: abgelaufen');
ok(!bwsp_verify_signature($secret, (string)$now, $body, $sig, $now - 301), 'Signatur: Zeitstempel in der Zukunft');
ok(!bwsp_verify_signature($secret, 'abc', $body, $sig, $now), 'Signatur: Zeitstempel keine Zahl');
ok(!bwsp_verify_signature($secret, (string)$now, $body, '', $now), 'Signatur: leer');
$cfgN = ['data_dir' => "$tmp/data"];
$real = time();
ok(!bwsp_nonce_seen($cfgN, $sig, $real), 'Nonce: erster Aufruf');
ok(bwsp_nonce_seen($cfgN, $sig, $real), 'Nonce: Wiederholung wird erkannt');
ok(bwsp_nonce_seen($cfgN, 'zz', $real), 'Nonce: ungültiges Format wird abgelehnt');

// --- plugins.txt ---
$list = bwsp_parse_plugins_txt("# Kommentar\n\nclassic-editor\nwordpress-seo inactive  # hinten\nzip:mein-plugin.zip\ndir:bw-wp-bridge\n");
ok(count($list) === 4, 'plugins.txt: 4 Einträge');
ok($list[0] === ['type' => 'wporg', 'ref' => 'classic-editor', 'activate' => true], 'plugins.txt: wp.org-Slug');
ok($list[1]['activate'] === false, 'plugins.txt: inactive');
ok($list[2]['type'] === 'zip' && $list[3]['type'] === 'dir', 'plugins.txt: zip und dir');
foreach (["../evil", "zip:../x.zip", "dir:a/b", "Bad_Slug", "classic-editor foo", "zip:"] as $bad) {
    throws(function () use ($bad) {
        bwsp_parse_plugins_txt($bad);
    }, 'plugins.txt abgelehnt: ' . $bad);
}

// --- ZIP ---
$zipFile = "$tmp/ok.zip";
$z = new ZipArchive();
$z->open($zipFile, ZipArchive::CREATE);
$z->addFromString('wordpress/index.php', 'x');
$z->addFromString('wordpress/wp-content/a.txt', 'a');
$z->addEmptyDir('wordpress/empty');
$z->addFromString('outside.txt', 'skip');
$z->addFromString('__MACOSX/foo', 'skip');
$z->close();
ok(bwsp_unzip($zipFile, "$tmp/out", 'wordpress/') === 2, 'unzip: 2 Dateien');
ok(is_file("$tmp/out/index.php") && is_file("$tmp/out/wp-content/a.txt") && is_dir("$tmp/out/empty"), 'unzip: Struktur ohne Präfix');
ok(!is_file("$tmp/out/outside.txt") && !file_exists("$tmp/out/__MACOSX"), 'unzip: Fremdes wird übersprungen');
ok(bwsp_zip_toplevel($zipFile) === '', 'toplevel: mehrere -> leer');
$z = new ZipArchive();
$z->open("$tmp/plug.zip", ZipArchive::CREATE);
$z->addFromString('my-plugin/my-plugin.php', '<?php');
$z->close();
ok(bwsp_zip_toplevel("$tmp/plug.zip") === 'my-plugin', 'toplevel: ein Ordner');

foreach (['../evil.txt', 'a/../../evil.txt', '/abs.txt', 'C:/win.txt'] as $bad) {
    $evil = "$tmp/evil.zip";
    @unlink($evil);
    $z = new ZipArchive();
    $z->open($evil, ZipArchive::CREATE);
    $z->addFromString($bad, 'pwn');
    $z->close();
    throws(function () use ($evil, $tmp) {
        bwsp_unzip($evil, "$tmp/dest");
    }, 'Zip-Slip abgelehnt: ' . $bad);
}
ok(!file_exists("$tmp/evil.txt") && !file_exists(dirname($tmp) . '/evil.txt'), 'Zip-Slip: nichts außerhalb geschrieben');
throws(function () use ($zipFile, $tmp) {
    bwsp_unzip($zipFile, "$tmp/big", '', 1);
}, 'ZIP-Größenlimit');
ok(!bwsp_zip_ok("$tmp/nonexistent.zip"), 'zip_ok: fehlende Datei');
file_put_contents("$tmp/fake.zip", str_repeat('x', 500));
ok(!bwsp_zip_ok("$tmp/fake.zip"), 'zip_ok: keine ZIP');

// --- Dateisystem ---
mkdir("$tmp/src/sub", 0755, true);
file_put_contents("$tmp/src/a.txt", 'a');
file_put_contents("$tmp/src/sub/b.txt", 'b');
file_put_contents("$tmp/src/.gitkeep", '');
symlink('/etc', "$tmp/src/link");
bwsp_copy_tree("$tmp/src", "$tmp/dst");
ok(is_file("$tmp/dst/sub/b.txt") && !file_exists("$tmp/dst/.gitkeep") && !file_exists("$tmp/dst/link"), 'copy_tree: kopiert, überspringt .gitkeep und Symlinks');
$st = bwsp_dir_stats("$tmp/dst");
ok($st['files'] === 2 && $st['bytes'] === 2, 'dir_stats');
// rrmdir darf Symlinks nicht verfolgen
mkdir("$tmp/keep");
file_put_contents("$tmp/keep/important.txt", 'x');
symlink("$tmp/keep", "$tmp/dst/linkdir");
bwsp_rrmdir("$tmp/dst");
ok(!file_exists("$tmp/dst") && is_file("$tmp/keep/important.txt"), 'rrmdir: folgt keinem Symlink');

// --- Pfadprüfung für Instanzen ---
$target = realpath($tmp);
mkdir("$target/2606-johndow");
$c = ['target_path' => $target];
ok(bwsp_instance_path_ok($c, '2606-johndow', "$target/2606-johndow"), 'instance_path_ok: normaler Fall');
ok(!bwsp_instance_path_ok($c, '2606-other', "$target/2606-johndow"), 'instance_path_ok: Name passt nicht zum Ordner');
symlink("$target/2606-johndow", "$target/2607-link");
ok(!bwsp_instance_path_ok($c, '2607-link', "$target/2607-link"), 'instance_path_ok: Symlink abgelehnt');
mkdir("$target/2606-johndow/2608-sub");
ok(!bwsp_instance_path_ok($c, '2608-sub', "$target/2606-johndow/2608-sub"), 'instance_path_ok: kein direktes Kind');
ok(!bwsp_instance_path_ok($c, '../x', "$target/2606-johndow"), 'instance_path_ok: ungültiger Name');

// --- Registry ---
$rc = ['data_dir' => "$tmp/reg"];
ok(bwsp_registry_read($rc) === [], 'Registry: leer');
bwsp_registry_write($rc, ['2606-a' => ['name' => '2606-a', 'prefix' => 'bw_2606_']]);
ok(bwsp_registry_read($rc)['2606-a']['prefix'] === 'bw_2606_', 'Registry: Round-Trip');
ok(strpos((string)file_get_contents("$tmp/reg/registry.php"), '<?php http_response_code(404); exit; ?>') === 0, 'Registry: PHP-Schutzzeile');
$h = bwsp_lock($rc);
throws(function () use ($rc) {
    // zweiter Prozess würde scheitern; hier per eigener Datei-Handle simulieren
    $h2 = fopen($rc['data_dir'] . '/lock', 'c');
    if (flock($h2, LOCK_EX | LOCK_NB)) {
        throw new Exception('Sperre greift nicht');
    }
    throw new BwspError('gesperrt');
}, 'Sperre blockiert parallelen Zugriff');
fclose($h);
file_put_contents("$tmp/reg/registry.php", 'kaputt');
throws(function () use ($rc) {
    bwsp_registry_read($rc);
}, 'Registry: Beschädigung wird gemeldet');

// --- Konfiguration ---
file_put_contents("$tmp/config.php", "<?php return ['db_name'=>'d','db_user'=>'u','base_url'=>'https://x.de/','secret'=>'" . str_repeat('s', 32) . "','admin_email'=>'a@b.de','target_path'=>'$target'];");
$lc = bwsp_load_config("$tmp/config.php");
ok($lc['base_url'] === 'https://x.de' && $lc['prefix_base'] === 'bw' && $lc['locale'] === 'de_DE', 'Konfig: Defaults und base_url ohne Slash');
foreach ([
    "'secret'=>'kurz'",
    "'secret'=>'" . str_repeat('s', 20) . "CHANGE_ME" . "'",
    "'base_url'=>'ftp://x'",
    "'admin_email'=>'kaputt'",
    "'locale'=>'de-DE'",
    "'prefix_base'=>'Bad'",
    "'target_path'=>'/nonexistent/path'",
    "'db_name'=>''",
] as $override) {
    file_put_contents("$tmp/c2.php", "<?php return array_merge(include '$tmp/config.php', [$override]);");
    throws(function () use ($tmp) {
        bwsp_load_config("$tmp/c2.php");
    }, 'Konfig abgelehnt: ' . $override);
}

// --- IO ---
$cli = bwsp_read_cli(['wp-spawn.php', 'create', '2606-x', '--email=a@b.de', '--title=Hallo Welt', '--self-delete']);
ok($cli['action'] === 'create' && $cli['name'] === '2606-x' && $cli['admin_email'] === 'a@b.de' && $cli['title'] === 'Hallo Welt' && $cli['self_delete'] === true, 'CLI: create');
$cli = bwsp_read_cli(['wp-spawn.php', 'remove', '2606-x', '--yes']);
ok($cli['confirm'] === '2606-x' && $cli['dry_run'] === false, 'CLI: remove --yes');
$cli = bwsp_read_cli(['wp-spawn.php', 'remove', '2606-x', '--dry-run']);
ok($cli['dry_run'] === true && !isset($cli['confirm']), 'CLI: remove --dry-run');
$pp = bwsp_clean_params(['action' => 'preview', 'name' => '2606-x', 'evil' => 'x', 'db_pass' => 'x']);
ok($pp['action'] === 'remove' && $pp['dry_run'] === true && !isset($pp['evil']) && !isset($pp['db_pass']), 'Parameter: preview, unbekannte Felder verworfen');
ok(strpos(bwsp_html_page(bwsp_html_value(['a' => '<script>'])), '<script>') === false, 'HTML: Ausgabe maskiert');

// Aufräumen
bwsp_rrmdir($tmp);
echo $GLOBALS['count'] - $GLOBALS['fails'], '/', $GLOBALS['count'], " Prüfungen bestanden\n";
exit($GLOBALS['fails'] ? 1 : 0);
