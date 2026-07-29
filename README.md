# D.I.S. Bare-Metal Installation And Runtime

D.I.S. (Drone Inzet Systeem) is deployed as a bare-metal Ubuntu application under `/opt/dis`.
This repository contains only the files required to install, run, update and uninstall the platform.

## Domain Terminology

- A **deployment request** (`DeploymentRequest`, Dutch UI: **Aanvraag**) is the pre-operational
  request from registration through preparation.
- A **deployment** (`Deployment`, Dutch UI: **Inzet**) is the operational record created when a
  complete request is prepared.
- A **dispatch request** (`DispatchRequest`, Dutch UI: **alarmering**) selects, alerts and tracks
  recipients for a deployment.
- A **product request** (`ProductRequest`, Dutch UI: **Verzoek**) is a user-submitted feature request,
  change request or bug report. It is separate from the operational deployment-request workflow.
- Software changes are described as an installation, release, update or production rollout in this
  document; they are not domain deployments.

The canonical operational API resources are `/deployment-requests` and `/deployments`; relationship keys
are `deployment_request_id` and `deployment_id`. The corresponding web routes are `/aanvragen` and
`/inzetten`. Product requests use `/product-requests` and the web route `/verzoeken`.
Old incident/intake identifiers exist only in immutable historical migrations, bounded upgrade readers,
queue-drain compatibility, web redirects and bounded native-client compatibility aliases. Older supported
native builds may temporarily use read/report/location routes under `/incidents` and receive additive
`incident_id` or `intake` response aliases; current clients use only the canonical fields and routes.
Persisted configuration, realtime events and internal queued/outbox push contracts use only the canonical
deployment terminology. During the coordinated mobile transition, provider payloads temporarily include
the canonical `deployment_event_type` plus bounded, old-client-safe wire aliases.

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
built-in Aeret map origins, and the configured same-service websocket host. Media is restricted to
authenticated same-origin delivery and browser-local `blob:` previews; no external media origin is
allowed. Adding a new external frontend dependency requires updating and testing
`webapp/frontend/src/lib/securityPolicy.ts`.

### RBAC for requests, calendar and forecasts

The `/verzoeken` product-request workflow is protected end to end by four permissions:
`product-requests.view`, `product-requests.create`, `product-requests.update-own` and
`product-requests.resolve`. Viewers can read every request, while only the server-recorded requester may
edit its content and only while it remains open or in progress. Status changes, resolution, rejection and
reopening require the resolve permission, optimistic-lock version checks and an audit record. The
three action permissions require `product-requests.view` as well. The API is web-only; bearer tokens
cannot use it. The `request-handler` role grants all four permissions but is never assigned to a
user automatically.

The operational `/weather`, `/uav-forecast` and `/calendar` web routes and their first-party API endpoints
require `operational-weather.view`, `uav-forecast.view` and `calendar.view` respectively. Agenda mutation is
separate under `calendar.manage` and requires `calendar.view` as well. Every agenda item belongs to at least
one agenda-specific group. A group can contain direct users and dynamic team sources; its effective membership
is the deduplicated union of those sources. The protected `Iedereen` system group dynamically represents every
non-deleted user and cannot be changed or removed. The legacy `team_id` response field is compatibility data
only and never decides new agenda visibility.

Self-registration and cancellation require `calendar.register` alongside `calendar.view` and are available to
both the secure web session and Operator mobile clients. Capacity is enforced transactionally against the same
locked agenda row; duplicate registration requests are idempotent, cancellation always releases a seat, and a
maximum cannot be lowered below the active participant count. Participant names are exposed only by the
separate roster endpoint. Viewing registrations, managing registrations for another active audience member,
and managing agenda groups use the dedicated `calendar.registrations.view`,
`calendar.registrations.manage` and `calendar.groups.manage` permissions respectively. Those privileged
group and participant endpoints require a stateful web session and produce audit records. Frontend permission
checks control navigation and route UX only; Laravel middleware and validated requests remain authoritative.

### Admin application log viewer

The **Logbestanden** tab under `/admin` requires the dedicated `system.logs.view` permission. New and
upgraded installations grant it only to the system-administrator role by default; an authorised role
manager may assign it deliberately to another web-administration role. Every list and read request still
passes the normal authenticated web-session, completed-2FA, operational and server-side permission
middleware.

The viewer exposes only the allowlisted Laravel daily files in the canonical backend log directory. It
never accepts a server path, follows a symlink or reads journald, Nginx, PostgreSQL, Redis or arbitrary
`/var/log` content. Log chunks are byte-, line- and rate-limited, re-redacted when read, returned with
`Cache-Control: no-store` and followed by bounded two-second cursor polling while the browser tab is
visible. The active daily file follows rotation automatically, while an explicitly selected archive
stays selected. Actor-bound HMAC checkpoints detect rotation, truncation and rewritten cursor context.
Starting or resetting a view is audit logged without storing log content or cursor data. Raw log lines
are not broadcast through Reverb. Server recovery during web or maintenance outages therefore remains
an SSH/system-console responsibility.

### Deployment location enrichment

For non-test deployments, the isolated `deployment-enrichment` queue classifies an already stored deployment
coordinate through the official PDOK province WFS and, when needed, the Eurostat GISCO country lookup.
Provider URLs are restricted to their exact HTTPS hosts and port 443; redirects, credentials, configured
query strings and fragments are rejected. Exact coordinates are sent only as lookup parameters. DIS stores
the canonical province/country code and name, the provider identifier and the resolution timestamp alongside
the deployment; it does not retain provider response bodies. These fields follow the deployment's normal retention
and deletion lifecycle. Wallboard KPI output contains aggregate counts only, never coordinates or deployment
identifiers. Test deployments are not sent to either provider.

`DEPLOYMENT_LOCATION_ENRICHMENT_ENABLED` is the operational kill switch and is enabled in the supplied runtime
configuration; set it to `false` before a production rollout to prevent all outbound lookups and scheduled backfill. The
deployment write path never contacts Redis for this enrichment. Every five minutes the scheduler admits a bounded
batch of two or three rows: one lane selects the newest never-attempted deployment and at least one lane preserves
oldest-due backfill fairness. An unresolved row waits at least six hours before another provider attempt, so a
provider or queue outage cannot obstruct push or the normal application queue.

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

- A preannouncement asks operators whether they are available for a possible deployment. It creates a
  draft dispatch and does not count as an attendance response. The operator payload contains only the
  derived place name; reporter details, the full street address and coordinates remain hidden until the
  real dispatch is sent.
- A real dispatch is selected and authorised server-side. Operational team membership, active account
  status, push reachability, availability and required certification validity remain part of normal
  eligibility.
- A manual test alert defaults to `self` and sends only to the signed-in user's active paired apps.
- The backwards-compatible `all_online` reachability-test scope targets active users who may use the
  operator app and have push enabled with at least one reachable operator-app token. Reachability requires
  an active linked mobile session and a heartbeat inside the 24-hour push window; the shorter online window
  remains a freshness indicator only. The test intentionally does not
  filter on availability, certifications or assigned drones, and the web interface requires explicit
  confirmation before sending.
- Test-alert acknowledgements confirm technical receipt only. They do not start a deployment, change
  attendance state or trigger operational dispatch transitions.

The test-alert result reports targeted users, queued devices, users skipped before queueing and users for
whom no notification could be queued. The action requires the `deployments.dispatch.manage` permission and
is recorded in the audit log.

### Deployment requests

Authorised centralists start an application under `/aanvragen` before a deployment exists. Every request uses the
published, immutable request-workflow revision that was current when the request was created and contains
common questions plus exactly one subject branch: person, animal or object. Answers are autosaved with
optimistic locking and idempotent mutations. A linked request remains editable after preparation, and configured
field bindings keep the corresponding deployment fields synchronized without requiring duplicate entry.
`Laatst gezien locatie` and `Opkomstlocatie` are separate address answers: only the latter supplies the
deployment `location_label`. The system never copies the last-seen address into the assembly location.
For a linked deployment, a changed priority or deployment proposal is persisted through the request decision.
Later answer edits invalidate that decision for review but retain the current operational plan; teams added by
dispatch escalation are synchronized back into that plan without rewriting dispatch recipients or history.

Administrators with `forms.manage` configure, validate, simulate, publish and restore request workflows under
`/forms`. Priority advice and deployment proposals remain separate from the centralist's recorded decision.
Departures from the advice require the dedicated override permission and a reason. Preparing a complete request
creates exactly one draft deployment; it never sends a preannouncement, dispatch, push notification or alarm.
Operator clients receive only fields that are marked operator-visible in both the request's frozen workflow
revision and the currently published revision.

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

A playlist contains an ordered set of allowlisted DIS pages: an operational map, deployment list, operational
summary, live KPI overview, calendar, safely formatted announcement or safety notice, an administrator-managed
daily quote, a UAV Forecast, curated drone-news page, photo carousel or allowlisted YouTube/Vimeo video. Every
page has its own bounded display duration. The playlist also owns
map layers, rotation, focus settings and an optional bottom ticker. The ticker accepts bounded
plain-text internal messages and multiple HTTPS RSS or Atom feeds; feed retrieval is cached, size-limited and
restricted to public destinations. Each RSS source can show between one and eight items; legacy and omitted
`max_items` settings default to eight. External display pages, arbitrary HTML and executable content are not
accepted.

Every playlist explicitly uses either `live` or `demo` data. A demo playlist keeps its configured pages and
managed static media, but replaces operational summaries, deployments, locations, calendar entries, KPI values,
news, ticker items and UAV Forecast readings with fixed, clearly labelled fictitious fixtures. That path does
not query operational records or retrieve external feeds, weather or address data, and it never reacts to a real
deployment, focus event or active-deployment override. Demo weather is presentation data only and must never be
used for a flight decision. Changing mode advances the playlist and linked-screen cache versions and removes
live content snapshots. An active-deployment playlist must remain `live`.

The managed media library accepts ordered photo playlists and local MP4 videos. Administrators can reorder a
photo playlist with pointer, keyboard or touch-friendly controls; the stored order is the presentation order.
New raster images must have at least Full HD source detail in either landscape or portrait orientation. DIS
decodes and re-encodes them as verified WebP, proportionally fits them inside a 1920 x 1080 box without cropping
or upscaling, and creates a separate thumbnail. The scheduled migration applies the same bounded conversion to
oversized existing images, leaves smaller legacy images intact and advances the affected media revisions so
paired displays replace stale cached files. Source files, generated thumbnails and their database metadata are
installed atomically and failed conversions retain the previous verified image.

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

The calendar page reuses current and upcoming events already managed in the DIS agenda. Administrators choose
a bounded number of entries per page; the kiosk presents their date, time, relative day and location. Only
events linked to the protected `Iedereen` group are exposed to a paired wallboard; limited-group events remain
private regardless of any legacy team column. Calendar data remains server-authoritative live state and is not
stored as static media.

The KPI page exposes a fixed, server-owned catalogue of 42 aggregate metrics for pilot availability, real
deployments, managed assets, live dispatch responses and submitted flight reports. Administrators can enable or
disable every KPI separately. They can choose a counter, bar, pie or ring wherever the metric has a real
distribution or denominator; standalone totals remain counters, and one page accepts at most six visible charts.
An omitted legacy selection enables the complete catalogue while an explicitly empty selection remains empty.
The paired display and the administrator playlist preview receive the same live aggregate payload and use the
same renderer.

Pilot availability follows the same OCP operator-pilot, push, latest-status and schedule rules as the operational
summary. Daily lifecycle counts use `Europe/Amsterdam`; completed and cancelled totals are also available since
registration. Monthly flight metrics count submitted non-test reports with a positive flight duration. A validated
assigned-drone selection is snapshotted as manufacturer and model so later asset changes do not rewrite existing
report history. Its distribution shows up to eight drone types plus `Overig` and `Onbekend`. Reports and clients that do
not submit a supported drone selection remain honestly `Onbekend`; in particular, the current fixed iOS pilot-
report form does not yet offer the dynamic drone selector and is not included as a known drone type.

Province and country charts use only the stored canonical classifications described under deployment location
enrichment. Deployment and response totals exclude test deployments, and repeated recipient rows are deduplicated per
deployment and user. Names, e-mail addresses, notes, exact locations, deployment identifiers and other recipient
details are never included in the KPI payload. A percentage without a valid denominator is shown as unknown
rather than zero.

The UAV Forecast page uses either an administrator-selected address, resolved server-side through the existing
DIS address search, or the exact average of one reference point in each of the twelve Dutch provinces. Weather,
temperature, dew point, precipitation, visibility, cloud layers, model cloud base and winds come directly from
the tokenless [DMI Forecast Data EDR API](https://www.dmi.dk/friedata/dokumentation/forecast-data-edr-api) and
are cached in Redis for at most fifteen minutes. DMI HARMONIE DINI data is licensed under
[CC BY 4.0](https://www.dmi.dk/friedata/dokumentation/terms-of-use). Daylight is calculated server-side for the
resolved position and the planetary Kp index comes from the fixed
[NOAA SWPC feed](https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json). No forecast archive,
GRIB/HDF5 file or immutable weather snapshot is stored on the application filesystem.

The `/weather` page presents a map-first, Buienradar-style timeline. The server reads the open
[anonymous KNMI WMS](https://developer.dataplatform.knmi.nl/wms). It combines the
[`nl_rdr_data_rtcor_5m`](https://dataplatform.knmi.nl/dataset/access/nl-rdr-data-rtcor-5m-1-0)
real-time corrected observations with the
[`radar_forecast_2.0`](https://dataplatform.knmi.nl/dataset/radar-forecast-2-0) two-hour nowcast and exposes
37 validated same-origin frames from -60 through +120 minutes. The current model boundary is shown as a fixed
`NU` seam between observations and forecast. Both KNMI datasets are reused under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).

The selectable lightning layer reads the fixed EUMETView `mtg_fd:li_afa` WMS layer live and exposes the latest
seven five-minute Accumulated Flash Area frames. It represents total lightning and does not distinguish
cloud-to-ground from cloud-to-cloud flashes. EUMETView imagery is shown with source and policy attribution under
the [EUMETSAT data registration and licensing terms](https://user.eumetsat.int/resources/user-guides/data-registration-and-licensing).
DIS does not embed or scrape Buienradar.nl, LightningMaps or Blitzortung.

Weather-provider images are validated, size-bounded and cached only through the configured Laravel cache
(Redis in production); they are never written to local or shared snapshot directories. Browsers receive
immutable, HMAC-protected same-origin frame URLs. A 1 MiB per-frame ceiling and bounded Redis retention of
two hours for KNMI and ninety minutes for EUMETSAT preserve the full history plus stale-fallback window; the
browser cache prevents repeat downloads. KNMI anonymous WMS traffic is serialized through one Redis-backed,
installation-wide gate with at least one second between upstream requests. Capabilities use the same gate and
a separate four-minute timeline cache, so concurrent frame loads cannot bypass the provider limit or turn a
cache outage into an unbounded outbound request storm. Browsers contact only PDOK directly for the interactive
pastel WMTS background.
The map supports pan, zoom, fullscreen, location centring and reduced motion, with visible attribution for
PDOK/Kadaster, KNMI and EUMETSAT. Wallboards use the same live frame contract and preload the bounded active
series through their existing browser cache.

Deployment retires the former `dis-knmi` and `dis-knmi-realtime` workers, clears only their two Redis queues,
removes their unit files and obsolete environment settings, and securely removes exactly
`/opt/dis-data/webapp/backend/storage/app/knmi-forecast` and
`/opt/dis-data/webapp/backend/storage/app/eumetsat-lightning`. Restore applies the same retirement after
installing an older backup, so retired snapshots cannot silently return.

DMI HARMONIE values are model expectations, not measurements. The model cloud-base field is displayed without
claiming an AGL or MSL reference because that reference is not specified by this dataset contract. Missing,
invalid, rate-limited or stale source data stays unknown and can never become green.

Wind is reported at its source height in metres AGL. The service also derives the highest sampled height at
10, 100 or 150 metres AGL whose wind classification has not reached red. Visibility changes from metres to
kilometres with two decimals at 10 km. Administrators may hide individual information cards, but the mandatory
flight advice always evaluates the complete server-side metric set. Each full metric card is classified green,
orange, red or unknown, while the advice bar shows one stable data-refresh time in `Europe/Amsterdam`. Source,
observation time and stale state remain available in the server contract without repeated source rows on the
display. Missing, incomplete, invalid or stale data is always unknown and can never become green. GNSS satellite
availability remains explicitly unknown until a reliable location- and time-dependent source exists. Device limits,
mission profile, local observations, airspace rules and operational authority always override this indicative forecast.

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

Each playlist owns a global page transition and bounded transition duration; an individual page may inherit or
override it. Supported transitions include fade, dissolve, directional slide, directional flip, zoom and wipe.
News stories and photos also have their own bounded item transition, duration and flip direction, implemented as
a two-card hand-off so the old item visibly leaves while the new item arrives. Browser reduced-motion preferences
replace spatial transitions with a brief non-spatial dissolve.

Before normal playlist playback starts, the kiosk inventories every configured page and keeps a dedicated
preparation screen visible until all referenced, locally cacheable images and posters are completely stored and
the matching cache version is activated. The screen reports page and asset progress,
retries automatically without a manual button and never exposes download URLs or technical error details.
Live API state and external YouTube/Vimeo streams are deliberately not stored as static media; supported
external players receive only a bounded connection warm-up. Verified local MP4 files can be assigned to video
pages and are served through the paired wallboard session with byte-range support. MP4 source uploads are
accepted up to the configurable 512 MiB server limit. Files above 1080p or outside the browser-safe profile are
queued for bounded H.264/AAC normalization at a maximum of 1920 x 1080 without upscaling; a 4K display still
scales the resulting 1080p video to its fullscreen viewport without repeatedly downloading it.

The precache version changes when the playlist configuration or referenced news/media content changes. DIS
builds the replacement cache in isolation and activates it only after a complete successful pass, so a partial
download never becomes the presentation source. After activation it removes unused entries and obsolete DIS
wallboard caches; unpairing, revocation or disabling a display clears all of its wallboard caches. Maintenance
has highest display priority, followed by a server-issued focus screen. Either may interrupt preparation
immediately; preparation resumes afterwards and normal rotation starts only when the complete cache is ready.

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
notice temporarily replaces playlist, focus, preparation and offline-warning content. A display keeps trying to
reconnect and removes the notice automatically
only after the server has passed its health checks and reopens production. The notice also expires locally after
at most six hours, preventing a failed or abandoned operation from leaving an offline television on a permanent
maintenance screen.

Each playlist independently configures focus screens for a preannouncement, a real alarm and a test alarm.
Every focus type has a bounded screen duration and an optional response feed. That feed contains only the
recipient name snapshot, response status and response timestamp; it never exposes e-mail addresses, response
notes or user identifiers. For a real alarm, the response contract additionally contains the complete list of
accepted responders. A current live location received under active deployment consent is converted server-side
to a navigation or explicitly labelled fallback ETA; otherwise that responder remains visible with no ETA.
A preannouncement and test alarm use a one-shot focus window. While a real non-test
deployment is being dispatched or is in progress, its focus screen is inserted server-side before the assigned
playlist and the combined cycle repeats. A playlist containing only the operational map therefore alternates
between alarm focus and map until the deployment ends. The server supplies every phase and deadline through the
two-second control feed, so paired displays stay synchronized and refreshes cannot restart a timer. A real alarm
always takes precedence over a simultaneous preannouncement or test alarm.

Each screen may additionally select a dedicated active-deployment playlist. It becomes the runtime playlist only
while a real, non-test deployment is in progress and returns to the normal assigned playlist afterwards. The
selection is server-authoritative, audited and part of the wallboard cache version, so a running deployment is
not masked by a newer dispatching record and a display prepares the replacement playlist before showing it.

Administrators can send a rate-limited focus test for any of the three focus types to one selected screen. DIS
uses fixed, clearly labelled example counts, names and ETAs from a short-lived per-screen cache; no deployment,
dispatch or recipient is created. The preview is audited, respects optimistic control versions and expires after
thirty seconds, after which the screen automatically resumes its existing manual page or playlist rotation. A
real operational alarm blocks a new preview and immediately takes priority if it starts during one.

Stored legacy `incident_override` values are migrated once to the canonical `deployment_override` value; new
configuration accepts only the canonical name. Neither value pins or replaces the configured normal or
active-deployment playlist. Operational-map pages in either playlist receive the same live deployment, responder,
route and focus data, so they can centre and zoom on the active deployment without a hard-coded deployment page.
Test alerts never count as active deployments and are omitted from every persistent operational summary, map,
deployment list and historical wallboard layer; they can only appear in their bounded focus screen. After the
final matching real deployment closes or is cancelled, the normal playlist or current
manual selection becomes effective again.
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

After an operator has accepted and explicitly shares a current deployment location, DIS can calculate a
navigation ETA from that location. A location older than five minutes is stale: it is not plotted as live and
its former ETA is not shown. The API's optional `eta_source` value is `navigation`, `fallback`, or `unknown`;
clients must continue to handle older responses where this field is absent.

The operational map can explicitly request the current OSRM route for each accepted pilot who is actively
sharing a current location. Each successful poll replaces the complete route geometry, so movement never
builds a historical location trail behind the pilot. Route geometry is not cached or stored as history. If
OSRM is unavailable or returns an invalid route, the map keeps the current pilot marker but draws no straight
line or other route substitute. Route geometry is available only to users who also have
`operational-map.view`; ordinary deployment and mobile location responses retain their existing contract and do
not trigger route-geometry requests.

Administrators can install and activate the self-hosted Netherlands-and-Belgium OSRM service from the dedicated
**Routering** admin page. The browser cannot choose download URLs, checksums, readiness coordinates or pass shell
input. DIS uses an application-fixed Dutch readiness coordinate and a root-controlled Belgian readiness coordinate solely to
prove that the generated road graph can answer local nearest-road requests before activation. A dedicated root-only
systemd request broker validates the immutable database operation snapshot,
resolves both fixed HTTPS sources to a common dated Geofabrik snapshot, verifies each supplier MD5 and matching
source timestamp, and merges both extracts without network access. It shows bounded live stage logging, verifies
both Dutch and Belgian readiness probes, atomically activates the dataset, and rolls back to the prior healthy
release on failure. Once ready, the same panel offers only a deliberate map-data update; normal DIS updates
never download map data implicitly. See
`infrastructure/osrm/README.md` for the privilege boundary, storage requirements and recovery behavior.
The runtime uses Ubuntu's APT-held Podman package and an official OSRM image pinned to an immutable amd64 manifest
digest. Neither a map-data action nor a normal DIS update changes that container image.
Each revoke/re-consent transition advances a server-side consent generation. Location updates are stored
against that generation, so an update started under an older grant cannot reappear after re-consent. Declining
attendance, a `no_response` override, arriving on scene, or closing the deployment stops live sharing server-side.

## Mobile Apps And Push Behaviour

Mobile app installation and updates are handled through the platform app stores. The DIS runtime no longer
exposes a public APK download page.

Android treats a preannouncement as a one-shot DIS alarm rather than the persistent looping alarm used for
a real dispatch. When alarm sound is enabled, Android plays the configured DIS tone through a fresh,
sound-specific `preannouncements_v4_*` notification channel; this remains reliable when the app is cold or in
Doze. When alarm sound is disabled, it uses `preannouncements_muted_v4` and stays silent. A separate channel is
used for authorised Do Not Disturb bypass. Global notification permission and Android's Do Not Disturb access
remain authoritative.

Silent device-presence pings use normal FCM priority so Android cannot downgrade later visible alarms for
abusive background wakeups. The strict online indicator remains short-lived, while operational push
selection requires push to be enabled, a live linked operator session and an active operator token seen
within a separate 24-hour reachability window. The web interface labels a reachable device with a delayed
heartbeat as stand-by instead of offline. A phone in Doze therefore remains eligible for the subsequent
HIGH-priority preannouncement or dispatch alarm.

After an operator has signed in, Android offers a one-time, optional request to exempt DIS from battery
optimisation. The current state and a permanent recovery action remain available under **Gedrag en rechten**.
Granting the exemption triggers both an immediate heartbeat and durable WorkManager recovery; declining it
does not block operational access.

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
