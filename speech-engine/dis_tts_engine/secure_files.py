from __future__ import annotations

import json
import os
import re
import stat
from pathlib import Path
from typing import Any

_ULID = r"[0-9A-HJKMNP-TV-Z]{26}"
JOB_BASENAME = re.compile(rf"^{_ULID}\.job\.json$", re.IGNORECASE)
REFERENCE_BASENAME = re.compile(rf"^{_ULID}\.reference$", re.IGNORECASE)
OUTPUT_BASENAME = re.compile(rf"^{_ULID}\.wav$", re.IGNORECASE)
MAX_JOB_BYTES = 16 * 1024
MAX_REFERENCE_BYTES = 32 * 1024 * 1024


class SecureFileError(Exception):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


class StagingDirectory:
    def __init__(self, root: Path) -> None:
        self.root = root
        self._directory_fd: int | None = None

    def __enter__(self) -> StagingDirectory:
        self._directory_fd = os.open(
            self.root,
            os.O_RDONLY | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0),
        )
        return self

    def __exit__(self, _type: object, _value: object, _traceback: object) -> None:
        if self._directory_fd is not None:
            os.close(self._directory_fd)
            self._directory_fd = None

    def consume_job(self, basename: object) -> dict[str, Any]:
        validated = _validated_basename(basename, JOB_BASENAME, "invalid_job_basename")
        payload = self._read_and_unlink(validated, MAX_JOB_BYTES)
        try:
            decoded = json.loads(payload.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exception:
            raise SecureFileError("invalid_job_file") from exception
        if not isinstance(decoded, dict):
            raise SecureFileError("invalid_job_file")
        return decoded

    def consume_reference(self, basename: object) -> bytes:
        validated = _validated_basename(basename, REFERENCE_BASENAME, "invalid_reference_basename")
        return self._read_and_unlink(validated, MAX_REFERENCE_BYTES)

    def output_paths(self, basename: object) -> tuple[Path, Path]:
        validated = _validated_basename(basename, OUTPUT_BASENAME, "invalid_output_basename")
        return self.root / f"{validated}.part", self.root / validated

    def create_output_part(self, basename: object) -> tuple[int, Path, Path]:
        part_path, final_path = self.output_paths(basename)
        directory_fd = self._require_fd()
        part_basename = part_path.name
        try:
            descriptor = os.open(
                part_basename,
                os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0),
                0o600,
                dir_fd=directory_fd,
            )
        except FileExistsError as exception:
            raise SecureFileError("output_already_exists") from exception
        return descriptor, part_path, final_path

    def commit_output(self, descriptor: int, part_path: Path, final_path: Path) -> None:
        os.fsync(descriptor)
        os.close(descriptor)
        linked = False
        try:
            os.link(
                part_path.name,
                final_path.name,
                src_dir_fd=self._require_fd(),
                dst_dir_fd=self._require_fd(),
                follow_symlinks=False,
            )
            linked = True
            os.chmod(final_path.name, 0o640, dir_fd=self._require_fd(), follow_symlinks=False)
            os.unlink(part_path.name, dir_fd=self._require_fd())
            os.fsync(self._require_fd())
        except FileExistsError as exception:
            raise SecureFileError("output_already_exists") from exception
        except OSError as exception:
            if linked:
                try:
                    os.unlink(final_path.name, dir_fd=self._require_fd())
                except FileNotFoundError:
                    pass
            try:
                os.unlink(part_path.name, dir_fd=self._require_fd())
            except FileNotFoundError:
                pass
            raise SecureFileError("output_commit_failed") from exception

    def discard_output(self, descriptor: int | None, part_path: Path | None) -> None:
        if descriptor is not None:
            try:
                os.close(descriptor)
            except OSError:
                pass
        if part_path is not None:
            try:
                os.unlink(part_path.name, dir_fd=self._require_fd())
            except FileNotFoundError:
                pass

    def _read_and_unlink(self, basename: str, maximum_bytes: int) -> bytes:
        directory_fd = self._require_fd()
        try:
            descriptor = os.open(
                basename,
                os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0),
                dir_fd=directory_fd,
            )
        except (FileNotFoundError, OSError) as exception:
            raise SecureFileError("staging_input_unavailable") from exception

        try:
            metadata = os.fstat(descriptor)
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
                raise SecureFileError("invalid_staging_input")
            if metadata.st_size < 1 or metadata.st_size > maximum_bytes:
                raise SecureFileError("invalid_staging_input_size")
            os.unlink(basename, dir_fd=directory_fd)
            chunks: list[bytes] = []
            remaining = metadata.st_size
            while remaining > 0:
                chunk = os.read(descriptor, min(64 * 1024, remaining))
                if not chunk:
                    raise SecureFileError("truncated_staging_input")
                chunks.append(chunk)
                remaining -= len(chunk)
            return b"".join(chunks)
        finally:
            os.close(descriptor)

    def _require_fd(self) -> int:
        if self._directory_fd is None:
            raise RuntimeError("staging directory is not open")
        return self._directory_fd


def validate_job(job: dict[str, Any]) -> tuple[str, str, str, str | None, str | None]:
    allowed = {"text", "locale", "model_id", "voice_reference_basename", "voice_transcript"}
    if set(job) - allowed:
        raise SecureFileError("unsupported_job_field")

    text = job.get("text")
    if not isinstance(text, str) or not 1 <= len(text) <= 500 or _has_unsafe_control(text):
        raise SecureFileError("invalid_speech_text")
    text = " ".join(text.split())
    if not text:
        raise SecureFileError("invalid_speech_text")

    locale = job.get("locale")
    if locale != "nl-NL":
        raise SecureFileError("unsupported_locale")

    model_id = job.get("model_id")
    if not isinstance(model_id, str):
        raise SecureFileError("invalid_model_id")

    reference = job.get("voice_reference_basename")
    if reference is not None:
        reference = _validated_basename(reference, REFERENCE_BASENAME, "invalid_reference_basename")

    transcript = job.get("voice_transcript")
    if transcript is not None:
        if not isinstance(transcript, str) or len(transcript) > 2_000 or _has_unsafe_control(transcript):
            raise SecureFileError("invalid_voice_transcript")
        transcript = " ".join(transcript.split()) or None
        if reference is None and transcript is not None:
            raise SecureFileError("voice_transcript_without_reference")

    return text, locale, model_id, reference, transcript


def _validated_basename(value: object, pattern: re.Pattern[str], error_code: str) -> str:
    if not isinstance(value, str) or pattern.fullmatch(value) is None:
        raise SecureFileError(error_code)
    return value.upper().replace(".JOB.JSON", ".job.json").replace(".REFERENCE", ".reference").replace(".WAV", ".wav")


def _has_unsafe_control(value: str) -> bool:
    return any(ord(character) < 32 and character not in {"\t", "\n", "\r"} for character in value)
