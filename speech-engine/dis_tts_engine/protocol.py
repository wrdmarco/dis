from __future__ import annotations

import json
import socket
import struct
from collections.abc import Mapping
from typing import Any

from . import PROTOCOL_VERSION

REQUEST_LIMIT_BYTES = 64 * 1024
RESPONSE_LIMIT_BYTES = 256 * 1024
_HEADER = struct.Struct(">I")


class ProtocolError(Exception):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


def receive_request(connection: socket.socket) -> dict[str, Any]:
    payload = _receive_frame(connection, REQUEST_LIMIT_BYTES)
    return _decode_object(payload)


def send_response(connection: socket.socket, payload: Mapping[str, Any]) -> None:
    encoded = json.dumps(
        dict(payload),
        ensure_ascii=False,
        separators=(",", ":"),
        allow_nan=False,
    ).encode("utf-8")
    if len(encoded) > RESPONSE_LIMIT_BYTES:
        raise ProtocolError("response_too_large")
    connection.sendall(_HEADER.pack(len(encoded)) + encoded)


def request_identity(payload: Mapping[str, Any]) -> tuple[str, str]:
    if set(payload) != {"protocol_version", "request_id", "action", "payload"}:
        raise ProtocolError("invalid_request_envelope")
    if payload.get("protocol_version") != PROTOCOL_VERSION:
        raise ProtocolError("unsupported_protocol_version")

    request_id = payload.get("request_id")
    if not isinstance(request_id, str) or not _is_ulid(request_id):
        raise ProtocolError("invalid_request_id")

    action = payload.get("action")
    if action not in {"health", "synthesize", "install", "cancel_install", "status"}:
        raise ProtocolError("unsupported_action")

    return request_id, action


def _receive_frame(connection: socket.socket, maximum_bytes: int) -> bytes:
    header = _receive_exact(connection, _HEADER.size)
    (length,) = _HEADER.unpack(header)
    if length < 2 or length > maximum_bytes:
        raise ProtocolError("invalid_frame_length")
    return _receive_exact(connection, length)


def _receive_exact(connection: socket.socket, length: int) -> bytes:
    chunks: list[bytes] = []
    remaining = length
    while remaining > 0:
        chunk = connection.recv(remaining)
        if not chunk:
            raise ProtocolError("truncated_frame")
        chunks.append(chunk)
        remaining -= len(chunk)
    return b"".join(chunks)


def _decode_object(payload: bytes) -> dict[str, Any]:
    try:
        decoded = json.loads(payload.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exception:
        raise ProtocolError("invalid_json") from exception
    if not isinstance(decoded, dict):
        raise ProtocolError("invalid_json_object")
    return decoded


def _is_ulid(value: str) -> bool:
    if len(value) != 26:
        return False
    alphabet = frozenset("0123456789ABCDEFGHJKMNPQRSTVWXYZ")
    return all(character in alphabet for character in value.upper())
