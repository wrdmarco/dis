from __future__ import annotations

import logging
import os
import signal
import stat
import threading
from pathlib import Path

from .engine import EngineError, SpeechEngine
from .installer import InstallationStateStore, ModelInstaller
from .server import SpeechUnixServer


def main() -> None:
    os.umask(0o007)
    logging.basicConfig(
        level=os.getenv("DIS_TTS_LOG_LEVEL", "INFO").upper(),
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )

    models_root = _directory("DIS_TTS_MODELS_ROOT", "/opt/dis-data/tts/models", create=True)
    state_root = _directory("DIS_TTS_STATE_ROOT", "/opt/dis-data/tts/state", create=True)
    staging_root = _directory("DIS_TTS_STAGING_ROOT", "/opt/dis-data/tts/staging", create=False)
    socket_path = Path(os.getenv("DIS_TTS_SOCKET_PATH", "/run/dis-tts/engine.sock"))

    state_store = InstallationStateStore(state_root)
    installer = ModelInstaller(models_root, state_store)
    engine = SpeechEngine(
        staging_root,
        installer,
        synthesis_deadline_seconds=_integer("DIS_TTS_SYNTHESIS_DEADLINE_SECONDS", 14_300, 30, 14_300),
    )
    server = SpeechUnixServer(socket_path, engine)

    def request_shutdown(_signal: int, _frame: object) -> None:
        threading.Thread(target=server.shutdown, name="tts-shutdown", daemon=True).start()

    signal.signal(signal.SIGTERM, request_shutdown)
    signal.signal(signal.SIGINT, request_shutdown)

    try:
        server.serve_forever(poll_interval=0.25)
    finally:
        server.server_close()
        engine.close()


def _directory(environment_key: str, default: str, create: bool) -> Path:
    path = Path(os.getenv(environment_key, default))
    if not path.is_absolute():
        raise EngineError("invalid_runtime_directory")
    if create:
        path.mkdir(mode=0o750, parents=True, exist_ok=True)
    try:
        metadata = path.lstat()
    except OSError as exception:
        raise EngineError("invalid_runtime_directory") from exception
    if not stat.S_ISDIR(metadata.st_mode) or path.is_symlink() or metadata.st_mode & stat.S_IWOTH:
        raise EngineError("invalid_runtime_directory")
    return path


def _integer(environment_key: str, default: int, minimum: int, maximum: int) -> int:
    try:
        value = int(os.getenv(environment_key, str(default)))
    except ValueError as exception:
        raise EngineError("invalid_runtime_configuration") from exception
    if not minimum <= value <= maximum:
        raise EngineError("invalid_runtime_configuration")
    return value


if __name__ == "__main__":
    main()
