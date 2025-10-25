# Easyverein Go

Easyverein Go ist ein WordPress‑Plugin, das Daten aus EasyVerein lokal spiegelt und ein komfortables Frontend für eingeloggte Mitglieder bereitstellt. Die wichtigsten Funktionen im Überblick:

## Funktionsumfang

- **API‑Sync mit EasyVerein**  
  Lädt Mitglieder, Gruppen, Contact-Details und Mitglied‑zu‑Gruppen-Zuordnungen herunter und speichert sie lokal in eigenen Tabellen (`wp_evg_*`).  
  • Manueller Sync über die Admin-Oberfläche („Jetzt synchronisieren“ / „Nur 10 Mitglieder testen“)  
  • Optionaler nächtlicher Vollsync via WP-Cron (ca. 03:00 Uhr, wenn in den Einstellungen aktiviert)  
  • Fehlertolerante API-Aufrufe mit Retry/Backoff, konfigurierbaren Limits und Logging (bei aktivem Debug)

- **Frontend-Tabelle für Mitglieder**  
  Shortcode `[easyverein_table]` zeigt die synchronisierten Mitglieder als sortierbare, paginierte Tabelle (100 Einträge pro Seite) mit Live-Suche, Gruppenfilter und CSV-Export. Spalten sind fest definiert (u. a. Name, Geburtsdatum, Jahrgang, Anrede, Telefonnummer, Adresse, Gruppen) und können optional per Shortcode überschrieben werden.  
  Die Anzeige ist auf eingeloggte WordPress-Benutzer beschränkt und respektiert deren Gruppenfreigabe.

- **Benutzerbezogene Gruppenfreigabe**  
  Admins können im Benutzerprofil pro WordPress-User festlegen, ob alle Gruppen sichtbar sind oder nur eine manuell ausgewählte Teilmenge. Die Auswahl erfolgt in einer komfortablen Tabelle direkt im Profil; die Einstellungen wirken sich auf Frontend-Filter und Datenreduzierung aus.

- **Admin-Einstellungen**  
  Konfigurierbare API-Parameter (URL, Key, Endpoints) und Sync-Limits (Rate, Calls pro Tick, Seitenlimit, „nur Mitglieder ohne Gruppen“).  
  Checkbox zum Aktivieren des automatischen nächtlichen Syncs.  
  Debug-Modus schreibt API-Request-Protokolle unter `wp-content/easyverein-debug/`.

## Installation & Setup

1. Repository klonen oder in den WordPress-Plugin-Ordner (`wp-content/plugins/easyverein-go`) kopieren.  
2. Plugin im WordPress-Backend aktivieren.  
3. Unter **Einstellungen → Easyverein Go** die API-Zugangsdaten und optional weitere Parameter eintragen; anschließend speichern.  
4. Erstsync starten („Jetzt synchronisieren“) oder nächtlichen Sync aktivieren.  
5. Den Shortcode `[easyverein_table]` in eine Seite oder einen Beitrag einfügen (nur eingeloggte Nutzer sehen die Daten).

### Optional: Shortcode-Spalten anpassen

```
[easyverein_table columns="full_name,email_private,phone,groups"]
```

Erlaubte Spaltennamen entsprechen den Keys aus `EVG_Frontend::COLUMN_LABELS`.

## WP-Cron & CLI

- WP-Cron sorgt bei aktivierter Checkbox für den täglichen Sync.  
- Manueller Cron-Aufruf (z. B. per CLI): `wp cron event run evg_nightly_sync`  
- Geplante Events prüfen: `wp cron event list | grep evg_nightly_sync`

## Entwicklung & Beiträge

- Git-Repository: `https://github.com/klerafukan/easyverein-go`  
- Standard-Branch: `main`  
- Pull Requests oder Issues sind willkommen.

### Build-/Test-Hinweise

Das Projekt benötigt keine Build-Schritte; PHP-Dateien folgen WordPress-Kompatibilitätsrichtlinien. Stelle sicher, dass nach Änderungen mindestens ein kompletter Sync durchläuft und das Frontend den erwarteten Datenstand zeigt.

## Lizenz

MIT-Lizenz (siehe `LICENSE`, falls vorhanden) oder ergänze die passende Lizenzinformation.

