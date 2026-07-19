# D.I.S. Bare-Metal Deployment

D.I.S. (Drone Inzet Systeem) is deployed as a bare-metal Ubuntu application under `/opt/dis`.
This repository contains only the files required to install, run, update and uninstall the platform.

## Requirements

- Ubuntu 26.04 LTS
- Root or sudo access
- Public HTTPS termination in front of DIS; the inner Nginx listener may use HTTP only when it is
  shielded from direct access and the trusted edge overwrites forwarding headers
- DNS record pointing to the server
- Fresh clone path: `/opt/dis`

## First Install

Clone the repository directly into `/opt/dis`:

```bash
sudo git clone https://github.com/wrdmarco/dis.git /opt/dis
cd /opt/dis
sudo bash setup.sh --domain dis.example.nl
```

Replace `dis.example.nl` with the hostname or IP address you want to use.

The installer will:

- install required Ubuntu packages
- create the `dis` system user and group
- create `/opt/dis/.env`
- generate secure local secrets
- provision PostgreSQL
- install PHP, Nginx and systemd configuration
- build frontend assets
- run Laravel migrations and seeders
- start queue, scheduler and websocket services
- install the global `update` command

## Web Setup Wizard

After the CLI install finishes, open:

```text
https://dis.example.nl/setup
```

The setup wizard configures:

- tenant name
- public server URL
- first system administrator
- SMTP mail settings
- Firebase app configuration
- Separate Android FCM and iOS APNs push configuration

The setup wizard is only available before the first user exists. After completion, further configuration is done in the admin panel.

## Web Security Configuration

Browser authentication uses encrypted, database-backed server sessions. The only browser session
credential is the `Secure`, `HttpOnly`, host-only `__Host-dis_session` cookie. Keep Redis available for
shared rate-limit and replay-protection state, and keep PostgreSQL available for shared web sessions.

Set these production values in `/opt/dis-data/.env` before deployment:

- `APP_URL` and `CORS_ALLOWED_ORIGINS` must use the exact public HTTPS origin.
- `TRUSTED_PROXIES` must contain only the actual TLS/reverse-proxy addresses or CIDR ranges. Wildcards
  are rejected by the deployment hardening step. The edge must overwrite, rather than append to,
  untrusted forwarding headers before traffic reaches the inner Nginx service.
- `SECURITY_CONTACT` must be a monitored `mailto:` or HTTPS URI. The RFC 9116 endpoint deliberately
  returns `503` until this value is valid; no placeholder contact is published.
- `CSP_AERET_FRAME_ORIGINS` may contain comma-separated, exact HTTPS origins only when an additional
  Aeret deployment is genuinely required.

The enforced CSP permits only DIS itself plus the sources used by the current code: PDOK and Photon
for address lookup, ArcGIS for map imagery, OpenStreetMap for the embedded location picker, the two
built-in Aeret map origins, and the configured same-service websocket host. Adding a new external
frontend dependency requires updating and testing `webapp/frontend/src/lib/securityPolicy.ts`.

HSTS is owned by the public TLS edge. The inner Nginx configuration removes duplicate upstream
security headers and supplies the remaining common headers exactly once. Every setup, update and
direct deployment regenerates and validates the first Nginx `server_name` from the HTTPS `APP_URL`;
the raw `_` template is never installed as the production virtual host. The outer OpenResty/TLS
edge is not part of this repository and must suppress its own `Server` and `X-Served-By` headers;
the inner proxy cannot remove headers that the edge adds after receiving the upstream response.

## Updates

After installation you can update the server and application with:

```bash
sudo update
```

This will:

- run `apt-get update`
- run `apt-get upgrade -y`
- run `apt-get autoremove -y`
- pull the latest Git source
- rebuild backend and frontend
- run pending migrations only
- refresh Nginx, PHP and systemd configuration
- restart services
- run a local health check

Useful options:

```bash
sudo update --skip-system
sudo update --skip-source
sudo update --skip-app
sudo update --skip-healthcheck
```

The implementation lives in:

```text
/opt/dis/scripts/update.sh
```

The root `/opt/dis/update.sh` file is only a wrapper.

Database seeders are intentionally not run during updates, so admin-managed teams, roles and settings are not overwritten. For an intentional reseed during a manual deploy, run:

```bash
sudo RUN_SEEDERS=1 bash /opt/dis/scripts/deploy.sh
```

The authentication-hardening upgrade deliberately revokes all existing browser sessions, mobile/API
access tokens, mobile pairing codes and active push-device registrations. After this migration is installed,
every user and paired device must sign in or pair again and register for push notifications again; revoked
credentials cannot be recovered by rolling the migration back.

The same upgrade rotates the historical backup encryption/HMAC key because older releases made that key
readable to the shared runtime group. Existing local backups, pending imports and request state are moved to
the root-only `/opt/dis-data/legacy-backup-state` quarantine and are no longer trusted by the web restore
workflow. The deployment creates and verifies a fresh backup with the new generation before reopening
production. An offline copy of an old key can still decrypt historical material, so previously exposed backup
confidentiality cannot be recovered by permission changes; any exceptional legacy recovery must therefore be
performed manually by an authorised root operator in an isolated environment.

A missing key or generation marker is never treated as proof of a fresh installation. Setup and deployment
always enter the same fail-closed cutover state, quarantine any existing backup state and keep maintenance
enabled until the first replacement backup has been verified and durably synchronised to its target filesystem.

## Operational Alerting

DIS keeps operational dispatch selection and reachability testing deliberately separate:

- A preannouncement asks operators whether they are available for a possible incident. It creates a
  draft dispatch and does not count as an attendance response. The operator payload contains only the
  derived place name; reporter details, the full street address and coordinates remain hidden until the
  real dispatch is sent.
- A real dispatch is selected and authorised server-side. Operational team membership, active account
  status, push reachability, availability and required certification validity remain part of normal
  eligibility.
- A manual test alert defaults to `self` and sends only to the signed-in user's active paired apps.
- The optional `all_online` reachability test targets active users who may use the operator app and have
  push enabled with at least one active, currently online operator-app token. It intentionally does not
  filter on availability, certifications or assigned drones, and the web interface requires explicit
  confirmation before sending.
- Test-alert acknowledgements confirm technical receipt only. They do not start an incident, change
  attendance state or trigger operational dispatch transitions.

The test-alert result reports targeted users, queued devices, users skipped before queueing and users for
whom no notification could be queued. The action requires the `incidents.dispatch.manage` permission and
is recorded in the audit log.

## Managed Wallboards

System administrators with completed 2FA manage paired displays from `/wallboards`; the display itself uses
the dedicated `/wallboard` kiosk route and never inherits an administrator browser session. Screen control
and reusable content playlists are managed separately. A new screen receives its own playlist by default,
or can be assigned to an existing playlist that is shared by multiple screens. Existing installations are
migrated without losing their wallboard configuration: every existing screen initially receives a separate
playlist containing its current content.

Each physical wallboard also has its own display profile, independent of the assigned playlist. `auto` is
the default and keeps the responsive browser layout; administrators can select `1080p` or `4k` when a TV
browser needs an explicit Full HD or Ultra HD readability profile. This setting adjusts only DIS presentation
density. It does not change the television, HDMI input, operating-system or browser output resolution, and a
shared playlist can therefore be shown on screens with different display profiles.

A playlist contains an ordered set of allowlisted DIS pages: an operational map, incident list, operational
summary, safely formatted announcement or safety notice, an administrator-managed daily quote, a UAV Forecast,
curated drone-news page, photo carousel or allowlisted YouTube/Vimeo video. Every page has its own bounded display duration. The playlist also owns
map layers, rotation, the incident override and an optional bottom ticker. The ticker accepts bounded
plain-text internal messages and multiple HTTPS RSS or Atom feeds; feed retrieval is cached, size-limited and
restricted to public destinations. Each RSS source can show between one and eight items; legacy and omitted
`max_items` settings default to eight. External display pages, arbitrary HTML and executable content are not
accepted.

Announcement page names are management metadata for playlist and page selection; the kiosk labels the page
as `Mededeling` and does not render that management name in the announcement body. Announcement content uses
a versioned structured document rather than HTML. The allowlist is limited to headings, paragraphs, quotes,
bullet or numbered lists, left/centred alignment and bold/italic text. Unknown fields and formatting, links,
styles, embedded media and executable markup are rejected server-side. Existing plain-text announcement bodies
are read losslessly and emitted through the same canonical structured document, so no manual data migration is
required.

The distinct `Quote van de dag` page contains between one and fifty administrator-managed plain-text quotes,
each with an optional author. DIS never fills this page from an external service and ships no production example
quote. The display selects one entry deterministically from the page identifier and the current
`Europe/Amsterdam` calendar date, so every refresh on the same local day shows the same quote. Empty lists,
oversized values and unknown fields are rejected server-side; a malformed legacy configuration is shown as an
explicit unconfigured state rather than substituted content.

The UAV Forecast page is bound to an administrator-supplied label and WGS84 coordinate. Current wind, gust,
precipitation and visibility come from [Open-Meteo](https://open-meteo.com/en/docs); the planetary Kp index comes
from the fixed [NOAA SWPC feed](https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json). Each value
shows its source, observation time and stale state. Central server-side thresholds classify the full metric card
green, orange, red or unknown, and the overall advice uses the worst available factor. Missing, invalid or stale
data is always unknown and can never become green. GNSS satellite availability remains explicitly unknown until
a reliable location- and time-dependent source exists. Device limits, mission profile, local observations,
airspace rules and operational authority always override this indicative forecast.

Each drone-news page can enable the fixed Nationaal Drone Team and Dronewatch sources, add up to eight named
custom HTTPS RSS or Atom sources and show between one and twelve items across all enabled sources. At least one
fixed or custom source must remain enabled. DIS first selects only items published during the preceding seven
days. When none of the enabled sources has a recent item, it falls back to the configured number of latest items
and marks that state on the display. Fixed source addresses are application-owned; custom sources are validated
and fetched through public-destination-only DNS pinning, strict transport limits and XML hardening. Retrieval is
bounded and cached server-side; the display receives only a sanitized title, short excerpt, publication
timestamp and canonical article link, never remote markup or executable content. News is presented as one
readable briefing story at a time instead of up to twelve small cards. Administrators configure the bounded
display time and transition per story independently from the playlist page duration. Whenever the news page
becomes active it starts with the first story for one complete configured story duration; a temporary connection
loss pauses that local story timer. Each story has a locally generated QR code for its
validated canonical HTTPS article URL. When a feed provides a suitable raster image, DIS exposes it only through
an authenticated, size-limited same-origin image cache with public-address DNS validation; wallboards never load
the original remote image URL directly and SVG or executable image content is not accepted.

Each playlist also owns a global page-fade switch. When enabled, every normal playlist page enters with the same
short opacity transition; when disabled, page changes are immediate. Browser reduced-motion preferences always
disable that page fade and reduce spatial news transitions to a brief non-spatial dissolve.

An unpaired television starts the pairing flow itself and shows a short-lived, human-readable code on
`/wallboard`; no keyboard is required on the display. An administrator selects the intended wallboard in
`/wallboards` and enters that television code there. Approval is one-time and database-backed. Only the
waiting television receives the resulting dedicated `Secure`, `HttpOnly`, host-only wallboard session cookie;
the administrator never receives or handles the display credential. Expired requests are replaced by a new
code on the television. A paired display session has no idle or absolute server-side expiry: it remains paired
until an administrator revokes it or disables the wallboard. Its persistent browser cookie is renewed whenever
the credential rotates, and an administrator can revoke all paired display sessions at any time.

An administrator can pin a configured page immediately or return the display to its server-authoritative
rotation. A lightweight, authenticated control feed lets the kiosk observe control and configuration
versions without repeatedly loading the full map payload. Page rotation is derived from a server timestamp
and the configured durations, so refreshes and process restarts do not create an independent browser clock.
When connectivity is lost, the kiosk keeps the last known presentation visible with an offline warning and
continues reconnecting automatically. An audited `Wallboard herstarten` command can be sent from administration;
the command is persisted and causes exactly one hard browser reload when that paired screen next receives it,
including after a temporary outage. Normal reconnection never performs a hard reload by itself.

Before an update, direct deployment or manually enabled maintenance stops the web tier, DIS publishes a bounded
maintenance notice through both wallboard feeds and waits six seconds so connected displays can receive it. The
notice temporarily replaces playlist and focus content, while the existing offline warning remains visible if
the connection subsequently drops. A display keeps trying to reconnect and removes the notice automatically
only after the server has passed its health checks and reopens production. The notice also expires locally after
at most six hours, preventing a failed or abandoned operation from leaving an offline television on a permanent
maintenance screen.

Each playlist independently configures focus screens for a preannouncement, a real alarm and a test alarm.
Every focus type has a bounded screen duration and an optional response feed. That feed contains only the
recipient name snapshot, response status and response timestamp; it never exposes e-mail addresses, response
notes or user identifiers. For a real alarm, the response contract additionally contains the complete list of
accepted responders. A current live location received under active incident consent is converted server-side
to a navigation or explicitly labelled fallback ETA; otherwise that responder remains visible with no ETA.
A preannouncement and test alarm use a one-shot focus window. While a real non-test
incident is being dispatched or is in progress, its focus screen is inserted server-side before the assigned
playlist and the combined cycle repeats. A playlist containing only the operational map therefore alternates
between alarm focus and map until the incident ends. The server supplies every phase and deadline through the
two-second control feed, so paired displays stay synchronized and refreshes cannot restart a timer. A real alarm
always takes precedence over a simultaneous preannouncement or test alarm.

Administrators can send a rate-limited focus test for any of the three focus types to one selected screen. DIS
uses fixed, clearly labelled example counts, names and ETAs from a short-lived per-screen cache; no incident,
dispatch or recipient is created. The preview is audited, respects optimistic control versions and expires after
thirty seconds, after which the screen automatically resumes its existing manual page or playlist rotation. A
real operational alarm blocks a new preview and immediately takes priority if it starts during one.

Per playlist, the legacy optional incident override can still pin a preselected page while at least one non-test
incident is actually being dispatched or is in progress. It remains the fallback when real-alarm focus is
disabled. Test alerts never count as active incidents and are omitted from every persistent operational summary,
map, incident list and historical wallboard layer; they can only appear in their bounded focus screen. After the
final matching real incident closes or is cancelled, the previous manual pin or the current rotation becomes
effective again.
Playlist configuration, screen assignment and live-control changes require `wallboards.manage`, use
optimistic versions to prevent stale administrators overwriting each other, and are audit logged. Updating a
shared playlist atomically advances every linked screen to the same configuration while preserving each
screen's pairing, online state and live-control selection where that page still exists.

Dispatch ETA selection uses server-side road routing. Before dispatch, the operator's globally geocoded
home city is the route origin; it remains an approximate origin and never exposes a home address. Navigation
durations are rounded up into 15-minute rings for recipient selection. The configured OSRM service uses its
available road-network data and does not include live traffic. If routing is temporarily unavailable, the API
may return an explicitly identified fallback estimate instead of blocking an alarm. The web interface labels
that value as an estimate and never presents a missing or unknown source as a navigation ETA.

Sending an actual alarm commits the dispatch and one deduplicated push-outbox row per target device in
the same database transaction. DIS tries to queue those rows immediately and the scheduler retries pending
rows every ten seconds with bounded backoff when Redis is unavailable. Exhausted provider retries return the
row to pending, and a stale 15-minute queue lease is reclaimed after an ambiguous worker or Redis failure.
Repeating the send action does not create duplicate outbox rows, but delivery is explicitly at-least-once: a
crash after queue acceptance can still send a duplicate. Stable FCM/APNs collapse identifiers reduce visible
duplicates while they are pending at the provider; preventing loss takes precedence over exactly-once delivery.

After an operator has accepted and explicitly shares a current incident location, DIS can calculate a
navigation ETA from that location. A location older than five minutes is stale: it is not plotted as live and
its former ETA is not shown. The API's optional `eta_source` value is `navigation`, `fallback`, or `unknown`;
clients must continue to handle older responses where this field is absent.

The operational map can explicitly request the current OSRM route for each accepted pilot who is actively
sharing a current location. Each successful poll replaces the complete route geometry, so movement never
builds a historical location trail behind the pilot. Route geometry is not cached or stored as history. If
OSRM is unavailable or returns an invalid route, the map keeps the current pilot marker but draws no straight
line or other route substitute. Route geometry is available only to users who also have
`operational-map.view`; ordinary incident and mobile location responses retain their existing contract and do
not trigger route-geometry requests.

Administrators can install and activate the self-hosted Netherlands-and-Belgium OSRM service from the dedicated
**Routering** admin page. The browser cannot choose download URLs, checksums, readiness coordinates or pass shell
input. DIS uses an application-fixed Dutch readiness coordinate and a root-controlled Belgian readiness coordinate solely to
prove that the generated road graph can answer local nearest-road requests before activation. A dedicated root-only
systemd request broker validates the immutable database operation snapshot,
resolves both fixed HTTPS sources to a common dated Geofabrik snapshot, verifies each supplier MD5 and matching
source timestamp, and merges both extracts without network access. It shows bounded live stage logging, verifies
both Dutch and Belgian readiness probes, atomically activates the dataset, and rolls back to the prior healthy
release on failure. Once ready, the same panel offers only a deliberate map-data update; normal DIS deployments
never download map data implicitly. See
`infrastructure/osrm/README.md` for the privilege boundary, storage requirements and recovery behavior.
The runtime uses Ubuntu's APT-held Podman package and an official OSRM image pinned to an immutable amd64 manifest
digest. Neither a map-data action nor a normal DIS update changes that container image.
Each revoke/re-consent transition advances a server-side consent generation. Location updates are stored
against that generation, so an update started under an older grant cannot reappear after re-consent. Declining
attendance, a `no_response` override, arriving on scene, or closing the incident stops live sharing server-side.

## Mobile Apps And Push Behaviour

Mobile app installation and updates are handled through the platform app stores. The deployment no longer
exposes a public APK download page.

Android treats a preannouncement as a one-shot DIS alarm rather than the persistent looping alarm used for
a real dispatch. When alarm sound is enabled, Android plays the configured DIS tone through a fresh,
sound-specific `preannouncements_v4_*` notification channel; this remains reliable when the app is cold or in
Doze. When alarm sound is disabled, it uses `preannouncements_muted_v4` and stays silent. A separate channel is
used for authorised Do Not Disturb bypass. Global notification permission and Android's Do Not Disturb access
remain authoritative.

Silent device-presence pings use normal FCM priority so Android cannot downgrade later visible alarms for
abusive background wakeups. The strict online indicator remains short-lived, while operational push
selection accepts an active operator token seen within a separate 24-hour reachability window. A phone in
Doze therefore remains eligible for the subsequent HIGH-priority preannouncement or dispatch alarm.

iOS receives the same server-derived place and preannouncement text through APNs. The APNs alert contains
the default notification sound, and the foreground notification delegate presents standard dispatch
updates with banner, badge and sound. The iPhone silent switch, Focus modes and per-app notification
settings remain authoritative.

## Backup

Create a backup:

```bash
sudo bash /opt/dis/scripts/backup.sh
```

Backups are stored under:

```text
/opt/dis-data/backup
```

New backups contain an encrypted `backup.payload.enc`, a checksum manifest and a keyed `BACKUP.HMAC`.
Verification and restore reject legacy plaintext or unauthenticated archives. Runtime backup settings are
written as validated JSON and are never evaluated as shell code. The encryption key is stored separately at
`/opt/dis-data/secrets/backup-encryption.key` with restricted permissions. Keep an offline escrow copy
of this key in the organisation's secret manager; a backup cannot be restored on a replacement server
without the matching key and its `.generation-v2` marker. Never store the escrow copy beside the backup
archive. Backup creation validates the storage archive against the same no-links/no-special-files policy used
by restore. Verification fully extracts storage into protected scratch space, while restore completes that
preflight before maintenance, its mutation marker or `pg_restore` can change live state.

Privileged backup requests normally start immediately through `dis-backup-request.path`. The
`dis-backup-request.timer` unit also sweeps the same root worker every minute, so an existing request is
still picked up if a filesystem notification is missed. Deployments verify this broker end to end before
reopening production. Each worker invocation handles one request and is bounded to 30 minutes; deployments
stop accepting new requests and let an already claimed request finish instead of terminating it mid-backup
or during restore preflight. If the worker is nevertheless terminated, its next invocation converts the
abandoned claim into an explicit failed result instead of leaving the request permanently stuck.

Verify a backup:

```bash
sudo bash /opt/dis/scripts/verify-backup.sh /opt/dis-data/backup/<timestamp>
```

Restore a backup:

```bash
sudo bash /opt/dis/scripts/restore.sh /opt/dis-data/backup/<timestamp>
```

## Maintenance Mode

Enable maintenance mode:

```bash
sudo bash /opt/dis/scripts/maintenance.sh enable
```

Disable maintenance mode:

```bash
sudo bash /opt/dis/scripts/maintenance.sh disable
```

Deployments and updates use this maintenance boundary automatically. The operational API, APK delivery
and websocket endpoints return `503`, while only `/health` and the authenticated, rate-limited
`POST /api/developer/system/maintenance` recovery endpoint remain reachable. Queue workers, the scheduler,
the privileged backup-request worker, websocket server and frontend are stopped before migrations or package changes. A failed deploy/update
intentionally keeps maintenance enabled and leaves stopped services stopped; correct the error and rerun
the command. Production is reopened only after Laravel, Nginx, the frontend, health endpoint and all DIS
runtime services have passed verification.
Paired wallboards are notified six seconds before this boundary closes. Their maintenance screen is cleared only
as part of the same health-gated reopening; failed operations leave the bounded notice and last known content in
place while the kiosk continues its normal automatic reconnect cycle.

## Uninstall

Default uninstall removes service/config integration but keeps data:

```bash
cd /opt/dis
sudo bash uninstall.sh
```

Remove the local database as well:

```bash
sudo bash uninstall.sh --remove-database
```

Remove application files too:

```bash
sudo bash uninstall.sh --remove-app-dir
```

Full removal, excluding Ubuntu package purge:

```bash
sudo bash uninstall.sh --all
```

Package purge is intentionally separate and should only be used on a dedicated server:

```bash
sudo bash uninstall.sh --purge-packages
```

## Git Layout

Only the `Deploy` folder is intended to be a Git repository source.
Development prompt files, Android source files, Docker files, local build output, secrets and generated artifacts are not part of this deployment repository.
