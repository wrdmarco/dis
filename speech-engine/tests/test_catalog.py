from __future__ import annotations

import unittest

from dis_tts_engine.adapters import VOXCPM2_BUILT_IN_VOICE_DESIGN_REVISION
from dis_tts_engine.catalog import CHATTERBOX_MULTILINGUAL_V3, VOXCPM2, model_spec


class CatalogTest(unittest.TestCase):
    def test_model_manifest_digest_is_stable(self) -> None:
        self.assertEqual(
            "f4f54a01de9d1354e282eab28a15ffeabac9d87d5f3a28fe72ed2548e9cd2286",
            CHATTERBOX_MULTILINGUAL_V3.weights_sha256,
        )
        self.assertEqual(CHATTERBOX_MULTILINGUAL_V3, model_spec("chatterbox_multilingual_v3"))

    def test_voxcpm2_manifest_digest_is_stable(self) -> None:
        self.assertEqual(4_960_722_408, VOXCPM2.download_bytes)
        self.assertEqual("acee75a3e2125be4089f81fa8259372a797e87c799ab13dad27ce38fd385e126", VOXCPM2.weights_sha256)
        self.assertEqual("voxcpm2-nl-nl-female-journalistic-v4", VOXCPM2.built_in_voice_design_revision)
        self.assertEqual(VOXCPM2_BUILT_IN_VOICE_DESIGN_REVISION, VOXCPM2.built_in_voice_design_revision)
        self.assertEqual(VOXCPM2, model_spec("voxcpm2"))

    def test_unknown_model_is_not_allowlisted(self) -> None:
        with self.assertRaisesRegex(KeyError, "model_not_allowlisted"):
            model_spec("untrusted/model")
