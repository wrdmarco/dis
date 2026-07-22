from __future__ import annotations

import os
import tempfile
import unittest
import wave

import numpy

from dis_tts_engine.audio import AudioValidationError, write_pcm16_mono_wav


class AudioWriterTest(unittest.TestCase):
    def test_writes_valid_pcm16_mono_wave(self) -> None:
        descriptor, path = tempfile.mkstemp(suffix=".wav")
        self.addCleanup(lambda: os.path.exists(path) and os.unlink(path))
        try:
            samples = numpy.sin(numpy.linspace(0, 100, 24_000, dtype=numpy.float32)) * 0.2
            result = write_pcm16_mono_wav(descriptor, samples, 24_000)
        finally:
            os.close(descriptor)

        self.assertEqual(1_000, result.duration_ms)
        with wave.open(path, "rb") as wav:
            self.assertEqual(1, wav.getnchannels())
            self.assertEqual(2, wav.getsampwidth())
            self.assertEqual(24_000, wav.getframerate())
            self.assertEqual(24_000, wav.getnframes())

    def test_rejects_non_finite_waveform(self) -> None:
        descriptor, path = tempfile.mkstemp(suffix=".wav")
        self.addCleanup(lambda: os.path.exists(path) and os.unlink(path))
        try:
            with self.assertRaisesRegex(AudioValidationError, "invalid_waveform_values"):
                write_pcm16_mono_wav(descriptor, numpy.array([numpy.nan] * 2_400), 24_000)
        finally:
            os.close(descriptor)
