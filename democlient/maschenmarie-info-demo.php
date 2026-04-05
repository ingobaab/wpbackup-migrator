<?php
/**
 * Minimaler Democlient (PHP 8.2+, prozedural): REST /info nach Application-Password-Auth.
 *
 * Voraussetzung: Auf https://maschenmarie.de ist WPBackup Migrator aktiv.
 * Der Migration Key kann per Redirect (?migration_key=…) kommen (z. B. von wpbackup.org);
 * er wird hier nur angezeigt – der /info-Endpunkt liefert den Key ohnehin nach erfolgreicher Auth.
 *
 * Konfiguration:
 *   Umgebungsvariablen WPBACKUP_DEMO_USER und WPBACKUP_DEMO_APP_PASSWORD
 *   oder Konstanten unten setzen.
 *
 * Aufruf im Browser (PHP eingebettet oder php -S) – oder CLI:
 *   WPBACKUP_DEMO_USER=admin WPBACKUP_DEMO_APP_PASSWORD=xxxx php maschenmarie-info-demo.php
 *
 * @noinspection PhpUndefinedConstantInspection
 */

declare( strict_types=1 );

// --- Konfiguration Testdomain ---
const DEMO_SITE_BASE = 'https://maschenmarie.de';
const REST_INFO_PATH = '/wp-json/wpbackup-migrator/v1/info';

$default_user = '';
$default_pass = '';

// Aus Umgebung (empfohlen, keine Secrets im Repo)
if ( getenv( 'WPBACKUP_DEMO_USER' ) !== false ) {
	$default_user = (string) getenv( 'WPBACKUP_DEMO_USER' );
}
if ( getenv( 'WPBACKUP_DEMO_APP_PASSWORD' ) !== false ) {
	$default_pass = (string) getenv( 'WPBACKUP_DEMO_APP_PASSWORD' );
}

$migration_key_from_redirect = isset( $_GET['migration_key'] ) ? (string) $_GET['migration_key'] : '';

/**
 * HTTP GET mit Basic Auth.
 *
 * @param string $url    URL.
 * @param string $user   WP-Benutzername.
 * @param string $app_pw Application Password (ohne Leerzeichen).
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
			'error'  => 'Request failed (file_get_contents).',
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

// --- CLI ---
if ( php_sapi_name() === 'cli' ) {
	$user = $default_user;
	$pass = $default_pass;
	if ( $user === '' || $pass === '' ) {
		fwrite( STDERR, "Setze WPBACKUP_DEMO_USER und WPBACKUP_DEMO_APP_PASSWORD.\n" );
		exit( 1 );
	}
	$url  = DEMO_SITE_BASE . REST_INFO_PATH;
	$resp = demo_http_get_basic( $url, $user, $pass );
	echo "HTTP {$resp['status']}\n";
	if ( $resp['error'] !== '' ) {
		fwrite( STDERR, $resp['error'] . "\n" );
		exit( 1 );
	}
	echo $resp['body'] . "\n";
	exit( $resp['ok'] ? 0 : 1 );
}

// --- Web (nach Authorize-Redirect mit migration_key) ---
header( 'Content-Type: text/html; charset=utf-8' );
echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>WPBackup Migrator – Info-Demo</title></head><body>';
echo '<h1>WPBackup Migrator – /info Demo</h1>';

if ( $migration_key_from_redirect !== '' ) {
	echo '<p><strong>migration_key</strong> aus Redirect (Query): <code>' . htmlspecialchars( $migration_key_from_redirect, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</code></p>';
}

if ( $default_user === '' || $default_pass === '' ) {
	echo '<p>Bitte <code>WPBACKUP_DEMO_USER</code> und <code>WPBACKUP_DEMO_APP_PASSWORD</code> in der Server-Umgebung setzen oder im Skript eintragen.</p>';
	echo '<p>Der <strong>/info</strong>-Endpunkt erfordert einen Administrator mit <a href="https://make.wordpress.org/core/2020/11/05/application-passwords-integration/">Application Password</a> (Basic Auth).</p>';
	echo '</body></html>';
	exit;
}

$url  = DEMO_SITE_BASE . REST_INFO_PATH;
$resp = demo_http_get_basic( $url, $default_user, $default_pass );

echo '<p>HTTP-Status: <strong>' . (int) $resp['status'] . '</strong></p>';
if ( $resp['error'] !== '' ) {
	echo '<p style="color:red">' . htmlspecialchars( $resp['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</p>';
} else {
	$json = json_decode( $resp['body'], true );
	if ( is_array( $json ) ) {
		echo '<pre>' . htmlspecialchars( json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</pre>';
	} else {
		echo '<pre>' . htmlspecialchars( $resp['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</pre>';
	}
}
echo '</body></html>';
