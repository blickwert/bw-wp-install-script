<?php
/**
 * Installiert WordPress in einer frisch vorbereiteten Instanz.
 *
 * Diese Datei wird von wp-spawn.php auf oberster Ebene (globaler Scope) eingebunden. Das ist nötig,
 * weil WordPress beim Laden hunderte globale Variablen setzt – innerhalb einer Funktion ginge das schief.
 * Deshalb benutzt sie nur Variablen mit dem Präfix $bwsp_ und liest ihre Eingaben aus $bwsp_job.
 *
 * Direkter Aufruf per Browser tut nichts.
 */
if (!isset($bwsp_job) || !is_array($bwsp_job) || !defined('BWSP_ROOT')) {
    http_response_code(404);
    exit;
}

// URLs der neuen Instanz festlegen, bevor WordPress sie aus der aktuellen Anfrage "errät".
define('WP_INSTALLING', true);
define('WP_SITEURL', $bwsp_job['url']);
define('WP_HOME', $bwsp_job['url']);
define('WP_USE_THEMES', false);

$_SERVER['HTTP_HOST'] = (string)parse_url($bwsp_job['url'], PHP_URL_HOST);
$_SERVER['REQUEST_URI'] = '/' . $bwsp_job['name'] . '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (strpos($bwsp_job['url'], 'https://') === 0) {
    $_SERVER['HTTPS'] = 'on';
}

require $bwsp_job['dir'] . '/wp-load.php';

// Keine Mail an den Admin (enthielte das Passwort im Klartext).
add_filter('pre_wp_mail', '__return_true');

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

if (is_blog_installed()) {
    throw new BwspError('Die Datenbank enthält bereits eine WordPress-Installation mit diesem Präfix.');
}

$bwsp_set = $bwsp_job['settings'];
$bwsp_locale = $bwsp_job['locale'];

$bwsp_res = wp_install(
    $bwsp_job['title'],
    $bwsp_job['admin_user'],
    $bwsp_job['admin_email'],
    empty($bwsp_set['blog_public']) ? 0 : 1,
    '',
    $bwsp_job['admin_password'],
    $bwsp_locale
);
if (is_wp_error($bwsp_res)) {
    throw new BwspError('wp_install fehlgeschlagen: ' . $bwsp_res->get_error_message());
}
update_option('WPLANG', $bwsp_locale === 'en_US' ? '' : $bwsp_locale);

// Beispielinhalte entfernen (nur wenn sie noch unverändert sind)
if (!empty($bwsp_set['remove_sample_content'])) {
    $bwsp_post = get_post(1);
    if ($bwsp_post && $bwsp_post->post_name === 'hello-world') {
        wp_delete_post(1, true);
    }
    $bwsp_page = get_post(2);
    if ($bwsp_page && $bwsp_page->post_name === 'sample-page') {
        wp_delete_post(2, true);
    }
    wp_delete_comment(1, true);
}

// Optionen aus settings.php
if (!empty($bwsp_set['timezone_string'])) {
    update_option('timezone_string', (string)$bwsp_set['timezone_string']);
}
foreach ((array)$bwsp_set['options'] as $bwsp_k => $bwsp_v) {
    update_option((string)$bwsp_k, $bwsp_v);
}
if (!empty($bwsp_set['permalink_structure'])) {
    update_option('permalink_structure', (string)$bwsp_set['permalink_structure']);
    delete_option('rewrite_rules');
    $bwsp_ht = $bwsp_job['dir'] . '/.htaccess';
    if (!file_exists($bwsp_ht)) {
        $bwsp_base = (string)parse_url($bwsp_job['url'], PHP_URL_PATH);
        $bwsp_base = '/' . trim($bwsp_base, '/') . '/';
        file_put_contents($bwsp_ht, "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\n"
            . "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
            . "RewriteBase $bwsp_base\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . {$bwsp_base}index.php [L]\n</IfModule>\n# END WordPress\n");
    }
}

// Mitgelieferte Plugins/Themes aufräumen
if (!empty($bwsp_set['remove_bundled_plugins'])) {
    $bwsp_pl = $bwsp_job['dir'] . '/wp-content/plugins';
    @unlink($bwsp_pl . '/hello.php');
    bwsp_rrmdir($bwsp_pl . '/akismet');
}

// Theme
$bwsp_theme = (string)$bwsp_set['theme'];
if ($bwsp_theme !== '') {
    if (wp_get_theme($bwsp_theme)->exists()) {
        switch_theme($bwsp_theme);
    } else {
        $bwsp_job['warnings'][] = "Theme '$bwsp_theme' nicht gefunden – Standard-Theme bleibt aktiv.";
    }
}
if (!empty($bwsp_set['remove_default_themes'])) {
    $bwsp_active = get_option('stylesheet');
    foreach ((array)glob($bwsp_job['dir'] . '/wp-content/themes/twenty*', GLOB_ONLYDIR) as $bwsp_t) {
        if (basename($bwsp_t) !== $bwsp_active) {
            bwsp_rrmdir($bwsp_t);
        }
    }
}

// mu-plugins erst jetzt kopieren (sie werden beim Laden von WordPress ausgeführt)
if (is_dir($bwsp_job['mu_src'])) {
    bwsp_copy_tree($bwsp_job['mu_src'], $bwsp_job['dir'] . '/wp-content/mu-plugins');
}

// Plugins aktivieren
$bwsp_all = get_plugins();
foreach ($bwsp_job['plugins'] as $bwsp_p) {
    if (!$bwsp_p['activate']) {
        continue;
    }
    $bwsp_file = '';
    foreach (array_keys($bwsp_all) as $bwsp_key) {
        if (strpos($bwsp_key, $bwsp_p['folder'] . '/') === 0) {
            $bwsp_file = $bwsp_key;
            break;
        }
    }
    if ($bwsp_file === '') {
        $bwsp_job['warnings'][] = "Plugin '{$bwsp_p['folder']}' nicht gefunden – nicht aktiviert.";
        continue;
    }
    ob_start();
    $bwsp_act = activate_plugin($bwsp_file, '', false, true);
    ob_end_clean();
    if (is_wp_error($bwsp_act)) {
        $bwsp_job['warnings'][] = "Plugin '{$bwsp_p['folder']}' ließ sich nicht aktivieren: " . $bwsp_act->get_error_message();
    }
}

wp_cache_flush();
