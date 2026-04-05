<?php
/**
 * CLI: Stellt sicher, dass WPBackup Migrator aktiv ist – nicht nur installiert.
 *
 * Nach Umbenennung des Plugin-Ordners (z. B. flywp-migrator → wpbackup-migrator)
 * zeigt WordPress das Plugin oft als inaktiv oder „Datei fehlt“. Dieses Skript:
 * - migriert einen alten Eintrag in active_plugins auf den neuen Pfad
 * - ruft activate_plugin() auf, falls nötig
 *
 * Aufruf aus der WordPress-Installation (Webroot):
 *   php wp-content/plugins/wpbackup-migrator/tools/ensure-plugin-active.php
 *
 * @noinspection PhpUndefinedConstantInspection ABSPATH nach wp-load
 */

declare( strict_types=1 );

if ( php_sapi_name() !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php nicht gefunden. Bitte aus dem WordPress-Webroot ausführen, z. B.:\n" );
	fwrite( STDERR, "  php wp-content/plugins/wpbackup-migrator/tools/ensure-plugin-active.php\n" );
	exit( 1 );
}

require_once $wp_load;
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$old_rel = 'flywp-migrator/wpbackup-migrator.php';
$new_rel = 'wpbackup-migrator/wpbackup-migrator.php';

$active = get_option( 'active_plugins', [] );
if ( ! is_array( $active ) ) {
	$active = [];
}

$key_old = array_search( $old_rel, $active, true );
if ( false !== $key_old ) {
	unset( $active[ $key_old ] );
	$active   = array_values( $active );
	$active[] = $new_rel;
	$active   = array_unique( $active );
	update_option( 'active_plugins', $active );
	echo "active_plugins: alter Pfad {$old_rel} → {$new_rel} migriert.\n";
}

if ( is_plugin_active( $new_rel ) ) {
	echo "Plugin bereits aktiv: {$new_rel}\n";
	exit( 0 );
}

$result = activate_plugin( $new_rel, '', false, true );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'activate_plugin fehlgeschlagen: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}

echo "Plugin aktiviert: {$new_rel}\n";
exit( 0 );
