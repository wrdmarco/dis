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

Administrators can install and activate the self-hosted Netherlands OSRM service from the admin system page after
entering an independently verified source SHA-256 and a routable Dutch probe coordinate. The browser cannot choose
the download URL or pass shell input. A dedicated root-only systemd request broker validates the immutable database
operation snapshot, downloads only the fixed HTTPS Netherlands source, shows bounded live stage logging, verifies
and atomically activates the dataset, and rolls back to the prior healthy release on failure. Once ready, the same
panel offers only a deliberate map-data update; normal DIS deployments never download map data implicitly. See
`infrastructure/osrm/README.md` for the privilege boundary, storage requirements and recovery behavior.
The attested OSRM package is APT-held: neither the map-data action nor a normal system update changes its binary.
Each revoke/re-consent transition advances a server-side consent generation. Location updates are stored
against that generation, so an update started under an older grant cannot reappear after re-consent. Declining
attendance, a `no_response` override, arriving on scene, or closing the incident stops live sharing server-side.

## Mobile Apps And Push Behaviour

Mobile app installation and updates are handled through the platform app stores. The deployment no longer
exposes a public APK download page.

Android treats a preannouncement as a normal, one-shot notification rather than the persistent operational
alarm used for a real dispatch. When alarm sound is enabled it uses the versioned
`preannouncements_v1` channel with the system notification sound; when disabled it uses
`preannouncements_muted_v1`. Existing Android channel settings, global notification permission and Do Not
Disturb policy remain authoritative.

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
