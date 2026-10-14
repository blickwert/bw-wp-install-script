<?php
/**
 * wp-spawn.php – legt eine vorkonfigurierte WordPress-Instanz in einem neuen Ordner an.
 *
 * Alle Instanzen teilen sich eine Datenbank und unterscheiden sich durch den Tabellenpräfix
 * (Ordner 2606-johndow  ->  Präfix bw_2606_).
 *
 * Aufruf:
 *   Kommandozeile:  php wp-spawn.php create 2606-johndow
 *   Browser:        https://<host>/<pfad>/wp-spawn.php  (Formular, Schlüssel aus config.php)
 *   HTTPS-API:      POST mit JSON und HMAC-Signatur (siehe README), z. B. aus WordPress
 *
 * Ablauf der Dateien:
 *   lib/bwsp.php   Logik (Namen, Präfix, Download, Entpacken, Registry, Löschen)
 *   lib/io.php     Kommandozeile, JSON-API, Formular
 *   lib/runner.php Installation – wird hier auf oberster Ebene eingebunden (globaler Scope),
 *                  weil WordPress beim Laden viele globale Variablen setzt.
 *
 * Alle Variablen auf oberster Ebene heißen $bwsp_*, damit sie WordPress nicht stören.
 */
declare(strict_types=1);

define('BWSP_ROOT', __DIR__);
require __DIR__ . '/lib/bwsp.php';
require __DIR__ . '/lib/io.php';

$bwsp_mode = bwsp_mode();
if ($bwsp_mode !== 'cli') {
    @ini_set('display_errors', '0');
}
@ini_set('memory_limit', '256M');

// Sicherheitsnetz (entspricht "trap"): bricht der Lauf mitten drin ab (Fatal Error, wp_die, Zeitlimit),
// wird die halb angelegte Instanz aufgeräumt.
register_shutdown_function(static function () use ($bwsp_mode): void {
    $state = bwsp_state();
    if (empty($state['active'])) {
        return;
    }
    bwsp_rollback();
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    bwsp_respond($bwsp_mode, 500, ['ok' => false, 'error' => 'Vorgang abgebrochen. Die halb angelegte Instanz wurde aufgeräumt.']);
});

try {
    $bwsp_cfg = bwsp_load_config(__DIR__ . '/config.php');
    bwsp_http_config(['ca_bundle' => (string)$bwsp_cfg['ca_bundle']]);
} catch (BwspError $e) {
    error_log('bw-wp-install-script: ' . $e->getMessage());
    bwsp_respond($bwsp_mode, 503, ['ok' => false, 'error' => $bwsp_mode === 'cli' ? $e->getMessage() : 'Nicht konfiguriert.']);
    exit($bwsp_mode === 'cli' ? 1 : 0);
}

$bwsp_params = bwsp_read_request($bwsp_cfg, $bwsp_mode);
$bwsp_status = 200;
$bwsp_payload = [];
$bwsp_lock = null;

try {
    $bwsp_action = (string)$bwsp_params['action'];

    if ($bwsp_action === 'list') {
        $bwsp_result = ['instances' => bwsp_list($bwsp_cfg)];
    } elseif ($bwsp_action === 'remove') {
        $bwsp_lock = bwsp_lock($bwsp_cfg);
        $bwsp_result = bwsp_remove(
            $bwsp_cfg,
            (string)($bwsp_params['name'] ?? ''),
            isset($bwsp_params['confirm']) ? (string)$bwsp_params['confirm'] : null,
            !empty($bwsp_params['dry_run'])
        );
    } elseif ($bwsp_action === 'create') {
        $bwsp_lock = bwsp_lock($bwsp_cfg);
        $bwsp_job = bwsp_create_prepare($bwsp_cfg, $bwsp_params);

        // WordPress laden und installieren (globaler Scope, Ausgaben verwerfen)
        $bwsp_ob = ob_get_level();
        ob_start();
        try {
            require __DIR__ . '/lib/runner.php';
        } finally {
            while (ob_get_level() > $bwsp_ob) {
                ob_end_clean();
            }
        }

        $bwsp_result = bwsp_create_finish($bwsp_cfg, $bwsp_job);
        if (!empty($bwsp_params['self_delete']) || $bwsp_cfg['self_delete']) {
            register_shutdown_function(static function (): void {
                @unlink(__FILE__);
            });
            $bwsp_result['self_delete'] = 'wp-spawn.php wird nach diesem Lauf gelöscht.';
        }
    } else {
        throw new BwspError('Unbekannte Aktion. Erlaubt: create, list, remove.');
    }
    $bwsp_payload = ['ok' => true, 'result' => $bwsp_result];
} catch (BwspError $e) {
    bwsp_rollback();
    $bwsp_status = 400;
    $bwsp_payload = ['ok' => false, 'error' => $e->getMessage()];
} catch (Throwable $e) {
    bwsp_rollback();
    error_log('bw-wp-install-script: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    $bwsp_status = 500;
    $bwsp_payload = ['ok' => false, 'error' => 'Interner Fehler (Details im Server-Log). Eine halb angelegte Instanz wurde aufgeräumt.'];
}

bwsp_respond($bwsp_mode, $bwsp_status, $bwsp_payload);
exit($bwsp_mode === 'cli' && empty($bwsp_payload['ok']) ? 1 : 0);
