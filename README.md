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
security headers and supplies the remaining common headers exactly once.

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

## Mobile Apps

Mobile app installation and updates are handled through the platform app stores. The deployment no longer exposes a public APK download page.

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
