# Local OSRM routing

DIS can calculate road-network ETA values through a self-hosted OSRM backend. The service is optional and
listens only on `127.0.0.1:5000`; it is never proxied by Nginx and the deployment does not use the public OSRM
demo server. Runtime logging is limited to warnings and errors so normal routing requests containing location
coordinates are not intentionally written to the service journal.

## Container runtime availability

Ubuntu 26.04 does not currently expose an installable `osrm-backend` candidate in its configured official archive.
DIS therefore runs the official Project OSRM container with rootful Podman. Podman itself is accepted only from an
already configured Ubuntu 26.04 `resolute`, `resolute-updates` or `resolute-security` main/universe pocket on an
official Ubuntu archive host. Installation uses a temporary source list derived from those entries and the
root-controlled `ubuntu-keyring`; an existing unattested Podman package is reinstalled through that restricted
source list.

The OSRM runtime is fixed in source to `v26.5.0-amd64-debian` and pulled by the immutable amd64 manifest digest
`sha256:51299b2a506807dc0ed7d3afcd5f04d9754ece85e9dd39a35669c2b4904304f2`. Mutable tags, foreign APT
repositories, locally supplied executables and public routing services are not accepted. DIS verifies the digest,
architecture, operating system and upstream OCI source, version, revision and license labels before use.

The Podman version and package-file fingerprint, container digest and image ID, and the bundled `/opt/car.lua`
profile fingerprint are recorded in `/opt/dis-data/osrm/package-provenance.json`. At service start DIS checks the
current runtime against that protected receipt. Podman is APT-held and every container invocation uses
`--pull=never`, so normal DIS updates and **Kaartdata bijwerken** cannot silently change the runtime. A future OSRM
image upgrade is a separate reviewed code change that must update the digest and tests.

The receipt covers the Podman package and selected container content, not the complete host operating-system
dependency closure. Normal host repository policy remains responsible for shared dependencies. Do not enable
unapproved PPAs, foreign suites, mirrors or Debian repositories on a DIS host.

The composite NL+BE build additionally uses Ubuntu's `osmium-tool`. It has its own official-archive selection,
installed-file fingerprint, protected receipt (`build-tool-provenance.json`) and APT hold. It is deliberately not
part of `osrm_tools_available`: a missing or damaged build tool blocks a new map operation, but deploying DIS code
cannot make an already attested and active OSRM runtime appear unhealthy. The root worker installs and verifies
this dependency before any map download starts.

Run this after enabling an appropriate, organisation-approved Ubuntu archive component and outbound HTTPS access
to `ghcr.io` if the runtime is not yet installed:

```bash
sudo apt-get update
sudo /usr/local/lib/dis/osrm-admin/osrm.sh install-package
```

On a fresh host without an official Podman candidate, or when the pinned image cannot be retrieved and verified,
OSRM remains explicitly degraded and DIS continues to use its bounded fallback ETA. A previously attested runtime
remains usable when repository or registry access is temporarily unavailable because normal operation never pulls.
Do not substitute another image, mutable tag, public endpoint or package from a different distribution release.

## Admin installation and map updates

An authorised administrator can install and activate OSRM from the dedicated **Routering** admin page. The browser
request normally supplies only the operation action; it cannot choose a URL, checksum, readiness coordinate, file
path, shell option or OSRM profile for root. A legacy coordinate field from an older admin client is accepted only
for upgrade compatibility and ignored. DIS stores its fixed Dutch readiness coordinate (`5.1214,52.0907`) in the
immutable database operation record consumed by the privileged worker.

The accepted source set is fixed in code, ordered and cannot be overridden from `.env` or the browser:

```text
https://download.geofabrik.de/europe/netherlands-latest.osm.pbf
https://download.geofabrik.de/europe/belgium-latest.osm.pbf
```

For every operation the worker performs a non-following `HEAD` request to both `latest` URLs. Each must return one
exact HTTPS `302` on `download.geofabrik.de` to its country-specific `country-YYMMDD.osm.pbf` filename, without user
information, a port, query, fragment or another path. DIS selects the oldest date advertised by both rolling URLs,
constructs both dated URLs for that common snapshot and requires `HEAD 200` plus a bounded size from each. It then
downloads each version-specific `.md5` sidecar and exact PBF with redirects disabled. Every sidecar must contain one
bounded `md5sum` line for its own dated filename, and each PBF must match its supplier MD5.

The separately attested `/usr/bin/osmium` reads each verified PBF's `osmosis_replication_timestamp`. Both timestamps
must be valid whole-second UTC timestamps, equal each other and have the same calendar date as the common snapshot.
Only then does a network-isolated, resource-limited transient unit merge the two files as `dis-osrm-build`. Root
computes an internal SHA-256 over the merged PBF, removes the two component downloads and passes the merged file plus
a strict root-owned source manifest to the normal OSRM import. The manifest records the fixed source-set identity,
snapshot date, common source timestamp and, for both countries, the exact filename, dated URL, supplier MD5 and byte
size. This supplier provenance is separate from the internal merged-file SHA-256.

The worker rejects HTTP, any unexpected redirect, non-public DNS results, DNS rebinding, oversized or partial
responses, insufficient disk space, a supplier MD5 mismatch, timestamp mismatch or malformed composite manifest.
DNS is checked before its public address is pinned into curl while normal TLS verification remains mandatory. All
downloads must be regular, non-symlink, single-link files owned by the isolated build account before root consumes
them. Disk preflight requires `combined-source-bytes * (OSRM_IMPORT_DISK_FACTOR + 1) + reserve`; with the defaults,
two billion input bytes require 20,147,483,648 bytes. The merged PBF is separately capped at 5 GiB by
`OSRM_ADMIN_MAX_COMBINED_PBF_BYTES` (hard maximum 20 GiB) and by a source-relative bound. The import repeats its
check against the actual merged size.

Installation and activation are one operation: DIS verifies or installs restricted Podman, the pinned OSRM image and the build package,
provisions the service, verifies both suppliers, merges and preprocesses the common snapshot, atomically activates
it, and runs artifact and route-readiness checks. A composite release stores the server-controlled Dutch probe and
a second root-controlled Belgian probe. The Belgian probe defaults to central Brussels (`4.3517,50.8503`) and
may only be changed through the root service environment variable `OSRM_BELGIUM_HEALTH_COORDINATE`; both probes use
the bounded `OSRM_HEALTH_MAX_SNAP_METERS`. Browser input never controls the Belgian probe. Only after both probes
pass does the backend mark routing enabled.

Once healthy, the admin page offers only **Kaartdata bijwerken**. A source manifest that exactly matches the active
NL+BE manifest, together with the same Dutch probe and two successful live checks, completes as a no-op health
recheck. Any supplier MD5, size, snapshot, timestamp or provenance difference prepares a new release and retains the
old one for automatic rollback. Crash recovery likewise requires the root marker's complete source manifest,
immutable database payload, stored probe, active status, artifact verification and both live probes to match.
Legacy SHA-256-only releases remain readable, healthy and updateable; their next deliberate update migrates them to
the composite source manifest and dual-probe contract.

A map-data update never pulls or upgrades the OSRM image. It keeps the exact already verified and healthy container
so a failed import can safely retain or restore the previous dataset. Runtime installation is restricted to the
initial **Installeren en activeren** operation; normal DIS deploys and system updates do not install or upgrade OSRM
behind the administrator's back.

After provenance verification, DIS places `podman` and `osmium-tool` on separate APT holds. This prevents a general
host upgrade from silently changing the runtime or merge tool and invalidating the protected receipts. The worker
verifies both holds before use. A normal DIS uninstall removes DIS-managed holds while retaining packages and route
data. Even `--purge-packages` leaves the host-wide Podman runtime installed so DIS cannot break unrelated containers;
removing Podman is a separate infrastructure decision.

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
container-runtime installation, provisioning, merge, extract, partition, customize, activation, verification and configuration.
An interrupted import is coupled to the broker systemd unit; subsequent recovery reconciles the durable activation
marker and releases the backend operation lock. A transient parser has `PartOf` and `BindsTo` coupling to that
broker but deliberately no `After` ordering on the waiting oneshot parent. If interruption occurs after a new
release was committed but before the success snapshot was written, recovery reloads the immutable database payload
and requires the root-recorded composite source manifest and exact probe to match the active status, plus artifact
verification and both live health probes, before recording success. A temporary PHP/database outage preserves the work marker and
status unchanged for the next timer retry;
only a loaded contract with a definitive runtime mismatch is failed closed. The normal DIS application update only refreshes code, runtime
integration and current-service state. It never silently downloads or imports new map data.

## Manual dataset import

A root operator can still import one already prepared `.osm.pbf` from a controlled source, validate its licensing
and update policy, and supply its independently obtained SHA-256. This emergency/manual path intentionally creates
a legacy SHA-256 release with one operator-selected readiness probe; only the privileged admin broker can create the
strict NL+BE supplier manifest and dual-probe release. Keep the original source outside `/opt/dis-data/osrm`;
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
2. runs the pinned container's `osrm-extract`, `osrm-partition` and `osrm-customize` as the numeric isolated
   `dis-osrm-build` identity in networkless, capability-free, read-only, resource-limited transient systemd units;
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

`status` derives container-runtime provenance, provisioning, active dataset metadata, service state and current health.
`publish-status` atomically refreshes the bounded browser-readable snapshot at `/var/log/dis/osrm-status.json`.
The state is `ready` only after a successful local probe; a missing verified runtime, missing dataset or readiness failure
is reported as `not_installed`, `installed_inactive` or `degraded`. An OSRM failure
does not stop an alarm or the DIS web tier: the backend uses its explicit fallback estimate.

Normal DIS uninstall keeps `/opt/dis-data`, including generated OSRM releases. While that data exists, the
dedicated numeric service identities are retained as well so their group ownership and narrow data-root ACL
cannot silently be reassigned to unrelated future accounts. Remove or archive the generated data under an
approved maintenance procedure before intentionally removing those identities.

The service defaults to two OSRM threads, 8 GiB maximum memory and 300% CPU. If a larger approved extract
requires different limits, create a systemd drop-in with `systemctl edit dis-osrm.service`, review the server
capacity, run `systemctl daemon-reload`, restart the service and repeat both `health` and `verify`.
