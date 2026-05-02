# Easyverein Go

Easyverein Go ist ein WordPress-Plugin, das Mitglieder-, Gruppen-, Contact-Details- und Custom-Field-Daten aus der EasyVerein-API (v3.0) lokal in eigene Datenbanktabellen (`wp_evg_*`) spiegelt. Eingeloggte WordPress-Benutzer erhalten über einen Shortcode eine filterbare, sortierbare und exportierbare Mitgliedertabelle im Frontend. Der Admin-Bereich bietet vollständige Sync-Steuerung mit Fortschrittsbalken, Stop-Button und integriertem Log-Viewer.

---

## Voraussetzungen

| Komponente | Mindestversion |
|---|---|
| WordPress | 5.8 |
| PHP | 7.4 |
| EasyVerein | Account mit API-Key (v3.0) |

---

## Funktionsumfang

### API-Sync mit EasyVerein

Alle Stamm- und Zuordnungsdaten werden in sechs aufeinanderfolgenden Phasen heruntergeladen und lokal gespeichert:

1. `groups` – Gruppen-Definitionen
2. `custom_fields` – Custom-Field-Definitionen
3. `members_list` – Mitgliederliste (Basisdaten)
4. `details` – Detaildaten pro Mitglied (Contact-Details, Adresse usw.)
5. `member_cf` – Custom-Field-Werte pro Mitglied
6. `member_groups` – Gruppen-Zuordnungen pro Mitglied

API-Aufrufe erfolgen **per Member** (kein Bulk), um HTTP-429-Rate-Limiting zu vermeiden. Fehlertolerante Aufrufe mit automatischem Retry/Backoff sind eingebaut. Die WP-Option `evg_last_sync_completed` wird nach jedem abgeschlossenen Lauf aktualisiert.

**Sync-Modi im Admin:**

- **Manueller Sync** – startet einen vollständigen Lauf mit Fortschrittsbalken und Tick-by-Tick-Log; jederzeit per Stop-Button abbrechbar
- **Quick-10-Test** – verarbeitet nur 10 Mitglieder, schreibt in das Nightly-Präfix, ohne Produktivdaten zu berühren
- **Nächtlicher Vollsync** – täglich via WP-Cron (ca. 03:00 Uhr, per Checkbox aktivierbar)
- **Nightly-Sync manuell simulieren** – vollständiger Lauf in die Spiegel-Tabellen direkt aus dem Admin heraus

### Automatische API-Token-Erneuerung

EasyVerein-API-Tokens sind 30 Tage gültig. Das Plugin erkennt den `tokenRefreshNeeded`-Response-Header und ruft automatisch `GET /api/v3.0/refresh-token` auf. Der erneuerte Token wird sofort gespeichert. Bei Erfolg und bei Fehler wird eine Benachrichtigungs-E-Mail an die hinterlegte Adresse gesendet. Im Admin-Bereich wird das Token-Alter farbcodiert angezeigt (grün / gelb / rot).

### Frontend-Tabelle für Mitglieder

Der Shortcode `[easyverein_table]` rendert synchronisierte Mitglieder als sortierbare, paginierte Tabelle (100 Einträge/Seite) mit:

- **Live-Suche** über alle sichtbaren Spalten
- **Gruppenfilter** – gefiltert nach der benutzerbezogenen Gruppenfreigabe
- **Custom-Field-Filter** – nur freigeschaltete Feld/Wert-Kombinationen werden angeboten
- **CSV-Export** des aktuell gefilterten Ergebnisses
- **Datenstand** – letzter Sync-Zeitstempel in Berliner Zeit, über der Tabelle angezeigt
- Responsives Kartenlayout auf kleinen Viewports
- Sichtbarkeit beschränkt auf eingeloggte WordPress-Benutzer

### Benutzerbezogene Gruppenfreigabe

Admins legen im WordPress-Benutzerprofil fest, ob ein User alle EasyVerein-Gruppen oder nur eine definierte Teilmenge sieht. Die Einstellung wirkt sich auf den Gruppenfilter und die angezeigte Datenmenge im Frontend aus.

### Custom-Field-Filter pro Benutzer

Im WordPress-Benutzerprofil lassen sich alle synchronisierten Feld/Wert-Kombinationen freischalten. Die Auswahl bestimmt, welche Merkmalsfilter im Frontend für diesen User sichtbar und aktiv sind.

### Admin-Oberfläche

- Konfigurierbare API-Parameter (URL, Key) und Sync-Limits (Rate, Calls pro Tick, Seitenlimit)
- Separate Tabellen-Präfixe für manuellen und nächtlichen Sync
- Optionale E-Mail-Adresse für das Nachtlauf-Protokoll
- Debug-Modus schreibt API-Protokolle nach `wp-content/easyverein-debug/`
- **Nightly-Log-Viewer** direkt im Admin (liest `nightly-YYYYMMDD-HHMMSS.log`-Dateien)
- **Versionsnummer** als fester Eintrag in der WordPress-Admin-Bar (`EVG X.X.X`)

---

## Installation & Setup

1. Plugin-Ordner nach `wp-content/plugins/easyverein-go` kopieren oder das Repository dort klonen.
2. Plugin im WordPress-Backend unter **Plugins** aktivieren. Das Datenbankschema (`wp_evg_*`-Tabellen) wird automatisch via `dbDelta()` angelegt.
3. Unter **Einstellungen → Easyverein Go** den EasyVerein-API-Key sowie optional weitere Parameter (Rate-Limit, Seitenlimit, Tabellen-Präfixe, Log-E-Mail) eintragen und speichern.
4. Erstsync über **„Jetzt synchronisieren"** starten oder den nächtlichen Sync per Checkbox aktivieren.
5. Shortcode `[easyverein_table]` in eine Seite oder einen Beitrag einfügen – nur eingeloggte Nutzer sehen die Tabelle.
6. Optional: Im jeweiligen WordPress-Benutzerprofil Gruppenfreigabe und Custom-Field-Filter konfigurieren.

---

## Shortcode-Referenz

```
[easyverein_table]
[easyverein_table columns="first_name,family_name,email_private,phone,groups"]
```

### Parameter

| Parameter | Typ | Standard | Beschreibung |
|---|---|---|---|
| `columns` | string | *(Standardspalten aus `EVG_Frontend::DEFAULT_COLUMNS`)* | Kommagetrennte Liste der anzuzeigenden Spalten-Keys |

### Verfügbare Spalten-Keys

| Key | Bezeichnung |
|---|---|
| `full_name` | Name (Vor- und Nachname kombiniert) |
| `first_name` | Vorname |
| `family_name` | Nachname |
| `email_private` | E-Mail-Adresse |
| `date_of_birth` | Geburtsdatum |
| `age` | Alter |
| `birth_year` | Jahrgang |
| `gender` | Geschlecht |
| `phone` | Telefon |
| `zip` | Postleitzahl |
| `city` | Ort |
| `street` | Straße |
| `address_suffix` | Adresszusatz |
| `group_name` | Gruppe (erste/primäre Gruppe) |
| `groups` | Alle Gruppen (kommasepariert) |
| `custom_fields` | Merkmale (Custom Fields) |
| `member_number` | Mitgliedsnummer |
| `contact_details` | Kontakt-Details |
| `updated_at` | Stand (letztes Datenbank-Update) |

Wird `columns` weggelassen, werden die in `EVG_Frontend::DEFAULT_COLUMNS` hinterlegten Standardspalten verwendet.

---

## WP-Cron & CLI

Der nächtliche Sync läuft als täglich geplantes WP-Cron-Event (`evg_nightly_sync`) um ca. 03:00 Uhr, sofern in den Einstellungen aktiviert.

**WP-CLI-Befehle:**

```bash
# Nightly-Sync sofort manuell auslösen
wp cron event run evg_nightly_sync

# Geplante Events prüfen
wp cron event list | grep evg_nightly_sync
```

Über **Einstellungen → Easyverein Go → Tabellen-Präfix (nächtlich)** lässt sich steuern, ob der Cron-Lauf in Spiegel-Tabellen (`evg_nightly_*`) oder direkt in die produktiven Tabellen (`evg_*`) schreibt.

---

## Deployment (GitHub Actions)

Bei jedem Push auf `main` läuft der CI/CD-Workflow automatisch in zwei Schritten:

1. **Version-Bump** – Die Patch-Version in `easyverein-go.php` und `README.md` wird inkrementiert und als Commit zurück in den Branch geschrieben.
2. **rsync-Deploy** – Plugin-Dateien werden per SSH auf den Staging-Server übertragen.

Folgende GitHub-Secrets müssen im Repository hinterlegt sein:

| Secret | Beschreibung |
|---|---|
| `SSH_KEY` | Privater SSH-Schlüssel für den Deploy-User |
| `SSH_HOST` | Hostname / IP des Zielservers |
| `SSH_USER` | SSH-Benutzername |
| `SSH_PORT` | SSH-Port (Standard: 22) |
| `REMOTE_PATH` | Absoluter Pfad zum Plugin-Verzeichnis auf dem Server |

---

## Entwicklung

- **Kein Build-Schritt erforderlich** – alle Dateien sind reines PHP/JS/CSS nach WordPress-Coding-Standards.
- Einstiegspunkt ist `easyverein-go.php`; Klassen liegen unter `includes/`.
- Das Datenbankschema wird beim Plugin-Aktivieren via `dbDelta()` angelegt und bei Updates automatisch migriert.
- Debug-Logs werden ausschließlich bei aktiviertem Debug-Modus unter `wp-content/easyverein-debug/` geschrieben – **niemals dauerhaft in der Produktion aktivieren**.
- Nach Änderungen an der Sync-Logik stets einen vollständigen Lauf (inkl. Custom-Fields) durchführen und den Datenstand im Frontend prüfen.
- Git-Repository: `https://github.com/klerafukan/easyverein-go` – Haupt-Branch: `main`

---

Plugin-Version: **3.2.17**
