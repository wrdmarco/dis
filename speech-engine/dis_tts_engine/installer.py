from __future__ import annotations

import hashlib
import json
import os
import re
import secrets
import shutil
import signal
import stat
import subprocess
import sys
import threading
import time
from pathlib import Path
from typing import Any

from .catalog import MODEL_SPECS, ModelSpec


_INSTALL_STAGING_NAME = re.compile(r"^\.installing-[0-9A-HJKMNP-TV-Z]{26}$", re.IGNORECASE)


class InstallationError(Exception):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


class InstallationStateStore:
    def __init__(self, state_root: Path) -> None:
        self.state_root = state_root
        self.state_root.mkdir(mode=0o750, parents=True, exist_ok=True)
        self._lock = threading.Lock()

    def read(self, model_id: str) -> dict[str, Any]:
        path = self._path(model_id)
        try:
            encoded = _read_regular_file(path, maximum_bytes=64 * 1024)
            payload = json.loads(encoded.decode("utf-8"))
        except (FileNotFoundError, OSError, UnicodeDecodeError, json.JSONDecodeError, InstallationError):
            return {"status": "not_installed", "progress_percent": 0}
        return payload if isinstance(payload, dict) else {"status": "not_installed", "progress_percent": 0}

    def write(self, model_id: str, payload: dict[str, Any]) -> None:
        destination = self._path(model_id)
        encoded = json.dumps(payload, separators=(",", ":"), sort_keys=True).encode("utf-8")
        temporary = self.state_root / f".{model_id}.{os.getpid()}.{secrets.token_hex(8)}.tmp"
        with self._lock:
            descriptor: int | None = None
            try:
                descriptor = os.open(
                    temporary,
                    os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0),
                    0o640,
                )
                _write_all(descriptor, encoded)
                os.fsync(descriptor)
                os.close(descriptor)
                descriptor = None
                os.replace(temporary, destination)
                directory_fd = os.open(
                    self.state_root,
                    os.O_RDONLY | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0),
                )
                try:
                    os.fsync(directory_fd)
                finally:
                    os.close(directory_fd)
            finally:
                if descriptor is not None:
                    os.close(descriptor)
                try:
                    temporary.unlink()
                except FileNotFoundError:
                    pass

    def _path(self, model_id: str) -> Path:
        if re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,79}", model_id) is None:
            raise InstallationError("invalid_model_id")
        return self.state_root / f"{model_id}.json"


class ModelInstaller:
    def __init__(self, models_root: Path, state_store: InstallationStateStore) -> None:
        self.models_root = models_root
        self.state_store = state_store
        self.models_root.mkdir(mode=0o750, parents=True, exist_ok=True)
        _remove_orphan_install_staging(self.models_root)
        self._threads: dict[str, threading.Thread] = {}
        self._cancel_events: dict[str, threading.Event] = {}
        self._download_processes: dict[str, subprocess.Popen[bytes]] = {}
        self._lock = threading.Lock()
        self._verification_lock = threading.Lock()
        self._verified_releases: dict[tuple[str, str], tuple[tuple[int, ...], ...]] = {}

    def installed_path(self, spec: ModelSpec) -> Path | None:
        release = self.models_root / spec.model_id / spec.revision
        try:
            fingerprint = _release_fingerprint(release, spec)
            cache_key = (spec.model_id, spec.revision)
            with self._verification_lock:
                if self._verified_releases.get(cache_key) != fingerprint:
                    self._verify_release(release, spec)
                    self._verified_releases[cache_key] = _release_fingerprint(release, spec)
        except InstallationError:
            return None
        return release

    def installed_path_fast(self, spec: ModelSpec) -> Path | None:
        release = self.models_root / spec.model_id / spec.revision
        try:
            self._verify_release_metadata(release, spec)
        except InstallationError:
            return None
        return release

    def status(self, spec: ModelSpec) -> dict[str, Any]:
        state = self.state_store.read(spec.model_id)
        if state.get("status") == "installing":
            thread = self._threads.get(spec.model_id)
            if thread is not None and thread.is_alive():
                return state
            state = {
                "status": "failed",
                "progress_percent": int(state.get("progress_percent", 0)),
                "error_code": "installation_interrupted",
            }
            self.state_store.write(spec.model_id, state)

        installed = self.installed_path_fast(spec)
        if installed is not None:
            return {
                "status": "installed",
                "progress_percent": 100,
                "installed_revision": spec.revision,
                "weights_sha256": spec.weights_sha256,
            }
        if state.get("status") == "installed":
            state = {
                "status": "failed",
                "progress_percent": 0,
                "error_code": "installed_files_missing",
            }
            self.state_store.write(spec.model_id, state)
        return state

    def start(self, spec: ModelSpec, request_id: str) -> dict[str, Any]:
        with self._lock:
            current = self._threads.get(spec.model_id)
            if current is not None and current.is_alive():
                return self.status(spec)
            if self.installed_path_fast(spec) is not None:
                return self.status(spec)
            cancel_event = threading.Event()
            thread = threading.Thread(
                target=self._install,
                name=f"install-{spec.model_id}",
                args=(spec, request_id, cancel_event),
                daemon=True,
            )
            self._threads[spec.model_id] = thread
            self._cancel_events[spec.model_id] = cancel_event
            self.state_store.write(spec.model_id, {
                "status": "installing",
                "stage": "queued",
                "progress_percent": 0,
            })
            thread.start()
            return self.status(spec)

    def cancel(self, spec: ModelSpec) -> dict[str, Any]:
        with self._lock:
            thread = self._threads.get(spec.model_id)
            cancel_event = self._cancel_events.get(spec.model_id)
            process = self._download_processes.get(spec.model_id)
            if thread is None or cancel_event is None or not thread.is_alive():
                return self.status(spec)
            cancel_event.set()
        state = self.state_store.read(spec.model_id)
        progress = max(0, min(99, int(state.get("progress_percent", 0))))
        cancelling = {
            "status": "installing",
            "stage": "cancelling_for_alarm",
            "progress_percent": progress,
        }
        if process is not None:
            _terminate_download(process)
        return cancelling

    def cancel_all_and_wait(self, timeout_seconds: float = 10.0) -> None:
        with self._lock:
            active = [
                (model_id, thread, self._cancel_events.get(model_id), self._download_processes.get(model_id))
                for model_id, thread in self._threads.items()
                if thread.is_alive()
            ]
            for _model_id, _thread, cancel_event, _process in active:
                if cancel_event is not None:
                    cancel_event.set()
        for _model_id, _thread, _cancel_event, process in active:
            if process is not None:
                _terminate_download(process)

        deadline = time.monotonic() + max(1.0, timeout_seconds)
        for _model_id, thread, _cancel_event, _process in active:
            thread.join(timeout=max(0.0, deadline - time.monotonic()))
        if any(thread.is_alive() for _model_id, thread, _event, _process in active):
            raise InstallationError("model_installation_cancel_failed")

    def _install(self, spec: ModelSpec, request_id: str, cancel_event: threading.Event) -> None:
        model_parent = self.models_root / spec.model_id
        model_parent.mkdir(mode=0o750, parents=True, exist_ok=True)
        staging = model_parent / f".installing-{request_id}"
        if staging.exists():
            _remove_install_staging(staging, model_parent)
        staging.mkdir(mode=0o750)

        monitor_stop = threading.Event()
        monitor = threading.Thread(
            target=self._monitor_download,
            args=(spec, staging, monitor_stop),
            daemon=True,
        )
        try:
            _raise_if_cancelled(cancel_event)
            self.state_store.write(spec.model_id, {
                "status": "installing",
                "stage": "downloading",
                "progress_percent": 5,
            })
            monitor.start()
            process = subprocess.Popen(
                [
                    sys.executable,
                    "-m",
                    "dis_tts_engine.download_worker",
                    "--model-id",
                    spec.model_id,
                    "--destination",
                    str(staging),
                ],
                stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                close_fds=True,
                start_new_session=True,
                env=_download_environment(),
            )
            with self._lock:
                self._download_processes[spec.model_id] = process
            while process.poll() is None:
                if cancel_event.wait(0.25):
                    _terminate_download(process)
                    raise InstallationError("installation_cancelled_for_alarm")
            if process.returncode != 0:
                _raise_if_cancelled(cancel_event)
                raise InstallationError("model_download_failed")
            _raise_if_cancelled(cancel_event)
            monitor_stop.set()
            monitor.join(timeout=2)
            self.state_store.write(spec.model_id, {
                "status": "installing",
                "stage": "verifying",
                "progress_percent": 92,
            })
            cache_directory = staging / ".cache"
            if cache_directory.exists():
                _remove_install_staging(cache_directory, staging)
            self._verify_weight_files(staging, spec, cancel_event)
            _assert_plain_tree(staging, cancel_event)
            _raise_if_cancelled(cancel_event)
            _write_completion_marker(staging, spec)
            _normalize_release_permissions(staging, cancel_event)
            self.state_store.write(spec.model_id, {
                "status": "installing",
                "stage": "activating",
                "progress_percent": 98,
            })
            destination = model_parent / spec.revision
            if destination.exists():
                self._verify_release(destination, spec, cancel_event)
                _remove_install_staging(staging, model_parent)
            else:
                _raise_if_cancelled(cancel_event)
                os.rename(staging, destination)
                _fsync_directory(model_parent)
            with self._verification_lock:
                self._verified_releases[(spec.model_id, spec.revision)] = _release_fingerprint(destination, spec)
            self.state_store.write(spec.model_id, {
                "status": "installed",
                "progress_percent": 100,
                "installed_revision": spec.revision,
                "weights_sha256": spec.weights_sha256,
            })
        except Exception as exception:
            monitor_stop.set()
            if monitor.is_alive():
                monitor.join(timeout=2)
            error_code = exception.code if isinstance(exception, InstallationError) else "model_installation_failed"
            self.state_store.write(spec.model_id, {
                "status": "failed",
                "progress_percent": 0,
                "error_code": error_code,
            })
            if staging.exists():
                _remove_install_staging(staging, model_parent)
        finally:
            with self._lock:
                current = self._cancel_events.get(spec.model_id)
                if current is cancel_event:
                    self._cancel_events.pop(spec.model_id, None)
                    self._download_processes.pop(spec.model_id, None)

    def _monitor_download(self, spec: ModelSpec, staging: Path, stop: threading.Event) -> None:
        while not stop.wait(1):
            downloaded = _regular_file_bytes(staging)
            ratio = min(1.0, downloaded / max(1, spec.download_bytes))
            self.state_store.write(spec.model_id, {
                "status": "installing",
                "stage": "downloading",
                "progress_percent": max(5, min(90, 5 + int(ratio * 85))),
            })

    def _verify_release(
        self,
        release: Path,
        spec: ModelSpec,
        cancel_event: threading.Event | None = None,
    ) -> None:
        self._verify_release_metadata(release, spec)
        self._verify_weight_files(release, spec, cancel_event)

    @staticmethod
    def _verify_release_metadata(release: Path, spec: ModelSpec) -> None:
        try:
            metadata = release.lstat()
        except OSError as exception:
            raise InstallationError("installed_files_missing") from exception
        if not stat.S_ISDIR(metadata.st_mode) or release.is_symlink():
            raise InstallationError("installed_files_missing")
        for expected in spec.weight_files:
            _verify_regular_file_metadata(release / expected.relative_path, release, expected.size_bytes)
        marker = release / ".complete.json"
        try:
            payload = json.loads(_read_regular_file(marker, maximum_bytes=4 * 1024).decode("utf-8"))
        except (FileNotFoundError, OSError, UnicodeDecodeError, json.JSONDecodeError, InstallationError) as exception:
            raise InstallationError("invalid_model_completion_marker") from exception
        if payload != {
            "model_id": spec.model_id,
            "revision": spec.revision,
            "weights_sha256": spec.weights_sha256,
        }:
            raise InstallationError("invalid_model_completion_marker")

    @staticmethod
    def _verify_weight_files(
        root: Path,
        spec: ModelSpec,
        cancel_event: threading.Event | None = None,
    ) -> None:
        for expected in spec.weight_files:
            _raise_if_cancelled(cancel_event)
            path = root / expected.relative_path
            _verify_regular_file(path, root, expected.size_bytes, expected.sha256, cancel_event)


def _verify_regular_file(
    path: Path,
    root: Path,
    expected_size: int,
    expected_sha256: str,
    cancel_event: threading.Event | None = None,
) -> None:
    descriptor = _open_verified_regular_file(path, root, expected_size)
    digest = hashlib.sha256()
    try:
        while True:
            _raise_if_cancelled(cancel_event)
            chunk = os.read(descriptor, 1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
    finally:
        os.close(descriptor)
    if digest.hexdigest() != expected_sha256:
        raise InstallationError("model_file_checksum_mismatch")


def _verify_regular_file_metadata(path: Path, root: Path, expected_size: int) -> None:
    descriptor = _open_verified_regular_file(path, root, expected_size)
    os.close(descriptor)


def _open_verified_regular_file(path: Path, root: Path, expected_size: int) -> int:
    try:
        resolved_parent = path.parent.resolve(strict=True)
        resolved_root = root.resolve(strict=True)
    except (FileNotFoundError, OSError) as exception:
        raise InstallationError("model_file_missing") from exception
    if resolved_parent != resolved_root and resolved_root not in resolved_parent.parents:
        raise InstallationError("invalid_model_file_path")
    try:
        descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    except OSError as exception:
        raise InstallationError("model_file_missing") from exception
    try:
        metadata = os.fstat(descriptor)
    except OSError:
        os.close(descriptor)
        raise
    if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size != expected_size:
        os.close(descriptor)
        raise InstallationError("model_file_invalid")
    return descriptor


def _release_fingerprint(release: Path, spec: ModelSpec) -> tuple[tuple[int, ...], ...]:
    paths = [release / ".complete.json", *(release / expected.relative_path for expected in spec.weight_files)]
    fingerprint: list[tuple[int, ...]] = []
    for path in paths:
        try:
            metadata = path.lstat()
        except OSError as exception:
            raise InstallationError("installed_files_missing") from exception
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            raise InstallationError("model_file_invalid")
        fingerprint.append((
            metadata.st_dev,
            metadata.st_ino,
            metadata.st_size,
            metadata.st_mtime_ns,
            metadata.st_ctime_ns,
            metadata.st_nlink,
        ))
    return tuple(fingerprint)


def _read_regular_file(path: Path, maximum_bytes: int) -> bytes:
    descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            raise InstallationError("invalid_regular_file")
        if metadata.st_size < 1 or metadata.st_size > maximum_bytes:
            raise InstallationError("invalid_regular_file_size")
        chunks: list[bytes] = []
        remaining = metadata.st_size
        while remaining:
            chunk = os.read(descriptor, min(64 * 1024, remaining))
            if not chunk:
                raise InstallationError("truncated_regular_file")
            chunks.append(chunk)
            remaining -= len(chunk)
        return b"".join(chunks)
    finally:
        os.close(descriptor)


def _write_all(descriptor: int, payload: bytes) -> None:
    offset = 0
    while offset < len(payload):
        written = os.write(descriptor, payload[offset:])
        if written < 1:
            raise OSError("short write")
        offset += written


def _assert_plain_tree(root: Path, cancel_event: threading.Event | None = None) -> None:
    for directory, directories, files in os.walk(root, followlinks=False):
        _raise_if_cancelled(cancel_event)
        for name in [*directories, *files]:
            path = Path(directory) / name
            metadata = path.lstat()
            if stat.S_ISLNK(metadata.st_mode) or not (stat.S_ISDIR(metadata.st_mode) or stat.S_ISREG(metadata.st_mode)):
                raise InstallationError("unsafe_model_tree")


def _write_completion_marker(root: Path, spec: ModelSpec) -> None:
    marker = root / ".complete.json"
    payload = json.dumps({
        "model_id": spec.model_id,
        "revision": spec.revision,
        "weights_sha256": spec.weights_sha256,
    }, separators=(",", ":"), sort_keys=True).encode("utf-8")
    descriptor = os.open(marker, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
    try:
        _write_all(descriptor, payload)
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    _fsync_directory(root)


def _regular_file_bytes(root: Path) -> int:
    total = 0
    for directory, _directories, files in os.walk(root, followlinks=False):
        for name in files:
            try:
                metadata = (Path(directory) / name).lstat()
            except OSError:
                continue
            if stat.S_ISREG(metadata.st_mode):
                total += metadata.st_size
    return total


def _normalize_release_permissions(root: Path, cancel_event: threading.Event | None = None) -> None:
    for directory, directories, files in os.walk(root, followlinks=False):
        _raise_if_cancelled(cancel_event)
        os.chmod(directory, 0o750, follow_symlinks=False)
        for name in directories:
            os.chmod(Path(directory) / name, 0o750, follow_symlinks=False)
        for name in files:
            os.chmod(Path(directory) / name, 0o640, follow_symlinks=False)


def _remove_install_staging(path: Path, allowed_parent: Path) -> None:
    resolved_parent = allowed_parent.resolve(strict=True)
    resolved = path.resolve(strict=True)
    if resolved == resolved_parent or resolved_parent not in resolved.parents or path.is_symlink():
        raise InstallationError("unsafe_installation_staging")
    shutil.rmtree(resolved)


def _remove_orphan_install_staging(models_root: Path) -> None:
    """Remove only allowlisted, process-orphaned install trees on engine startup."""
    for model_id in MODEL_SPECS:
        model_parent = models_root / model_id
        try:
            parent_metadata = model_parent.lstat()
        except FileNotFoundError:
            continue
        if not stat.S_ISDIR(parent_metadata.st_mode) or model_parent.is_symlink():
            raise InstallationError("unsafe_model_directory")

        with os.scandir(model_parent) as entries:
            for entry in entries:
                if not entry.name.startswith(".installing-"):
                    continue
                if _INSTALL_STAGING_NAME.fullmatch(entry.name) is None:
                    raise InstallationError("unsafe_installation_staging")
                metadata = entry.stat(follow_symlinks=False)
                if not stat.S_ISDIR(metadata.st_mode) or entry.is_symlink():
                    raise InstallationError("unsafe_installation_staging")
                _remove_install_staging(model_parent / entry.name, model_parent)


def _fsync_directory(path: Path) -> None:
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def _raise_if_cancelled(cancel_event: threading.Event | None) -> None:
    if cancel_event is not None and cancel_event.is_set():
        raise InstallationError("installation_cancelled_for_alarm")


def _download_environment() -> dict[str, str]:
    environment = os.environ.copy()
    environment["HF_HUB_DISABLE_TELEMETRY"] = "1"
    environment["HF_HUB_DISABLE_IMPLICIT_TOKEN"] = "1"
    environment["HF_HUB_DISABLE_PROGRESS_BARS"] = "1"
    return environment


def _terminate_download(process: subprocess.Popen[bytes]) -> None:
    if process.poll() is not None:
        return
    try:
        if os.name == "posix":
            os.killpg(process.pid, signal.SIGTERM)
        else:
            process.terminate()
        process.wait(timeout=2)
        return
    except (ProcessLookupError, subprocess.TimeoutExpired):
        pass
    try:
        if os.name == "posix":
            os.killpg(process.pid, signal.SIGKILL)
        else:
            process.kill()
        process.wait(timeout=2)
    except (ProcessLookupError, subprocess.TimeoutExpired):
        raise InstallationError("model_installation_cancel_failed")
