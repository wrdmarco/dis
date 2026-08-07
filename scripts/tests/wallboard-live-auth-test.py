#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import http.client
import importlib.util
import io
import json
import pathlib
import sys
import threading
from contextlib import redirect_stderr


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "wallboard-live-auth.py"
SPEC = importlib.util.spec_from_file_location("wallboard_live_auth", MODULE_PATH)
if SPEC is None or SPEC.loader is None:
    raise SystemExit("auth helper could not be loaded")
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)

KEY = "Abcdefghijklmnopqrstuvwxyz0123456789"
WRONG_KEY = "Zbcdefghijklmnopqrstuvwxyz0123456789"
EXPECTED_HASH = hashlib.sha256(KEY.encode("ascii")).hexdigest()
MODULE.AuthHandler.expected_hash = EXPECTED_HASH
MODULE.AuthHandler.attempt_limiter = MODULE.AttemptLimiter()


def request(port: int, payload: object, *, content_type: str = "application/json") -> int:
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=2)
    body = json.dumps(payload).encode("utf-8")
    connection.request("POST", "/auth", body=body, headers={"Content-Type": content_type})
    response = connection.getresponse()
    response.read()
    connection.close()
    return response.status


clock = [0.0]
limiter = MODULE.AttemptLimiter(window_seconds=60.0, maximum_sources=2, clock=lambda: clock[0])
assert limiter.allow("publish", "203.0.113.1", 2)
assert limiter.allow("publish", "203.0.113.1", 2)
assert not limiter.allow("publish", "203.0.113.1", 2)
clock[0] = 61.0
assert limiter.allow("publish", "203.0.113.1", 2)

audit_output = io.StringIO()
with redirect_stderr(audit_output):
    server = MODULE.BoundedThreadingHTTPServer(("127.0.0.1", 0), MODULE.AuthHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    port = server.server_address[1]
    try:
        assert MODULE.constant_time_path_match(f"live/{KEY}", EXPECTED_HASH)
        assert not MODULE.constant_time_path_match(f"live/{WRONG_KEY}", EXPECTED_HASH)
        assert not MODULE.constant_time_path_match("live/" + ("a" * 32), hashlib.sha256(("a" * 32).encode()).hexdigest())
        assert request(port, {"action": "publish", "protocol": "rtmp", "path": f"live/{KEY}", "ip": "203.0.113.10"}) == 204
        assert request(port, {"action": "publish", "protocol": "rtmp", "path": f"live/{WRONG_KEY}", "ip": "203.0.113.10"}) == 403
        assert request(port, {"action": "read", "protocol": "rtmp", "path": f"live/{KEY}", "ip": "127.0.0.1"}) == 204
        assert request(port, {"action": "read", "protocol": "rtmp", "path": f"live/{KEY}", "ip": "203.0.113.10"}) == 403
        assert request(port, {"action": "publish", "protocol": "rtsp", "path": f"live/{KEY}", "ip": "203.0.113.10"}) == 403
        assert request(port, [], content_type="application/json") == 403
        assert request(port, {}, content_type="text/plain") == 400
        for _ in range(MODULE.PUBLIC_AUTH_ATTEMPTS_PER_MINUTE):
            assert request(port, {"action": "publish", "protocol": "rtmp", "path": f"live/{KEY}", "ip": "203.0.113.200"}) == 204
        assert request(port, {"action": "publish", "protocol": "rtmp", "path": f"live/{KEY}", "ip": "203.0.113.200"}) == 403
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2)

audit_text = audit_output.getvalue()
assert "action=publish outcome=allowed source=" in audit_text
assert "outcome=denied" in audit_text
assert "outcome=rate_limited" in audit_text
assert KEY not in audit_text
assert WRONG_KEY not in audit_text
assert "203.0.113." not in audit_text

print("Wallboard live-stream auth helper contract passed.")
