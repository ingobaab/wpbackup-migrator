=== WPBackup Migrator ===
Contributors: wpbackup
Tags: migration, backup, wordpress, transfer
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.3.0
Requires PHP: 8.2
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
Plugin URI: https://wpbackup.org

Backup and migration tooling for WordPress sites (REST API, migration key, optional resumable database export).

== Installation ==

1. Kopiere den Ordner `wpbackup-migrator` nach `wp-content/plugins/`.
2. **Wichtig:** Das Plugin muss in WordPress **aktiviert** werden (Plugins → WPBackup Migrator → Aktivieren). Nur Dateien hochladen reicht nicht – ohne Aktivierung läuft kein Code, kein REST, kein Migration Key.
3. Nach einem **Ordner-Umbenennen** (z. B. von `flywp-migrator`): optional per CLI aktivieren und `active_plugins` anpassen:
   `php wp-content/plugins/wpbackup-migrator/tools/ensure-plugin-active.php`
4. Im Admin unter „WPBackup Migrator“ den Migration Key kopieren bzw. für externe Tools verwenden.

== Description ==

Siehe Plugin-URI https://wpbackup.org für die aktuelle Dokumentation.

Technische Übersicht der REST-Endpunkte (u. a. /info, media_size): `docs/rest-api.md` im Plugin-Verzeichnis.

== Changelog ==

= 1.3.0 =
* Rebranding WPBackup Migrator, REST-Namespace `wpbackup-migrator/v1`.
