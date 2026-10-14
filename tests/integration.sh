#!/usr/bin/env bash
# Integrationstest: startet eine lokale MariaDB und spielt wp-spawn.php mit echtem WordPress durch.
#
# Voraussetzungen: php (mysqli, zip, curl), mariadbd + mariadb-install-db + mariadb, curl, openssl.
# Netzwerk: wordpress.org wird erreicht (Core-ZIP und Plugin classic-editor).
# Optional:  BWSP_TEST_CA=/pfad/ca-bundle.crt   BWSP_TEST_CACHE=/pfad/cache
#
# Aufruf: bash tests/integration.sh
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T="$(mktemp -d)"
CACHE="${BWSP_TEST_CACHE:-$T/cache}"
CA="${BWSP_TEST_CA:-}"
PORT_DB=3399
PORT_WEB=8099
SOCK="$T/db.sock"
DB=bwtest
fails=0
checks=0
pids=()

cleanup() {
    for p in "${pids[@]:-}"; do
        if [ -n "$p" ]; then kill "$p" 2>/dev/null || true; fi
    done
    sleep 1
    rm -rf "$T"
}
trap cleanup EXIT

for bin in php mariadbd mariadb-install-db mariadb curl openssl; do
    command -v "$bin" >/dev/null || { echo "SKIP: '$bin' fehlt"; exit 0; }
done

check() { # check "<Beschreibung>" <Befehl...>
    local label="$1"
    shift
    checks=$((checks + 1))
    if "$@" >/dev/null 2>&1; then
        echo "  ok   $label"
    else
        echo "  FAIL $label"
        fails=$((fails + 1))
    fi
}
q() { mariadb -S "$SOCK" -uroot -N -B "$DB" -e "$1"; }
eq() { [ "$(q "$1")" = "$2" ]; }
spawn() { (cd "$SPAWN" && php -d error_log="$T/php-error.log" wp-spawn.php "$@"); }

echo "== MariaDB starten =="
mkdir -p "$T/data" "$T/sites" "$CACHE"
DBUSER="$(id -un)"
mariadb-install-db --user="$DBUSER" --datadir="$T/data" --auth-root-authentication-method=normal >"$T/install-db.log" 2>&1 \
    || { cat "$T/install-db.log"; exit 1; }
mariadbd --user="$DBUSER" --datadir="$T/data" --socket="$SOCK" --port=$PORT_DB --bind-address=127.0.0.1 \
    --pid-file="$T/db.pid" >"$T/mariadb.log" 2>&1 &
pids+=("$!")
for _ in $(seq 1 60); do
    mariadb -S "$SOCK" -uroot -e 'select 1' >/dev/null 2>&1 && break
    sleep 1
done
mariadb -S "$SOCK" -uroot -e "CREATE DATABASE $DB CHARACTER SET utf8mb4; CREATE USER 'wpu'@'127.0.0.1' IDENTIFIED BY 'p\"w\\\\d''x'; CREATE USER 'wpu'@'localhost' IDENTIFIED BY 'p\"w\\\\d''x'; GRANT ALL ON $DB.* TO 'wpu'@'127.0.0.1'; GRANT ALL ON $DB.* TO 'wpu'@'localhost';"

echo "== Skript und Vorlage vorbereiten =="
SPAWN="$T/sites/spawn"
mkdir -p "$SPAWN"
cp -r "$REPO/wp-spawn.php" "$REPO/lib" "$SPAWN/"
TPL="$T/tpl"
mkdir -p "$TPL/plugins/bw-test-plugin" "$TPL/plugins/bw-zip-src/bw-zip-plugin" "$TPL/mu-plugins" "$TPL/themes"
printf '<?php\n/* Plugin Name: BW Test Plugin */\n' >"$TPL/plugins/bw-test-plugin/bw-test-plugin.php"
printf '<?php\n/* Plugin Name: BW Zip Plugin */\n' >"$TPL/plugins/bw-zip-src/bw-zip-plugin/bw-zip-plugin.php"
# shellcheck disable=SC2016  # PHP-Code in einfachen Anführungszeichen
(cd "$TPL/plugins/bw-zip-src" && php -r '$z=new ZipArchive;$z->open("../bw-zip-plugin.zip",ZipArchive::CREATE);$z->addFile("bw-zip-plugin/bw-zip-plugin.php");$z->close();')
rm -rf "$TPL/plugins/bw-zip-src"
printf '<?php\n// mu-plugin Test\n' >"$TPL/mu-plugins/bw-mu.php"
cat >"$TPL/plugins.txt" <<'EOF'
classic-editor
dir:bw-test-plugin
zip:bw-zip-plugin.zip
EOF
cat >"$TPL/settings.php" <<'EOF'
<?php
return [
    'permalink_structure' => '/%postname%/',
    'timezone_string' => 'Europe/Berlin',
    'blog_public' => 0,
    'options' => ['date_format' => 'd.m.Y', 'start_of_week' => 1],
];
EOF
write_config() { # write_config <datei> <template_dir>
    cat >"$1" <<EOF
<?php
return [
    'db_host' => '127.0.0.1:$PORT_DB',
    'db_name' => '$DB',
    'db_user' => 'wpu',
    'db_pass' => 'p"w\\d\'x',
    'base_url' => 'http://127.0.0.1:$PORT_WEB',
    'target_path' => '$T/sites',
    'secret' => 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEF',
    'admin_email' => 'admin@example.test',
    'cache_dir' => '$CACHE',
    'template_dir' => '$2',
    'ca_bundle' => '$CA',
];
EOF
}
write_config "$SPAWN/config.php" "$TPL"

# Fremde Tabellen, die niemals angefasst werden dürfen
q "CREATE TABLE other_data (id int); CREATE TABLE bw_26_foo (id int); CREATE TABLE bw_2605_conflict (id int);"

echo "== 1. Neue Instanz 2606-testxyz =="
out="$(spawn create 2606-testxyz --title="Test XYZ" 2>"$T/err.txt")" || { cat "$T/err.txt"; echo "$out"; echo "FAIL: create"; exit 1; }
PW="$(printf '%s\n' "$out" | sed -n 's/^admin_password: //p')"
check "Passwort wurde ausgegeben (>=20 Zeichen)" test "${#PW}" -ge 20
check "Präfix bw_2606_ im Ergebnis" grep -q 'prefix: bw_2606_' <<<"$out"
check "WordPress-Dateien vorhanden" test -f "$T/sites/2606-testxyz/wp-load.php"
check "Tabellen mit Präfix angelegt (>=10)" test "$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME LIKE 'bw\\_2606\\_%'")" -ge 10
check "Keine Tabellen mit Standardpräfix wp_" eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME LIKE 'wp\\_%'" 0
check "blogname" eq "SELECT option_value FROM bw_2606_options WHERE option_name='blogname'" "Test XYZ"
check "siteurl = base_url/name" eq "SELECT option_value FROM bw_2606_options WHERE option_name='siteurl'" "http://127.0.0.1:$PORT_WEB/2606-testxyz"
check "home = base_url/name" eq "SELECT option_value FROM bw_2606_options WHERE option_name='home'" "http://127.0.0.1:$PORT_WEB/2606-testxyz"
check "permalink_structure" eq "SELECT option_value FROM bw_2606_options WHERE option_name='permalink_structure'" "/%postname%/"
check "timezone_string" eq "SELECT option_value FROM bw_2606_options WHERE option_name='timezone_string'" "Europe/Berlin"
check "blog_public = 0" eq "SELECT option_value FROM bw_2606_options WHERE option_name='blog_public'" "0"
check "date_format aus settings.php" eq "SELECT option_value FROM bw_2606_options WHERE option_name='date_format'" "d.m.Y"
check "Sprache de_DE" eq "SELECT option_value FROM bw_2606_options WHERE option_name='WPLANG'" "de_DE"
check "Option bw_2606_user_roles vorhanden" eq "SELECT COUNT(*) FROM bw_2606_options WHERE option_name='bw_2606_user_roles'" 1
check "usermeta bw_2606_capabilities vorhanden" eq "SELECT COUNT(*) FROM bw_2606_usermeta WHERE meta_key='bw_2606_capabilities'" 1
check "Admin-User admin" eq "SELECT user_login FROM bw_2606_users" "admin"
check "Admin-Mail" eq "SELECT user_email FROM bw_2606_users" "admin@example.test"
check "Beispielbeitrag entfernt" eq "SELECT COUNT(*) FROM bw_2606_posts WHERE post_name IN ('hello-world','sample-page')" 0
check "Plugin classic-editor aktiv" eq "SELECT option_value LIKE '%classic-editor/classic-editor.php%' FROM bw_2606_options WHERE option_name='active_plugins'" 1
check "Plugin (dir) aktiv" eq "SELECT option_value LIKE '%bw-test-plugin/bw-test-plugin.php%' FROM bw_2606_options WHERE option_name='active_plugins'" 1
check "Plugin (zip) aktiv" eq "SELECT option_value LIKE '%bw-zip-plugin/bw-zip-plugin.php%' FROM bw_2606_options WHERE option_name='active_plugins'" 1
check "mu-plugin kopiert" test -f "$T/sites/2606-testxyz/wp-content/mu-plugins/bw-mu.php"
check "hello.php und akismet entfernt" bash -c "! test -e '$T/sites/2606-testxyz/wp-content/plugins/hello.php' && ! test -d '$T/sites/2606-testxyz/wp-content/plugins/akismet'"
check ".htaccess für Permalinks" grep -q 'RewriteBase /2606-testxyz/' "$T/sites/2606-testxyz/.htaccess"
check "wp-config.php Rechte 640" test "$(stat -c %a "$T/sites/2606-testxyz/wp-config.php")" = 640
check "wp-config.php: Präfix" grep -q "table_prefix = 'bw_2606_'" "$T/sites/2606-testxyz/wp-config.php"
check "Registry enthält die Instanz" grep -q '"prefix": "bw_2606_"' "$SPAWN/data/registry.php"
check "Passwort steht nirgends im Dateisystem" bash -c "! grep -rqF -- '$PW' '$T/sites' '$T/php-error.log' 2>/dev/null"
check "Passwort steht nicht im Klartext in der DB" bash -c "! mariadb -S '$SOCK' -uroot -N -B $DB -e 'select user_pass from bw_2606_users' | grep -qF -- '$PW'"
check "Fremde Tabellen unangetastet" eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME IN ('other_data','bw_26_foo','bw_2605_conflict')" 3

echo "== 2. Konflikte und ungültige Eingaben =="
check "Gleicher Name wird abgelehnt" bash -c "! (cd '$SPAWN' && php wp-spawn.php create 2606-testxyz) 2>/dev/null"
check "Gleiche Projektnummer wird abgelehnt" bash -c "(cd '$SPAWN' && php wp-spawn.php create 2606-other 2>&1 || true) | grep -q 'Projektnummer 2606'"
check "  ... und es entstand kein Ordner" bash -c "! test -e '$T/sites/2606-other'"
check "Ungültiger Name wird abgelehnt" bash -c "! (cd '$SPAWN' && php wp-spawn.php create Bad_Name) 2>/dev/null"
check "Bestehende Tabellen mit Präfix blockieren" bash -c "(cd '$SPAWN' && php wp-spawn.php create 2605-x 2>&1 || true) | grep -q 'Tabellen mit dem Präfix bw_2605_'"
check "  ... und es entstand kein Ordner" bash -c "! test -e '$T/sites/2605-x'"

echo "== 3. Zweite Instanz und Liste =="
spawn create 2607-second >/dev/null 2>&1
check "2607-second hat Präfix bw_2607_" eq "SELECT COUNT(*) FROM bw_2607_options WHERE option_name='bw_2607_user_roles'" 1
check "Instanzen sind getrennt (blogname)" eq "SELECT option_value FROM bw_2607_options WHERE option_name='blogname'" "Second"
check "Liste zeigt beide" bash -c "(cd '$SPAWN' && php wp-spawn.php list) | grep -c 'prefix: bw_260[67]_' | grep -qx 2"

echo "== 4. Fehler mitten in der Installation -> Aufräumen =="
mkdir -p "$TPL/plugins/bw-fatal"
printf '<?php\n/* Plugin Name: Fatal */\nbw_does_not_exist();\n' >"$TPL/plugins/bw-fatal/bw-fatal.php"
cp "$TPL/plugins.txt" "$T/plugins.txt.bak"
echo 'dir:bw-fatal' >>"$TPL/plugins.txt"
(cd "$SPAWN" && php wp-spawn.php create 2608-fatal >/dev/null 2>&1) || true
check "Ordner nach Fatal Error entfernt" bash -c "! test -e '$T/sites/2608-fatal'"
check "Tabellen nach Fatal Error entfernt" eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME LIKE 'bw\\_2608\\_%'" 0
check "Keine Registry-Zeile für 2608" bash -c "! grep -q 2608-fatal '$SPAWN/data/registry.php'"
echo 'zip:fehlt.zip' >>"$T/plugins.txt.bak"
cp "$T/plugins.txt.bak" "$TPL/plugins.txt"
(cd "$SPAWN" && php wp-spawn.php create 2609-missing >/dev/null 2>&1) || true
check "Ordner nach Fehler bei fehlendem ZIP entfernt" bash -c "! test -e '$T/sites/2609-missing'"
check "Tabellen nach Fehler bei fehlendem ZIP: keine" eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME LIKE 'bw\\_2609\\_%'" 0
check "Instanz 2606 nach den Fehlläufen intakt" eq "SELECT COUNT(*) FROM bw_2606_options WHERE option_name='blogname'" 1
sed -i '/fehlt.zip/d' "$TPL/plugins.txt"

echo "== 5. HTTPS-API mit HMAC-Signatur =="
php -S "127.0.0.1:$PORT_WEB" -t "$T/sites" >"$T/web.log" 2>&1 &
pids+=("$!")
sleep 1
URL="http://127.0.0.1:$PORT_WEB/spawn/wp-spawn.php"
SECRET='abcdefghijklmnopqrstuvwxyz0123456789ABCDEF'
call() { # call <body> [timestamp-offset] [secret]
    local body="$1" ts sig
    ts="$(($(date +%s) + ${2:-0}))"
    sig="$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "${3:-$SECRET}" -hex | sed 's/^.* //')"
    curl -s -m 300 -o "$T/resp.json" -w '%{http_code}' -X POST "$URL" -H 'Content-Type: application/json' \
        -H "X-BW-Timestamp: $ts" -H "X-BW-Signature: $sig" --data-binary "$body"
}
BODY='{"action":"create","name":"2610-api","title":"API Test"}'
check "API: create liefert 200" test "$(call "$BODY")" = 200
# shellcheck disable=SC2016
check "API: ok=true und Passwort im JSON" php -r '$j=json_decode(file_get_contents($argv[1]),true); exit($j["ok"]===true && strlen($j["result"]["admin_password"])>=20 && $j["result"]["prefix"]==="bw_2610_" ? 0 : 1);' "$T/resp.json"
check "API: Instanz läuft (Login-Seite erreichbar)" bash -c "curl -s -m 60 -o /dev/null -w '%{http_code}' 'http://127.0.0.1:$PORT_WEB/2610-api/wp-login.php' | grep -qx 200"
check "API: Startseite enthält Titel" bash -c "curl -s -m 60 'http://127.0.0.1:$PORT_WEB/2610-api/' | grep -q 'API Test'"
check "API: Wiederholung derselben Signatur -> 401" bash -c "
    ts=\$(date +%s); body='{\"action\":\"list\"}'
    sig=\$(printf '%s.%s' \"\$ts\" \"\$body\" | openssl dgst -sha256 -hmac '$SECRET' -hex | sed 's/^.* //')
    c1=\$(curl -s -o /dev/null -w '%{http_code}' -X POST '$URL' -H 'Content-Type: application/json' -H \"X-BW-Timestamp: \$ts\" -H \"X-BW-Signature: \$sig\" --data-binary \"\$body\")
    c2=\$(curl -s -o /dev/null -w '%{http_code}' -X POST '$URL' -H 'Content-Type: application/json' -H \"X-BW-Timestamp: \$ts\" -H \"X-BW-Signature: \$sig\" --data-binary \"\$body\")
    [ \"\$c1\" = 200 ] && [ \"\$c2\" = 401 ]"
check "API: falscher Schlüssel -> 401" test "$(call '{"action":"list"}' 0 'wrong-secret-wrong-secret-wrong-secret')" = 401
check "API: abgelaufener Zeitstempel -> 401" test "$(call '{"action":"list"}' -1000)" = 401
check "API: GET ohne Signatur zeigt nur das Formular (200, kein Inhalt)" bash -c "curl -s '$URL' | grep -q 'type=\"password\"' && ! curl -s '$URL' | grep -q 2606-testxyz"
check "Browser: falscher Schlüssel -> 403" bash -c "test \"\$(curl -s -o /dev/null -w '%{http_code}' -X POST '$URL' -d key=falsch -d action=list)\" = 403"
check "Browser: richtiger Schlüssel listet Instanzen" bash -c "curl -s -X POST '$URL' --data-urlencode key='$SECRET' -d action=list | grep -q 2610-api"
check "Direktaufruf lib/runner.php tut nichts (404)" bash -c "test \"\$(curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:$PORT_WEB/spawn/lib/runner.php')\" = 404"

echo "== 6. Löschen =="
before_2607="$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME LIKE 'bw\\_2607\\_%'")"
check "Probelauf löscht nichts" bash -c "cd '$SPAWN' && php wp-spawn.php remove 2606-testxyz --dry-run | grep -q 'removed: nein' && test -d '$T/sites/2606-testxyz'"
check "Ohne Bestätigung wird nichts gelöscht" bash -c "cd '$SPAWN' && echo falsch | php wp-spawn.php remove 2606-testxyz >/dev/null 2>&1; test -d '$T/sites/2606-testxyz'"
check "Unbekannte Instanz wird abgelehnt" bash -c "! (cd '$SPAWN' && php wp-spawn.php remove 2699-nope --yes) 2>/dev/null"
# Manipulierte wp-config: anderer Präfix -> Abbruch
cp "$T/sites/2607-second/wp-config.php" "$T/wpconfig.bak"
sed -i "s/bw_2607_/bw_9999_/" "$T/sites/2607-second/wp-config.php"
check "wp-config mit fremdem Präfix -> Abbruch" bash -c "! (cd '$SPAWN' && php wp-spawn.php remove 2607-second --yes) 2>/dev/null && test -d '$T/sites/2607-second'"
cp "$T/wpconfig.bak" "$T/sites/2607-second/wp-config.php"
check "remove --yes löscht Instanz 2606" bash -c "cd '$SPAWN' && php wp-spawn.php remove 2606-testxyz --yes | grep -q 'removed: ja'"
check "  Verzeichnis weg" bash -c "! test -e '$T/sites/2606-testxyz'"
check "  alle bw_2606_-Tabellen weg" eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME LIKE 'bw\\_2606\\_%'" 0
check "  Instanz 2607 unverändert" eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME LIKE 'bw\\_2607\\_%'" "$before_2607"
check "  Instanz 2610 unverändert" eq "SELECT COUNT(*) FROM bw_2610_options WHERE option_name='blogname'" 1
check "  fremde Tabellen unangetastet" eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME IN ('other_data','bw_26_foo','bw_2605_conflict')" 3
check "  Registry ohne 2606" bash -c "! grep -q 2606-testxyz '$SPAWN/data/registry.php'"
check "  Nummer 2606 ist wieder frei" bash -c "cd '$SPAWN' && php wp-spawn.php create 2606-again >/dev/null 2>&1 && test -d '$T/sites/2606-again'"
spawn remove 2606-again --yes >/dev/null 2>&1
spawn remove 2607-second --yes >/dev/null 2>&1
spawn remove 2610-api --yes >/dev/null 2>&1
check "Nach dem Aufräumen: nur fremde Tabellen übrig" eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB'" 3

echo "== 7. Selbstlöschung =="
SPAWN2="$T/sites/spawn2"
mkdir -p "$SPAWN2"
cp -r "$REPO/wp-spawn.php" "$REPO/lib" "$SPAWN2/"
write_config "$SPAWN2/config.php" "$TPL"
(cd "$SPAWN2" && php wp-spawn.php create 2611-selfdel --self-delete >/dev/null 2>&1) || true
check "wp-spawn.php hat sich nach dem Lauf gelöscht" bash -c "! test -e '$SPAWN2/wp-spawn.php'"
check "  Instanz wurde trotzdem angelegt" test -f "$T/sites/2611-selfdel/wp-load.php"
q "DROP TABLE IF EXISTS other_data, bw_26_foo, bw_2605_conflict" || true

echo
echo "$((checks - fails))/$checks Prüfungen bestanden"
[ "$fails" -eq 0 ]
