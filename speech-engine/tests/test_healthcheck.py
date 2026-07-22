from __future__ import annotations

import unittest

from dis_tts_engine import PROTOCOL_VERSION
from dis_tts_engine.healthcheck import _REQUEST_ID, _valid_health_response


class HealthcheckResponseTest(unittest.TestCase):
    def test_accepts_only_a_ready_matching_engine_response(self) -> None:
        response = {
            "protocol_version": PROTOCOL_VERSION,
            "request_id": _REQUEST_ID,
            "ok": True,
            "result": {"status": "ok", "ready": True},
        }

        self.assertTrue(_valid_health_response(response))
        self.assertFalse(_valid_health_response(response | {"protocol_version": PROTOCOL_VERSION - 1}))
        self.assertFalse(_valid_health_response(response | {"request_id": "0" * 26}))
        self.assertFalse(_valid_health_response(response | {"result": {"status": "ok", "ready": False}}))
