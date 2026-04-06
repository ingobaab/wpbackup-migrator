<?php
/**
 * Democlient: Aktivierungs-/OAuth-Flow (Application Passwords) + REST-/info + migration_key-Dekodierung.
 *
 * PHP 8.2+, prozedural. Später unter https://migrate.wpbackup.org/callback (o. Ä.) betreibbar.
 *
 * Voraussetzung: WPBackup Migrator auf der Ziel-WordPress-Instanz installiert und aktiviert
 * (kein Repo-Auto-Install in diesem Demo – siehe Abschnitt „migration_key“ unten).
 *
 * @noinspection PhpUndefinedConstantInspection
 */

declare( strict_types=1 );

if ( session_status() === PHP_SESSION_NONE ) {
	session_start();
}

// --- Konfiguration ---
const DEMO_DEFAULT_SITE = 'https://maschenmarie.de';
const REST_INFO_PATH             = '/wp-json/wpbackup-migrator/v1/info';
const REST_FILESYSTEM_SCAN_PATH  = '/wp-json/wpbackup-migrator/v1/filesystem-scan';
const APP_NAME                   = 'WPBackup Migrator';

// -----------------------------------------------------------------------------
// Hilfsfunktionen
// -----------------------------------------------------------------------------

/**
 * Öffentliche URL dieses Skripts (für success_url an WordPress authorize-application.php).
 *
 * Zwingend https:// – WordPress akzeptiert die success_url für Application Passwords nicht mit http.
 */
function demo_callback_self_url(): string {
	$host   = (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
	$script = (string) ( $_SERVER['SCRIPT_NAME'] ?? '/callback.php' );

	return 'https://' . $host . $script;
}

/**
 * Basis-URL normalisieren (mit optionalem Pfad, z. B. WP in Unterverzeichnis).
 */
function demo_normalize_site_base( string $raw ): string {
	$t = trim( $raw );
	if ( $t === '' ) {
		return '';
	}
	if ( ! preg_match( '#^https?://#i', $t ) ) {
		$t = 'https://' . $t;
	}
	$parts = parse_url( $t );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return '';
	}
	$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
	if ( $scheme !== 'http' && $scheme !== 'https' ) {
		$scheme = 'https';
	}
	$host = (string) $parts['host'];
	$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
	$path = isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';

	return $scheme . '://' . $host . $port . $path;
}

/**
 * GET-Anfrage, liefert HTTP-Status und Body (für REST-Probes).
 *
 * @return array{status: int, body: string, url: string}
 */
function demo_http_get_probe( string $url ): array {
	$ctx = stream_context_create(
		[
			'http' => [
				'method'        => 'GET',
				'header'        => "Accept: application/json\r\n",
				'timeout'       => 20,
				'ignore_errors' => true,
			],
			'ssl'  => [
				'verify_peer'      => true,
				'verify_peer_name' => true,
			],
		]
	);

	$body   = @file_get_contents( $url, false, $ctx );
	$status = 0;
	if ( isset( $http_response_header ) && is_array( $http_response_header ) ) {
		foreach ( $http_response_header as $line ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) {
				$status = (int) $m[1];
				break;
			}
		}
	}

	return [
		'status' => $status,
		'body'   => $body !== false ? (string) $body : '',
		'url'    => $url,
	];
}

/**
 * Prüft (1) WordPress-Kern-REST unter /wp-json/ und (2) Plugin-Route /info ohne Auth.
 *
 * @return array{
 *   wordpress: array{ok: bool, status: int, url: string, detail: string},
 *   plugin: array{installed: bool, status: int, url: string, detail: string},
 *   authorize_ok: bool
 * }
 */
function demo_probe_wordpress_and_plugin( string $site_base ): array {
	$base    = rtrim( $site_base, '/' );
	$wp_resp = demo_http_get_probe( $base . '/wp-json/' );
	$in_resp = demo_http_get_probe( $base . REST_INFO_PATH );

	$is_wordpress = false;
	if ( $wp_resp['status'] === 200 && $wp_resp['body'] !== '' ) {
		$data = json_decode( $wp_resp['body'], true );
		if ( is_array( $data ) && ( isset( $data['namespaces'] ) || isset( $data['routes'] ) ) ) {
			$is_wordpress = true;
		}
	}

	// /info ohne Login: Route registriert → typisch 401/403 (Administrator erforderlich).
	$plugin_installed = ( $in_resp['status'] === 401 || $in_resp['status'] === 403 );

	$detail_wp = strlen( $wp_resp['body'] ) > 2000 ? substr( $wp_resp['body'], 0, 2000 ) . '…' : $wp_resp['body'];
	$detail_in = strlen( $in_resp['body'] ) > 2000 ? substr( $in_resp['body'], 0, 2000 ) . '…' : $in_resp['body'];

	return [
		'wordpress' => [
			'ok'     => $is_wordpress,
			'status' => $wp_resp['status'],
			'url'    => $wp_resp['url'],
			'detail' => $detail_wp,
		],
		'plugin'    => [
			'installed' => $plugin_installed,
			'status'    => $in_resp['status'],
			'url'       => $in_resp['url'],
			'detail'    => $detail_in,
		],
		'authorize_ok' => $is_wordpress,
	];
}

/**
 * HTTP GET mit Basic Auth (Application Password).
 *
 * @return array{ok: bool, status: int, body: string, error: string}
 */
function demo_http_get_basic( string $url, string $user, string $app_pw ): array {
	$auth = base64_encode( $user . ':' . $app_pw );

	$ctx = stream_context_create(
		[
			'http' => [
				'method'        => 'GET',
				'header'        => "Authorization: Basic {$auth}\r\nAccept: application/json\r\n",
				'timeout'       => 30,
				'ignore_errors' => true,
			],
			'ssl'  => [
				'verify_peer'      => true,
				'verify_peer_name' => true,
			],
		]
	);

	$body = @file_get_contents( $url, false, $ctx );
	if ( $body === false ) {
		return [
			'ok'     => false,
			'status' => 0,
			'body'   => '',
			'error'  => 'Request fehlgeschlagen (file_get_contents).',
		];
	}

	$status = 0;
	if ( isset( $http_response_header ) && is_array( $http_response_header ) ) {
		foreach ( $http_response_header as $line ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) {
				$status = (int) $m[1];
				break;
			}
		}
	}

	return [
		'ok'     => $status >= 200 && $status < 300,
		'status' => $status,
		'body'   => $body,
		'error'  => '',
	];
}

/**
 * Dekodiert den in den Plugin-Einstellungen angezeigten migration_key (doppeltes Base64, vgl. Admin::get_migration_key()).
 *
 * @return array{ok: bool, error: string, steps: list<array<string, mixed>>, plain_key: string, site_url: string, mode: string, db_prefix: string}
 */
function demo_decode_display_migration_key( string $encoded ): array {
	$steps   = [];
	$trimmed = trim( $encoded );
	if ( $trimmed === '' ) {
		return [ 'ok' => false, 'error' => 'Leerer String.', 'steps' => [], 'plain_key' => '', 'site_url' => '', 'mode' => '', 'db_prefix' => '' ];
	}

	$outer = base64_decode( $trimmed, true );
	if ( $outer === false ) {
		return [ 'ok' => false, 'error' => 'Äußeres Base64 ungültig.', 'steps' => [], 'plain_key' => '', 'site_url' => '', 'mode' => '', 'db_prefix' => '' ];
	}
	$steps[] = [
		'label'   => '1) Äußeres Base64 dekodiert',
		'preview' => demo_trunc( $outer, 200 ),
		'full'    => $outer,
	];

	$parts = explode( ':', $outer, 2 );
	if ( count( $parts ) !== 2 ) {
		return [ 'ok' => false, 'error' => 'Nach außen: Erwartet „inner_base64:roher_key“ (ein Doppelpunkt).', 'steps' => $steps, 'plain_key' => '', 'site_url' => '', 'mode' => '', 'db_prefix' => '' ];
	}

	[ $inner_b64, $plain_key ] = $parts;
	$steps[] = [
		'label'      => '2) Am Doppelpunkt getrennt',
		'inner_b64'  => demo_trunc( $inner_b64, 80 ),
		'plain_key'  => $plain_key,
		'plain_note' => 'Entspricht der Option wpbackup_migration_key (32 Zeichen, bei Aktivierung generiert).',
	];

	$inner = base64_decode( $inner_b64, true );
	if ( $inner === false ) {
		return [ 'ok' => false, 'error' => 'Inneres Base64 ungültig.', 'steps' => $steps, 'plain_key' => $plain_key, 'site_url' => '', 'mode' => '', 'db_prefix' => '' ];
	}
	$steps[] = [
		'label'   => '3) Inneres Base64 dekodiert (Pipe-getrennt)',
		'full'    => $inner,
		'preview' => demo_trunc( $inner, 200 ),
	];

	$pipe     = explode( '|', $inner, 3 );
	$site_url = $pipe[0] ?? '';
	$mode     = $pipe[1] ?? '';
	$prefix   = $pipe[2] ?? '';
	$steps[]  = [
		'label'     => '4) Felder',
		'site_url'  => $site_url,
		'multisite' => $mode,
		'db_prefix' => $prefix,
	];

	return [
		'ok'        => true,
		'error'     => '',
		'steps'     => $steps,
		'plain_key' => $plain_key,
		'site_url'  => $site_url,
		'mode'      => $mode,
		'db_prefix' => $prefix,
	];
}

function demo_trunc( string $s, int $max ): string {
	if ( strlen( $s ) <= $max ) {
		return $s;
	}
	return substr( $s, 0, $max ) . '…';
}

function demo_mask_secret( string $s ): string {
	if ( strlen( $s ) <= 4 ) {
		return '****';
	}
	return substr( $s, 0, 4 ) . str_repeat( '*', max( 4, strlen( $s ) - 4 ) );
}

/**
 * Bytezahl für Anzeige (Democlient).
 */
function demo_format_bytes( int $bytes ): string {
	if ( $bytes < 0 ) {
		$bytes = 0;
	}
	$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	$n       = (float) $bytes;
	$u       = 0;
	while ( $n >= 1024.0 && $u < count( $units ) - 1 ) {
		$n /= 1024.0;
		++$u;
	}
	return sprintf( '%.2f %s', $n, $units[ $u ] );
}

/**
 * Boolesche Werte für Tabellen (Democlient).
 */
function demo_bool_de( bool $v ): string {
	return $v ? 'ja' : 'nein';
}

/**
 * GET mit Header X-WPBackup-Key (filesystem-scan u. ä.).
 *
 * @return array{ok: bool, status: int, body: string, error: string}
 */
function demo_http_get_migration_key( string $url, string $migration_key ): array {
	$key = preg_replace( '/[\r\n\x00]/', '', trim( $migration_key ) );
	if ( $key === '' ) {
		return [
			'ok'     => false,
			'status' => 0,
			'body'   => '',
			'error'  => 'Migration Key fehlt.',
		];
	}

	$ctx = stream_context_create(
		[
			'http' => [
				'method'        => 'GET',
				'header'        => "Accept: application/json\r\nX-WPBackup-Key: {$key}\r\n",
				'timeout'       => 60,
				'ignore_errors' => true,
			],
			'ssl'  => [
				'verify_peer'      => true,
				'verify_peer_name' => true,
			],
		]
	);

	$body = @file_get_contents( $url, false, $ctx );
	if ( $body === false ) {
		return [
			'ok'     => false,
			'status' => 0,
			'body'   => '',
			'error'  => 'Request fehlgeschlagen (file_get_contents).',
		];
	}

	$status = 0;
	if ( isset( $http_response_header ) && is_array( $http_response_header ) ) {
		foreach ( $http_response_header as $line ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) {
				$status = (int) $m[1];
				break;
			}
		}
	}

	return [
		'ok'     => $status >= 200 && $status < 300,
		'status' => $status,
		'body'   => $body,
		'error'  => '',
	];
}

/**
 * @param list<array<string, mixed>> $entries API-Feld "entries"
 * @return array<string, array{meta: ?array, children: array}>
 */
function demo_fs_entries_to_nested_tree( array $entries ): array {
	$root = [];
	foreach ( $entries as $e ) {
		if ( ! is_array( $e ) || ! isset( $e['relative_path'] ) ) {
			continue;
		}
		$parts = array_values(
			array_filter(
				explode( '/', str_replace( '\\', '/', (string) $e['relative_path'] ) ),
				'strlen'
			)
		);
		$ref = &$root;
		foreach ( $parts as $pi => $seg ) {
			if ( ! isset( $ref[ $seg ] ) ) {
				$ref[ $seg ] = [
					'meta'     => null,
					'children' => [],
				];
			}
			if ( $pi === count( $parts ) - 1 ) {
				$ref[ $seg ]['meta'] = $e;
			}
			$ref = &$ref[ $seg ]['children'];
		}
	}
	return $root;
}

/**
 * @param array<string, array{meta: ?array, children: array}> $nodes
 */
function demo_fs_render_tree_html( array $nodes ): string {
	if ( $nodes === [] ) {
		return '';
	}
	ksort( $nodes, SORT_NATURAL | SORT_FLAG_CASE );
	$html = '<ul class="fs-tree">';
	foreach ( $nodes as $name => $data ) {
		$meta     = is_array( $data['meta'] ?? null ) ? $data['meta'] : null;
		$children = is_array( $data['children'] ?? null ) ? $data['children'] : [];
		$type     = is_array( $meta ) ? (string) ( $meta['type'] ?? '?' ) : '?';
		$extra    = '';
		if ( is_array( $meta ) && isset( $meta['size'] ) && $meta['size'] !== null ) {
			$extra = ' · ' . (int) $meta['size'] . ' B';
		}
		$html .= '<li><span class="fs-node"><strong>' . htmlspecialchars( (string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</strong> ';
		$html .= '<span class="fs-meta">(' . htmlspecialchars( $type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . htmlspecialchars( $extra, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . ')</span></span>';
		if ( $children !== [] ) {
			$html .= demo_fs_render_tree_html( $children );
		}
		$html .= '</li>';
	}
	$html .= '</ul>';

	return $html;
}

// -----------------------------------------------------------------------------
// Eingaben
// -----------------------------------------------------------------------------

$has_wp_app_redirect = isset( $_GET['user_login'], $_GET['password'] )
	&& is_string( $_GET['user_login'] )
	&& is_string( $_GET['password'] );

$form_site = DEMO_DEFAULT_SITE;
if ( isset( $_GET['wpbackup_demo_site'] ) && is_string( $_GET['wpbackup_demo_site'] ) ) {
	$normalized = demo_normalize_site_base( (string) $_GET['wpbackup_demo_site'] );
	if ( $normalized !== '' ) {
		$form_site = $normalized;
	}
}
if ( $_SERVER['REQUEST_METHOD'] === 'POST'
	&& isset( $_POST['demo_filesystem_scan'], $_POST['wpbackup_demo_site'] )
	&& is_string( $_POST['wpbackup_demo_site'] ) ) {
	$normalized = demo_normalize_site_base( (string) $_POST['wpbackup_demo_site'] );
	if ( $normalized !== '' ) {
		$form_site = $normalized;
	}
}

$probe = null;
$should_probe = false;
if ( isset( $_GET['wpbackup_demo_site'] ) && is_string( $_GET['wpbackup_demo_site'] ) && trim( $_GET['wpbackup_demo_site'] ) !== '' ) {
	$should_probe = true;
}
if ( $has_wp_app_redirect ) {
	$should_probe = true;
}
if ( $should_probe && $form_site !== '' ) {
	$probe = demo_probe_wordpress_and_plugin( $form_site );
	if ( is_array( $probe ) && ! empty( $probe['authorize_ok'] ) ) {
		$_SESSION['wpbackup_demo_rest_ok_site'] = $form_site;
	}
}

$rest_ok_session = isset( $_SESSION['wpbackup_demo_rest_ok_site'] )
	&& is_string( $_SESSION['wpbackup_demo_rest_ok_site'] )
	&& $_SESSION['wpbackup_demo_rest_ok_site'] === $form_site;
$auth_enabled = ( is_array( $probe ) && ! empty( $probe['authorize_ok'] ) ) || $rest_ok_session;

$self_url    = demo_callback_self_url();
$success_url = $self_url . '?wpbackup_demo_site=' . rawurlencode( $form_site );

$authorize_url = rtrim( $form_site, '/' ) . '/wp-admin/authorize-application.php'
	. '?app_name=' . rawurlencode( APP_NAME )
	. '&success_url=' . rawurlencode( $success_url );

$info_result      = null;
$wp_base_for_info = '';
if ( isset( $_GET['site_url'] ) && is_string( $_GET['site_url'] ) ) {
	$wp_base_for_info = demo_normalize_site_base( (string) $_GET['site_url'] );
}
if ( $wp_base_for_info === '' && $form_site !== '' ) {
	$wp_base_for_info = $form_site;
}

if ( $has_wp_app_redirect ) {
	$u = (string) $_GET['user_login'];
	$p = (string) $_GET['password'];
	if ( $wp_base_for_info !== '' ) {
		$info_url    = rtrim( $wp_base_for_info, '/' ) . REST_INFO_PATH;
		$info_result = demo_http_get_basic( $info_url, $u, $p );
	}
}

if ( $info_result !== null && $info_result['ok'] && $info_result['error'] === '' ) {
	$info_json = json_decode( $info_result['body'], true );
	if ( is_array( $info_json ) && isset( $info_json['key'] ) && is_string( $info_json['key'] ) && $info_json['key'] !== '' ) {
		$_SESSION['wpbackup_demo_migration_key']      = $info_json['key'];
		$_SESSION['wpbackup_demo_migration_key_site'] = $wp_base_for_info;
	}
}

$fs_form_path             = 'uploads';
$fs_form_depth            = 2;
$fs_scan_result           = null;
$fs_scan_duration_seconds = null;
$fs_migration_override = isset( $_POST['migration_key_override'] ) && is_string( $_POST['migration_key_override'] )
	? (string) $_POST['migration_key_override'] : '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['demo_filesystem_scan'] ) ) {
	$fs_form_path = isset( $_POST['filesystem_scan_path'] ) ? trim( (string) $_POST['filesystem_scan_path'] ) : 'uploads';
	if ( $fs_form_path === '' ) {
		$fs_form_path = 'uploads';
	}
	$fs_form_depth = isset( $_POST['filesystem_scan_max_depth'] ) ? max( 0, min( 50, (int) $_POST['filesystem_scan_max_depth'] ) ) : 2;

	$mkey = '';
	if ( trim( $fs_migration_override ) !== '' ) {
		$mkey = trim( $fs_migration_override );
	} elseif (
		isset( $_SESSION['wpbackup_demo_migration_key'], $_SESSION['wpbackup_demo_migration_key_site'] )
		&& is_string( $_SESSION['wpbackup_demo_migration_key'] )
		&& is_string( $_SESSION['wpbackup_demo_migration_key_site'] )
		&& $_SESSION['wpbackup_demo_migration_key_site'] === $wp_base_for_info
	) {
		$mkey = $_SESSION['wpbackup_demo_migration_key'];
	}

	if ( $wp_base_for_info !== '' && $mkey !== '' ) {
		$fs_url = rtrim( $wp_base_for_info, '/' ) . REST_FILESYSTEM_SCAN_PATH . '?' . http_build_query(
			[
				'path'      => $fs_form_path,
				'max_depth' => $fs_form_depth,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$t0                       = microtime( true );
		$fs_scan_result           = demo_http_get_migration_key( $fs_url, $mkey );
		$fs_scan_duration_seconds = microtime( true ) - $t0;
	} else {
		$fs_scan_result = [
			'ok'     => false,
			'status' => 0,
			'body'   => '',
			'error'  => 'WordPress-Basis-URL oder Migration Key fehlt (nach /info in Session oder manuell eintragen).',
		];
	}
}

$migration_key_raw = '';
if ( isset( $_GET['migration_key'] ) && is_string( $_GET['migration_key'] ) ) {
	$migration_key_raw = (string) $_GET['migration_key'];
}
$decoded_mk = $migration_key_raw !== '' ? demo_decode_display_migration_key( $migration_key_raw ) : null;

// -----------------------------------------------------------------------------
// Ausgabe
// -----------------------------------------------------------------------------

header( 'Content-Type: text/html; charset=utf-8' );
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>WPBackup Migrator – Aktivierungs-Demo (Callback)</title>
	<style>
		:root { font-family: system-ui, sans-serif; line-height: 1.45; }
		body { max-width: 52rem; margin: 1.5rem auto; padding: 0 1rem; }
		h1 { font-size: 1.35rem; }
		h2 { font-size: 1.1rem; margin-top: 1.75rem; }
		label { display: block; font-weight: 600; margin-bottom: 0.35rem; }
		input[type="url"] { width: 100%; max-width: 36rem; padding: 0.45rem 0.6rem; font-size: 1rem; box-sizing: border-box; }
		.row { margin-bottom: 1rem; }
		button, .btn {
			display: inline-block; padding: 0.55rem 1rem; font-size: 1rem; cursor: pointer;
			border: 1px solid #1d6b2f; background: #2271b1; color: #fff; text-decoration: none; border-radius: 4px;
		}
		button.secondary { background: #f0f0f1; color: #1d2327; border-color: #c3c4c7; }
		.btn.authorize {
			display: inline-block; margin-top: 0.75rem; padding: 0.85rem 1.4rem; font-size: 1.15rem; font-weight: 600;
			background: #00a32a; border-color: #00a32a;
		}
		.btn.authorize[aria-disabled="true"] {
			background: #c3c4c7; border-color: #c3c4c7; cursor: not-allowed; pointer-events: none;
		}
		.ok { color: #008a20; font-weight: 700; }
		.bad { color: #b32d2e; }
		pre {
			background: #f6f7f7; border: 1px solid #dcdcde; padding: 0.75rem; overflow: auto; font-size: 0.85rem;
			border-radius: 4px;
		}
		.check { font-size: 1.25rem; }
		table { border-collapse: collapse; width: 100%; font-size: 0.9rem; }
		th, td { border: 1px solid #dcdcde; padding: 0.4rem 0.5rem; text-align: left; vertical-align: top; }
		th { background: #f0f0f1; }
		.note { font-size: 0.9rem; color: #50575e; }
		.table-wrap { overflow-x: auto; margin: 0.75rem 0 1.25rem; -webkit-overflow-scrolling: touch; }
		.table-wrap table { font-size: 0.82rem; }
		.table-wrap caption { text-align: left; font-weight: 700; margin-bottom: 0.35rem; }
		.table-wrap td.name { white-space: normal; max-width: 16rem; }
		.fs-tree { list-style: none; margin: 0.2rem 0 0.2rem 1rem; padding: 0; }
		.fs-tree .fs-tree { margin-left: 0.85rem; border-left: 1px solid #dcdcde; padding-left: 0.5rem; }
		.fs-tree li { margin: 0.2rem 0; }
		.fs-meta { color: #50575e; font-size: 0.88rem; font-weight: normal; }
		#fs-tree-output { margin-top: 0.75rem; padding: 0.75rem; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; overflow: auto; max-height: 28rem; }
	</style>
</head>
<body>

<h1>WPBackup Migrator – Aktivierungs-Demo</h1>

<p class="note">
	Dieses Skript zeigt den Ablauf: WordPress-URL prüfen (Kern-REST <code>/wp-json/</code> + optional Plugin <code>/info</code>) → <strong>Application Password</strong> über
	<code>authorize-application.php</code> → Redirect zurück auf diese Callback-URL → optional
	<code>migration_key</code> dekodieren → REST <code>GET …/v1/info</code>
	(u. a. <code>database_size</code>, <code>media_size</code>; siehe <code>docs/rest-api.md</code>).
</p>

<h2>1) WordPress-URL &amp; REST-Status</h2>
<p class="note">Gültige URL eingeben – die Prüfung startet automatisch (kein separater Button).</p>
<form method="get" action="" id="demo-site-form">
	<div class="row">
		<label for="site">HTTPS-Domain (WordPress-Startseite)</label>
		<input type="url" id="site" name="wpbackup_demo_site" value="<?php echo htmlspecialchars( $form_site, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>" required placeholder="https://example.com" autocomplete="url">
	</div>
</form>

<?php if ( is_array( $probe ) ) : ?>
	<div class="probe-results">
		<p class="check">
			<?php if ( ! empty( $probe['wordpress']['ok'] ) ) : ?>
				<span class="ok">✓</span>
				<span><strong>WordPress-REST-API</strong> erreichbar: <code>/wp-json/</code> liefert eine typische Index-Antwort (HTTP <?php echo (int) $probe['wordpress']['status']; ?>).</span>
			<?php else : ?>
				<span class="bad">✗</span>
				<span><strong>WordPress-REST-API</strong> nicht erkannt unter <code><?php echo htmlspecialchars( $probe['wordpress']['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code>
					(HTTP <?php echo (int) $probe['wordpress']['status']; ?> – erwartet wird u. a. JSON mit <code>namespaces</code> / <code>routes</code>).</span>
			<?php endif; ?>
		</p>
		<p class="check">
			<?php if ( ! empty( $probe['plugin']['installed'] ) ) : ?>
				<span class="ok">✓</span>
				<span><strong>WPBackup Migrator</strong>: Route <code><?php echo htmlspecialchars( REST_INFO_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code> ist registriert (HTTP <?php echo (int) $probe['plugin']['status']; ?>, Authentifizierung erforderlich – Plugin aktiv).</span>
			<?php else : ?>
				<span class="bad">○</span>
				<span><strong>WPBackup Migrator</strong>: Endpunkt <code><?php echo htmlspecialchars( REST_INFO_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code> noch nicht erreichbar oder nicht installiert (HTTP <?php echo (int) $probe['plugin']['status']; ?>). Nach Installation erscheint hier üblicherweise 401/403 ohne Login.</span>
			<?php endif; ?>
		</p>
	</div>
	<details>
		<summary>Technische Details (Rohantworten, gekürzt)</summary>
		<p><strong>GET</strong> <code><?php echo htmlspecialchars( $probe['wordpress']['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code></p>
		<pre><?php echo htmlspecialchars( $probe['wordpress']['detail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></pre>
		<p><strong>GET</strong> <code><?php echo htmlspecialchars( $probe['plugin']['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code></p>
		<pre><?php echo htmlspecialchars( $probe['plugin']['detail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></pre>
	</details>
<?php elseif ( $rest_ok_session ) : ?>
	<p class="check"><span class="ok">✓</span> <span>WordPress-REST für diese Domain wurde in dieser Session bereits als erreichbar gespeichert.</span></p>
<?php endif; ?>

<h2>2) WPBackup.org bei WordPress autorisieren</h2>
<p class="note">
	Link-Ziel (Beispiel): <code>…/wp-admin/authorize-application.php?app_name=…&amp;success_url=…</code>.
	Nach dem Login erzeugt WordPress ein <strong>Application Password</strong> und leitet auf
	<code>success_url</code> mit den Parametern <code>site_url</code>, <code>user_login</code>,
	<code>password</code> um (Core-Verhalten).
</p>
<p>
	<a class="btn authorize" href="<?php echo htmlspecialchars( $authorize_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
		<?php echo $auth_enabled ? '' : ' aria-disabled="true" onclick="return false;"'; ?>>
		WPBackup.org bei WordPress autorisieren
	</a>
	<?php if ( ! $auth_enabled ) : ?>
		<br><span class="note">Zuerst eine gültige WordPress-URL eintragen; die Kern-REST-API (<code>/wp-json/</code>) muss erreichbar sein.</span>
	<?php endif; ?>
</p>
<p class="note"><strong>success_url</strong> (immer <code>https://</code>):<br><code><?php echo htmlspecialchars( $success_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code></p>

<?php if ( $has_wp_app_redirect || isset( $_GET['site_url'] ) || isset( $_GET['migration_key'] ) ) : ?>
	<h2>3) Ergebnis des Success-Redirects (Query-Parameter)</h2>
	<p class="note">Empfindliche Werte werden teilweise maskiert.</p>
	<table>
		<tr><th>Parameter</th><th>Wert</th></tr>
		<?php foreach ( $_GET as $k => $v ) : ?>
			<?php if ( ! is_string( $v ) ) { continue; } ?>
			<tr>
				<td><code><?php echo htmlspecialchars( (string) $k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code></td>
				<td>
					<?php
					$show = $v;
					if ( $k === 'password' ) {
						$show = demo_mask_secret( $v );
					}
					echo htmlspecialchars( $show, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
					?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php endif; ?>

<?php if ( $decoded_mk !== null ) : ?>
	<h2>4) migration_key (doppelt Base64 – wie in den Plugin-Einstellungen)</h2>
	<p class="note">
		<strong>Herkunft des rohen Secrets:</strong> Bei <strong>Plugin-Aktivierung</strong> wird
		<code>wp_generate_password( 32, false )</code> in der Option
		<code>wpbackup_migration_key</code> gespeichert (siehe <code>Plugin::activate()</code>).
		Der in WP angezeigte lange Key ist <strong>kein</strong> Zeitstempel, sondern
		<strong>zweifach Base64</strong>-kodierte Metadaten + Roh-Key (siehe <code>Admin::get_migration_key()</code>).
	</p>
	<?php if ( $decoded_mk['ok'] ) : ?>
		<p class="ok">✓ Dekodierung erfolgreich</p>
		<ul>
			<li><strong>site_url</strong> (aus innerem Payload): <code><?php echo htmlspecialchars( $decoded_mk['site_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code></li>
			<li><strong>single|multisite</strong>: <code><?php echo htmlspecialchars( $decoded_mk['mode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code></li>
			<li><strong>db_prefix</strong>: <code><?php echo htmlspecialchars( $decoded_mk['db_prefix'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code></li>
			<li><strong>roher migration key</strong> (Option): <code><?php echo htmlspecialchars( $decoded_mk['plain_key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code></li>
		</ul>
		<h3>Alle Dekodierungsschritte</h3>
		<pre><?php echo htmlspecialchars( json_encode( $decoded_mk['steps'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></pre>
	<?php else : ?>
		<p class="bad">Dekodierung fehlgeschlagen: <?php echo htmlspecialchars( $decoded_mk['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></p>
		<?php if ( $decoded_mk['steps'] !== [] ) : ?>
			<pre><?php echo htmlspecialchars( json_encode( $decoded_mk['steps'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></pre>
		<?php endif; ?>
	<?php endif; ?>
<?php endif; ?>

<?php if ( $info_result !== null ) : ?>
	<h2>5) REST API <code>GET …/v1/info</code> (Basic Auth mit Application Password)</h2>
	<p class="note">
		Die Antwort enthält u. a. <code>media_size</code> (Bytes, Schätzung aus DB-Metadaten für Hauptdateien und Thumbnails, ohne Uploads-Verzeichnis zu scannen).
	</p>
	<p>URL: <code><?php echo htmlspecialchars( rtrim( $wp_base_for_info, '/' ) . REST_INFO_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code></p>
	<p>HTTP-Status: <strong><?php echo (int) $info_result['status']; ?></strong></p>
	<?php if ( $info_result['error'] !== '' ) : ?>
		<p class="bad"><?php echo htmlspecialchars( $info_result['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></p>
	<?php elseif ( $info_result['ok'] ) : ?>
		<?php
		$json = json_decode( $info_result['body'], true );
		?>
		<?php if ( is_array( $json ) ) : ?>
			<?php if ( isset( $json['media_size'] ) && is_numeric( $json['media_size'] ) ) : ?>
				<p><strong>media_size:</strong>
					<code><?php echo (int) $json['media_size']; ?></code> Bytes
					(≈ <?php echo htmlspecialchars( demo_format_bytes( (int) $json['media_size'] ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>)
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $json['list_plugins'] ) && is_array( $json['list_plugins'] ) ) : ?>
				<div class="table-wrap">
					<table>
						<caption>Plugins (<code>list_plugins</code>)</caption>
						<thead>
							<tr>
								<th scope="col">Name</th>
								<th scope="col">Version</th>
								<th scope="col">aktiv</th>
								<th scope="col">Slug</th>
								<th scope="col">Update</th>
								<th scope="col">MU</th>
								<th scope="col">Netzwerk</th>
								<th scope="col">PHP</th>
								<th scope="col">WP</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $json['list_plugins'] as $pl ) : ?>
								<?php if ( ! is_array( $pl ) ) { continue; } ?>
								<tr>
									<td class="name"><?php echo htmlspecialchars( (string) ( $pl['name'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
									<td><?php echo htmlspecialchars( (string) ( $pl['version'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
									<td><?php echo htmlspecialchars( demo_bool_de( ! empty( $pl['active'] ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
									<td><code><?php echo htmlspecialchars( (string) ( $pl['slug'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code></td>
									<td><?php echo htmlspecialchars( demo_bool_de( ! empty( $pl['update_available'] ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
									<td><?php echo htmlspecialchars( demo_bool_de( ! empty( $pl['is_mu'] ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
									<td><?php echo htmlspecialchars( demo_bool_de( ! empty( $pl['is_network'] ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
									<td><?php echo htmlspecialchars( (string) ( $pl['requires_php'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
									<td><?php echo htmlspecialchars( (string) ( $pl['requires_wp'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $json['list_themes'] ) && is_array( $json['list_themes'] ) ) : ?>
				<div class="table-wrap">
					<table>
						<caption>Themes (<code>list_themes</code>)</caption>
						<thead>
							<tr>
								<th scope="col">Name</th>
								<th scope="col">Version</th>
								<th scope="col">aktiv</th>
								<th scope="col">Child</th>
								<th scope="col">Parent (Stylesheet)</th>
								<th scope="col">Parent-Version</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $json['list_themes'] as $th ) : ?>
								<?php if ( ! is_array( $th ) ) { continue; } ?>
								<tr>
									<td class="name"><?php echo htmlspecialchars( (string) ( $th['name'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
									<td><?php echo htmlspecialchars( (string) ( $th['version'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
									<td><?php echo htmlspecialchars( demo_bool_de( ! empty( $th['active'] ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
									<td><?php echo htmlspecialchars( demo_bool_de( ! empty( $th['is_child'] ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
									<td><code><?php echo htmlspecialchars( (string) ( $th['parent'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code></td>
									<td><?php echo htmlspecialchars( (string) ( $th['parent_version'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php
			$json_for_pre = $json;
			unset( $json_for_pre['list_plugins'], $json_for_pre['list_themes'] );
			?>
			<p class="note">Weitere Felder (JSON ohne Plugin-/Themenlisten):</p>
			<pre><?php echo htmlspecialchars( json_encode( $json_for_pre, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></pre>
		<?php else : ?>
			<pre><?php echo htmlspecialchars( $info_result['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></pre>
		<?php endif; ?>
	<?php else : ?>
		<p class="bad">Anfrage nicht erfolgreich.</p>
		<pre><?php echo htmlspecialchars( $info_result['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></pre>
	<?php endif; ?>
<?php elseif ( isset( $_GET['migration_key'] ) && ! $has_wp_app_redirect ) : ?>
	<p class="note">Keine <code>user_login</code>/<code>password</code>-Parameter – /info wird nicht abgefragt (nur <code>migration_key</code>-Test).</p>
<?php endif; ?>

<h2>6) REST API <code>GET …/v1/filesystem-scan</code> (Migration Key)</h2>
<p class="note">
	Listet Dateien/Ordner unter <code>wp-content</code> (relativer <code>path</code>, <code>max_depth</code>). Authentifizierung: Header <code>X-WPBackup-Key</code>.
	Nach erfolgreichem Abschnitt 5 wird der Key aus der <code>/info</code>-Antwort in der Session gespeichert; alternativ unten manuell eintragen.
</p>
<form method="post" action="" id="demo-filesystem-scan-form">
	<input type="hidden" name="wpbackup_demo_site" value="<?php echo htmlspecialchars( $form_site, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">
	<input type="hidden" name="demo_filesystem_scan" value="1">
	<div class="row">
		<label for="filesystem_scan_path">Pfad unter <code>wp-content</code> (<code>filesystem-scan-path</code>)</label>
		<input type="text" id="filesystem_scan_path" name="filesystem_scan_path" value="<?php echo htmlspecialchars( $fs_form_path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>" placeholder="uploads oder .">
	</div>
	<div class="row">
		<label for="filesystem_scan_max_depth"><code>max_depth</code></label>
		<input type="number" id="filesystem_scan_max_depth" name="filesystem_scan_max_depth" value="<?php echo (int) $fs_form_depth; ?>" min="0" max="50" step="1">
	</div>
	<div class="row">
		<label for="migration_key_override">Migration Key (optional, falls nicht aus /info-Session)</label>
		<input type="password" id="migration_key_override" name="migration_key_override" value="" autocomplete="off" placeholder="Leer = Session nach Abschnitt 5">
	</div>
	<button type="submit" class="secondary">filesystem-scan ausführen</button>
</form>

<?php if ( $fs_scan_result !== null ) : ?>
	<p>HTTP-Status: <strong><?php echo (int) $fs_scan_result['status']; ?></strong>
		<?php if ( $fs_scan_duration_seconds !== null ) : ?>
			· Abfrage-Dauer: <strong><?php echo htmlspecialchars( number_format( $fs_scan_duration_seconds, 3, ',', '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?> s</strong>
		<?php endif; ?>
		<?php if ( $fs_scan_result['error'] !== '' ) : ?>
			<span class="bad"><?php echo htmlspecialchars( $fs_scan_result['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></span>
		<?php endif; ?>
	</p>
	<?php if ( $fs_scan_result['error'] === '' && $fs_scan_result['ok'] ) : ?>
		<?php
		$fs_json = json_decode( $fs_scan_result['body'], true );
		?>
		<?php if ( is_array( $fs_json ) && isset( $fs_json['entries'] ) && is_array( $fs_json['entries'] ) ) : ?>
			<?php
			$nested = demo_fs_entries_to_nested_tree( $fs_json['entries'] );
			?>
			<p class="note">
				<code><?php echo htmlspecialchars( rtrim( $wp_base_for_info, '/' ) . REST_FILESYSTEM_SCAN_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></code>
				· Einträge: <?php echo isset( $fs_json['entry_count'] ) ? (int) $fs_json['entry_count'] : count( $fs_json['entries'] ); ?>
				<?php if ( ! empty( $fs_json['truncated'] ) ) : ?>
					· <strong>gekürzt</strong> (Limit)
				<?php endif; ?>
				· Bytes (Dateien): <?php echo isset( $fs_json['total_bytes'] ) ? (int) $fs_json['total_bytes'] : 0; ?>
			</p>
			<div id="fs-tree-output">
				<?php if ( $nested === [] ) : ?>
					<p class="note">Keine Einträge (leeres Verzeichnis oder Filter).</p>
				<?php else : ?>
					<?php echo demo_fs_render_tree_html( $nested ); ?>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<pre><?php echo htmlspecialchars( $fs_scan_result['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></pre>
		<?php endif; ?>
	<?php elseif ( $fs_scan_result['error'] === '' && ! $fs_scan_result['ok'] ) : ?>
		<p class="bad">Anfrage fehlgeschlagen (HTTP <?php echo (int) $fs_scan_result['status']; ?>).</p>
		<pre><?php echo htmlspecialchars( $fs_scan_result['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></pre>
	<?php endif; ?>
<?php endif; ?>

<h2>Hinweise (Auto-Install &amp; Demo)</h2>
<ul class="note">
	<li><strong>Auto-Install aus dem WordPress-Plugin-Verzeichnis</strong> ist in diesem Projekt nicht abgebildet (Plugin noch nicht im offiziellen Repo). Auf der Zielseite muss WPBackup Migrator manuell installiert und aktiviert werden – erst dann existiert die REST-Route und der Migration Key in der Datenbank.</li>
	<li>Ein CLI-Hilfsskript zum Aktivieren nach Ordner-Umbenennung liegt unter <code>tools/ensure-plugin-active.php</code> (nur Server-CLI).</li>
	<li>Der Parameter <code>migration_key</code> gehört <strong>nicht</strong> zum Standard-Redirect von WordPress; er kann ergänzt werden oder man kopiert den Key aus den WP-Einstellungen zum Testen:
		<code>?migration_key=…</code> an diese URL anhängen.</li>
</ul>

<script>
(function () {
	var input = document.getElementById('site');
	var form = document.getElementById('demo-site-form');
	if (!input || !form) return;
	var debounceMs = 550;
	var t = null;
	// URL mit http(s), Host, optional Pfad (z. B. Unterverzeichnis-WordPress)
	var urlLine = /^https?:\/\/[^\s/$.?#].[^\s]*$/i;

	function currentParamSite() {
		var q = new URLSearchParams(window.location.search);
		return q.get('wpbackup_demo_site') || '';
	}

	function navigateIfValid() {
		var raw = (input.value || '').trim();
		if (!raw || !urlLine.test(raw)) return;
		try {
			var u = new URL(raw);
			if (u.protocol !== 'http:' && u.protocol !== 'https:') return;
		} catch (e) {
			return;
		}
		if (currentParamSite() === raw) return;
		var q = new URLSearchParams(window.location.search);
		q.set('wpbackup_demo_site', raw);
		window.location.search = q.toString();
	}

	function scheduleProbe() {
		clearTimeout(t);
		t = setTimeout(navigateIfValid, debounceMs);
	}

	input.addEventListener('input', scheduleProbe);
	input.addEventListener('change', function () { clearTimeout(t); navigateIfValid(); });

	// Erste Anzeige ohne Query: Standard-URL im Feld → automatisch testen
	if (!currentParamSite() && input.value.trim() && urlLine.test(input.value.trim())) {
		scheduleProbe();
	}
})();
</script>

</body>
</html>
