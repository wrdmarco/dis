#!/usr/bin/env python3
"""Extract regular-file-only backup archives into a protected empty directory."""

from __future__ import annotations

import argparse
import os
from pathlib import PurePosixPath
import shutil
import stat
import sys
import tarfile


class ExtractionError(RuntimeError):
    pass


def normalize_member(name: str) -> str:
    while name.startswith("./"):
        name = name[2:]
    name = name.rstrip("/")
    if name in {"", "."}:
        return ""
    if "\\" in name or any(ord(character) < 32 or ord(character) == 127 for character in name):
        raise ExtractionError("archive member contains a control character or backslash")
    path = PurePosixPath(name)
    if path.is_absolute() or any(part in {"", ".", ".."} for part in path.parts):
        raise ExtractionError(f"archive member has an unsafe path: {name}")
    return path.as_posix()


def protected_empty_directory(path: str) -> None:
    normalized = os.path.normpath(path)
    if not os.path.isabs(path) or normalized != path.rstrip("/"):
        raise ExtractionError("destination path must be normalized and absolute")
    if os.path.realpath(path) != normalized:
        raise ExtractionError("destination path may not contain symbolic links")
    metadata = os.stat(path, follow_symlinks=False)
    if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != 0:
        raise ExtractionError("destination must be a root-owned directory")
    if stat.S_IMODE(metadata.st_mode) & 0o077:
        raise ExtractionError("destination must not be accessible to group or other users")
    if os.listdir(path):
        raise ExtractionError("destination must be empty")


def allowed_member(path: str, roots: list[str]) -> bool:
    if not roots:
        return True
    return any(path == root or path.startswith(f"{root}/") for root in roots)


def extract_archive(
    archive_path: str,
    destination: str,
    maximum_bytes: int,
    allowed_roots: list[str],
    *,
    validate_only: bool,
) -> None:
    protected_empty_directory(destination)
    normalized_roots = [normalize_member(root) for root in allowed_roots]
    seen: set[str] = set()
    files: set[str] = set()
    directories: set[str] = set()
    members: list[tuple[tarfile.TarInfo, str]] = []
    total_size = 0

    with tarfile.open(archive_path, mode="r:*") as archive:
        for index, member in enumerate(archive):
            if index >= 1_000_000:
                raise ExtractionError("archive contains too many entries")
            normalized = normalize_member(member.name)
            if normalized == "":
                if not member.isdir():
                    raise ExtractionError("archive root entry is not a directory")
                continue
            if normalized in seen:
                raise ExtractionError(f"archive contains a duplicate member: {normalized}")
            if not allowed_member(normalized, normalized_roots):
                raise ExtractionError(f"archive member is outside the allowed roots: {normalized}")
            if not (member.isdir() or member.isreg()):
                raise ExtractionError(f"archive contains a link or special entry: {normalized}")

            seen.add(normalized)
            members.append((member, normalized))
            if member.isdir():
                directories.add(normalized)
            else:
                if member.size < 0:
                    raise ExtractionError(f"archive member has an invalid size: {normalized}")
                total_size += member.size
                if total_size > maximum_bytes:
                    raise ExtractionError("archive expands beyond the configured restore limit")
                files.add(normalized)

        for path in seen:
            parent = PurePosixPath(path).parent
            while parent.as_posix() not in {"", "."}:
                if parent.as_posix() in files:
                    raise ExtractionError(f"regular file is used as a parent path: {parent}")
                parent = parent.parent

        if validate_only:
            return

        disk = shutil.disk_usage(destination)
        reserve = min(max(disk.total // 20, 1024**3), 10 * 1024**3)
        if total_size + reserve > disk.free:
            raise ExtractionError("insufficient protected scratch space for archive extraction")

        for directory in sorted(directories, key=lambda item: (item.count("/"), item)):
            os.makedirs(os.path.join(destination, *PurePosixPath(directory).parts), mode=0o700, exist_ok=True)

        for member, normalized in members:
            if member.isdir():
                continue
            target = os.path.join(destination, *PurePosixPath(normalized).parts)
            os.makedirs(os.path.dirname(target), mode=0o700, exist_ok=True)
            source = archive.extractfile(member)
            if source is None:
                raise ExtractionError(f"archive member could not be read: {normalized}")
            with source, open(target, "xb", buffering=0) as output:
                remaining = member.size
                while remaining > 0:
                    block = source.read(min(1024 * 1024, remaining))
                    if not block:
                        raise ExtractionError(f"archive member ended early: {normalized}")
                    pending = memoryview(block)
                    while pending:
                        written = output.write(pending)
                        if written is None or written <= 0:
                            raise ExtractionError(f"archive member could not be written: {normalized}")
                        pending = pending[written:]
                    remaining -= len(block)
                if source.read(1):
                    raise ExtractionError(f"archive member exceeded its declared size: {normalized}")
            os.chmod(target, 0o600, follow_symlinks=False)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("archive")
    parser.add_argument("destination")
    parser.add_argument("--max-bytes", type=int, required=True)
    parser.add_argument("--allowed-root", action="append", default=[])
    parser.add_argument("--validate-only", action="store_true")
    arguments = parser.parse_args()
    if arguments.max_bytes < 1:
        parser.error("--max-bytes must be positive")
    try:
        extract_archive(
            arguments.archive,
            arguments.destination,
            arguments.max_bytes,
            arguments.allowed_root,
            validate_only=arguments.validate_only,
        )
    except (OSError, tarfile.TarError, ExtractionError) as error:
        print(f"[dis:error] safe archive extraction failed: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
