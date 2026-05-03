# Plan: Termine & Kalender in der TV-Miesbach-App

> Erstellt: Mai 2026  
> Status: **Entwurf – zur Abstimmung**  
> Umfang: WP-Plugin `easyverein-go` + React-Native-App

---

## 1. Zielbild

Die App soll alle Vereinstermine aus EasyVerein lesend darstellen:

- **Listenansicht** – chronologisch, filterbar nach Kalender/Monat
- **Monatsansicht** – klassischer Kalender-Grid mit Punkt-Indikatoren
- **Wochenansicht** – Zeitraster mit Tages-Spalten
- **Detailansicht** – Titel, Ort, Beschreibung, Teilnehmerinfo, Preisgruppen
- **Heute-Widget / Home-Banner** – nächster anstehender Termin auf dem HomeScreen

Perspektivisch (spätere Phase): Terminanmeldung (Participation) direkt aus der App.

---

## 2. Analyse der EasyVerein API

### 2.1 Verfügbare Endpoints (v2.0/stable)

| Endpoint | Methoden | Beschreibung |
|----------|----------|--------------|
| `GET /api/v2.0/event` | R | Terminliste mit umfangreichen Filtern |
| `GET /api/v2.0/event/{pk}` | R | Einzeltermin |
| `GET /api/v2.0/event/{pk}/participation` | R/W | Teilnahmen je Termin |
| `GET /api/v2.0/calendar` | R | Kalendergruppen (Name, Farbe) |
| `GET /api/v2.0/location` | R | Veranstaltungsorte |

### 2.2 Wichtige Event-Felder

```
id, name, description, prologue, note
start, end, allDay
locationName, locationObject (FK → location)
calendar (FK → calendar)
isPublic, canceled
isReservation, reservationParentEvent
minParticipators, maxParticipators
startParticipation, endParticipation
access (0=gesperrt, 1=erlaubt, 2=mit Begleitperson)
```

### 2.3 Filter-Möglichkeiten

```
start__gte / start__lte          # Zeitraum-Filter
calendar / calendar__in          # Kalender-Filter
isPublic=true                    # nur öffentliche
canceled=false                   # keine abgesagten
ordering=-start                  # Sortierung
search=...                       # Volltext (name, locationName, description)
limit=100 / offset=N             # Paginierung
```

### 2.4 Einschränkungen

- **Authentifizierung** erforderlich – Bearer-Token für alle Endpoints
- **Rate Limit**: ~100 Requests/Minute pro API-Key
- **Standard-Limit**: 5 Einträge/Antwort, max. 100 via `?limit=`

### 2.5 Achtung: `booking` ≠ Reservierung

`/api/v2.0/booking` sind **Finanzbuchungen** (Buchhaltung), nicht Event-Reservierungen.  
Echte Ortsreservierungen laufen als Events mit `isReservation=true`.

---

## 3. Architekturentscheidung

### Optionen im Vergleich

| | A: Direkter API-Call aus App | B: WP-Plugin als Proxy | C: WP-Plugin mit Sync (✅ empfohlen) |
|--|--|--|--|
| Datenfreshness | Echtzeit | Echtzeit + 1 Cache-TTL | ≤ 15 min Verzögerung |
| Performance App | Langsam (2× Netzwerk) | Mittel | Schnell (lokal) |
| Offline-Nutzung | ❌ | ❌ | ✅ (SQLite) |
| EasyVerein Token in App | ❌ Sicherheitsrisiko | ✅ Nein | ✅ Nein |
| Rate-Limit-Risiko | Hoch (N User × Requests) | Mittel | Niedrig (nur 1 Sync-Job) |
| Konsistenz mit Members | ❌ | Teilweise | ✅ Gleiche Architektur |
| Schreibzugriff (später) | ❌ | ✅ | ✅ |

### Entscheidung: Option C – WP-Plugin-Sync analog zu Members

**Begründung:**
1. **Sicherheit** – Der EasyVerein API-Key liegt ausschließlich auf dem Server, niemals im App-Bundle
2. **Performance** – App fragt lokale WP-DB ab (< 50 ms vs. 500+ ms via EasyVerein)
3. **Offline** – Termine sind im lokalen SQLite der App gespeichert → funktioniert ohne Internet
4. **Rate-Limit-Schutz** – Nur der Sync-Job trifft die EasyVerein-API, egal wie viele User
5. **Konsistenz** – Gleiche Patterns wie Members/Groups; bekannter Sync-Mechanismus
6. **Zukunftssicherheit** – Anmeldung (Participation) erfordert sowieso serverseitige Logik

---

## 4. Datenmodell

### 4.1 WordPress-Datenbank (neue Tabellen)

#### `wp_evg_events`
```sql
CREATE TABLE wp_evg_events (
    id          BIGINT UNSIGNED NOT NULL,          -- EasyVerein-ID
    name        VARCHAR(500)    NOT NULL,
    description TEXT,
    start_dt    DATETIME        NOT NULL,
    end_dt      DATETIME,
    all_day     TINYINT(1)      NOT NULL DEFAULT 0,
    location_id BIGINT UNSIGNED,                   -- FK → evg_locations
    calendar_id BIGINT UNSIGNED,                   -- FK → evg_calendars
    is_public   TINYINT(1)      NOT NULL DEFAULT 1,
    canceled    TINYINT(1)      NOT NULL DEFAULT 0,
    is_reservation TINYINT(1)   NOT NULL DEFAULT 0,
    min_participants SMALLINT UNSIGNED,
    max_participants SMALLINT UNSIGNED,
    participation_start DATETIME,
    participation_end   DATETIME,
    access_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    raw_json    LONGTEXT,                          -- vollständiges EV-Objekt als Fallback
    synced_at   DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_start (start_dt),
    KEY idx_calendar (calendar_id),
    KEY idx_public (is_public, canceled)
);
```

#### `wp_evg_calendars`
```sql
CREATE TABLE wp_evg_calendars (
    id       BIGINT UNSIGNED NOT NULL,
    name     VARCHAR(200)    NOT NULL,
    color    VARCHAR(7),                           -- Hex-Farbe, z.B. "#1a3a6b"
    short    VARCHAR(4),
    PRIMARY KEY (id)
);
```

#### `wp_evg_locations`
```sql
CREATE TABLE wp_evg_locations (
    id           BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(500)    NOT NULL,
    street       VARCHAR(500),
    city         VARCHAR(500),
    zip          VARCHAR(20),
    country_code VARCHAR(5),
    description  TEXT,
    is_reservable TINYINT(1)     NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
);
```

### 4.2 App-seitiges SQLite-Schema (neue Tabellen)

```sql
-- Termine
CREATE TABLE IF NOT EXISTS events (
    event_id   TEXT PRIMARY KEY,
    data       TEXT NOT NULL,   -- JSON: komplettes Event-Objekt
    start_dt   TEXT NOT NULL,   -- ISO 8601, für SQLite-Sortierung
    calendar_id TEXT,
    updated_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_events_start ON events (start_dt);
CREATE INDEX IF NOT EXISTS idx_events_cal   ON events (calendar_id);

-- Kalender (Kategorien)
CREATE TABLE IF NOT EXISTS calendars (
    calendar_id TEXT PRIMARY KEY,
    name        TEXT NOT NULL,
    color       TEXT,
    short       TEXT
);

-- Locations (denormalisiert in events.data, Tabelle als Cache)
CREATE TABLE IF NOT EXISTS locations (
    location_id TEXT PRIMARY KEY,
    data        TEXT NOT NULL
);
```

---

## 5. WP-Plugin-Änderungen

### 5.1 Neuer Event-Sync (`class-evg-events-sync.php`)

Ein eigener, leichtgewichtiger Sync getrennt vom Member-Sync:

```
Sync-Ablauf:
1. GET /api/v2.0/calendar             → wp_evg_calendars befüllen
2. GET /api/v2.0/location             → wp_evg_locations befüllen
3. GET /api/v2.0/event                → wp_evg_events befüllen
   Filter: start__gte = (heute - 7 Tage)
           start__lte = (heute + 12 Monate)
   Paginierung: limit=100, offset++
4. Veraltete Events löschen (start_dt < heute - 7 Tage)
```

**Sync-Frequenz:** WP-Transient oder WP-Cron alle **15 Minuten**  
→ Wesentlich schneller als Member-Sync (<30 Sekunden für ~500 Events)  
→ Kein CLI-Job nötig, WP-Cron reicht

**Option: Webhook-basiertes Invaliding** (später)  
Wenn EasyVerein in Zukunft Webhooks unterstützt, kann ein POST an `/wp-json/easyverein-go/v1/events/refresh` den Sync sofort auslösen.

### 5.2 Neue REST-Endpoints (Namespace `easyverein-go/v1`)

#### `GET /events` – Terminliste

```
Auth: Bearer (bestehender Mechanismus)
Query-Parameter:
  from      = ISO-Datum (default: heute)
  to        = ISO-Datum (default: +12 Monate)
  calendar  = Kalender-ID (optional, mehrfach)
  limit     = 1..200 (default: 100)
  offset    = ≥ 0 (default: 0)

Response:
{
  "total": 42,
  "synced_at": "2026-05-03T10:00:00",
  "events": [
    {
      "event_id": "1234",
      "name": "Trainer-Meeting",
      "description": "...",
      "start": "2026-05-10T18:00:00",
      "end": "2026-05-10T20:00:00",
      "all_day": false,
      "location": { "id": "5", "name": "Vereinsheim", "city": "Miesbach" },
      "calendar": { "id": "2", "name": "Training", "color": "#83c324" },
      "is_public": true,
      "canceled": false,
      "max_participants": 20,
      "participation_open": true
    }
  ]
}
```

#### `GET /events/{id}` – Termindetail

Volles Objekt inkl. `description`, `raw_json` für Felder die noch nicht explizit gemappt sind.

#### `GET /calendars` – Kalendergruppen

```
Response: [{ "calendar_id": "2", "name": "Training", "color": "#83c324", "short": "TR" }]
```

#### `GET /events/sync-status` – Letzter Sync-Zeitstempel (öffentlich)

```
Response: { "synced_at": "2026-05-03T10:00:00", "event_count": 42 }
```

### 5.3 Admin-UI Erweiterung

Im bestehenden Admin-Panel unter „Einstellungen" neuer Tab **„Kalender"**:

- Anzeige: letzter Sync + Event-Anzahl
- Button: „Jetzt synchronisieren"
- Checkbox: „Nur öffentliche Termine an die App übergeben" (Default: ✅)
- Kalender-Filter: Welche Kalender-Gruppen sollen synchronisiert werden?

---

## 6. App-Änderungen

### 6.1 Abhängigkeit: Kalender-Bibliothek

**Empfehlung: `react-native-calendars`**

```bash
npx expo install react-native-calendars
```

- ✅ Expo SDK 54 kompatibel
- ✅ Aktiv gewartet (über 10.000 GitHub Stars)
- ✅ `<Calendar>` (Monat), `<WeekCalendar>`, `<AgendaList>` out-of-the-box
- ✅ Dot-Indikatoren, Custom-Day-Renderer, Thema-Support
- Alternatives Package: `@howljs/calendar-kit` (moderner, week/day fokussiert)

### 6.2 Neue Dateien / Screens

```
src/
  api/
    wordpress.ts              # ← fetchEvents(), fetchEventDetail(), fetchCalendars() ergänzen
  db/
    database.ts               # ← events, calendars, locations Tabellen ergänzen
  hooks/
    useSync.ts                # ← syncEvents() im Sync-Ablauf ergänzen
  types/
    index.ts                  # ← Event, Calendar, Location Types ergänzen
  screens/
    events/
      EventsScreen.tsx        # Haupt-Screen mit Tab-Wechsel (Liste/Monat/Woche)
      EventDetailScreen.tsx   # Detail-Ansicht
  components/
    events/
      EventListView.tsx       # Chronologische Liste (FlashList)
      EventMonthView.tsx      # Monats-Kalender (<Calendar>)
      EventWeekView.tsx       # Wochen-Ansicht (<WeekCalendar>)
      EventCard.tsx           # Karte für Listen-Item
      CalendarDot.tsx         # Farb-Punkt für Monatsansicht
```

### 6.3 Navigation

```
RootStackParamList:
  Events:       undefined          # neu
  EventDetail:  { eventId: string } # neu
```

Im HomeScreen: Button/Card „Termine" → navigiert zu `Events`.

### 6.4 EventsScreen – Tab-Struktur

```
┌─────────────────────────────────┐
│  TV Miesbach – Termine          │
│  [Liste] [Monat] [Woche]        │ ← Top-Tabs
├─────────────────────────────────┤
│  [Alle Kalender ▾]              │ ← Kalender-Filter
├─────────────────────────────────┤
│  So. 10.05.                     │
│  ● 18:00  Trainer-Meeting       │ ← EventCard
│           Vereinsheim           │
│  Fr. 15.05.                     │
│  ● 09:00  Wandertag             │
│           Startet am Bahnhof    │
└─────────────────────────────────┘
```

### 6.5 TypeScript-Types

```typescript
// Neuer Type: Event (App-seitig, nach WP-API-Response)
export interface AppEvent {
  event_id:    string;
  name:        string;
  description: string | null;
  start:       string;   // ISO 8601
  end:         string | null;
  all_day:     boolean;
  location: {
    id:   string;
    name: string;
    city: string | null;
  } | null;
  calendar: {
    id:    string;
    name:  string;
    color: string | null;
  } | null;
  is_public:          boolean;
  canceled:           boolean;
  max_participants:   number | null;
  participation_open: boolean;
}

export interface AppCalendar {
  calendar_id: string;
  name:        string;
  color:       string | null;
  short:       string | null;
}
```

### 6.6 Sync-Integration

Events werden **separat von Members** synchronisiert:

```typescript
// useSync.ts – Erweiterung:
// Nach Mitglieder-Sync ODER eigenständig aufrufbar:
async function syncEvents(force = false): Promise<void> {
  const lastEventSync = await getMeta('last_event_sync');
  const stale = !lastEventSync || Date.now() - new Date(lastEventSync).getTime() > 15 * 60 * 1000;

  if (!force && !stale) return; // Cache noch frisch

  const result = await fetchEvents({ from: toISO(subDays(new Date(), 7)) });
  await upsertEvents(result.events);
  await upsertCalendars(result.calendars ?? []);
  await setMeta('last_event_sync', new Date().toISOString());
}
```

**Cache-Strategie:** 15 Minuten TTL in SQLite-Meta, Pull-to-Refresh im Screen.

---

## 7. Implementierungsphasen

### Phase 1 – Backend-Fundament (WP-Plugin)
**Dauer:** ~1–2 Tage

- [ ] `class-evg-events-sync.php` erstellen
  - [ ] `sync_calendars()` – Kalender holen + in WP-DB schreiben
  - [ ] `sync_locations()` – Orte holen + in WP-DB schreiben
  - [ ] `sync_events()` – Events paginiert holen + in WP-DB schreiben
- [ ] DB-Schema: Tabellen `evg_calendars`, `evg_locations`, `evg_events` anlegen
- [ ] WP-Cron Hook `evg_sync_events` alle 15 Minuten
- [ ] Admin-Button „Events jetzt syncen" (AJAX)
- [ ] Manueller Test: `/wp-json/easyverein-go/v1/events` gibt korrekte Daten zurück

### Phase 2 – WP-REST-Endpoints
**Dauer:** ~1 Tag

- [ ] `GET /events` mit Filtern (`from`, `to`, `calendar`, `limit`, `offset`)
- [ ] `GET /events/{id}` 
- [ ] `GET /calendars`
- [ ] Auth-Check (bestehender Bearer-Mechanismus)
- [ ] Paginierungs-Header (`X-Total-Count`)

### Phase 3 – App-Fundament
**Dauer:** ~1 Tag

- [ ] `react-native-calendars` installieren
- [ ] SQLite-Tabellen `events`, `calendars`, `locations` in `database.ts`
- [ ] TypeScript Types in `types/index.ts`
- [ ] `fetchEvents()`, `fetchEventDetail()`, `fetchCalendars()` in `wordpress.ts`
- [ ] `upsertEvents()`, `upsertCalendars()` in `database.ts`
- [ ] `syncEvents()` in `useSync.ts`
- [ ] Navigation: `Events` und `EventDetail` in `RootStackParamList`

### Phase 4 – UI-Screens
**Dauer:** ~2–3 Tage

- [ ] `EventCard.tsx` – Kompakter Termin-Eintrag mit Uhrzeit, Titel, Ort, Kalender-Farbe
- [ ] `EventListView.tsx` – FlashList mit Datums-Trennzeilen (SectionList oder custom)
- [ ] `EventMonthView.tsx` – `<CalendarList>` mit Dot-Indikatoren je Kalender-Farbe
- [ ] `EventWeekView.tsx` – `<WeekCalendar>` aus react-native-calendars
- [ ] `EventsScreen.tsx` – Tab-Bar oben (Liste/Monat/Woche) + Kalender-Filter
- [ ] `EventDetailScreen.tsx` – Vollständige Infos, Karte/Adresse, Teilnehmerinfo

### Phase 5 – Polishing
**Dauer:** ~1 Tag

- [ ] HomeScreen: „Nächster Termin" Banner/Card
- [ ] Abgesagte Termine visuell markieren (durchgestrichen)
- [ ] Pull-to-Refresh in allen Event-Views
- [ ] Ladeindikator während Sync
- [ ] Leerer State (keine Termine in diesem Zeitraum)
- [ ] App-interner Deeplink: Von Termin zu Location-Info

---

## 8. Spätere Phase: Anmeldung (Participation)

> Erst nach abgeschlossener Lesefunktion angehen.

### Was EasyVerein bietet:

```
POST /api/v2.0/event/{pk}/participation   # Teilnahme anlegen
PATCH /api/v2.0/event/{pk}/participation/{id}  # Status ändern
```

**State-Werte:** 0=kommt nicht, 1=kommt, 2=vielleicht, 3=eingeladen, 4=teilgenommen, 5=nicht teilgenommen

### App-Flow:
1. `EventDetailScreen` zeigt Anmelde-Button wenn `participation_open = true` und `access_level = 1`
2. Tap → WP-Plugin prüft ob User bereits angemeldet + leitet Anmeldung an EasyVerein weiter
3. Bestätigungs-Screen + optionale E-Mail-Benachrichtigung

### WP-Endpoint:
```
POST /wp-json/easyverein-go/v1/events/{id}/participate
Body: { "state": 1 }
→ Plugin ruft EasyVerein-API mit dem Server-API-Key auf
→ Participant wird als aktuell eingeloggter User angelegt
```

---

## 9. Offene Fragen

| Nr. | Frage | Auswirkung |
|-----|-------|------------|
| 1 | Sollen **alle** Kalender-Gruppen synchronisiert werden oder nur ausgewählte? | Admin-Einstellung nötig |
| 2 | Sollen **nicht-öffentliche** Termine (`isPublic=false`) an alle App-User gezeigt werden, oder nur an berechtigte Gruppen? | Berechtigungslogik in WP-Endpoint |
| 3 | Wie weit in die Zukunft sollen Termine synchronisiert werden? (Empfehlung: 12 Monate) | Sync-Window-Parameter |
| 4 | Sollen vergangene Termine sichtbar bleiben? Wenn ja: Wie weit zurück? | Archiv-Feature |
| 5 | Sollen **Reservierungen** (`isReservation=true`) separat oder gar nicht angezeigt werden? | Filter-Konfiguration |
| 6 | iOS-App Store-Link bekannt? | Für Update-Modal |

---

## 10. Zusammenfassung

```
EasyVerein API
      │
      │ (alle 15 min, WP-Cron)
      ▼
WP-Plugin (evg_events, evg_calendars, evg_locations Tabellen)
      │
      │ REST: GET /wp-json/easyverein-go/v1/events
      │       (Bearer-Auth, wie /members)
      ▼
React Native App (SQLite: events, calendars Tabellen)
      │
      ▼
EventsScreen
  ├── ListView  (FlashList + Datums-Gruppen)
  ├── MonthView (react-native-calendars <Calendar>)
  └── WeekView  (react-native-calendars <WeekCalendar>)
```

**Kein EasyVerein-Token in der App. Kein nginx-Timeout-Risiko. Offline-fähig.**
