from __future__ import annotations

import logging
import os
import re
import socket
import socketserver
import stat
from pathlib import Path
from typing import Any

from . import PROTOCOL_VERSION
from .adapters import AdapterError
from .audio import AudioValidationError
from .engine import EngineError, SpeechEngine, dispatch_action
from .installer import InstallationError
from .protocol import ProtocolError, receive_request, request_identity, send_response
from .secure_files import SecureFileError

LOGGER = logging.getLogger("dis_tts_engine")
_ERROR_CODE = re.compile(r"^[a-z0-9_]{1,80}$")
_EMPTY_REQUEST_ID = "00000000000000000000000000"


class SpeechRequestHandler(socketserver.BaseRequestHandler):
    server: SpeechUnixServer

    def handle(self) -> None:
        connection: socket.socket = self.request
        connection.settimeout(self.server.request_timeout_seconds)
        request_id = _EMPTY_REQUEST_ID
        action = "invalid"
        try:
            request = receive_request(connection)
            request_id, action = request_identity(request)
            result = dispatch_action(self.server.engine, request_id, action, request.get("payload"))
            response: dict[str, Any] = {
                "protocol_version": PROTOCOL_VERSION,
                "request_id": request_id,
                "ok": True,
                "result": result,
            }
            LOGGER.info("request_completed request_id=%s action=%s", request_id, action)
        except (
            ProtocolError,
            EngineError,
            AdapterError,
            AudioValidationError,
            InstallationError,
            SecureFileError,
        ) as exception:
            code = exception.code if _ERROR_CODE.fullmatch(exception.code) else "engine_failed"
            response = {
                "protocol_version": PROTOCOL_VERSION,
                "request_id": request_id,
                "ok": False,
                "error_code": code,
            }
            LOGGER.warning(
                "request_failed request_id=%s action=%s error_code=%s",
                request_id,
                action,
                code,
            )
        except Exception:
            response = {
                "protocol_version": PROTOCOL_VERSION,
                "request_id": request_id,
                "ok": False,
                "error_code": "engine_internal_error",
            }
            LOGGER.error(
                "request_failed request_id=%s action=%s error_code=engine_internal_error",
                request_id,
                action,
            )

        try:
            send_response(connection, response)
        except (OSError, ProtocolError):
            LOGGER.warning("response_delivery_failed request_id=%s action=%s", request_id, action)


class SpeechUnixServer(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True
    block_on_close = True

    def __init__(
        self,
        socket_path: Path,
        engine: SpeechEngine,
        request_timeout_seconds: float = 10.0,
    ) -> None:
        self.socket_path = _prepare_socket_path(socket_path)
        self.engine = engine
        self.request_timeout_seconds = request_timeout_seconds
        super().__init__(str(self.socket_path), SpeechRequestHandler, bind_and_activate=True)
        os.chmod(self.socket_path, 0o660, follow_symlinks=False)
        self._socket_identity = _socket_identity(self.socket_path)

    def server_close(self) -> None:
        try:
            super().server_close()
        finally:
            try:
                if _socket_identity(self.socket_path) == self._socket_identity:
                    self.socket_path.unlink()
            except FileNotFoundError:
                pass


def _prepare_socket_path(path: Path) -> Path:
    if not path.is_absolute() or path.name in {"", ".", ".."}:
        raise EngineError("invalid_socket_path")
    parent = path.parent
    try:
        parent_metadata = parent.lstat()
    except OSError as exception:
        raise EngineError("invalid_socket_path") from exception
    if not stat.S_ISDIR(parent_metadata.st_mode) or parent.is_symlink() or parent_metadata.st_mode & stat.S_IWOTH:
        raise EngineError("invalid_socket_path")

    try:
        metadata = path.lstat()
    except FileNotFoundError:
        return path
    if not stat.S_ISSOCK(metadata.st_mode) or metadata.st_uid != os.geteuid():
        raise EngineError("unsafe_existing_socket")
    path.unlink()
    return path


def _socket_identity(path: Path) -> tuple[int, int]:
    metadata = path.lstat()
    if not stat.S_ISSOCK(metadata.st_mode):
        raise EngineError("invalid_socket_path")
    return metadata.st_dev, metadata.st_ino
