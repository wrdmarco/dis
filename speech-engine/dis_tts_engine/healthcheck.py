from __future__ import annotations

import json
import os
import socket
import struct
from pathlib import Path
from typing import Any

from . import PROTOCOL_VERSION

_REQUEST_ID = "00000000000000000000000001"
_HEADER = struct.Struct(">I")
_MAXIMUM_RESPONSE_BYTES = 256 * 1024


def probe(socket_path: Path, timeout_seconds: float = 3.0) -> bool:
    if not socket_path.is_absolute() or socket_path.name in {"", ".", ".."}:
        return False
    request = json.dumps(
        {
            "protocol_version": PROTOCOL_VERSION,
            "request_id": _REQUEST_ID,
            "action": "health",
            "payload": [],
        },
        ensure_ascii=False,
        separators=(",", ":"),
        allow_nan=False,
    ).encode("utf-8")
    try:
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
            connection.settimeout(timeout_seconds)
            connection.connect(str(socket_path))
            connection.sendall(_HEADER.pack(len(request)) + request)
            (length,) = _HEADER.unpack(_receive_exact(connection, _HEADER.size))
            if length < 2 or length > _MAXIMUM_RESPONSE_BYTES:
                return False
            response = json.loads(_receive_exact(connection, length).decode("utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError, struct.error):
        return False
    return _valid_health_response(response)


def _receive_exact(connection: socket.socket, length: int) -> bytes:
    chunks: list[bytes] = []
    remaining = length
    while remaining > 0:
        chunk = connection.recv(remaining)
        if not chunk:
            raise OSError("truncated health response")
        chunks.append(chunk)
        remaining -= len(chunk)
    return b"".join(chunks)


def _valid_health_response(response: Any) -> bool:
    if not isinstance(response, dict):
        return False
    result = response.get("result")
    return (
        response.get("protocol_version") == PROTOCOL_VERSION
        and response.get("request_id") == _REQUEST_ID
        and response.get("ok") is True
        and isinstance(result, dict)
        and result.get("status") == "ok"
        and result.get("ready") is True
    )


def main() -> None:
    socket_path = Path(os.getenv("DIS_TTS_SOCKET_PATH", "/run/dis-tts/engine.sock"))
    raise SystemExit(0 if probe(socket_path) else 1)


if __name__ == "__main__":
    main()
