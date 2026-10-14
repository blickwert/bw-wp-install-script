<?php
/**
 * Ein-/Ausgabe für wp-spawn.php: Kommandozeile, signierter HTTPS-Aufruf (JSON) und einfaches Browser-Formular.
 * Kompatibel mit PHP 7.4+.
 */
declare(strict_types=1);

/** @return string 'cli' | 'api' | 'html' */
function bwsp_mode(): string
{
    if (PHP_SAPI === 'cli') {
        return 'cli';
    }
    if (isset($_SERVER['HTTP_X_BW_SIGNATURE']) || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
        return 'api';
    }
    return 'html';
}

function bwsp_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bwsp_headers(string $type): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: ' . $type);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

/**
 * Gibt das Ergebnis aus.
 *
 * @param array<string,mixed> $payload ['ok'=>bool, 'error'=>string, 'result'=>array]
 */
function bwsp_respond(string $mode, int $status, array $payload): void
{
    if ($mode === 'cli') {
        if (empty($payload['ok'])) {
            fwrite(STDERR, 'Fehler: ' . ($payload['error'] ?? 'unbekannt') . "\n");
            return;
        }
        echo bwsp_text($payload['result'] ?? []) . "\n";
        return;
    }
    http_response_code($status);
    if ($mode === 'api') {
        bwsp_headers('application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        return;
    }
    bwsp_headers('text/html; charset=utf-8');
    $body = '';
    if (empty($payload['ok'])) {
        $body .= '<p class="err">' . bwsp_h((string)($payload['error'] ?? 'Fehler')) . '</p>';
    } else {
        $body .= '<div class="ok">' . bwsp_html_value($payload['result'] ?? []) . '</div>';
    }
    echo bwsp_html_page($body . bwsp_html_form());
}

/** @param mixed $v */
function bwsp_text($v, int $indent = 0): string
{
    $pad = str_repeat('  ', $indent);
    if (!is_array($v)) {
        return $pad . (is_bool($v) ? ($v ? 'ja' : 'nein') : (string)$v);
    }
    $lines = [];
    foreach ($v as $k => $val) {
        if (is_array($val)) {
            $lines[] = $pad . (is_int($k) ? '-' : $k . ':');
            $lines[] = bwsp_text($val, $indent + 1);
        } else {
            $lines[] = $pad . (is_int($k) ? '- ' : $k . ': ') . (is_bool($val) ? ($val ? 'ja' : 'nein') : (string)$val);
        }
    }
    return implode("\n", $lines);
}

/** @param mixed $v */
function bwsp_html_value($v): string
{
    if (!is_array($v)) {
        return bwsp_h(is_bool($v) ? ($v ? 'ja' : 'nein') : (string)$v);
    }
    $isList = array_keys($v) === range(0, count($v) - 1);
    $html = '<dl>';
    foreach ($v as $k => $val) {
        if ($isList) {
            $html .= '<dd>' . bwsp_html_value($val) . '</dd>';
        } else {
            $html .= '<dt>' . bwsp_h((string)$k) . '</dt><dd>' . bwsp_html_value($val) . '</dd>';
        }
    }
    return $html . '</dl>';
}

function bwsp_html_page(string $body): string
{
    return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>WP Spawn</title><style>'
        . 'body{font:16px/1.5 system-ui,sans-serif;max-width:46rem;margin:2rem auto;padding:0 1rem;color:#1d2327}'
        . 'label{display:block;margin:.8rem 0 .2rem;font-weight:600}input,select{width:100%;padding:.5rem;box-sizing:border-box}'
        . 'input[type=checkbox]{width:auto}button{margin-top:1rem;padding:.6rem 1.2rem;font-size:1rem}'
        . '.err{background:#fcf0f1;border-left:4px solid #d63638;padding:.7rem 1rem}'
        . '.ok{background:#edfaef;border-left:4px solid #00a32a;padding:.7rem 1rem;overflow-wrap:anywhere}'
        . 'dt{font-weight:600;margin-top:.5rem}dd{margin:0 0 0 1rem}small{color:#646970}'
        . '</style></head><body><h1>WP Spawn</h1>' . $body . '</body></html>';
}

function bwsp_html_form(): string
{
    return '<form method="post" autocomplete="off">'
        . '<label>Schlüssel (secret aus config.php)<input type="password" name="key" required></label>'
        . '<label>Aktion<select name="action">'
        . '<option value="create">Instanz anlegen</option><option value="list">Instanzen auflisten</option>'
        . '<option value="preview">Löschen: nur anzeigen, was betroffen wäre</option><option value="remove">Instanz löschen</option></select></label>'
        . '<label>Name (z. B. 2606-johndow)<input name="name" pattern="[0-9]{2,6}-[a-z0-9-]+" maxlength="60"></label>'
        . '<label>Titel der Website (optional)<input name="title" maxlength="100"></label>'
        . '<label>Admin-Mail (optional, sonst aus config.php)<input type="email" name="admin_email"></label>'
        . '<label>Zum Löschen: Namen zur Bestätigung noch einmal eintippen<input name="confirm" maxlength="60"></label>'
        . '<label><input type="checkbox" name="self_delete" value="1"> Skript nach dem Anlegen selbst löschen</label>'
        . '<button type="submit">Ausführen</button></form>';
}

/**
 * Liest die Anfrage und prüft die Berechtigung. Bei Fehlern wird geantwortet und beendet.
 *
 * @param array<string,mixed> $cfg
 * @return array<string,mixed> Parameter: action, name, title, admin_email, locale, confirm, dry_run, self_delete
 */
function bwsp_read_request(array $cfg, string $mode): array
{
    $fail = static function (int $status, string $msg) use ($mode): void {
        usleep(600000); // bremst Raten
        bwsp_respond($mode, $status, ['ok' => false, 'error' => $msg]);
        exit($mode === 'cli' ? 1 : 0);
    };

    if ($mode === 'cli') {
        return bwsp_read_cli((array)($_SERVER['argv'] ?? []));
    }

    if ($mode === 'api') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $fail(405, 'Nur POST.');
        }
        $body = (string)file_get_contents('php://input', false, null, 0, 65536);
        $ts = (string)($_SERVER['HTTP_X_BW_TIMESTAMP'] ?? '');
        $sig = (string)($_SERVER['HTTP_X_BW_SIGNATURE'] ?? '');
        $now = time();
        if (!bwsp_verify_signature((string)$cfg['secret'], $ts, $body, $sig, $now)) {
            $fail(401, 'Signatur ungültig oder Zeitstempel abgelaufen.');
        }
        if (bwsp_nonce_seen($cfg, strtolower($sig), $now)) {
            $fail(401, 'Signatur wurde bereits verwendet.');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $fail(400, 'Body ist kein gültiges JSON.');
        }
        return bwsp_clean_params($data);
    }

    // Browser
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        bwsp_respond('html', 200, ['ok' => true, 'result' => ['hinweis' => 'Bitte Formular ausfüllen.']]);
        exit;
    }
    $key = (string)($_POST['key'] ?? '');
    if (!hash_equals((string)$cfg['secret'], $key)) {
        $fail(403, 'Schlüssel falsch.');
    }
    return bwsp_clean_params($_POST);
}

/**
 * @param array<mixed> $in
 * @return array<string,mixed>
 */
function bwsp_clean_params(array $in): array
{
    $action = (string)($in['action'] ?? '');
    $dry = !empty($in['dry_run']);
    if ($action === 'preview') {
        $action = 'remove';
        $dry = true;
    }
    $p = ['action' => $action, 'dry_run' => $dry];
    foreach (['name', 'title', 'admin_email', 'locale', 'confirm'] as $k) {
        if (isset($in[$k]) && is_string($in[$k]) && $in[$k] !== '') {
            $p[$k] = $in[$k];
        }
    }
    $p['self_delete'] = !empty($in['self_delete']);
    return $p;
}

/**
 * Kommandozeile: create <name> [--title=] [--email=] [--locale=] [--self-delete]
 *                list | remove <name> [--yes] [--dry-run]
 *
 * @param array<int,string> $argv
 * @return array<string,mixed>
 */
function bwsp_read_cli(array $argv): array
{
    $args = array_slice($argv, 1);
    $action = (string)array_shift($args);
    $in = ['action' => $action];
    foreach ($args as $a) {
        if (preg_match('/^--(title|email|locale)=(.*)$/s', $a, $m)) {
            $in[$m[1] === 'email' ? 'admin_email' : $m[1]] = $m[2];
        } elseif ($a === '--yes') {
            $in['yes'] = true;
        } elseif ($a === '--dry-run') {
            $in['dry_run'] = true;
        } elseif ($a === '--self-delete') {
            $in['self_delete'] = true;
        } elseif (strncmp($a, '--', 2) !== 0 && !isset($in['name'])) {
            $in['name'] = $a;
        } else {
            fwrite(STDERR, "Unbekannte Option: $a\n");
            exit(2);
        }
    }
    if (!in_array($action, ['create', 'list', 'remove'], true)) {
        fwrite(STDERR, "Aufruf:\n  php wp-spawn.php create <name> [--title=..] [--email=..] [--locale=de_DE] [--self-delete]\n"
            . "  php wp-spawn.php list\n  php wp-spawn.php remove <name> [--dry-run] [--yes]\n");
        exit(2);
    }
    if ($action === 'remove' && isset($in['name']) && empty($in['dry_run'])) {
        if (!empty($in['yes'])) {
            $in['confirm'] = $in['name'];
        } else {
            fwrite(STDERR, 'Zum Löschen den Namen "' . $in['name'] . '" eintippen: ');
            $line = trim((string)fgets(STDIN));
            $in['confirm'] = $line;
        }
    }
    return bwsp_clean_params($in);
}
