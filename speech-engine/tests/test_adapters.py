from __future__ import annotations

import tempfile
import time
import unittest
from contextlib import nullcontext
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import numpy

from dis_tts_engine.adapters import (
    VOXCPM2_BUILT_IN_REFERENCE_TRANSCRIPT,
    VOXCPM2_BUILT_IN_VOICE_DESIGN_REVISION,
    VoxCpm2Adapter,
)


class _FakeStream:
    def __init__(self, chunks: list[numpy.ndarray]) -> None:
        self._chunks = chunks
        self.closed = False

    def __iter__(self):
        return iter(self._chunks)

    def close(self) -> None:
        self.closed = True


class _FakeVoxModel:
    def __init__(self) -> None:
        self.tts_model = SimpleNamespace(sample_rate=24_000)
        self.calls: list[tuple[str, dict[str, object]]] = []

    def generate_streaming(self, text: str, **options: object) -> _FakeStream:
        self.calls.append((text, options))
        samples = numpy.sin(
            numpy.linspace(0, 500, 48_000, dtype=numpy.float32),
        ) * numpy.float32(0.2)
        return _FakeStream([samples])


def _adapter(state_root: Path, model: _FakeVoxModel) -> VoxCpm2Adapter:
    adapter = VoxCpm2Adapter.__new__(VoxCpm2Adapter)
    adapter._voice_state_root = state_root
    adapter._built_in_reference_path = (
        state_root / f"{VOXCPM2_BUILT_IN_VOICE_DESIGN_REVISION}.wav"
    )
    adapter._model = model
    return adapter


class VoxCpm2AdapterTest(unittest.TestCase):
    def test_built_in_voice_is_materialized_once_and_anchors_every_target(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            state_root = Path(directory)
            model = _FakeVoxModel()
            adapter = _adapter(state_root, model)

            with patch(
                "dis_tts_engine.adapters.deterministic_inference_seed",
                return_value=nullcontext(),
            ):
                adapter.synthesize(
                    "Dit is de eerste melding.",
                    "nl-NL",
                    None,
                    None,
                    time.monotonic() + 30,
                )
                adapter.synthesize(
                    "Dit is een ander adres.",
                    "nl-NL",
                    None,
                    None,
                    time.monotonic() + 30,
                )

            self.assertEqual(3, len(model.calls))
            anchor_text, anchor_options = model.calls[0]
            self.assertIn(VOXCPM2_BUILT_IN_REFERENCE_TRANSCRIPT, anchor_text)
            self.assertNotIn("reference_wav_path", anchor_options)

            first_text, first_options = model.calls[1]
            second_text, second_options = model.calls[2]
            self.assertEqual("Dit is de eerste melding.", first_text)
            self.assertEqual("Dit is een ander adres.", second_text)
            self.assertEqual(
                first_options["reference_wav_path"],
                first_options["prompt_wav_path"],
            )
            self.assertEqual(
                first_options["reference_wav_path"],
                second_options["reference_wav_path"],
            )
            self.assertEqual(
                VOXCPM2_BUILT_IN_REFERENCE_TRANSCRIPT,
                first_options["prompt_text"],
            )
            self.assertTrue(adapter._built_in_reference_path.is_file())

            restarted_model = _FakeVoxModel()
            restarted_adapter = _adapter(state_root, restarted_model)
            restarted_adapter.synthesize(
                "De server is opnieuw gestart.",
                "nl-NL",
                None,
                None,
                time.monotonic() + 30,
            )

            self.assertEqual(1, len(restarted_model.calls))
            restarted_text, restarted_options = restarted_model.calls[0]
            self.assertEqual("De server is opnieuw gestart.", restarted_text)
            self.assertEqual(
                first_options["reference_wav_path"],
                restarted_options["reference_wav_path"],
            )

    def test_profile_transcript_uses_reference_for_timbre_and_prompt(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            state_root = Path(directory)
            profile = state_root / "profile.wav"
            profile.write_bytes(b"profile")
            model = _FakeVoxModel()
            adapter = _adapter(state_root, model)

            adapter.synthesize(
                "Melding met eigen stem.",
                "nl-NL",
                profile,
                "Dit is mijn stemfragment.",
                time.monotonic() + 30,
            )

            self.assertEqual(1, len(model.calls))
            _, options = model.calls[0]
            self.assertEqual(str(profile), options["reference_wav_path"])
            self.assertEqual(str(profile), options["prompt_wav_path"])
            self.assertEqual("Dit is mijn stemfragment.", options["prompt_text"])
