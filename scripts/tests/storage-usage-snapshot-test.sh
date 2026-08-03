#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
COLLECTOR="${APP_ROOT}/scripts/storage-usage-snapshot.py"
SERVICE="${APP_ROOT}/infrastructure/systemd/dis-storage-metrics.service"
TIMER="${APP_ROOT}/infrastructure/systemd/dis-storage-metrics.timer"
DEPLOY="${APP_ROOT}/scripts/deploy.sh"
UNINSTALL="${APP_ROOT}/scripts/uninstall.sh"
README="${APP_ROOT}/README.md"

require_text() {
  local file="$1" value="$2"
  grep -Fq -- "${value}" "${file}" || {
    printf 'Missing storage-usage contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  }
}

reject_text() {
  local file="$1" value="$2"
  if grep -Fq -- "${value}" "${file}"; then
    printf 'Forbidden storage-usage contract in %s: %s\n' "${file}" "${value}" >&2
    exit 1
  fi
}

require_text "${COLLECTOR}" 'DATA_ROOT: Final = "/opt/dis-data"'
require_text "${COLLECTOR}" 'OUTPUT_FILE: Final = "/var/lib/dis-system-metrics/storage-usage.json"'
require_text "${COLLECTOR}" 'os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC'
require_text "${COLLECTOR}" 'follow_symlinks=False'
require_text "${COLLECTOR}" 'metadata.st_dev != expected_device'
require_text "${COLLECTOR}" '_walk_directory('
require_text "${COLLECTOR}" 'if _transient_entry_error(error):'
require_text "${COLLECTOR}" '"version": 1'
require_text "${COLLECTOR}" '"directories": directories'
require_text "${COLLECTOR}" 'storage usage snapshot unavailable'
reject_text "${COLLECTOR}" '("secrets", "secrets")'
for directory_name in \
  backup \
  backup-imports \
  backup-requests \
  backup-request-work \
  legacy-backup-state \
  osrm \
  osrm-admin \
  playwright-browsers \
  storage \
  webapp; do
  require_text "${COLLECTOR}" "(\"${directory_name}\", \"${directory_name}\")"
done
reject_text "${COLLECTOR}" '("backup_imports", "backup-imports")'
reject_text "${COLLECTOR}" '("backup_requests", "backup-requests")'
reject_text "${COLLECTOR}" '("backup_request_work", "backup-request-work")'
reject_text "${COLLECTOR}" '("osrm_admin", "osrm-admin")'
reject_text "${COLLECTOR}" '("playwright_browsers", "playwright-browsers")'
reject_text "${COLLECTOR}" 'stack.append('

for contract in \
  'User=root' \
  'Group=www-data' \
  'ExecStart=/usr/bin/python3 -I -S /usr/local/bin/dis-storage-usage-snapshot' \
  'StateDirectory=dis-system-metrics' \
  'StateDirectoryMode=0750' \
  'NoNewPrivileges=true' \
  'PrivateNetwork=true' \
  'PrivateDevices=true' \
  'ProtectSystem=strict' \
  'ProtectHome=true' \
  'ProtectProc=invisible' \
  'RestrictNamespaces=true' \
  'CapabilityBoundingSet=CAP_DAC_READ_SEARCH' \
  'ReadOnlyPaths=/opt/dis-data /opt/dis' \
  'InaccessiblePaths=/opt/dis-data/secrets /opt/dis-data/.env' \
  'ReadWritePaths=/var/lib/dis-system-metrics' \
  'MemoryMax=192M' \
  'CPUQuota=25%' \
  'TasksMax=16' \
  'LimitNOFILE=512' \
  'IOSchedulingClass=idle'; do
  require_text "${SERVICE}" "${contract}"
done

require_text "${TIMER}" 'OnActiveSec=1min'
require_text "${TIMER}" 'OnUnitActiveSec=1h'
reject_text "${TIMER}" '15min'
require_text "${DEPLOY}" 'scripts/storage-usage-snapshot.py" /usr/local/bin/dis-storage-usage-snapshot'
require_text "${DEPLOY}" 'infrastructure/systemd/dis-storage-metrics.service'
require_text "${DEPLOY}" 'infrastructure/systemd/dis-storage-metrics.timer'
require_text "${DEPLOY}" 'dis-storage-metrics.timer'
require_text "${DEPLOY}" 'systemctl start dis-storage-metrics.timer'
require_text "${UNINSTALL}" '/etc/systemd/system/dis-storage-metrics.service'
require_text "${UNINSTALL}" '/etc/systemd/system/dis-storage-metrics.timer'
require_text "${UNINSTALL}" '/usr/local/bin/dis-storage-usage-snapshot'
require_text "${UNINSTALL}" 'secure_path_operation remove-tree /var/lib/dis-system-metrics'
require_text "${README}" 'dis-storage-metrics.timer'
require_text "${README}" 'meet elk uur de toegewezen schijfblokken'

if [ "$(uname -s)" != "Linux" ]; then
  printf 'SKIP: descriptor-walk runtime test requires Linux.\n'
  printf 'Sanitized storage-usage snapshot contract passed.\n'
  exit 0
fi

TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-storage-usage-test.XXXXXX")"
trap 'rm -rf -- "${TEST_ROOT}"' EXIT

python3 - "${COLLECTOR}" "${TEST_ROOT}" <<'PY'
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import sys

collector_path = Path(sys.argv[1])
test_root = Path(sys.argv[2])
spec = importlib.util.spec_from_file_location("dis_storage_usage_snapshot", collector_path)
assert spec is not None and spec.loader is not None
collector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(collector)

data_root = test_root / "data"
output_root = test_root / "output"
outside_root = test_root / "outside"
for path in (data_root, output_root, outside_root):
    path.mkdir()
approved_directories = (
    "backup",
    "backup-imports",
    "backup-requests",
    "backup-request-work",
    "legacy-backup-state",
    "osrm",
    "osrm-admin",
    "playwright-browsers",
    "storage",
    "webapp",
)
assert collector.DIRECTORIES == tuple((name, name) for name in approved_directories)
for name in (*approved_directories, "secrets", "customer-private-name"):
    (data_root / name).mkdir()

for name in approved_directories:
    (data_root / name / "allocated.bin").write_bytes(b"b" * 32_768)
(data_root / "storage" / "storage.bin").write_bytes(b"s" * 65_536)
(data_root / "secrets" / "must-not-be-published.key").write_bytes(b"x" * 1_048_576)
(data_root / "customer-private-name" / "private.bin").write_bytes(b"x" * 1_048_576)
(outside_root / "outside.bin").write_bytes(b"o" * 1_048_576)
os.symlink(outside_root, data_root / "storage" / "outside-link", target_is_directory=True)
if hasattr(os, "mkfifo"):
    os.mkfifo(data_root / "storage" / "ignored-fifo")

# A file disappearing after readdir is an ordinary live-cache race, not a
# reason to discard the entire measurement.
racing_file = data_root / "storage" / "vanishing-after-readdir.bin"
racing_file.write_bytes(b"r" * 4096)
original_entry_metadata = collector._entry_metadata
race_observed = {"value": False}

def racing_entry_metadata(descriptor: int, name: str):
    if name == racing_file.name and not race_observed["value"]:
        race_observed["value"] = True
        racing_file.unlink()
    return original_entry_metadata(descriptor, name)

collector._entry_metadata = racing_entry_metadata
try:
    raced = collector.build_snapshot(str(data_root), enforce_root_controlled=False)
finally:
    collector._entry_metadata = original_entry_metadata
assert race_observed["value"]
assert "storage" in raced["directories"]

# Hundreds of shallow sibling directories must not remain open together.
wide_root = data_root / "storage" / "wide"
wide_root.mkdir()
for index in range(256):
    (wide_root / f"sibling-{index:03d}").mkdir()
original_open_child = collector._open_child_directory
maximum_open_descriptors = {"value": 0}

def tracking_open_child(*args, **kwargs):
    child = original_open_child(*args, **kwargs)
    maximum_open_descriptors["value"] = max(
        maximum_open_descriptors["value"],
        len(os.listdir("/proc/self/fd")),
    )
    return child

collector._open_child_directory = tracking_open_child
try:
    collector.build_snapshot(str(data_root), enforce_root_controlled=False)
finally:
    collector._open_child_directory = original_open_child
assert maximum_open_descriptors["value"] < 64

output_file = output_root / "storage-usage.json"
first = collector.collect_snapshot(
    str(data_root),
    str(output_file),
    enforce_root_controlled=False,
)
decoded = json.loads(output_file.read_text(encoding="ascii"))
assert decoded == first
assert set(decoded) == {"version", "generated_at", "directories"}
assert decoded["version"] == 1
assert re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", decoded["generated_at"])
assert set(decoded["directories"]) == set(approved_directories)
assert all(isinstance(value, int) and value >= 0 for value in decoded["directories"].values())
assert "secrets" not in output_file.read_text(encoding="ascii")
assert "customer-private-name" not in output_file.read_text(encoding="ascii")
assert stat.S_IMODE(output_file.stat().st_mode) == 0o640

first_storage_size = decoded["directories"]["storage"]
with (outside_root / "outside.bin").open("ab") as outside:
    outside.write(b"o" * 2_097_152)
second = collector.collect_snapshot(
    str(data_root),
    str(output_file),
    enforce_root_controlled=False,
)
assert second["directories"]["storage"] == first_storage_size

previous_output = output_file.read_bytes()
for child in (data_root / "osrm").iterdir():
    child.unlink()
(data_root / "osrm").rmdir()
os.symlink(outside_root, data_root / "osrm", target_is_directory=True)
try:
    collector.collect_snapshot(
        str(data_root),
        str(output_file),
        enforce_root_controlled=False,
    )
except collector.SnapshotError:
    pass
else:
    raise AssertionError("an allowlisted top-level symlink was accepted")
assert output_file.read_bytes() == previous_output
(data_root / "osrm").unlink()

collector.MAX_ENTRIES_PER_DIRECTORY = 1
try:
    collector.collect_snapshot(
        str(data_root),
        str(output_file),
        enforce_root_controlled=False,
    )
except collector.SnapshotError:
    pass
else:
    raise AssertionError("the entry budget was not enforced")
assert output_file.read_bytes() == previous_output

outside_output = test_root / "outside-output.json"
outside_output.write_text("unchanged", encoding="ascii")
linked_output = output_root / "linked-output.json"
os.symlink(outside_output, linked_output)
try:
    collector.publish_snapshot(
        first,
        str(linked_output),
        enforce_root_controlled=False,
    )
except collector.SnapshotError:
    pass
else:
    raise AssertionError("a symbolic output path was accepted")
assert outside_output.read_text(encoding="ascii") == "unchanged"
PY

set +e
ERROR_OUTPUT="$(python3 "${COLLECTOR}" unexpected-argument 2>&1)"
ERROR_STATUS=$?
set -e
[ "${ERROR_STATUS}" -eq 1 ]
[ "${ERROR_OUTPUT}" = '[dis:error] storage usage snapshot unavailable' ]

printf 'Sanitized storage-usage snapshot contract passed.\n'
