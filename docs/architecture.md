# WPBackup Migrator – Architekturüberblick

Dieses Dokument beschreibt den **aktuellen Stand** des Plugins und soll als **Wiederaufsetzpunkt** für neue Features (z. B. weitere REST-Endpunkte) dienen. Ergänzend: [`rest-api.md`](rest-api.md) für die öffentlich beschriebene HTTP-Schnittstelle.

---

## Zweck

WordPress-Plugin für **Backup/Migration**: REST-API, Migration Key, optional größere Exporte über `Api\Database` und `Api\Files`. Ziel-Website: **wpbackup.org** / Migrationstools.

---

## Verzeichnisstruktur (relevant)

| Pfad | Rolle |
|------|--------|
| `wpbackup-migrator.php` | Einstieg, Composer-Autoload, `wpbackup_migrator()` |
| `includes/Plugin.php` | Singleton, Aktivierung, Option `wpbackup_migration_key`, REST-Registrierung |
| `includes/Api.php` | Namespace-Routen, `/verify`, `/info`, gemeinsame Payload-Logik, `media_size`, Plugin-/Theme-Listen |
| `includes/Api/Database.php` | DB-bezogene Routen (Migration Key i. d. R. `Api::check_permission`) |
| `includes/Api/Files.php` | Datei-bezogene Routen (u. a. Zip-Downloads, **`GET /filesystem-scan`**) |
| `includes/Admin.php` | Admin-UI, **doppelt Base64** kodierter Anzeige-Migration-Key |
| `democlient/callback.php` | Externes Demo: OAuth-ähnlicher Flow (Application Passwords), Probes `/wp-json/` + Plugin `/info` |
| `docs/rest-api.md` | Kurzbeschreibung der REST-Felder |
| `tools/ensure-plugin-active.php` | CLI: Plugin aktivieren, ggf. `active_plugins` nach Umbenennung |

---

## Bootstrap

- **`wpbackup_migrator()`** liefert `\Wpbackup\Migrator\Plugin::instance()`.
- **`Plugin::init_plugin()`** u. a. `rest_api_init` → `register_rest_routes()` → neue Instanz `Api()` und `register_routes()`.

---

## REST-API – Namespace

- **Namespace:** `wpbackup-migrator/v1` (Konstante `Api::NAMESPACE`).
- **Basis-URL:** `https://<site>/wp-json/wpbackup-migrator/v1/`

### Authentifizierungsmuster (wichtig für neue Endpunkte)

1. **`/info` (GET)**  
   - **Permission:** `current_user_can( 'manage_options' )`  
   - **Praxis:** **Application Password** + Basic Auth (oder eingeloggter Session-Cookie im Browser).  
   - Liefert `get_site_info_payload()` (u. a. `database_size`, `media_size`, `list_plugins`, `list_themes`).

2. **`/verify` (POST)**  
   - **Permission:** `__return_true` (öffentlich).  
   - Body: `key` = roher Migration Key (Option).  
   - Gleiche Payload wie `/info`, wenn Key stimmt.

3. **Database-/Files-Routen**  
   - **Permission:** typischerweise `Api::check_permission` – Migration Key per Header `X-WPBackup-Key` oder Legacy `X-FlyWP-Key`, alternativ Query `secret` (siehe Implementierung in `Api.php`).

Neue, **komplexe** Endpunkte sollten **explizit** dokumentiert werden: nur Admin? nur Migration Key? beides optional? Rate-Limits?

---

## Migration Key

- **Speicherung:** Option `wpbackup_migration_key` (siehe `Plugin::activate()` → `wp_generate_password( 32, false )` falls leer).
- **Admin-Anzeige:** in `Admin::get_migration_key()` **doppelt Base64** (Metadaten + Rohkey; siehe Code), nicht mit Zeitstempel verwechseln.
- **REST:** Rohkey für `/verify` und für geschützte Routen per Header.

---

## `Api.php` – Erweiterung für neue Routen

- **Registrierung:** in `Api::register_routes()` weitere `register_rest_route()`-Aufrufe **oder** Auslagerung in eine neue Klasse `Api\Something` mit `register_routes( $namespace )` analog zu `Database`/`Files`.
- **Payload-Duplikate vermeiden:** gemeinsame Arrays über private Hilfsmethoden (wie `get_site_info_payload()`).
- **Schwere Logik:** in eigene private Methoden oder eigene Klasse unter `includes/Api/`, damit `Api.php` dünn bleibt.

---

## Bereits implementierte Besonderheiten (`/info` / `/verify`)

- **`media_size`:** Schätzung aus DB (`_wp_attachment_metadata`), kein Filesystem-Scan; Details im Code und in `rest-api.md`.
- **`list_plugins` / `list_themes`:** aus WordPress-APIs (`get_plugins`, `get_mu_plugins`, `wp_get_themes`), sortiert nach Name.

---

## Democlient `callback.php` (kurz)

- Läuft u. a. unter **migrate.wpbackup.org**; `success_url` **muss https** sein (`demo_callback_self_url()`).
- Probe: **WordPress** `GET /wp-json/` (Index mit `namespaces`/`routes`), **Plugin** `GET …/v1/info` (401/403 = Route da).
- Kein separater „REST prüfen“-Button; GET-Parameter `wpbackup_demo_site` + JS-Debouncing.

---

## Was sollte bei neuen Endpunkten festgeschrieben werden?

Empfehlung – in **`docs/rest-api.md`** (und bei Bedarf hier in **architecture.md**):

- [ ] **Pfad, Methode** (GET/POST/…)
- [ ] **Auth** (Admin / Migration Key / beides / öffentlich)
- [ ] **Request-Body / Query / Header**
- [ ] **Response-Schema** (JSON-Felder, Fehlercodes)
- [ ] **Performance** (lange Laufzeit? Streaming? Hintergrundjob?)
- [ ] **Kompatibilität** (Multisite, PHP/WP-Mindestversion)
- [ ] **Versionierung** (neuer Pfad `v2` vs. Breaking Change in `v1`)

---

## Session vs. neue Session (für Entwickler)

- **Weiter in derselben Session:** sinnvoll, wenn der Kontext noch kurz ist und du direkt an denselben Code anschließt.
- **Neue Session:** sinnvoll bei **langem** Kontext oder wenn du mit **klarer Spezifikation** (dieses Dokument + Issue-Text) neu starten willst; Qualität leidet seltener unter „Kontextüberladung“.

Mit **`docs/architecture.md`** + **`docs/rest-api.md`** + ggf. **Ticket/Issue** mit Akzeptanzkriterien kann eine neue Session zuverlässig weitermachen.

---

*Stand: vom Plugin-Repo abgeleitet; bei Abweichungen gilt der Code in `includes/`.*
