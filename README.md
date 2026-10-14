# bw-wp-install-script

Legt auf einem Shared-Hosting-Server (nur FTP/SFTP, kein root, kein WP-CLI nötig) mit **einem Aufruf** eine
vorkonfigurierte WordPress-Instanz in einem neuen Ordner an. Alle Instanzen teilen sich **eine Datenbank** und
unterscheiden sich nur durch den **Tabellenpräfix**.

```
Ordner 2606-johndow   →   Präfix bw_2606_   →   https://dev.example.de/2606-johndow/
```

## Was es tut

1. Prüft den Namen (`<Projektnummer>-<kunde>`, nur `a-z 0-9 -`) und ob Ordner, Projektnummer oder Tabellen schon vergeben sind.
2. Lädt WordPress (Sprachpaket laut `locale`) von wordpress.org in einen Cache und entpackt es nach `<target_path>/<name>/`.
3. Schreibt `wp-config.php` mit den gemeinsamen DB-Zugangsdaten, dem Präfix und frischen Salts.
4. Installiert WordPress (ohne WP-CLI, direkt über `wp_install()`), Admin mit zufälligem Passwort. Das Passwort wird **einmal**
   ausgegeben und nirgends gespeichert oder geloggt; es wird auch keine Mail verschickt.
5. Kopiert/lädt Plugins, Themes und mu-plugins aus `templates/wp-base/`, aktiviert sie und setzt die Optionen aus `settings.php`.
6. Trägt die Instanz in die Registry ein (`data/registry.php`: Name, Präfix, Pfad, URL, Datum).
7. Bricht irgendetwas ab (auch ein Fatal Error oder Zeitlimit), wird die halb angelegte Instanz wieder entfernt
   (Verzeichnis und Tabellen mit genau diesem Präfix).

Nicht in der ersten Version: Dump-Import (`--with-dump`) und Premium-Plugins mit Lizenzschlüssel.
Lokale ZIPs/Ordner als Plugin gehen (`zip:` / `dir:` in `plugins.txt`), nur die Lizenzaktivierung fehlt.

## Dateien

```
wp-spawn.php              Einstieg: Kommandozeile, Browser-Formular, signierte HTTPS-API
lib/bwsp.php              Logik (Namen, Präfix, Download, Entpacken, Registry, Löschen)
lib/io.php                Ein-/Ausgabe
lib/runner.php            Installation (lädt WordPress im globalen Scope)
config.example.php        Vorlage für config.php (config.php steht in .gitignore)
templates/wp-base/        Vorlage für jede Instanz
  plugins.txt               welche Plugins (wp.org-Slug, zip:, dir:)
  settings.php              Theme, Permalinks, Zeitzone, Reading-Settings, weitere Optionen
  plugins/ themes/ mu-plugins/   lokale Dateien (ZIPs oder Ordner)
tests/unit.php            Unit-Tests der Logik (php tests/unit.php)
tests/integration.sh      Test mit lokaler MariaDB und echtem WordPress
```

Beim Hochladen muss der **ganze Ordner** auf den Server (nicht nur `wp-spawn.php`). `lib/`, `templates/`, `tests/` sind per
`.htaccess` für den Webzugriff gesperrt; `data/` und `cache/` sperrt das Skript selbst.

## Einrichtung

1. Ordner per SFTP auf den Server legen, z. B. nach `/<webroot>/wp-install/`. Instanzen entstehen als **Geschwisterordner**
   daneben (`/<webroot>/2606-johndow/`). Soll es woanders hin, `target_path` in `config.php` setzen.
2. `config.example.php` nach `config.php` kopieren und ausfüllen:
   - `db_*`: die gemeinsame Datenbank,
   - `base_url`: Adresse von `target_path` (Instanz-URL = `base_url/<name>`),
   - `secret`: `openssl rand -hex 32` (mind. 32 Zeichen),
   - `admin_email`, `locale`, ggf. `admin_user`.
   Dateirechte: `chmod 600 config.php`.
3. `templates/wp-base/` anpassen (siehe unten).
4. Voraussetzungen auf dem Server: PHP ≥ 7.4 mit `mysqli`, `zip` und `curl` (oder `allow_url_fopen`), ausgehend HTTPS zu
   `api.wordpress.org` und `downloads.wordpress.org`, genug `memory_limit` (256M reicht) und `max_execution_time` (der Lauf dauert
   je nach Server 10–60 s; das Skript versucht, das Limit selbst anzuheben).

## Benutzung

### Kommandozeile (SSH)

```bash
php wp-spawn.php create 2606-johndow [--title="John Dow"] [--email=admin@kunde.de] [--locale=de_DE] [--self-delete]
php wp-spawn.php list
php wp-spawn.php remove 2606-johndow --dry-run     # zeigt nur, was betroffen wäre
php wp-spawn.php remove 2606-johndow               # fragt nach: Namen zur Bestätigung eintippen
```

### Browser

`https://<host>/wp-install/wp-spawn.php` öffnen, den `secret` aus `config.php` als Schlüssel eingeben, Aktion wählen.
Zum Löschen den Namen zur Bestätigung ein zweites Mal eintippen; „Löschen: nur anzeigen“ ist der Probelauf.

### HTTPS-API (für den späteren Aufruf aus WordPress)

`POST` auf `wp-spawn.php` mit JSON-Body und zwei Headern:

| Header | Inhalt |
|---|---|
| `X-BW-Timestamp` | Unix-Zeit in Sekunden (±5 Minuten gültig) |
| `X-BW-Signature` | `hex(HMAC-SHA256(secret, "<timestamp>.<body>"))` |

Jede Signatur gilt nur **einmal** (Schutz gegen Wiederholung). Body-Beispiele:

```json
{"action":"create","name":"2606-johndow","title":"John Dow","admin_email":"admin@kunde.de"}
{"action":"list"}
{"action":"remove","name":"2606-johndow","dry_run":true}
{"action":"remove","name":"2606-johndow","confirm":"2606-johndow"}
```

Antwort: `{"ok":true,"result":{…}}` bzw. `{"ok":false,"error":"…"}`. Bei `create` enthält `result` den Login, das
Passwort steht nur in dieser einen Antwort.

```bash
body='{"action":"create","name":"2606-johndow"}'
ts=$(date +%s)
sig=$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.* //')
curl -X POST https://dev.example.de/wp-install/wp-spawn.php -H 'Content-Type: application/json' \
     -H "X-BW-Timestamp: $ts" -H "X-BW-Signature: $sig" --data-binary "$body"
```

Aus WordPress (z. B. in einem Plugin):

```php
$body = wp_json_encode(['action' => 'create', 'name' => '2606-johndow']);
$ts   = (string) time();
$res  = wp_remote_post($url, [
    'timeout' => 300,
    'headers' => [
        'Content-Type'    => 'application/json',
        'X-BW-Timestamp'  => $ts,
        'X-BW-Signature'  => hash_hmac('sha256', $ts . '.' . $body, BW_SPAWN_SECRET),
    ],
    'body'    => $body,
]);
```

## Namensregel und Präfix

- Ordnername: `^\d{2,6}-[a-z0-9]+(-[a-z0-9]+)*$`, max. 60 Zeichen (`2606-johndow`, `12-a-b`).
- Präfix: `<prefix_base>_<Projektnummer>_`, also `bw_2606_`. Der Unterstrich am Ende ist Absicht: Er verhindert, dass `bw_26_`
  als Anfang von `bw_2606_` gilt – sonst würde Löschen fremde Tabellen treffen.
- **Eine Projektnummer gibt es nur einmal.** `2606-johndow` und `2606-staging` kollidieren; das Skript bricht dann ab.
- Das Präfix wird zusätzlich gegen `^[a-z][a-z0-9_]{2,19}_$` geprüft.

## Vorlage `templates/wp-base/`

- `plugins.txt`: eine Zeile pro Plugin – `<wp.org-slug>`, `zip:<datei.zip>` (liegt in `plugins/`), `dir:<ordner>` (liegt in `plugins/`);
  mit `inactive` dahinter wird es installiert, aber nicht aktiviert.
- `themes/`: Ordner und ZIPs werden kopiert/entpackt. Welches Theme aktiv wird, steht in `settings.php` (`theme`).
  Themes von wordpress.org über `wporg_themes`.
- `mu-plugins/`: wird nach der Installation kopiert.
- `settings.php`: `theme`, `permalink_structure`, `timezone_string`, `blog_public` (Standard `0` = nicht indexieren),
  Aufräumen der Beispielinhalte/Hello Dolly/Akismet und beliebige `options`.

wordpress.org-Downloads werden in `cache/` abgelegt (Plugins 24 h, WordPress je Version); ist wordpress.org nicht erreichbar,
wird der letzte Cache benutzt.

## Löschen – was geprüft wird

`remove` löscht nur, wenn **alle** Punkte stimmen: Instanz steht in der Registry · Präfix passt zum Namen · Ordner ist ein direktes,
echtes (kein Symlink) Unterverzeichnis von `target_path` · `wp-config.php` darin enthält genau dieses Präfix und diese Datenbank ·
kein anderes registriertes Präfix überschneidet sich. Gelöscht werden Tabellen, die mit dem Präfix beginnen (vorher angezeigt),
und der Ordner. Ohne Bestätigung (`name` wiederholen) oder mit `--dry-run`/`dry_run` passiert nichts.

## Sicherheit

- Alles läuft nur mit dem `secret` (Browser) bzw. gültiger Signatur (API). Ohne `config.php` antwortet das Skript mit „Nicht konfiguriert“.
- Zugangsdaten stehen nur in `config.php` (nicht im Repo, `chmod 600`) und in der erzeugten `wp-config.php` (Modus `0640`,
  über `wp_config_mode` änderbar). Die DB-Verbindung läuft über PHP/mysqli – das Passwort steht nie in einer Prozessliste.
- `data/registry.php` beginnt mit einer PHP-Zeile, die den Inhalt auch ohne `.htaccess` verbirgt.
- Wer das Skript nicht mehr braucht, löscht es (`--self-delete` bzw. `self_delete` in der Konfiguration) oder entfernt `config.php`.
- Dev-Server: `blog_public` bleibt 0, damit Suchmaschinen die Instanzen nicht aufnehmen.

## Fehlersuche

| Meldung | Ursache / Lösung |
|---|---|
| `Nicht konfiguriert.` / `config.php fehlt` | `config.example.php` nach `config.php` kopieren und ausfüllen |
| `Datenbankverbindung fehlgeschlagen` | `db_host` (auch `host:port` oder `localhost:/socket`), Benutzer, Passwort prüfen |
| `PHP-Erweiterung 'zip' fehlt` | Im Hosting-Panel aktivieren oder anderen PHP-Handler wählen |
| `Download fehlgeschlagen` | Ausgehendes HTTPS gesperrt oder kein CA-Bundle: in `config.php` `ca_bundle` setzen |
| `Projektnummer … ist schon vergeben` | Es gibt schon eine Instanz mit dieser Nummer (`list`), oder Nummer ändern |
| `… Tabellen mit dem Präfix …` | In der DB liegen schon Tabellen mit diesem Präfix; prüfen, ob sie wirklich weg können |
| leere Seite / Abbruch nach langer Zeit | `max_execution_time` oder `memory_limit` zu klein; Fehlermeldung im PHP-Error-Log des Hosters |
| Seite zeigt Startseite, Unterseiten 404 | `.htaccess`/mod_rewrite nicht aktiv; Permalinks auf „Einfach“ stellen oder Hoster fragen |

Technische Fehler stehen im PHP-Error-Log (`bw-wp-install-script: …`); Passwörter werden nie geloggt.

## Tests

```bash
php -l wp-spawn.php lib/*.php && php tests/unit.php      # Syntax und Logik (ohne DB)
bash tests/integration.sh                                # lokale MariaDB + echtes WordPress, ~2 Minuten
shellcheck tests/integration.sh
```

Der Integrationstest legt mehrere Instanzen an und prüft u. a. Optionen, Plugins, Passwort-Hygiene, Konflikte, Aufräumen nach
einem Fatal Error, die HMAC-API (Wiederholung, falscher Schlüssel, alter Zeitstempel) und dass `remove` nur die eigenen Tabellen
löscht. Er braucht `mariadbd`, `curl`, `openssl` und Zugriff auf wordpress.org (`BWSP_TEST_CA`/`BWSP_TEST_CACHE` optional).
