# Gruppenanträge aus der App

Unter **Easyverein Go → Gruppenanträge** legt ein WordPress-Administrator fest,
welche aus EasyVerein synchronisierten Gruppen beantragbar sind, welche Benutzer
sie freigeben dürfen und ob Gruppenzahlungen aktiviert werden sollen. Standardmäßig
ist keine Gruppe freigegeben. WordPress-Administratoren dürfen alle fremden
Anträge bearbeiten; weitere Freigeber nur die jeweils zugewiesenen Gruppen.
Eigene Anträge können nicht selbst genehmigt werden.

Die Seite öffnet zunächst den Reiter **Anträge** mit den offenen Anträgen.
Administratoren sehen Mitglied, Gruppe, Nachricht, Zahlungsbestätigung und Status
und können dort **Genehmigen** oder mit Begründung **Ablehnen**. Statusfilter,
Freitextsuche und Seiten von zwanzig Anträgen erschließen auch die Historie mit
Bearbeiter und Entscheidungsbegründung. Ein unbestätigter Schreibversuch bietet
stattdessen **Mitgliedschaft prüfen**. Die Konfiguration liegt im separaten Reiter
**Einstellungen**. Die WordPress-Freigabe benötigt keine Firebase-Einrichtung.

Das Vereinsmitglied wird aus `evg_member_id` des angemeldeten WP-Kontos bestimmt.
Ein Antrag erzeugt zunächst nur einen Datensatz. Bei Genehmigung wird die
Zuordnung über EasyVerein v3.0 `member-group-assignment` geprüft, gegebenenfalls
angelegt und erneut gelesen. Erst die bestätigte Mitgliedschaft schließt den Antrag
als genehmigt ab. Verlorene Antworten werden durch erneutes Prüfen aufgelöst.
Nach einem unbestätigten Schreibversuch verhindert `processing` ein widersprüchliches
Ablehnen/Zurückziehen. Eine erneute Genehmigungsaktion prüft den Zustand idempotent.

Eine Gruppenmitgliedschaft gewährt **keine** App-Leserechte: `evg_groups` und
`evg_groups_all` der WP-Konten werden nicht verändert. Gruppenzahlungen müssen beim
Antrag bestätigt werden; eine zwischenzeitlich geänderte Zahlungsregel verhindert
die Genehmigung des alten Antrags.

## Schema und API

Die Tabellen werden bei Aktivierung bzw. beim ersten `init` angelegt:

- `evg_group_join_requests`: Antrag, Mitglied, Gruppe, Zahlungsbestätigung,
  Bearbeitungsstatus und Entscheidung; pro Mitglied/Gruppe nur ein aktiver Antrag.
- `evg_app_devices`: benutzergebundener Expo-Token und gehashtes Widerrufsgeheimnis.
- `evg_app_push_jobs`: dauerhafte Versandaufträge, Retry-Status und Expo-Receipts.

Alle Tabellennamen verwenden das aktuelle WordPress-Präfix.
Basis: `/wp-json/easyverein-go/v1`.

| Methode / Pfad | Zweck |
|---|---|
| GET `/membership/groups` | Beantragbare Gruppen, bestehende Mitgliedschaften und Rolle |
| GET `/membership/requests?scope=mine` | Eigene Anträge |
| GET `/membership/requests?scope=approvals` | Zuständige offene Freigaben, ohne eigene Anträge |
| POST `/membership/requests` | `group_id`, optional `note`, ggf. `acknowledge_payments: true` |
| POST `/membership/requests/<id>/decision` | `action: approve` oder `reject`, bei Ablehnung `note` |
| DELETE `/membership/requests/<id>` | Eigenen offenen Antrag zurückziehen |
| POST `/push-devices` | Authentifizierte Geräteanmeldung |
| DELETE `/push-devices/<installation_id>` | Widerruf mit installationsgebundenem `secret`, auch ohne Login |

Antragslisten liefern `items` und `total`, Seiten von 50 Einträgen, Fortsetzung mit
`offset`. `/me` liefert `group_requests_available`, `can_approve_group_requests`
und `pending_group_requests_count` für die App-Navigation.

## Push-Betrieb

Expo-Push-Tokens werden durch die App nach ausdrücklicher Aktivierung angemeldet.
Neue Anträge benachrichtigen die zuständigen Freigeber und Administratoren;
Entscheidungen benachrichtigen den Antragsteller. Push-Texte enthalten keine
Mitgliedsnamen oder Freitexte. Die Nutzdaten verweisen auf die Antragsansicht.
Die App prüft das zugehörige Konto vor Anzeige und Navigation.

Der Worker `evg_app_push_worker` läuft über WP-Cron. Bei `DISABLE_WP_CRON` ist ein
regelmäßig laufender Server-Cron erforderlich. Netzfehler werden begrenzt erneut
versucht, erfolgreiche Tickets werden nach 15 Minuten auf Receipts geprüft.
`DeviceNotRegistered` entfernt das Gerät. Ungültige FCM-Zugangsdaten markieren den
Auftrag als fehlgeschlagen; den Fehlerstatus zeigt die Admin-Seite.
Versand setzt weiterhin bestehende Freigaberechte voraus. Nach Rechteentzug oder
Gerätewiderruf werden wartende Benachrichtigungen nicht mehr versendet.

Android benötigt Firebase-Konfiguration im nativen Build und FCM-v1-Zugangsdaten
in EAS, iOS entsprechende APNs-Zugangsdaten. Einrichtung siehe App-Repository,
`docs/GROUP_MEMBERSHIP.md`. Optionalen Expo Access Token unter Gruppenanträge
hinterlegen oder `EVG_EXPO_ACCESS_TOKEN` in der Serverkonfiguration setzen.
Keine Service-Account-Dateien im Plugin speichern.

## Tests

```sh
bash tests/run-group-staging.sh
```

Der Runner verwendet SSH-Alias `tvm-live`, WordPress unter `public_html/test` und
`php83`; `EVG_SSH_HOST` und `EVG_PHP` sind überschreibbar. Er verweigert einen
Live-Pfad. Die Tests erstellen temporäre Tabellen und Testkonten und entfernen
sie anschließend. Externe HTTP-Aufrufe und E-Mails werden abgefangen; echte
Mitgliedschaften oder Benachrichtigungen werden nicht erzeugt.

Abgedeckt sind Authentifizierung, Rollen, Selbstgenehmigung, Dubletten,
Zahlungsbestätigung, Entscheidung, API-Ausfälle, verlorene Antworten, Wiederholung,
Geräteübernahme, Widerruf nach Login-Ablauf, Push-Retry und ungültige Tokens.
Die Admin-Prüfungen decken zusätzlich CSRF-Schutz, Administratorrechte,
Textausgabe ohne HTML-Ausführung, Suche, Statusfilter und Paginierung ab.
Die reale Push-Zustellung muss nach Einrichtung der nativen Zugangsdaten mit zwei
Testkonten separat abgenommen werden.

Android-Transporttest am 1. November 2026 erfolgreich auf Staging abgenommen:
Geräteregistrierung durch die App, Versand über die WordPress-Warteschlange an
Expo/FCM, sichtbarer Empfang im Android-Emulator bei App im Hintergrund,
Navigation zum Testantrag beim Antippen und erfolgreiches Expo-Receipt. Auch der
Widerruf der Geräteanmeldung wurde bestätigt. Dafür wurde ausschließlich ein
temporärer Antragsdatensatz verwendet; es erfolgte kein EasyVerein-Schreibzugriff.
Der Testdatensatz und der zugehörige Versandauftrag wurden danach entfernt.
