from __future__ import annotations

import unittest

from dis_tts_engine import AUDIO_RECIPE_REVISION
from dis_tts_engine.secure_files import SecureFileError, validate_job


class SecureJobValidationTest(unittest.TestCase):
    def test_accepts_only_normalized_nl_nl_job(self) -> None:
        parsed = validate_job({
            "text": "  Oproep.   Kerkstraat twaalf. ",
            "locale": "nl-NL",
            "model_id": "chatterbox_multilingual_v3",
            "audio_recipe_revision": AUDIO_RECIPE_REVISION,
            "voice_reference_basename": "01KXT7Z2P01H86GCGV1ZK3D5QD.reference",
            "voice_transcript": " Dit is mijn stem. ",
        })

        self.assertEqual("Oproep. Kerkstraat twaalf.", parsed[0])
        self.assertEqual("nl-NL", parsed[1])
        self.assertEqual(AUDIO_RECIPE_REVISION, parsed[3])
        self.assertEqual("Dit is mijn stem.", parsed[5])

    def test_rejects_path_traversal_basename(self) -> None:
        with self.assertRaisesRegex(SecureFileError, "invalid_reference_basename"):
            validate_job({
                "text": "Oproep.",
                "locale": "nl-NL",
                "model_id": "chatterbox_multilingual_v3",
                "audio_recipe_revision": AUDIO_RECIPE_REVISION,
                "voice_reference_basename": "../voice.reference",
            })

    def test_rejects_transcript_without_reference(self) -> None:
        with self.assertRaisesRegex(SecureFileError, "voice_transcript_without_reference"):
            validate_job({
                "text": "Oproep.",
                "locale": "nl-NL",
                "model_id": "chatterbox_multilingual_v3",
                "audio_recipe_revision": AUDIO_RECIPE_REVISION,
                "voice_transcript": "Niet toegestaan zonder fragment.",
            })

    def test_rejects_flemish_locale(self) -> None:
        with self.assertRaisesRegex(SecureFileError, "unsupported_locale"):
            validate_job({
                "text": "Oproep.",
                "locale": "nl-BE",
                "model_id": "chatterbox_multilingual_v3",
                "audio_recipe_revision": AUDIO_RECIPE_REVISION,
            })

    def test_rejects_a_stale_audio_recipe(self) -> None:
        with self.assertRaisesRegex(SecureFileError, "audio_recipe_mismatch"):
            validate_job({
                "text": "Oproep.",
                "locale": "nl-NL",
                "model_id": "voxcpm2",
                "audio_recipe_revision": "legacy-segmented-v1",
            })
