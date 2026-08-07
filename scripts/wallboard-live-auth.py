#!/usr/bin/env python3
"""Loopback-only MediaMTX auth for a hashed OBS Stream Key."""

from __future__ import annotations

import hashlib
import hmac
import ipaddress
import json
import os
import re
import signal
import stat
import sys
import time
from collections import OrderedDict
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Lock
from typing import Callable, ClassVar, NoReturn


SERVICE_USER = "dis-wallboard-live-ingress"
SERVICE_GROUP = "dis-wallboard-live-ingress"
EXPECTED_CREDENTIAL_DIRECTORY = Path(
    "/run/credentials/dis-wallboard-live-ingress.service"
)
RUNTIME_DIRECTORY = Path("/run/dis-wallboard-live-ingress")
READY_PATH = RUNTIME_DIRECTORY / "auth.ready"
LISTEN_ADDRESS = ("127.0.0.1", 19351)
MAXIMUM_BODY_BYTES = 4096
STREAM_PATH = re.compile(r"\Alive/([A-Za-z0-9._~-]{32,79})\Z", re.ASCII)
PUBLIC_AUTH_ATTEMPTS_PER_MINUTE = 30
LOCAL_READ_ATTEMPTS_PER_MINUTE = 120
RATE_LIMIT_WINDOW_SECONDS = 60.0
MAXIMUM_RATE_LIMIT_SOURCES = 2048
AUDIT_SALT = os.urandom(32)
AUDIT_LOCK = Lock()
AUDIT_DENIALS: OrderedDict[tuple[str, str], float] = OrderedDict()


def fail_closed() -> NoReturn:
    raise SystemExit(78)


def validate_identity() -> None:
    import grp
    import pwd

    user = pwd.getpwuid(os.getuid()).pw_name
    group = grp.getgrgid(os.getgid()).gr_name
    if (
        os.geteuid() == 0
        or user != SERVICE_USER
        or group != SERVICE_GROUP
        or any(group_id != os.getgid() for group_id in os.getgroups())
    ):
        fail_closed()


def read_expected_hash() -> str:
    credentials_directory = Path(os.environ.get("CREDENTIALS_DIRECTORY", ""))
    if credentials_directory != EXPECTED_CREDENTIAL_DIRECTORY:
        fail_closed()
    path = credentials_directory / "stream-key.sha256"
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
    except OSError:
        fail_closed()
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size != 64:
            fail_closed()
        value = os.read(descriptor, 65)
    finally:
        os.close(descriptor)
    try:
        decoded = value.decode("ascii")
    except UnicodeDecodeError:
        fail_closed()
    if re.fullmatch(r"[a-f0-9]{64}", decoded, re.ASCII) is None:
        fail_closed()
    return decoded


def constant_time_path_match(path: object, expected_hash: str) -> bool:
    if not isinstance(path, str):
        return False
    match = STREAM_PATH.fullmatch(path)
    if match is None:
        return False
    candidate = match.group(1)
    if len(set(candidate)) == 1:
        return False
    digest = hashlib.sha256(candidate.encode("ascii")).hexdigest()
    return hmac.compare_digest(digest, expected_hash)


def normalized_source_ip(value: object) -> str | None:
    if not isinstance(value, str) or len(value) > 64:
        return None
    try:
        return ipaddress.ip_address(value).compressed
    except ValueError:
        return None


class AttemptLimiter:
    def __init__(
        self,
        *,
        window_seconds: float = RATE_LIMIT_WINDOW_SECONDS,
        maximum_sources: int = MAXIMUM_RATE_LIMIT_SOURCES,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        self.window_seconds = window_seconds
        self.maximum_sources = maximum_sources
        self.clock = clock
        self.lock = Lock()
        self.entries: OrderedDict[tuple[str, str], tuple[float, int]] = OrderedDict()

    def allow(self, bucket: str, source_ip: str, limit: int) -> bool:
        now = self.clock()
        key = (bucket, source_ip)
        with self.lock:
            while self.entries:
                oldest_key, (oldest_started_at, _) = next(iter(self.entries.items()))
                if now - oldest_started_at < self.window_seconds:
                    break
                self.entries.pop(oldest_key, None)

            state = self.entries.pop(key, None)
            if state is None or now - state[0] >= self.window_seconds:
                started_at, count = now, 0
            else:
                started_at, count = state
            allowed = count < limit
            self.entries[key] = (started_at, count + 1 if allowed else count)
            while len(self.entries) > self.maximum_sources:
                self.entries.popitem(last=False)

            return allowed


def audit_publish_auth(outcome: str, source_ip: str | None) -> None:
    source_label = hmac.new(
        AUDIT_SALT,
        (source_ip or "invalid").encode("ascii", errors="replace"),
        hashlib.sha256,
    ).hexdigest()[:16]
    now = time.monotonic()
    with AUDIT_LOCK:
        if outcome != "allowed":
            key = (outcome, source_label)
            last_logged_at = AUDIT_DENIALS.get(key)
            if last_logged_at is not None and now - last_logged_at < RATE_LIMIT_WINDOW_SECONDS:
                return
            AUDIT_DENIALS[key] = now
            AUDIT_DENIALS.move_to_end(key)
            while len(AUDIT_DENIALS) > MAXIMUM_RATE_LIMIT_SOURCES:
                AUDIT_DENIALS.popitem(last=False)
        print(
            f"DIS wallboard live auth: action=publish outcome={outcome} source={source_label}",
            file=sys.stderr,
            flush=True,
        )


class AuthHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "DIS"
    sys_version = ""
    expected_hash: ClassVar[str]
    attempt_limiter: ClassVar[AttemptLimiter] = AttemptLimiter()

    def do_POST(self) -> None:  # noqa: N802 - stdlib handler contract
        self.connection.settimeout(2.0)
        if self.path != "/auth":
            self.respond(404)
            return
        content_type = self.headers.get("Content-Type", "").split(";", 1)[0].strip().lower()
        content_length = self.headers.get("Content-Length", "")
        if content_type != "application/json" or not content_length.isascii() or not content_length.isdigit():
            self.respond(400)
            return
        length = int(content_length, 10)
        if length < 2 or length > MAXIMUM_BODY_BYTES:
            self.respond(413)
            return
        body = self.rfile.read(length)
        if len(body) != length:
            self.respond(400)
            return
        try:
            payload = json.loads(body)
        except (UnicodeDecodeError, json.JSONDecodeError):
            self.respond(400)
            return
        if not isinstance(payload, dict):
            self.respond(403)
            return

        action = payload.get("action")
        protocol = payload.get("protocol")
        source_ip = normalized_source_ip(payload.get("ip"))
        local_read = action == "read" and source_ip in {"127.0.0.1", "::1"}
        rate_bucket = "local-read" if local_read else "public-auth"
        rate_limit = LOCAL_READ_ATTEMPTS_PER_MINUTE if local_read else PUBLIC_AUTH_ATTEMPTS_PER_MINUTE
        within_rate_limit = source_ip is not None and self.attempt_limiter.allow(
            rate_bucket,
            source_ip,
            rate_limit,
        )
        allowed = (
            within_rate_limit
            and protocol == "rtmp"
            and constant_time_path_match(payload.get("path"), self.expected_hash)
            and (
                action == "publish"
                or local_read
            )
        )
        if action == "publish":
            audit_publish_auth(
                "allowed" if allowed else ("denied" if within_rate_limit else "rate_limited"),
                source_ip,
            )
        self.respond(204 if allowed else 403)

    def do_GET(self) -> None:  # noqa: N802 - stdlib handler contract
        self.respond(404)

    def respond(self, status_code: int) -> None:
        self.send_response_only(status_code)
        self.send_header("Content-Length", "0")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.end_headers()
        self.close_connection = True

    def log_message(self, _format: str, *args: object) -> None:
        return


class BoundedThreadingHTTPServer(ThreadingHTTPServer):
    allow_reuse_address = True
    daemon_threads = True
    request_queue_size = 8


def publish_ready_marker() -> None:
    metadata = RUNTIME_DIRECTORY.stat(follow_symlinks=False)
    if (
        not stat.S_ISDIR(metadata.st_mode)
        or metadata.st_uid != os.getuid()
        or metadata.st_gid != os.getgid()
        or stat.S_IMODE(metadata.st_mode) != 0o750
    ):
        fail_closed()
    descriptor = os.open(
        READY_PATH,
        os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW,
        0o600,
    )
    os.close(descriptor)


def main() -> None:
    validate_identity()
    expected_hash = read_expected_hash()
    AuthHandler.expected_hash = expected_hash
    server = BoundedThreadingHTTPServer(LISTEN_ADDRESS, AuthHandler, bind_and_activate=False)
    server.socket.set_inheritable(False)
    server.server_bind()
    server.server_activate()
    publish_ready_marker()

    def stop(_signum: int, _frame: object) -> None:
        raise KeyboardInterrupt

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    try:
        try:
            server.serve_forever(poll_interval=0.25)
        except KeyboardInterrupt:
            pass
    finally:
        server.server_close()
        try:
            READY_PATH.unlink()
        except FileNotFoundError:
            pass


if __name__ == "__main__":
    main()
