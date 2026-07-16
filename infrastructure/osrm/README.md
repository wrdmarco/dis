# Local OSRM routing

DIS can calculate road-network ETA values through a self-hosted OSRM backend. The service is optional and
listens only on `127.0.0.1:5000`; it is never proxied by Nginx and the deployment does not use the public OSRM
demo server. Runtime logging is limited to warnings and errors so normal routing requests containing location
coordinates are not intentionally written to the service journal.

## Package availability

The deployment uses the `osrm-backend` package only when the configured APT metadata exposes it through an
Ubuntu 26.04 `resolute`, `resolute-updates` or `resolute-security` main/universe pocket on an official Ubuntu
archive host. Initial installation or repair of an unattested package runs with a temporary source list derived
from those configured entries only and the root-controlled `ubuntu-keyring` archive key; a version pin is not
origin proof because another repository can publish an identical version. An already installed package without
a matching durable file fingerprint is reinstalled through that restricted source list.

The resulting `osrm-backend` version and installed-file fingerprint are recorded under
`/opt/dis-data/osrm/package-provenance.json`. At service start, DIS compares the currently installed package and
package-owned OSRM executables with that protected receipt. A later APT metadata refresh or newly published
candidate does not change a previously attested runtime: the package remains held and both normal DIS updates and
the Admin **Kaartdata bijwerken** action leave the binary untouched. This workflow does not claim to perform a
transactional OSRM binary upgrade; such a migration must be handled as a separate planned platform change.

This receipt attests the `osrm-backend` package files themselves, not the complete operating-system dependency
closure. The normal host package-management and repository policy remains responsible for ensuring that shared
libraries and other dependencies come from organisation-approved Ubuntu sources. Do not enable unapproved PPAs,
foreign suites, mirrors, Debian repositories or binary downloads on a DIS host.

Run this after enabling an appropriate, organisation-approved Ubuntu archive component if the package is
not yet installed:

```bash
sudo apt-get update
sudo /usr/local/lib/dis/osrm-admin/osrm.sh install-package
```

On a fresh host with no Ubuntu 26.04 candidate, OSRM stays explicitly degraded and DIS continues to use its
bounded fallback ETA. A previously attested installation remains usable when repository metadata temporarily
has no candidate, but an unattested installation cannot be installed or repaired until an approved candidate is available. Once
an archive does advertise a candidate, installation or missing-tool errors are fatal so a partially installed
route engine cannot be mistaken for a supported degraded state. Do not install a package built for a different
Ubuntu or Debian release.

## Admin installation and map updates

An authorised administrator can install and activate OSRM from the DIS admin page. Before activation the form
requires an independently verified SHA-256 and a known routable probe coordinate. The privileged worker obtains
those values again from the immutable database operation record; the browser request cannot supply a URL, file
path, shell option or OSRM profile to root.

The only accepted download is the Netherlands extract configured by the root-owned
`OSRM_ADMIN_PBF_URL`. Its production value is fixed to:

```dotenv
OSRM_ADMIN_PBF_URL=https://download.geofabrik.de/europe/netherlands-latest.osm.pbf
```

The worker rejects HTTP, redirects, other hosts and paths, non-public DNS results, DNS rebinding, oversized or
partial responses, insufficient disk space and a SHA-256 mismatch. DNS is resolved and checked before the public
address is pinned into curl while normal TLS certificate verification remains mandatory. The completed download
must be a regular, non-symlink, single-link file owned by the isolated build account before root hashes it.

Installation and activation are one operation: DIS verifies or installs the restricted Ubuntu package, provisions
the isolated service, downloads and hashes the extract, preprocesses it, atomically activates it, and runs artifact
and route-readiness checks. Only after all checks pass does the backend mark routing enabled. Once the service is
installed, enabled and healthy, the admin page offers only **Kaartdata bijwerken**. A matching active source SHA
is rejected because there is no update to apply; a different SHA prepares a new release and retains the old one
for automatic rollback.

For recovery of an installed but degraded or not-yet-managed activation, the root worker may accept the currently
active SHA and rebuild the dataset from the verified source. Reuse without preprocessing is allowed only when the
existing package receipt, active SHA, probe coordinate and local health check all already match.

A map-data update never upgrades the `osrm-backend` package. It keeps the exact already verified and healthy
binary so a failed import can safely retain or restore the previous dataset. Package installation is restricted to
the initial **Installeren en activeren** operation; normal DIS deploys and system updates do not install or upgrade
OSRM behind the administrator's back.

After provenance verification, DIS places `osrm-backend` on an APT hold. This prevents a general host upgrade from
silently changing the routing binary and invalidating dataset rollback. The worker verifies that hold together with
the package receipt before treating OSRM as managed. Every normal DIS uninstall removes this DIS-managed hold, even
when Ubuntu packages and generated route data are deliberately retained; package purge remains a separate option.

Release retention defaults to three complete releases (`OSRM_RELEASE_RETENTION=3`, valid range 3–20). Pruning runs
before the download disk preflight and after successful activation. The root workflow always protects the exact
`current`, `previous` and durable pending-activation targets, validates every release directory, and removes only
older ordinary release directories through descriptor-based path handling. Configure a higher value only through a
root-controlled service environment override and account for the corresponding disk requirement.

The same preflight removes crash remnants only when their direct-child name exactly matches a DIS-generated
`.import.XXXXXX` or `.admin-download.XXXXXX` contract. It skips symlinks, special entries, unexpected owners,
group/world-writable directories, the explicitly active download/import directory and every release, pointer or
activation marker. A live manual activation owner defers the entire sweep. SIGKILL cannot run an EXIT trap, so the
next root broker timer pass performs this bounded descriptor-based recovery before examining abandoned operations;
uncontrolled lookalike directories are retained for operator inspection instead of being deleted.

The browser publishes a small mode-0600 request into `/opt/dis-data/osrm-admin/requests`. A root-only static
systemd broker claims it atomically, holds the global DIS operation lock for the full workflow and writes bounded,
request-specific JSON status and JSONL logs under `/opt/dis-data/osrm-admin/results`. PHP can read results but
cannot alter them. `dis-osrm-admin-request.path` starts work immediately and the timer retries missed filesystem
events and interrupted backend completion callbacks. No OSRM command is added to sudoers.

The persistent root broker never sources or executes OSRM maintenance code from the deployable Git checkout.
Setup, deploy, update and permission self-heal stage and verify an immutable runtime bundle under
`/usr/local/lib/dis/osrm-admin`: the common library, descriptor helper, OSRM runner and `dis-osrm.service` template
are regular single-link root-owned mode-0644 files below root-owned mode-0755 directories. The executable broker at
`/usr/local/bin/dis-osrm-admin-request-worker` is root-owned mode 0755. Before sourcing its common library, the
broker independently checks this complete ownership, mode, link and parent chain. OSRM provisioning reads only the
bundled service template, and the generated service executes only the bundled OSRM runner. `APP_ROOT` is retained
solely to run validated Laravel Artisan commands as the unprivileged `dis` account; root-side OSRM commands use the
root-controlled data path and configuration. Final deploy/update verification requires both broker Path and Timer
units to be enabled and active. Uninstall removes the worker and runtime bundle.

Each download scratch root and its `control` directory remain root-owned and non-writable by `dis-osrm-build`.
Root exclusively pre-creates the curl header, status, error and PBF files as regular single-link files in those
directories; the build account may write only through those already opened files and cannot replace their paths.
Control files are returned to root and made read-only immediately after curl exits, before root parses them. This
prevents a compromised or racing build process from substituting a symlink for a root redirection target.

Live admin logging reports these bounded stages without exposing raw commands or paths: validation, download,
package installation, provisioning, extract, partition, customize, activation, verification and configuration.
An interrupted import is coupled to the broker systemd unit; subsequent recovery reconciles the durable activation
marker and releases the backend operation lock. A transient parser has `PartOf` and `BindsTo` coupling to that
broker but deliberately no `After` ordering on the waiting oneshot parent. If interruption occurs after a new
release was committed but before the success snapshot was written, recovery reloads the immutable database payload
and requires an exact SHA/probe match plus status, artifact verification and live health success before recording
success. A temporary PHP/database outage preserves the work marker and status unchanged for the next timer retry;
only a loaded contract with a definitive runtime mismatch is failed closed. The normal DIS application update only refreshes code, package
integration and current-service state. It never silently downloads or imports new map data.

## Manual dataset import

A root operator can still obtain a suitable `.osm.pbf` from a controlled source, validate its licensing and update
policy, and supply its independently obtained SHA-256. Keep the original source outside `/opt/dis-data/osrm`;
preprocessing retains only protected OSRM artifacts.

Choose a longitude/latitude that is known to lie on a routable road inside the supplied extract. It is used
only for a local nearest-road readiness request and is stored with the generated release. The probe must snap
to the prepared road graph within 250 metres (configurable downwards or, after operational review, up to the
hard 5 km ceiling with `OSRM_HEALTH_MAX_SNAP_METERS`), so an extract for the wrong region is rejected:

```bash
sha256sum /root/netherlands-latest.osm.pbf

sudo /usr/local/lib/dis/osrm-admin/osrm.sh import \
  --pbf /root/netherlands-latest.osm.pbf \
  --sha256 <the-verified-64-character-sha256> \
  --health-coordinate <longitude,latitude>
```

The import procedure:

1. rejects links, oversized or changing input and verifies the supplied SHA-256 after a no-follow snapshot;
2. runs `osrm-extract`, `osrm-partition` and `osrm-customize` as the isolated `dis-osrm-build` account in
   hardened, resource-limited transient systemd units;
3. rejects links, hard links and special parser output, descriptor-freezes the complete staging tree as
   root-owned/read-only, then writes hashes, probe and manifest through exclusive no-follow temporary files,
   fsync and atomic rename before revalidating the tree;
4. atomically switches the `current` dataset pointer;
5. starts the loopback-only service and probes the supplied coordinate;
6. automatically restores the previous dataset pointer if readiness fails.

Before either dataset pointer changes, DIS writes and fsyncs a root-owned activation marker containing both
prior pointer values and the importing process identity. The marker is removed only after readiness succeeds.
After SIGKILL or reboot, a stale marker is recovered before normal serving: a privileged reconcile restores
both pointers, while the unprivileged service fails closed to the last committed release until that repair can
complete. Status output derives its dataset SHA at read time from the release that the service can effectively
serve. It therefore reports the restored fallback during interrupted activation and the new active manifest
even if a crash occurred after the activation marker was cleared but before the durable status file was refreshed.

Imports require enough free space for eight times the PBF size plus 2 GiB. Defaults cap the PBF at 50 GiB,
the import at 12 GiB RAM and 400% CPU, and each preprocessing stage at six hours. Override the documented
`OSRM_IMPORT_*` variables for one root invocation only when server sizing and the extract justify it. The
long-running import holds the DIS exclusive-operation lock so application deployment, restore and another
import cannot mutate shared operational state concurrently.

Generated releases are retained under `/opt/dis-data/osrm/releases` for deliberate rollback. They are not
included in DIS backups because they are reproducible from the controlled PBF. The bounded root retention
described above removes inactive releases automatically while protecting `current`, `previous` and pending targets.
If its best-effort post-commit pass warns, run
`sudo /usr/local/lib/dis/osrm-admin/osrm.sh prune` during planned maintenance.

## Application configuration

Configure the backend in `/opt/dis-data/.env` after the local service is healthy:

```dotenv
ROUTING_ENABLED=true
ROUTING_PROVIDER=osrm
OSRM_BASE_URL=http://127.0.0.1:5000
OSRM_ALLOWED_HOSTS=127.0.0.1,localhost,::1
OSRM_PROFILE=driving
```

Keep `OSRM_BASE_URL` on loopback. The backend refuses hosts outside the exact `OSRM_ALLOWED_HOSTS` list; add a
non-loopback hostname only for a deliberately managed multi-server router and protect that connection in the
infrastructure. The remaining routing timeout, cache, failure-cache, batching and fallback settings are documented
by `.env.example`. Apply changed Laravel configuration with the normal DIS deploy.
OSRM uses road speeds encoded in the chosen OSM extract and car profile; it does not include live traffic.

## Operations

```bash
sudo /usr/local/lib/dis/osrm-admin/osrm.sh status
sudo /usr/local/lib/dis/osrm-admin/osrm.sh publish-status
sudo /usr/local/lib/dis/osrm-admin/osrm.sh health
sudo /usr/local/lib/dis/osrm-admin/osrm.sh verify
sudo journalctl -u dis-osrm.service
sudo journalctl -u dis-osrm-admin-request.service
```

`status` derives package provenance, provisioning, active dataset metadata, service state and current health.
`publish-status` atomically refreshes the bounded browser-readable snapshot at `/var/log/dis/osrm-status.json`.
The state is `ready` only after a successful local probe; missing binaries, a missing dataset or readiness failure
is reported as `not_installed`, `installed_inactive` or `degraded`. An OSRM failure
does not stop an alarm or the DIS web tier: the backend uses its explicit fallback estimate.

Normal DIS uninstall keeps `/opt/dis-data`, including generated OSRM releases. While that data exists, the
dedicated numeric service identities are retained as well so their group ownership and narrow data-root ACL
cannot silently be reassigned to unrelated future accounts. Remove or archive the generated data under an
approved maintenance procedure before intentionally removing those identities.

The service defaults to two OSRM threads, 8 GiB maximum memory and 300% CPU. If a larger approved extract
requires different limits, create a systemd drop-in with `systemctl edit dis-osrm.service`, review the server
capacity, run `systemctl daemon-reload`, restart the service and repeat both `health` and `verify`.
