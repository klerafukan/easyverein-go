# Architekturplan: Easyverein Go – Mobile App

> Stand: 30. April 2026 | Plugin-Version: 3.2.5

---

## 1. Systemübersicht

```
┌─────────────────────────────────────────────────────────────┐
│  EasyVerein                                                 │
│  API v3.0 (Sync) ──► WP-Plugin (Easyverein Go)             │
│  OAuth2/OIDC       │  ┌─────────────────────────────────┐  │
│  get-token         │  │  MySQL: evg_members             │  │
│                    │  │         evg_groups              │  │
│                    │  │         evg_member_groups       │  │
│                    │  │         evg_member_custom_fields│  │
│                    │  │         evg_change_requests (neu)│  │
│                    │  └──────────────┬──────────────────┘  │
│                    └─────────────────┤                      │
│                                      │ WP REST API          │
│                              easyverein-go/v1/              │
│                              (erweitert)                    │
│                                      │                      │
└──────────────────────────────────────┼──────────────────────┘
                                       │ HTTPS
                         ┌─────────────▼──────────────┐
                         │   Mobile App (iOS/Android)  │
                         │   React Native / Expo       │
                         │   Lokaler SQLite-Cache      │
                         └────────────────────────────┘
```

---

## 2. Authentifizierung – Entscheidung

**Gewählt: Option C – EasyVerein OAuth2/OIDC (Professional Plan vorhanden)**

EasyVerein fungiert als Identity Provider. Die App führt einen OAuth2/PKCE-Flow durch. WordPress validiert das Access Token gegen den JWKS-Endpoint von EasyVerein und identifiziert den WP-User via `sub`-Claim.

### EasyVerein Identity Provider Konfiguration

| Einstellung | Wert |
|---|---|
| Name | TV Miesbach App |
| Client-ID | `kTkkaLFd1QdwytBuNNY2loAxear9lOBZANGEXAuz` |
| Client Type | **Public** (native App, kein Secret möglich) |
| PKCE | ✅ S256 |
| OpenID-Connect | ✅ Ja, mit RSA256 |
| Redirect URI | `tvmiesbach://auth/callback` |
| Erlaubte Scopes | `openid`, `myself`, `profile` |

### Discovery-Endpoint (offiziell)

```
https://easyverein.com/oauth2/.well-known/openid-configuration
```

| Endpoint | URL |
|---|---|
| Issuer | `https://easyverein.com/oauth2` |
| Authorization | `https://easyverein.com/oauth2/authorize/` |
| Token | `https://easyverein.com/oauth2/token/` |
| UserInfo | `https://easyverein.com/oauth2/userinfo/` |
| JWKS | `https://easyverein.com/oauth2/.well-known/jwks.json` |

> **Warum Public, nicht Confidential?** Native Apps (App Store / Play Store) können kein Client Secret sicher speichern – das Bundle ist analysierbar. RFC 8252 schreibt für native Apps explizit Public Client + PKCE vor. EasyVerein empfiehlt Confidential für Server-zu-Server-Verbindungen.

---

## 3. REST API Erweiterung im WordPress-Plugin

### Neue Endpoints (Namespace: `easyverein-go/v1`)

| Endpoint | Methode | Zweck | Status |
|---|---|---|---|
| `/members` | GET | Delta-Sync via `modified_after`, paginiert | ✅ vorhanden |
| `/me` | GET | WP-User-Profil + App-Konfiguration | 🔲 ausstehend |
| `/groups` | GET | Für den User sichtbare Gruppen | 🔲 ausstehend |
| `/change-requests` | POST | Neue Änderungsanfrage einreichen | 🔲 ausstehend |
| `/change-requests` | GET | Eigene offene Anfragen abrufen | 🔲 ausstehend |

### Authentifizierung der Endpoints (WP-seitig)

WP muss das EasyVerein Access Token verifizieren:
1. `Authorization: Bearer {access_token}` Header lesen
2. Token gegen `https://easyverein.com/oauth2/userinfo/` prüfen (Introspection)
3. `sub`-Claim gegen `evg_user_id` in `wp_usermeta` mappen
4. Bei erstem Login: WP-User auto-provisionen (aus `name`, `email` im UserInfo-Response)

```php
// Pseudocode WP-Endpoint-Absicherung
'permission_callback' => function() {
    $token = evg_extract_bearer_token();
    $user  = evg_validate_ev_token($token); // JWKS oder /userinfo
    return $user !== null;
}
```

---

## 4. Offline-Strategie

### Prinzip: "Offline-First mit Delta-Sync"

```
App-Start
    │
    ├─ Netz verfügbar? ─── JA ──► Delta-Sync: GET /members?modified_after={last_sync}
    │                              ─ Neue/geänderte Records mergen
    │                              ─ last_sync-Timestamp aktualisieren
    │
    └─ NEIN ──► Lokalen SQLite-Cache anzeigen
                (letzter bekannter Stand, sichtbar mit Hinweis "Offline-Modus")
```

### Lokaler Cache (SQLite via Expo SQLite / React Native SQLite Storage)

```sql
-- Tabellen spiegeln WP-Datenstruktur
CREATE TABLE members (member_id TEXT PRIMARY KEY, data TEXT, updated_at TEXT);
CREATE TABLE groups   (group_id  TEXT PRIMARY KEY, name TEXT);
CREATE TABLE meta     (key TEXT PRIMARY KEY, value TEXT);
-- meta: last_sync_timestamp, user_groups_config
```

### Sync-Strategie

- **Erster Start:** Vollsync (`per_page=500`, paginiert bis Ende)
- **Folgestarts:** Delta-Sync via `modified_after`
- **Konflikt-Handling:** Server gewinnt immer (App ist read-mostly)
- **Cache-Invalidierung:** Bei Token-Refresh oder explizitem "Neu laden"

---

## 5. Schreib-/Änderungs-Workflow

### Hintergrund
EasyVerein hat nur 10 schreibende Lizenzen → keine direkte API-Nutzung durch alle App-User möglich.

### Empfohlener Ansatz: Pending-Queue in WordPress

```
App-User                WP-Backend              EasyVerein
    │                       │                       │
    │── POST /change-requests ──►                   │
    │   { member_id, field, new_value, reason }     │
    │                       │                       │
    │                  Speichert in                 │
    │              evg_change_requests              │
    │              (status: pending)                │
    │                       │                       │
    │              ◄── 202 Accepted                 │
    │                       │                       │
    │              Admin sieht Requests             │
    │              im WP-Backend-Dashboard          │
    │                       │                       │
    │              Admin "Genehmigen" ──────────────►
    │                       │    PATCH /api/v3.0/   │
    │                       │    member/{id}        │
    │                       │                       │
    │              Status → approved                │
    │              Nächster Sync pullt              │
    │              Änderung zurück                  │
```

### Neue DB-Tabelle: `wp_evg_change_requests`

```sql
CREATE TABLE wp_evg_change_requests (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    member_id     VARCHAR(64) NOT NULL,
    field_name    VARCHAR(128) NOT NULL,
    old_value     TEXT,
    new_value     TEXT NOT NULL,
    reason        TEXT,
    requested_by  BIGINT NOT NULL,    -- wp_users.ID
    requested_at  DATETIME NOT NULL,
    reviewed_by   BIGINT,
    reviewed_at   DATETIME,
    status        ENUM('pending','approved','rejected') DEFAULT 'pending',
    ev_response   TEXT                -- Antwort von EV-API
);
```

### Alternative: Schreibrecht-Rolle (10 User)

- WP Custom Capability `evg_direct_write` für exakt 10 User
- Diese User können direkt (via App) PATCH-Requests auslösen; WP leitet sofort an EV weiter
- Alle anderen: immer über Pending-Queue
- Kombinierbar mit Pending-Queue-Ansatz

### Admin-UI im WordPress-Backend

- Neue Admin-Seite "Änderungsanfragen" unter dem bestehenden Easyverein-Go-Menü
- Liste der `pending` Requests mit "Genehmigen / Ablehnen"
- E-Mail-Benachrichtigung an Admins bei neuer Anfrage (analog `evg_sync_report_email`)

---

## 6. App-Technologie

**Gewählt: React Native + Expo (TypeScript)**

Projekt: `/Users/tlx/git/private/TV_Miesbach_APP/`

### Implementierter Stack

| Library | Zweck | Status |
|---|---|---|
| `expo-auth-session` | OAuth2/PKCE Flow | ✅ implementiert |
| `expo-secure-store` | Token-Speicherung (Keychain/Keystore) | ✅ implementiert |
| `expo-sqlite` | Lokaler Cache (WAL-Modus) | ✅ implementiert |
| `expo-network` | Netzwerk-Erkennung für Offline-Modus | ✅ implementiert |
| `expo-local-authentication` | Biometrie/App-Lock | ✅ installiert |
| `zustand` | Auth-State-Management | ✅ implementiert |
| `@tanstack/react-query` | API-State | ✅ installiert |
| `@react-navigation/native-stack` | Navigation | ✅ implementiert |

### Projektstruktur

```
TV_Miesbach_APP/
├── App.tsx                         # Navigation + Auth-Check
└── src/
    ├── config.ts                   # WP_BASE_URL, EV_OIDC (Client-ID gesetzt)
    ├── types/index.ts              # TypeScript-Typen
    ├── api/
    │   ├── auth.ts                 # OIDC Login, Token-Speicherung
    │   └── wordpress.ts            # WP REST API Client (fetchMembers etc.)
    ├── db/database.ts              # SQLite: init, upsert, query
    ├── store/auth.ts               # Zustand Auth-Store
    ├── hooks/useSync.ts            # Delta/Full-Sync Logic
    └── screens/
        ├── auth/LoginScreen.tsx    # EasyVerein OIDC Login Button
        └── members/
            ├── MembersScreen.tsx   # Liste + Suche + Gruppenfilter
            └── MemberDetailScreen.tsx  # Profil + Änderungsanfrage
```

---

## 7. Sicherheitsaspekte

### Transport

- Ausschließlich HTTPS; WP-REST-API muss HTTPS erzwingen
- HSTS im Webserver konfigurieren

### Token-Handling in der App

- JWTs **ausschließlich** in `expo-secure-store` (Keychain/Keystore) – niemals in AsyncStorage
- Refresh-Token-Rotation aktivieren (Simple JWT Login unterstützt dies)
- Token-Ablaufzeit: Access Token 15–60 min, Refresh Token 30 Tage

### API-Absicherung

- `nonce`-basierter Schutz entfällt bei JWT; stattdessen: kurze Token-Lebensdauer
- Eingaben in Schreib-Endpoints: `sanitize_text_field` / `wp_kses` auf Server-Seite
- Keine EV-API-Keys an die App übergeben – bleiben serverseitig in WP-Options

### Zugriffskontrolle

- Gruppen-basierte Filterung bleibt im WP-Plugin (bestehende `evg_groups` usermeta-Logik)
- App erhält niemals Rohdaten außerhalb des erlaubten Gruppenfilters
- Logging: alle Change-Requests mit `requested_by` + Timestamp persistent

### Datenschutz

- Lokaler Cache enthält personenbezogene Daten → App-Lock (Biometrie/PIN) empfohlen
- Cache-Löschung bei Logout implementieren
- Kein Caching in HTTP-Layern (Cache-Control: no-store Header auf API-Responses)

---

## 8. Implementierungs-Roadmap

### Phase 1 – WordPress REST API erweitern
- [x] Bestehender `/members`-Endpoint (Delta-Sync via `modified_after`, Pagination)
- [ ] EasyVerein Token-Validation in WP (JWKS oder `/userinfo`-Introspection)
- [ ] WP-User Auto-Provisioning via `sub`-Claim
- [ ] `/me`-Endpoint
- [ ] `/groups`-Endpoint
- [ ] `wp_evg_change_requests`-Tabelle + `/change-requests` Endpoints
- [ ] Admin-UI für Änderungsanfragen im WP-Backend

### Phase 2 – App Core
- [x] Expo-Projekt aufgesetzt (React Native + TypeScript)
- [x] OIDC-Login-Screen (EasyVerein PKCE-Flow)
- [x] Mitgliederliste mit Suche + Gruppenfilter
- [x] Mitglieder-Detailansicht
- [x] Lokaler SQLite-Cache
- [x] Delta-Sync-Logik (`useSync`-Hook)
- [ ] EasyVerein OIDC testen (WP-Endpoint muss bereit sein)
- [ ] Offline-Modus testen

### Phase 3 – Schreib-Workflow + Polish
- [ ] Änderungsanfrage-Formular (Long-Press → Feld auswählen → Begründung)
- [ ] App-Lock (Biometrie via `expo-local-authentication`)
- [ ] Push-Benachrichtigung bei Anfrage-Status-Änderung (optional: Expo Notifications)
- [ ] EAS Build + TestFlight (iOS) / Play Store Beta (Android)

---

## 9. Entscheidungsmatrix – Zusammenfassung

| Bereich | Entscheidung | Status |
|---|---|---|
| Auth | **EasyVerein OIDC – Public Client + PKCE** | ✅ konfiguriert |
| App-Technologie | **React Native + Expo (TypeScript)** | ✅ Projekt erstellt |
| Offline | **SQLite + Delta-Sync via `modified_after`** | ✅ implementiert |
| Schreib-Workflow | **Pending-Queue in WP (`evg_change_requests`)** | 🔲 ausstehend |
| Direktschreib-User | **Custom Capability `evg_direct_write` für ≤10 User** | 🔲 ausstehend |
| Distribution | **App Store (iOS) + Play Store (Android) via EAS Build** | 🔲 ausstehend |
| WP-Endpoints | **`/me`, `/groups`, `/change-requests`** | 🔲 ausstehend |

---

## 10. Deployment

### WordPress-Plugin (Easyverein-Go)

**Automatisch via GitHub Actions** – nach jedem Push auf `main` wird das Plugin auf den Server deployed.

> ⚠️ **Nie manuell per rsync deployen** – immer über Git pushen, die GitHub Action übernimmt den Rest.

```bash
# Plugin-Änderungen deployen:
git add -A
git commit -m "feat: ..."
git push   # → GitHub Action deployt automatisch auf staging.tv-miesbach.de
```

**Ziel-Pfad auf dem Server:**
```
tvwmie@www435.your-server.de:~/public_html/staging/wp-content/plugins/Easyverein-Go/
```

**SSH-Verbindung (falls manuell nötig):**
```bash
ssh -i ~/.ssh/deploy_easyverein -p222 tvwmie@www435.your-server.de
```

**Datenbank:**
- Host: `sql334.your-server.de`
- Datenbank: `tv_db_1`
- Tabellen-Prefix: `wp_tv_`
- EVG-Tabellen: `wp_tv_evg_members`, `wp_tv_evg_groups`, `wp_tv_evg_member_groups`, etc.

### OAuth-Callback-Flow (implementiert)

EasyVerein unterstützt keine Custom-Scheme-Redirects (`tvmiesbach://`) direkt.
Der WP-Proxy löst das Problem:

```
App (PKCE) → EasyVerein (authorizes) → WP /oauth/callback → JS-Redirect → tvmiesbach://auth/callback?code=...  → App
```

- **Redirect URI in EasyVerein:** `https://staging.tv-miesbach.de/wp-json/easyverein-go/v1/oauth/callback`
- **returnUrl für openAuthSessionAsync:** `tvmiesbach://auth/callback`
- Der WP-Endpoint gibt eine HTML-Seite mit `window.location.replace(tvmiesbach://...)` zurück, da Android Chrome Custom Tab 302-Redirects zu Custom Schemes blockiert.

