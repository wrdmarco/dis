#!/usr/bin/env python3
"""Publish a bounded, content-free DIS data-directory usage snapshot."""

from __future__ import annotations

import datetime as dt
import errno
import json
import os
import secrets
import stat
import sys
from collections.abc import Iterable
from typing import Final


DATA_ROOT: Final = "/opt/dis-data"
OUTPUT_FILE: Final = "/var/lib/dis-system-metrics/storage-usage.json"

# These are stable public identifiers, not names discovered from the filesystem.
# The secrets directory and every unmanaged direct child are deliberately absent.
DIRECTORIES: Final[tuple[tuple[str, str], ...]] = (
    ("backup", "backup"),
    ("backup-imports", "backup-imports"),
    ("backup-requests", "backup-requests"),
    ("backup-request-work", "backup-request-work"),
    ("legacy-backup-state", "legacy-backup-state"),
    ("osrm", "osrm"),
    ("osrm-admin", "osrm-admin"),
    ("playwright-browsers", "playwright-browsers"),
    ("storage", "storage"),
    ("webapp", "webapp"),
)

MAX_ENTRIES_PER_DIRECTORY: Final = 1_000_000
MAX_DEPTH: Final = 128
MAX_SAFE_JSON_INTEGER: Final = 9_007_199_254_740_991
MAX_OUTPUT_BYTES: Final = 16 * 1024
DIRECTORY_FLAGS: Final = (
    os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
)


class SnapshotError(RuntimeError):
    """A deliberately detail-free snapshot failure."""


def _normalized_components(path: str) -> list[str]:
    if not path.startswith("/") or "\x00" in path:
        raise SnapshotError

    normalized = os.path.normpath(path)
    if normalized != path.rstrip("/") and not (normalized == "/" and path == "/"):
        raise SnapshotError

    return [component for component in normalized.split("/") if component]


def _require_root_controlled(descriptor: int) -> None:
    metadata = os.fstat(descriptor)
    if metadata.st_uid != 0 or stat.S_IMODE(metadata.st_mode) & 0o022:
        raise SnapshotError


def _open_directory_chain(path: str, *, enforce_root_controlled: bool) -> int:
    descriptor = os.open("/", DIRECTORY_FLAGS)
    try:
        if enforce_root_controlled:
            _require_root_controlled(descriptor)

        for component in _normalized_components(path):
            child = os.open(component, DIRECTORY_FLAGS, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = child
            if enforce_root_controlled:
                _require_root_controlled(descriptor)

        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def _allocated_bytes(metadata: os.stat_result) -> int:
    blocks = metadata.st_blocks
    if not isinstance(blocks, int) or blocks < 0:
        raise SnapshotError

    value = blocks * 512
    if value < 0 or value > MAX_SAFE_JSON_INTEGER:
        raise SnapshotError

    return value


def _checked_add(total: int, value: int) -> int:
    result = total + value
    if result < total or result > MAX_SAFE_JSON_INTEGER:
        raise SnapshotError

    return result


def _entry_names(descriptor: int) -> Iterable[str]:
    with os.scandir(descriptor) as entries:
        for entry in entries:
            yield entry.name


def _transient_entry_error(error: OSError) -> bool:
    return error.errno in {
        errno.ENOENT,
        errno.ENOTDIR,
        errno.ELOOP,
        getattr(errno, "ESTALE", -1),
    }


def _entry_metadata(descriptor: int, name: str) -> os.stat_result | None:
    try:
        return os.stat(name, dir_fd=descriptor, follow_symlinks=False)
    except OSError as error:
        if _transient_entry_error(error):
            return None
        raise


def _open_child_directory(
    descriptor: int,
    name: str,
    metadata: os.stat_result,
    *,
    expected_device: int,
) -> int | None:
    try:
        child = os.open(name, DIRECTORY_FLAGS, dir_fd=descriptor)
    except OSError as error:
        if _transient_entry_error(error):
            return None
        raise

    try:
        opened = os.fstat(child)
    except BaseException:
        os.close(child)
        raise

    if (
        not stat.S_ISDIR(opened.st_mode)
        or opened.st_dev != expected_device
        or opened.st_dev != metadata.st_dev
        or opened.st_ino != metadata.st_ino
    ):
        os.close(child)
        return None

    return child


def _walk_directory(
    descriptor: int,
    *,
    expected_device: int,
    seen: set[tuple[int, int]],
    entries_seen: list[int],
    depth: int,
) -> int:
    total = 0
    for name in _entry_names(descriptor):
        entries_seen[0] += 1
        if entries_seen[0] > MAX_ENTRIES_PER_DIRECTORY:
            raise SnapshotError

        metadata = _entry_metadata(descriptor, name)
        if metadata is None:
            continue

        mode = metadata.st_mode
        if stat.S_ISLNK(mode) or metadata.st_dev != expected_device:
            continue

        identity = (metadata.st_dev, metadata.st_ino)
        if identity in seen:
            continue

        if stat.S_ISDIR(mode):
            if depth >= MAX_DEPTH:
                raise SnapshotError

            child = _open_child_directory(
                descriptor,
                name,
                metadata,
                expected_device=expected_device,
            )
            if child is None:
                continue

            try:
                child_metadata = os.fstat(child)
                child_bytes = _allocated_bytes(child_metadata)
                seen.add(identity)
                total = _checked_add(total, child_bytes)
                total = _checked_add(
                    total,
                    _walk_directory(
                        child,
                        expected_device=expected_device,
                        seen=seen,
                        entries_seen=entries_seen,
                        depth=depth + 1,
                    ),
                )
            finally:
                os.close(child)
        elif stat.S_ISREG(mode):
            seen.add(identity)
            total = _checked_add(total, _allocated_bytes(metadata))
        # Symlinks, devices, sockets and FIFOs are never opened or counted.

    return total


def _directory_allocated_bytes(
    descriptor: int,
    *,
    expected_device: int,
) -> int:
    root_metadata = os.fstat(descriptor)
    if not stat.S_ISDIR(root_metadata.st_mode) or root_metadata.st_dev != expected_device:
        raise SnapshotError

    root_bytes = _allocated_bytes(root_metadata)
    seen = {(root_metadata.st_dev, root_metadata.st_ino)}

    return _checked_add(
        root_bytes,
        _walk_directory(
            descriptor,
            expected_device=expected_device,
            seen=seen,
            entries_seen=[0],
            depth=0,
        ),
    )


def build_snapshot(
    data_root: str = DATA_ROOT,
    *,
    enforce_root_controlled: bool = True,
) -> dict[str, object]:
    root = _open_directory_chain(
        data_root,
        enforce_root_controlled=enforce_root_controlled,
    )
    try:
        root_metadata = os.fstat(root)
        directories: dict[str, int] = {}

        for key, directory_name in DIRECTORIES:
            metadata = _entry_metadata(root, directory_name)
            if metadata is None:
                continue

            if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
                raise SnapshotError
            if metadata.st_dev != root_metadata.st_dev:
                raise SnapshotError

            child = _open_child_directory(
                root,
                directory_name,
                metadata,
                expected_device=root_metadata.st_dev,
            )
            if child is None:
                latest = _entry_metadata(root, directory_name)
                if latest is not None and stat.S_ISLNK(latest.st_mode):
                    raise SnapshotError
                continue
            try:
                directories[key] = _directory_allocated_bytes(
                    child,
                    expected_device=root_metadata.st_dev,
                )
            finally:
                os.close(child)
    finally:
        os.close(root)

    generated_at = (
        dt.datetime.now(dt.timezone.utc)
        .isoformat(timespec="seconds")
        .replace("+00:00", "Z")
    )

    return {
        "version": 1,
        "generated_at": generated_at,
        "directories": directories,
    }


def _validate_existing_output(
    directory_descriptor: int,
    filename: str,
    *,
    enforce_root_controlled: bool,
) -> None:
    try:
        metadata = os.stat(filename, dir_fd=directory_descriptor, follow_symlinks=False)
    except FileNotFoundError:
        return

    if (
        not stat.S_ISREG(metadata.st_mode)
        or metadata.st_nlink != 1
        or stat.S_IMODE(metadata.st_mode) & 0o022
        or (enforce_root_controlled and metadata.st_uid != 0)
    ):
        raise SnapshotError


def publish_snapshot(
    snapshot: dict[str, object],
    output_file: str = OUTPUT_FILE,
    *,
    enforce_root_controlled: bool = True,
) -> None:
    encoded = json.dumps(
        snapshot,
        ensure_ascii=True,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("ascii")
    if not encoded or len(encoded) > MAX_OUTPUT_BYTES:
        raise SnapshotError

    output_directory, filename = os.path.split(output_file)
    if not filename or filename != os.path.basename(filename):
        raise SnapshotError

    directory = _open_directory_chain(
        output_directory,
        enforce_root_controlled=enforce_root_controlled,
    )
    temporary_name = f".{filename}.{secrets.token_hex(16)}.tmp"
    temporary: int | None = None
    installed = False
    try:
        _validate_existing_output(
            directory,
            filename,
            enforce_root_controlled=enforce_root_controlled,
        )
        temporary = os.open(
            temporary_name,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
            0o600,
            dir_fd=directory,
        )

        view = memoryview(encoded)
        while view:
            written = os.write(temporary, view)
            if written < 1:
                raise SnapshotError
            view = view[written:]

        if enforce_root_controlled:
            if os.geteuid() != 0 or os.getegid() == 0:
                raise SnapshotError
            created = os.fstat(temporary)
            if created.st_uid != 0 or created.st_gid != os.getegid():
                raise SnapshotError
        os.fchmod(temporary, 0o640)
        os.fsync(temporary)
        os.close(temporary)
        temporary = None

        os.replace(
            temporary_name,
            filename,
            src_dir_fd=directory,
            dst_dir_fd=directory,
        )
        installed = True
        os.fsync(directory)
    finally:
        if temporary is not None:
            os.close(temporary)
        if not installed:
            try:
                os.unlink(temporary_name, dir_fd=directory)
            except FileNotFoundError:
                pass
        os.close(directory)


def collect_snapshot(
    data_root: str = DATA_ROOT,
    output_file: str = OUTPUT_FILE,
    *,
    enforce_root_controlled: bool = True,
) -> dict[str, object]:
    snapshot = build_snapshot(
        data_root,
        enforce_root_controlled=enforce_root_controlled,
    )
    publish_snapshot(
        snapshot,
        output_file,
        enforce_root_controlled=enforce_root_controlled,
    )

    return snapshot


def main() -> int:
    try:
        if len(sys.argv) != 1 or os.geteuid() != 0:
            raise SnapshotError
        collect_snapshot()
    except Exception:
        # Never emit a filesystem path or an exception that may contain a name.
        print("[dis:error] storage usage snapshot unavailable", file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
