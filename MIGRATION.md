# Migration: staging.tv-miesbach.de → www.tv-miesbach.de

Dieses Dokument beschreibt alle Schritte, um die Staging-Instanz produktiv zu schalten
und mit Inhalten der alten WordPress-Installation zusammenzuführen.

---

## Übersicht

| | Staging (Quelle) | Live (Ziel) |
|---|---|---|
| URL | `https://staging.tv-miesbach.de` | `https://www.tv-miesbach.de` |
| Server | `www435.your-server.de`, Port 222 | gleicher Server, anderer vHost |
| WP-Pfad | `~/public_html/staging/` | `~/public_html/` (oder ähnlich) |
| Plugin | Easyverein Go v3.x | wird übernommen |

---

## Phase 1 – Vorbereitung (vor dem Cut-over)

### 1.1 Alte WordPress-Installation sichern

```bash
# Auf dem Server: Datenbank-Export der alten Live-Instanz
ssh -p 222 tvwmie@www435.your-server.de
cd ~/public_html
wp db export ~/backup-alt-$(date +%Y%m%d).sql --allow-root

# Dateien sichern (Uploads, Theme, Plugins)
tar czf ~/backup-alt-files-$(date +%Y%m%d).tar.gz wp-content/uploads/ wp-content/themes/
```

### 1.2 Staging-Datenbank sichern

```bash
cd ~/public_html/staging
wp db export ~/backup-staging-$(date +%Y%m%d).sql --allow-root
```

### 1.3 Inhalt-Inventar der alten Installation

Folgende Inhalte aus der alten Installation prüfen und ggf. migrieren:

- [ ] Seiten (Pages) – insbesondere: Impressum, Datenschutz, Kontakt, Über uns
- [ ] Beiträge (Posts) / News
- [ ] Medien (Bilder, PDFs) in `wp-content/uploads/`
- [ ] Menüs und Widget-Einstellungen
- [ ] Theme und Theme-Einstellungen (Customizer)
- [ ] Formulare (z.B. Contact Form 7, Gravity Forms)
- [ ] SEO-Metadaten (Yoast, RankMath o.ä.)
- [ ] Weiterleitungen (Redirection-Plugin o.ä.)

---

## Phase 2 – Inhaltsmigration

### 2.1 Uploads übertragen

```bash
# Uploads von alter Installation in Staging kopieren
rsync -av ~/public_html/wp-content/uploads/ \
          ~/public_html/staging/wp-content/uploads/
```

### 2.2 Seiten und Beiträge migrieren

**Option A – WP-eigener Exporter** (empfohlen, kein Plugin nötig):

1. Alte Installation: WP-Admin → Werkzeuge → Exportieren → „Alle Inhalte" → XML herunterladen
2. Staging: WP-Admin → Werkzeuge → Importieren → WordPress → XML hochladen
3. Option „Medien herunterladen" aktivieren (falls Uploads noch nicht kopiert)

**Option B – Tabellen direkt kopieren** (nur wenn Themes/Plugins identisch):

```sql
-- In der Staging-DB: Beiträge aus alter DB importieren
-- Achtung: Präfixe können abweichen (wp_ vs. wpXYZ_)
INSERT INTO staging_wp_posts SELECT * FROM alt_wp_posts WHERE post_type IN ('page','post');
```

### 2.3 URL-Suche-und-Ersetzen in der Datenbank

Nach der Inhaltsmigration müssen alle Referenzen auf die alte Domain ersetzt werden.
**WP-CLI** ist hierfür das sicherste Werkzeug (behandelt serialisierte Daten korrekt):

```bash
cd ~/public_html/staging

# Vorschau (--dry-run) – zeigt Anzahl der Treffer
wp search-replace 'http://alte-domain.de' 'https://staging.tv-miesbach.de' --dry-run

# Tatsächlich ersetzen
wp search-replace 'http://alte-domain.de'  'https://staging.tv-miesbach.de' --all-tables
wp search-replace 'https://alte-domain.de' 'https://staging.tv-miesbach.de' --all-tables
```

> **Hinweis:** Wird die Staging-URL `staging.tv-miesbach.de` direkt zu `www.tv-miesbach.de`
> umgezogen (kein Zwischenschritt), kann dieser Schritt direkt mit der Live-URL
> in Phase 3.3 kombiniert werden.

---

## Phase 3 – Cut-over (Go-Live)

### 3.1 Wartungsmodus aktivieren

```bash
# Staging (wird Live) in Wartungsmodus setzen
cd ~/public_html/staging
wp maintenance-mode activate --allow-root
```

### 3.2 Letzten EasyVerein-Sync ausführen

Im WP-Admin der Staging-Instanz:

1. Easyverein Go → „Jetzt synchronisieren" → abwarten bis fertig
2. „Nightly → Live übernehmen" → Delta prüfen → Bestätigen
3. „WP-Benutzer jetzt synchronisieren" → Testlauf → dann echter Lauf

### 3.3 URL in der Datenbank auf Live-Domain umstellen

```bash
cd ~/public_html/staging

wp search-replace 'https://staging.tv-miesbach.de' 'https://www.tv-miesbach.de' --all-tables
wp search-replace 'http://staging.tv-miesbach.de'  'https://www.tv-miesbach.de' --all-tables
```

### 3.4 wp-config.php anpassen

```php
// In wp-config.php der Live-Instanz sicherstellen:
define('WP_HOME',    'https://www.tv-miesbach.de');
define('WP_SITEURL', 'https://www.tv-miesbach.de');
```

### 3.5 OIDC Callback-URL in EasyVerein aktualisieren

Im EasyVerein-Backend die Redirect URI des Identity Providers ändern:

- **Alt:** `https://staging.tv-miesbach.de/wp-json/easyverein-go/v1/oidc/callback`
- **Neu:** `https://www.tv-miesbach.de/wp-json/easyverein-go/v1/oidc/callback`

Anschließend im WP-Admin → Easyverein Go → „Callback-URL"-Feld leeren
(damit der automatisch generierte Wert mit der neuen Domain greift).

### 3.6 OIDC Callback-URL in der mobilen App aktualisieren

In [src/config.ts](../TV_Miesbach_APP/src/config.ts):

```ts
// Alle Vorkommen von staging.tv-miesbach.de ersetzen:
export const WP_BASE_URL = 'https://www.tv-miesbach.de';
```

Danach neuen EAS-Build erstellen und im Play Store veröffentlichen.

### 3.7 DNS / vHost umstellen

- DNS-A-Record von `www.tv-miesbach.de` auf neuen vHost zeigen lassen
- SSL-Zertifikat für `www.tv-miesbach.de` erneuern/ausstellen (Let's Encrypt)
- Alten vHost auf 301-Weiterleitung zu `https://www.tv-miesbach.de` setzen

### 3.8 Wartungsmodus deaktivieren

```bash
wp maintenance-mode deactivate --allow-root
```

---

## Phase 4 – Nach dem Go-Live

### 4.1 Funktionstest-Checkliste

- [ ] WP-Frontend unter `https://www.tv-miesbach.de` erreichbar
- [ ] WP-Admin-Login per EasyVerein OIDC funktioniert
- [ ] Mitglieder-App: Login mit neuem Endpoint
- [ ] Bilder/Medien werden korrekt geladen (keine Broken Links)
- [ ] Alle internen Links zeigen auf `www.tv-miesbach.de`
- [ ] Nightly Cron läuft (E-Mail-Report prüfen)
- [ ] REST-API erreichbar: `https://www.tv-miesbach.de/wp-json/easyverein-go/v1/members`

### 4.2 Weiterleitungen einrichten

Für SEO und bestehende Links 301-Weiterleitungen im Redirection-Plugin oder `.htaccess` anlegen:

```apache
# .htaccess (alter Domain-vHost)
RewriteEngine On
RewriteRule ^(.*)$ https://www.tv-miesbach.de/$1 [R=301,L]
```

### 4.3 Google Search Console

- Neue Property `https://www.tv-miesbach.de` verifizieren
- Sitemap einreichen: `https://www.tv-miesbach.de/sitemap_index.xml`
- Adressänderung aus alter Property melden (falls vorhanden)

### 4.4 Backup-Strategie aktivieren

- Automatisches tägliches Backup der Live-DB einrichten (z.B. UpdraftPlus oder Cron-Script)
- Backup-Ziel: externer Speicher (nicht nur lokaler Server)

---

## Rollback-Plan

Falls nach dem Cut-over kritische Fehler auftreten:

```bash
# 1. DNS zurückstellen (auf alten vHost zeigen)
# 2. Staging-DB aus Backup wiederherstellen
cd ~/public_html/staging
wp db import ~/backup-staging-YYYYMMDD.sql --allow-root

# 3. wp-config.php zurücksetzen
# 4. Wartungsmodus deaktivieren
wp maintenance-mode deactivate --allow-root
```

---

## Offene Punkte / Entscheidungen

| Punkt | Entscheidung ausstehend |
|---|---|
| Welche Seiten aus der alten Installation übernehmen? | |
| Theme der alten vs. neuen Installation | |
| Formulare / Buchungssystem übernehmen? | |
| Newsletter-Liste migrieren? | |
| Staging-Subdomain nach Go-Live weiter nutzen? | |
