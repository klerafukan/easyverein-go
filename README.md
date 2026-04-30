# Easyverein Go

Aktuelle Plugin-Version: **3.2.3**

Easyverein Go ist ein WordPress‑Plugin, das Daten aus EasyVerein lokal spiegelt und ein komfortables Frontend für eingeloggte Mitglieder bereitstellt. Die wichtigsten Funktionen im Überblick:

## Funktionsumfang

- **API‑Sync mit EasyVerein**  
  Lädt Mitglieder, Gruppen, Contact-Details, Custom-Field-Definitionen sowie alle Member→Gruppen- und Member→Custom-Field-Werte herunter und speichert sie lokal in eigenen Tabellen (`wp_evg_*`).  
  • Manueller Sync über die Admin-Oberfläche („Jetzt synchronisieren“ / „Nur 10 Mitglieder testen“) – der Quick-Test nutzt automatisch das Nightly-Präfix für gefahrloses Testen.  
  • Optionaler nächtlicher Vollsync via WP-Cron (ca. 03:00 Uhr, wenn in den Einstellungen aktiviert; schreibt standardmäßig in das Testpräfix `wp_evg_nightly_*`)  
  • **Nächtlichen Sync manuell simulieren** direkt über die Admin-Oberfläche – vollständiger Lauf in die Spiegel-Tabellen, mit eigenem Fortschrittsbalken und Log (sichere Vorschau ohne Auswirkung auf Produktivdaten)
  • Fehlertolerante API-Aufrufe mit Retry/Backoff, konfigurierbaren Limits und Logging (bei aktivem Debug)

- **Automatische API-Token-Erneuerung**  
  EasyVerein-API-Tokens sind 30 Tage gültig und sollten nach 15 Tagen erneuert werden. Das Plugin erkennt den `tokenRefreshNeeded`-Response-Header der API und ruft automatisch `GET /api/v2.0/refresh-token` auf, sobald ein Refresh fällig ist. Der neue Token wird sofort gespeichert und für alle folgenden Requests verwendet. Bei Erfolg oder Fehler wird eine kurze E-Mail an die konfigurierte Protokoll-Adresse gesendet. Im Admin-Bereich wird das Alter des Tokens farbcodiert angezeigt (grün / gelb / rot).

- **Frontend-Tabelle für Mitglieder**  
  Shortcode `[easyverein_table]` zeigt die synchronisierten Mitglieder als sortierbare, paginierte Tabelle (100 Einträge pro Seite) mit Live-Suche, Gruppenfilter und CSV-Export. Standardmäßig werden kompakte Spalten (Vorname, Nachname, Kontaktwege, Adresse, Gruppen) angezeigt; zusätzliche Felder können optional per Shortcode zugeschaltet werden.  
  Das Design orientiert sich an den WordPress-Akzentfarben, reagiert auf kleinere Viewports mit Kartenlayout und passt die Spaltenbreite automatisch an.
  Die Anzeige ist auf eingeloggte WordPress-Benutzer beschränkt und respektiert deren Gruppenfreigabe.

- **Benutzerbezogene Gruppenfreigabe**  
  Admins können im Benutzerprofil pro WordPress-User festlegen, ob alle Gruppen sichtbar sind oder nur eine manuell ausgewählte Teilmenge. Die Auswahl erfolgt in einer komfortablen Tabelle direkt im Profil; die Einstellungen wirken sich auf Frontend-Filter und Datenreduzierung aus.
- **Custom-Field-Filter pro Benutzer**  
  Zusätzlich zu Gruppen lassen sich nun individuelle Custom-Field-Werte je WordPress-Benutzer freischalten. Im Profil werden alle synchronisierten Feld/Wert-Kombinationen angeboten – die Auswahl bestimmt, welche Merkmalsfilter im Frontend sichtbar sind und welche Datensätze der Benutzer sehen darf.

- **Admin-Einstellungen**  
  Konfigurierbare API-Parameter (URL, Key, Endpoints) und Sync-Limits (Rate, Calls pro Tick, Seitenlimit).  
  Separate Tabellen-Präfixe für manuellen und nächtlichen Sync.  
  Empfängeradresse für das Nachtlauf-Protokoll frei wählbar (optional).  
  Debug-Modus schreibt API-Request-Protokolle unter `wp-content/easyverein-debug/`.

## Installation & Setup

1. Repository klonen oder in den WordPress-Plugin-Ordner (`wp-content/plugins/easyverein-go`) kopieren.  
2. Plugin im WordPress-Backend aktivieren.  
3. Unter **Einstellungen → Easyverein Go** die API-Zugangsdaten und optional weitere Parameter eintragen; anschließend speichern.  
4. Erstsync starten („Jetzt synchronisieren“) oder nächtlichen Sync aktivieren.  
5. Den Shortcode `[easyverein_table]` in eine Seite oder einen Beitrag einfügen (nur eingeloggte Nutzer sehen die Daten).

### Optional: Shortcode-Spalten anpassen

```
[easyverein_table columns="first_name,family_name,email_private,phone,groups"]
```

Erlaubte Spaltennamen entsprechen den Keys aus `EVG_Frontend::COLUMN_LABELS`.

## WP-Cron & CLI

- WP-Cron sorgt bei aktivierter Checkbox für den täglichen Sync.  
- Über **Einstellungen → Easyverein Go → Tabellen-Präfix (nächtlich)** lässt sich festlegen, ob der Cron-Lauf in das Testpräfix (`evg_nightly`) oder direkt in die produktiven Tabellen (`evg`) schreibt.
- Manueller Cron-Aufruf (z. B. per CLI): `wp cron event run evg_nightly_sync`  
- Geplante Events prüfen: `wp cron event list | grep evg_nightly_sync`

Der Button „Nur 10 Mitglieder testen“ in der Admin-Oberfläche legt einen kurzfristigen Lauf mit dem Nightly-Präfix an. So lassen sich neue Funktionen wie der Custom-Field-Import testen, ohne produktive Tabellen zu überschreiben.

## Entwicklung & Beiträge

- Git-Repository: `https://github.com/klerafukan/easyverein-go`  
- Standard-Branch: `main`  
- Pull Requests oder Issues sind willkommen.

### Build-/Test-Hinweise

Das Projekt benötigt keine Build-Schritte; PHP-Dateien folgen WordPress-Kompatibilitätsrichtlinien. Stelle sicher, dass nach Änderungen mindestens ein kompletter Sync durchläuft und das Frontend den erwarteten Datenstand zeigt.

## Lizenz

MIT-Lizenz (siehe `LICENSE`, falls vorhanden) oder ergänze die passende Lizenzinformation.
