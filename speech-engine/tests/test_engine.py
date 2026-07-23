from __future__ import annotations

import json
import os
import tempfile
import unittest
import wave
from contextlib import nullcontext
from pathlib import Path
from unittest.mock import patch

import numpy

from dis_tts_engine import AUDIO_RECIPE_REVISION
from dis_tts_engine.adapters import SynthesizedWaveform
from dis_tts_engine.catalog import CHATTERBOX_MULTILINGUAL_V3, VOXCPM2
from dis_tts_engine.engine import SpeechEngine, _speaker_seed_material


class _FakeInstaller:
    def __init__(self, model_path: Path) -> None:
        self.model_path = model_path
        self.cancel_count = 0

    def installed_path(self, _spec: object) -> Path:
        return self.model_path

    def status(self, _spec: object) -> dict[str, object]:
        return {"status": "installed", "progress_percent": 100}

    def start(self, _spec: object, _request_id: str) -> dict[str, object]:
        return self.status(_spec)

    def cancel(self, _spec: object) -> dict[str, object]:
        self.cancel_count += 1
        return {"status": "not_installed", "progress_percent": 0}

    def cancel_all_and_wait(self, timeout_seconds: float = 10.0) -> None:
        assert timeout_seconds > 0
        self.cancel_count += 1


class _FakeAdapter:
    def __init__(self) -> None:
        self.reference_was_private = False
        self.closed = False

    def synthesize(
        self,
        text: str,
        locale: str,
        reference_path: Path | None,
        reference_transcript: str | None,
        deadline_monotonic: float,
    ) -> SynthesizedWaveform:
        assert deadline_monotonic > 0
        self.reference_was_private = (
            reference_path is not None
            and reference_path.is_file()
            and reference_transcript == "Mijn eigen stemfragment."
        )
        self.assertions = (text, locale)
        samples = numpy.sin(numpy.linspace(0, 100, 24_000, dtype=numpy.float32)) * 0.2
        return SynthesizedWaveform(samples=samples, sample_rate=24_000)

    def close(self) -> None:
        self.closed = True


@unittest.skipUnless(os.name == "posix" and hasattr(os, "O_DIRECTORY"), "secure dir-fd contract is Linux-only")
class EngineIntegrationTest(unittest.TestCase):
    def test_consumes_private_inputs_and_atomically_publishes_wave(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            staging = Path(directory) / "staging"
            model_path = Path(directory) / "model"
            voice_state_path = Path(directory) / "state" / "voices"
            staging.mkdir(mode=0o750)
            model_path.mkdir(mode=0o750)
            voice_state_path.parent.mkdir(mode=0o750)
            job_id = "01KXT7Z2P01H86GCGV1ZK3D5QD"
            reference_id = "01KXT7Z2P01H86GCGV1ZK3D5QE"
            job_path = staging / f"{job_id}.job.json"
            reference_path = staging / f"{reference_id}.reference"
            job_path.write_text(json.dumps({
                "text": "Oproep. Kerkstraat twaalf.",
                "locale": "nl-NL",
                "model_id": "chatterbox_multilingual_v3",
                "audio_recipe_revision": AUDIO_RECIPE_REVISION,
                "voice_reference_basename": reference_path.name,
                "voice_transcript": "Mijn eigen stemfragment.",
            }), encoding="utf-8")
            reference_path.write_bytes(b"RIFF" + b"\0" * 2_048)
            os.chmod(job_path, 0o600)
            os.chmod(reference_path, 0o600)
            adapter = _FakeAdapter()
            engine = SpeechEngine(
                staging,
                _FakeInstaller(model_path),  # type: ignore[arg-type]
                voice_state_path,
                adapter_factory=lambda _name, _path, _voice_state_path: adapter,
            )

            with patch("dis_tts_engine.engine.deterministic_inference_seed", return_value=nullcontext()):
                result = engine.synthesize({
                    "model_id": "chatterbox_multilingual_v3",
                    "job_basename": job_path.name,
                    "output_basename": f"{job_id}.wav",
                })

            self.assertFalse(job_path.exists())
            self.assertFalse(reference_path.exists())
            self.assertTrue(adapter.reference_was_private)
            self.assertEqual(1_000, result["duration_ms"])
            output = staging / f"{job_id}.wav"
            with wave.open(str(output), "rb") as wav:
                self.assertEqual(1, wav.getnchannels())
                self.assertEqual(24_000, wav.getframerate())
            self.assertEqual(0o640, output.stat().st_mode & 0o777)
            self.assertEqual([], list(staging.glob(".engine-reference-*")))
            engine.close()
            self.assertTrue(adapter.closed)


class SpeakerSeedTest(unittest.TestCase):
    def test_built_in_seed_is_voice_scoped_and_not_text_scoped(self) -> None:
        self.assertEqual(
            "built-in:voxcpm2-nl-nl-female-journalistic-v4",
            _speaker_seed_material(VOXCPM2, None),
        )

    def test_profile_seed_is_stable_for_the_reference_and_changes_with_it(self) -> None:
        first = _speaker_seed_material(CHATTERBOX_MULTILINGUAL_V3, b"voice one")
        repeated = _speaker_seed_material(CHATTERBOX_MULTILINGUAL_V3, b"voice one")
        second = _speaker_seed_material(CHATTERBOX_MULTILINGUAL_V3, b"voice two")

        self.assertEqual(first, repeated)
        self.assertNotEqual(first, second)
