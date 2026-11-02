# Termine aus der App anlegen

Die App legt Einzeltermine über WordPress an. EasyVerein bleibt führend:

1. `/event-creation-options` liefert die aktuell erlaubten Kalender, Vereinszeitzone und Datumsgrenze.
2. `POST /event-creations` validiert Daten und Rechte und speichert vor dem externen Aufruf einen Vorgang in `evg_event_writes`.
3. Der Server sendet einmalig `POST /api/v3.0/event`, liest den neuen Datensatz erneut und übernimmt erst diesen kanonischen Datensatz in `evg_events`.
4. App und Website lesen denselben Cache. Änderungen und Löschungen in EasyVerein werden weiterhin beim regelmäßigen vollständigen Terminabgleich übernommen.

Die App unterstützt Titel, Kalender, Beginn/Ende, Ganztag, Ortsname und Beschreibung. Sie übermittelt Vereins-Ortszeit; der Server ergänzt den korrekten Zeitzonenoffset. Bei Ganztag ist der letzte gewählte Tag eingeschlossen, das übertragene Ende ist die nächste Tagesgrenze. Die erste Ausbaustufe umfasst keine Serien oder nachträgliche Bearbeitung aus der App. Neue Termine sind nicht öffentlich, keine Raumreservierungen und öffnen noch keine Teilnahmeverwaltung.

## Rechte

Administratoren dürfen in allen synchronisierten Kalendern Termine anlegen. Andere Personen benötigen `evg_is_uebungsleiter`, ein verknüpftes Vereinsmitglied und eine ausdrückliche Trainingsgruppen-Kalender-Zuordnung im Plugin. Die Gruppenauswahl entspricht der Stundenerfassung: tatsächliche Mitgliedschaften plus explizit am WordPress-Konto zugeordnete Gruppen. Reine Mitgliedschaft und Mitgliederdaten-Leserechte allein reichen nicht aus.

Die Zuordnung wird unter **Easyverein Go → Trainingskalender** gepflegt. Jede vorhandene EasyVerein-Gruppe kann mehrere vorhandene Kalender erhalten; ein Kalender kann mehreren Gruppen zugeordnet werden. Die Option `evg_training_calendar_map` speichert Gruppen-IDs mit Kalender-IDs. Gruppen und Kalender bleiben EasyVerein-Stammdaten, Termine bleiben ausschließlich in EasyVerein führend. Die App erhält weiterhin dieselbe Liste erlaubter Kalender über `/event-creation-options`; `/me` meldet `can_create_events` entsprechend.

`allowed_groups` eines Kalenders regelt nur die Sichtbarkeit. Die Zuordnung erteilt zusätzliche Trainer-Schreibrechte, öffnet jedoch keinen in EasyVerein für die Person gesperrten Kalender. Auch Kalender ohne Sichtbarkeitsbeschränkung können ausdrücklich zugeordnet werden. Ohne Zuordnung gibt es für Trainer keine Schreibziele; bestehende Sichtbarkeitsfreigaben werden nicht automatisch migriert. Der konfigurierte Synchronisations-Kalenderfilter gilt zusätzlich. Rechte, vorhandene Gruppen/Kalender und Zuordnungen werden vor jedem neuen Schreibzugriff erneut geprüft.

Die Admin-Seite bietet Gruppen- und Kalendersuche, Mehrfachauswahl und entfernbare Auswahl-Chips. Suchfilter ändern keine gespeicherten oder im Formular gewählten Zuordnungen. Erst **Zuordnungen speichern** schreibt die Konfiguration; Administratorrecht und gültiger Nonce sind erforderlich. Gelöschte Gruppen/Kalender werden auch bei alten gespeicherten Zuordnungen nicht als Rechtequelle verwendet. Der normale EasyVerein-Sync überschreibt die Zuordnung nicht.


## Wiederaufnahme und Synchronisation

Jeder Vorgang hat eine UUID v4. App und Server speichern sie vor der Übertragung. Die interne EasyVerein-Notiz erhält `EVG-App:<UUID>`. Das Plugin führt `prepared`, `sending`, `uncertain`, `confirmed` und `rejected` als Zustände. Mehrfaches Absenden oder erneutes Öffnen verwendet dieselbe Kennung. Eine zweite Kennung wird blockiert, solange für das Konto eine frühere Speicherung offen ist.

Nach verlorenem POST-Ergebnis wird niemals blind erneut gepostet. Ein bekanntes Event wird per ID nachgelesen; andernfalls wird anhand von Kalender, Beginn und exakter interner Kennung gesucht. Zusätzlich kann der normale Vollsync offene Vorgänge anhand der Kennung bestätigen. Eine nicht auffindbare Speicherung bleibt offen und muss gegebenenfalls nach Prüfung in EasyVerein administrativ geklärt werden. Unbekannt bedeutet weder erfolgreich noch fehlgeschlagen.

`GET /event-creations` und `/event-creations/<UUID>` liefern nur eigene Vorgänge. Die App stellt offene Vorgänge auch nach Abmeldung bzw. Verlust des lokalen Caches aus dem Backend wieder her. Sie zeigt bis zu fünf zuletzt erfasste Vorgänge an. Anlagestatus und Cache-Abgleich sind getrennt: Ein fehlgeschlagener Detailabruf nach bestätigter Anlage löst keinen weiteren POST aus.

Vollsync und Terminanlage verwenden dieselbe verbindungsgebundene MySQL-Sperre. So kann ein mit altem Stand gestarteter Vollsync einen gerade angelegten Termin nicht wieder aus dem Cache löschen. Der Vollsync und das Rücklesen nutzen dieselbe Normalisierung (`store_event`).

## Prüfung und Betrieb

`bash tests/run-event-staging.sh` führt isolierte WordPress-Tests mit temporären Tabellen und Konten aus. Zuordnungsänderungen bleiben über WordPress-Optionsfilter ausschließlich im Testprozess; echte Staging-Zuordnungen werden nicht verändert. Alle HTTP- und Mail-Aufrufe werden abgefangen; es werden keine echten EasyVerein-Termine angelegt. Der Runner überspringt für diesen CLI-Prozess Plugins/Themes und lädt die geprüften Quelldateien direkt. Abgedeckt sind Admin-Speicherung, Nonce, explizite Zuordnungen, Rechteentzug, gelöschte Stammdaten, Sichtbarkeit, Synchronisationsfilter, Datumsvalidierung, kanonisches Rücklesen, Vollsync-Rückweg, Antwortverlust, Wiederaufnahme und konkurrierende Datenbanksperren.

API-Vertrag: [offizielle EasyVerein-v3-Dokumentation](https://easyverein.com/api/v3.0/documentation). Die Kalender-Relation ist in der Swagger-POST-Feldliste unvollständig dokumentiert; eine lesende `OPTIONS /api/v3.0/event`-Abfrage mit der Live-Konfiguration hat sie am 2. November 2026 als beschreibbar bestätigt. Staging lieferte bei dieser echten API-Abfrage HTTP 401; die isolierten Tests hängen nicht von diesem Zugang ab. Ein echter Anlage-/Löschtest in EasyVerein ist dadurch nicht ersetzt.
