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
| `database_info` | object | Tabellenübersicht: `table_count`, Summen (`total_data_bytes`, `total_index_bytes`, `total_bytes`) und `tables[]` je Tabelle |
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

**`database_info`:** Schnelle Tabellenübersicht (ohne `COUNT(*)` je Tabelle). Die Werte stammen aus `SHOW TABLE STATUS` für das aktuelle Tabellenpräfix und sind damit i. d. R. sehr performant; `records` kann je Storage-Engine/Statistik ein Näherungswert sein.

| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `table_count` | int | Anzahl erkannter Tabellen mit Site-Präfix |
| `total_data_bytes` | int | Summe der Datenblöcke (`Data_length`) |
| `total_index_bytes` | int | Summe der Indexblöcke (`Index_length`) |
| `total_bytes` | int | `total_data_bytes + total_index_bytes` |
| `tables` | array | Liste je Tabelle mit `name`, `records`, `data_bytes`, `index_bytes`, `total_bytes` |

---

## `POST /verify`

Prüft den Migration Key (Body-Parameter `key`).

**Authentifizierung:** keine (öffentlicher Endpunkt); stattdessen muss `key` dem gespeicherten Migration Key entsprechen.

**Antwort:** Gleiche Felder wie bei `GET /info` (inkl. `database_size`, `media_size`, `list_plugins`, `list_themes`), sofern der Key gültig ist.

---

## `GET /filesystem-scan`

Liefert eine **flache Liste** von Dateien und Verzeichnissen unter einem Startpfad **innerhalb von `wp-content`** (kein Download, nur Metadaten).

**Authentifizierung:** Migration Key (`X-WPBackup-Key` / `X-FlyWP-Key` oder Query `secret`) – wie bei den übrigen geschützten Datei-/DB-Routen.

**Query-Parameter:**

| Parameter | Typ | Pflicht | Beschreibung |
|-----------|-----|---------|--------------|
| `path` | string | nein | Relativer Pfad **unterhalb von `wp-content`** (POSIX-Slash). Beispiele: `uploads`, `plugins/meplugin`, `.` = gesamtes `wp-content`. Kein `..`, keine absoluten Pfade. |
| `max_depth` | integer | nein | Maximale **Verzeichnistiefe** relativ zum Startpfad: `0` = nur direkte Kinder des Startordners (kein Abstieg in Unterordner), `1` = eine Ebene tiefer, usw. Serverseitig nach oben begrenzt (siehe Antwort `max_depth_effective`). |

**Sicherheit:** Der aufgelöste Pfad wird per `realpath` gegen **`WP_CONTENT_DIR`** geprüft; alles außerhalb von `wp-content` wird abgelehnt.

**Antwort (JSON, Auszug):**

| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `success` | bool | `true` bei Erfolg |
| `wp_content` | string | Normalisierter Basis-Pfad (`WP_CONTENT_DIR`) |
| `path_requested` | string | Wie übergeben |
| `path_resolved` | string | Absoluter, geprüfter Scan-Start |
| `max_depth` | int | Angeforderter Wert |
| `max_depth_effective` | int | Nach Cap verwendeter Wert |
| `entry_count` | int | Anzahl Einträge in `entries` |
| `truncated` | bool | `true`, wenn die Eintrags-Obergrenze erreicht wurde |
| `total_bytes` | int | Summe der Dateigrößen (nur Dateien; Verzeichnisse zählen nicht) |
| `entries` | array | Liste von `{ "relative_path", "type": "file"\|"dir", "size": int\|null, "depth": int }` |

**Fehler:** HTTP 4xx/5xx mit `WP_Error`-Body (z. B. ungültiger Pfad, Ziel nicht lesbar).

**Hinweis:** Große Bäume können speicherintensiv sein; es gibt eine serverseitige Obergrenze für die Anzahl Einträge (Abbruch mit `truncated: true`).

---

## Weitere Routen

Datenbank-Routen: `includes/Api/Database.php`.  
Weitere Datei-Routen (Zip-Downloads usw.): `includes/Api/Files.php`.  
Überall dort, wo `Api::check_permission` genutzt wird: Migration Key per Header `X-WPBackup-Key` (oder Legacy `X-FlyWP-Key`) bzw. Query `secret`.

---

*Version der API-Inhalte richtet sich nach der Plugin-Version; bei Abweichungen gilt der Code in `includes/`.*
