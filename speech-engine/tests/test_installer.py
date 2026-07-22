from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from dis_tts_engine.installer import InstallationError, InstallationStateStore, ModelInstaller


class InstallationStateStoreTest(unittest.TestCase):
    def test_accepts_allowlisted_model_id_with_underscores(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            store = InstallationStateStore(Path(directory))

            self.assertEqual(
                Path(directory) / "chatterbox_multilingual_v3.json",
                store._path("chatterbox_multilingual_v3"),  # noqa: SLF001 - validates the filename boundary.
            )

    def test_rejects_model_id_path_characters(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            store = InstallationStateStore(Path(directory))

            with self.assertRaisesRegex(InstallationError, "invalid_model_id"):
                store.write("../model", {"status": "failed"})


class ModelInstallerStartupCleanupTest(unittest.TestCase):
    def test_removes_allowlisted_orphan_install_tree(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            models = root / "models"
            state = root / "state"
            orphan = models / "voxcpm2" / ".installing-01KXT7Z2P01H86GCGV1ZK3D5QF"
            orphan.mkdir(parents=True)
            (orphan / "partial.bin").write_bytes(b"partial")

            ModelInstaller(models, InstallationStateStore(state))

            self.assertFalse(orphan.exists())

    def test_rejects_unexpected_install_staging_name(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            models = root / "models"
            state = root / "state"
            unexpected = models / "voxcpm2" / ".installing-not-a-request"
            unexpected.mkdir(parents=True)

            with self.assertRaisesRegex(InstallationError, "unsafe_installation_staging"):
                ModelInstaller(models, InstallationStateStore(state))
