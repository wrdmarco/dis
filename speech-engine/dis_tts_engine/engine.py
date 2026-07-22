from __future__ import annotations

import os
import stat
import tempfile
import threading
import time
from collections.abc import Callable, Mapping
from pathlib import Path
from typing import Any

from .adapters import (
    AdapterError,
    SpeechModelAdapter,
    create_adapter,
    deterministic_inference_seed,
)
from .audio import AudioValidationError, write_pcm16_mono_wav
from .catalog import ModelSpec, model_spec
from .installer import InstallationError, ModelInstaller
from .secure_files import SecureFileError, StagingDirectory, validate_job


class EngineError(Exception):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


AdapterFactory = Callable[[str, Path], SpeechModelAdapter]


class SpeechEngine:
    def __init__(
        self,
        staging_root: Path,
        installer: ModelInstaller,
        adapter_factory: AdapterFactory = create_adapter,
        synthesis_deadline_seconds: int = 14_300,
    ) -> None:
        self.staging_root = _validated_private_directory(staging_root, "invalid_staging_root")
        self.installer = installer
        self.adapter_factory = adapter_factory
        if not 30 <= synthesis_deadline_seconds <= 14_300:
            raise EngineError("invalid_synthesis_deadline")
        self.synthesis_deadline_seconds = synthesis_deadline_seconds
        self._activity_lock = threading.Lock()
        self._synthesis_lock = threading.Lock()
        self._synthesis_requests = 0
        self._loaded_adapter: SpeechModelAdapter | None = None
        self._loaded_model_key: tuple[str, str] | None = None

    def health(self, payload: object) -> dict[str, Any]:
        _payload(payload, required=frozenset(), allowed=frozenset())
        loaded = self._loaded_model_key
        with self._activity_lock:
            synthesis_busy = self._synthesis_requests > 0
        return {
            "status": "ok",
            "ready": True,
            "synthesis_busy": synthesis_busy,
            "loaded_model_id": loaded[0] if loaded is not None else None,
            "loaded_revision": loaded[1] if loaded is not None else None,
        }

    def status(self, payload: object) -> dict[str, Any]:
        values = _payload(payload, required={"model_id"}, allowed={"model_id"})
        spec = _spec(values["model_id"])
        return self.installer.status(spec)

    def install(self, payload: object, request_id: str) -> dict[str, Any]:
        values = _payload(
            payload,
            required={"model_id", "revision", "weights_sha256"},
            allowed={"model_id", "revision", "weights_sha256"},
        )
        spec = _spec(values["model_id"])
        if values["revision"] != spec.revision or values["weights_sha256"] != spec.weights_sha256:
            raise EngineError("model_catalog_mismatch")
        with self._activity_lock:
            if self._synthesis_requests > 0:
                raise EngineError("synthesis_busy")
            return self.installer.start(spec, request_id)

    def cancel_install(self, payload: object) -> dict[str, Any]:
        values = _payload(payload, required={"model_id"}, allowed={"model_id"})
        spec = _spec(values["model_id"])
        return self.installer.cancel(spec)

    def synthesize(self, payload: object) -> dict[str, Any]:
        values = _payload(
            payload,
            required={"model_id", "job_basename", "output_basename"},
            allowed={"model_id", "job_basename", "output_basename"},
        )
        requested_spec = _spec(values["model_id"])
        with self._activity_lock:
            self._synthesis_requests += 1
        try:
            self.installer.cancel_all_and_wait(timeout_seconds=10)
            with self._synthesis_lock:
                return self._synthesize_locked(values, requested_spec)
        finally:
            with self._activity_lock:
                self._synthesis_requests -= 1

    def _synthesize_locked(self, values: Mapping[str, Any], requested_spec: ModelSpec) -> dict[str, Any]:
        with StagingDirectory(self.staging_root) as staging:
            deadline_monotonic = time.monotonic() + self.synthesis_deadline_seconds
            job = staging.consume_job(values["job_basename"])
            text, locale, job_model_id, reference_basename, reference_transcript = validate_job(job)
            if job_model_id != requested_spec.model_id:
                raise EngineError("job_model_mismatch")

            descriptor: int | None = None
            part_path: Path | None = None
            reference_path: Path | None = None
            try:
                descriptor, part_path, final_path = staging.create_output_part(values["output_basename"])
                if reference_basename is not None:
                    reference = staging.consume_reference(reference_basename)
                    reference_path = _write_private_reference(self.staging_root, reference)

                adapter = self._adapter(requested_spec)
                with deterministic_inference_seed(text, requested_spec.model_id):
                    waveform = adapter.synthesize(
                        text=text,
                        locale=locale,
                        reference_path=reference_path,
                        reference_transcript=reference_transcript,
                        deadline_monotonic=deadline_monotonic,
                    )
                audio = write_pcm16_mono_wav(descriptor, waveform.samples, waveform.sample_rate)
                staging.commit_output(descriptor, part_path, final_path)
                descriptor = None
                part_path = None
                return {
                    "output_basename": final_path.name,
                    "format": "wav",
                    "codec": "pcm_s16le",
                    "sample_rate": audio.sample_rate,
                    "channels": audio.channels,
                    "duration_ms": audio.duration_ms,
                }
            finally:
                staging.discard_output(descriptor, part_path)
                if reference_path is not None:
                    try:
                        reference_path.unlink()
                    except FileNotFoundError:
                        pass

    def close(self) -> None:
        self.installer.cancel_all_and_wait(timeout_seconds=10)
        with self._synthesis_lock:
            if self._loaded_adapter is not None:
                self._loaded_adapter.close()
            self._loaded_adapter = None
            self._loaded_model_key = None

    def _adapter(self, spec: ModelSpec) -> SpeechModelAdapter:
        model_path = self.installer.installed_path(spec)
        if model_path is None:
            raise EngineError("model_not_installed")
        model_key = (spec.model_id, spec.revision)
        if self._loaded_adapter is not None and self._loaded_model_key == model_key:
            return self._loaded_adapter
        if self._loaded_adapter is not None:
            self._loaded_adapter.close()
            self._loaded_adapter = None
            self._loaded_model_key = None
        try:
            adapter = self.adapter_factory(spec.adapter, model_path)
        except AdapterError:
            raise
        except Exception as exception:
            raise AdapterError("model_load_failed") from exception
        self._loaded_adapter = adapter
        self._loaded_model_key = model_key
        return adapter


def dispatch_action(engine: SpeechEngine, request_id: str, action: str, payload: object) -> dict[str, Any]:
    try:
        if action == "health":
            return engine.health(payload)
        if action == "status":
            return engine.status(payload)
        if action == "install":
            return engine.install(payload, request_id)
        if action == "cancel_install":
            return engine.cancel_install(payload)
        if action == "synthesize":
            return engine.synthesize(payload)
        raise EngineError("unsupported_action")
    except (EngineError, AdapterError, AudioValidationError, InstallationError, SecureFileError):
        raise
    except KeyError as exception:
        raise EngineError("model_not_allowlisted") from exception
    except Exception as exception:
        raise EngineError("engine_internal_error") from exception


def _payload(payload: object, required: set[str] | frozenset[str], allowed: set[str] | frozenset[str]) -> Mapping[str, Any]:
    if payload == [] and not required and not allowed:
        return {}
    if not isinstance(payload, dict):
        raise EngineError("invalid_action_payload")
    fields = set(payload)
    if fields - set(allowed) or not set(required).issubset(fields):
        raise EngineError("invalid_action_payload")
    return payload


def _spec(model_id: object) -> ModelSpec:
    try:
        return model_spec(model_id)
    except KeyError as exception:
        raise EngineError("model_not_allowlisted") from exception


def _validated_private_directory(path: Path, error_code: str) -> Path:
    if not path.is_absolute():
        raise EngineError(error_code)
    try:
        metadata = path.lstat()
    except OSError as exception:
        raise EngineError(error_code) from exception
    if not stat.S_ISDIR(metadata.st_mode) or path.is_symlink() or metadata.st_mode & stat.S_IWOTH:
        raise EngineError(error_code)
    return path


def _write_private_reference(root: Path, payload: bytes) -> Path:
    descriptor, name = tempfile.mkstemp(prefix=".engine-reference-", suffix=".audio", dir=root)
    path = Path(name)
    try:
        os.fchmod(descriptor, 0o600)
        offset = 0
        while offset < len(payload):
            written = os.write(descriptor, payload[offset:])
            if written < 1:
                raise OSError("short write")
            offset += written
        os.fsync(descriptor)
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            raise EngineError("invalid_voice_reference")
    except Exception:
        try:
            path.unlink()
        except FileNotFoundError:
            pass
        raise
    finally:
        os.close(descriptor)
    return path
