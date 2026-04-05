# WPBackup Migrator – REST API (Kurzüberblick)

Namespace: `wpbackup-migrator/v1`  
Basis-URL: `https://<deine-domain>/wp-json/wpbackup-migrator/v1/`

---

## `GET /info`

Liefert Stammdaten zur Site und zum Plugin (u. a. Migration Key, Größen).

**Authentifizierung:** WordPress-Benutzer mit `manage_options` (Administrator), typischerweise per **HTTP Basic Auth** mit einem **Application Password** (nicht das Login-Passwort).

**Beispiel:**

```http
GET /wp-json/wpbackup-migrator/v1/info
Authorization: Basic base64(user:application_password)
```

**Antwort (JSON, Auszug):**

| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `success` | bool | Immer `true` bei HTTP 200 |
| `username`, `email` | string | Erster Administrator (wie in der bisherigen Implementierung) |
| `url`, `site_title` | string | Site-URL und Titel |
| `key` | string | Roher Migration Key (Option `wpbackup_migration_key`) |
| `is_multisite` | bool | Multisite ja/nein |
| `prefix` | string | Tabellen-Präfix |
| `php_version`, `wp_version` | string | Laufzeit |
| `database_size` | int | Datenbank-Datengröße in Bytes (Summe `Data_length` der Präfix-Tabellen) |
| `media_size` | int | **Geschätzte** Größe der Mediathek (Hauptdateien + registrierte Thumbnails) in Bytes |
| `list_plugins` | array | Installierte Plugins (inkl. MU-Plugins), siehe Objektstruktur unten |
| `list_themes` | array | Installierte Themes, siehe Objektstruktur unten |
| `is_wp_cron_disabled` | bool | `DISABLE_WP_CRON` gesetzt |

**`media_size`:** Kein Scan des `uploads`-Verzeichnisses. Die Schätzung kommt aus der Datenbank (`_wp_attachment_metadata`): wo WordPress `filesize` speichert, wird das genutzt; sonst Näherung aus Bildbreite/-höhe und MIME-Typ (Bytes-pro-Pixel-Heuristik). Nicht identisch mit dem echten Dateisystem, aber schnell und ohne Iterator.

**`list_plugins`:** Ein Eintrag pro Plugin (normale Plugins aus `wp-content/plugins` plus Must-Use aus `mu-plugins`), sortiert nach Name:

| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `name` | string | Anzeigename aus Plugin-Header |
| `version` | string | Versionszeichenkette |
| `active` | bool | Auf der aktuellen Site aktiv (lokal und/oder Netzwerk) |
| `slug` | string | Ordner- bzw. Hauptdateiname (z. B. `akismet`) |
| `update_available` | bool | Laut Transient `update_plugins` ein Update verfügbar (nur normale Plugins) |
| `is_mu` | bool | Must-Use-Plugin |
| `is_network` | bool | Multisite: netzwerkweit aktiviert |
| `requires_php` | string | Header „Requires PHP“ |
| `requires_wp` | string | Header „Requires at least“ (WP-Version) |

**`list_themes`:** Ein Eintrag pro installiertem Theme, sortiert nach Name:

| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `name` | string | Theme-Name |
| `version` | string | Version |
| `active` | bool | Entspricht `get_option('stylesheet')` |
| `is_child` | bool | Hat ein Parent-Theme |
| `parent` | string | Stylesheet-Slug des Parent (leer wenn nicht Child) |
| `parent_version` | string | Version des Parent-Themes |

---

## `POST /verify`

Prüft den Migration Key (Body-Parameter `key`).

**Authentifizierung:** keine (öffentlicher Endpunkt); stattdessen muss `key` dem gespeicherten Migration Key entsprechen.

**Antwort:** Gleiche Felder wie bei `GET /info` (inkl. `database_size`, `media_size`, `list_plugins`, `list_themes`), sofern der Key gültig ist.

---

## Weitere Routen

Datenbank- und Datei-Routen sind unter demselben Namespace registriert (siehe `includes/Api/Database.php`, `includes/Api/Files.php`); ggf. Migration Key per Header `X-WPBackup-Key` (oder Legacy `X-FlyWP-Key`).

---

*Version der API-Inhalte richtet sich nach der Plugin-Version; bei Abweichungen gilt der Code in `includes/Api.php`.*
