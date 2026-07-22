from __future__ import annotations

import json
import socket
import struct
import unittest

from dis_tts_engine.protocol import ProtocolError, receive_request, request_identity


class ProtocolTest(unittest.TestCase):
    def test_accepts_bounded_model_install_cancellation_action(self) -> None:
        request_id = "01KXT7Z2P01H86GCGV1ZK3D5QD"

        self.assertEqual(
            (request_id, "cancel_install"),
            request_identity({
                "protocol_version": 1,
                "request_id": request_id,
                "action": "cancel_install",
                "payload": {"model_id": "voxcpm2"},
            }),
        )

    def test_receives_one_big_endian_json_frame(self) -> None:
        left, right = socket.socketpair()
        self.addCleanup(left.close)
        self.addCleanup(right.close)
        request = {
            "protocol_version": 1,
            "request_id": "01KXT7Z2P01H86GCGV1ZK3D5QD",
            "action": "health",
            "payload": [],
        }
        encoded = json.dumps(request).encode("utf-8")
        left.sendall(struct.pack(">I", len(encoded)) + encoded)

        self.assertEqual(request, receive_request(right))
        self.assertEqual((request["request_id"], "health"), request_identity(request))

    def test_rejects_extra_envelope_fields(self) -> None:
        with self.assertRaisesRegex(ProtocolError, "invalid_request_envelope"):
            request_identity({
                "protocol_version": 1,
                "request_id": "01KXT7Z2P01H86GCGV1ZK3D5QD",
                "action": "health",
                "payload": [],
                "unexpected": True,
            })

    def test_rejects_oversized_frame_before_reading_body(self) -> None:
        left, right = socket.socketpair()
        self.addCleanup(left.close)
        self.addCleanup(right.close)
        left.sendall(struct.pack(">I", 65_537))

        with self.assertRaisesRegex(ProtocolError, "invalid_frame_length"):
            receive_request(right)
