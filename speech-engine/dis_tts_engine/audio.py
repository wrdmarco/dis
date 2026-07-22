from __future__ import annotations

import os
import wave
from dataclasses import dataclass
from typing import Any


class AudioValidationError(Exception):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


@dataclass(frozen=True, slots=True)
class WrittenAudio:
    sample_rate: int
    duration_ms: int
    sample_count: int
    channels: int = 1
    sample_width_bytes: int = 2


def write_pcm16_mono_wav(descriptor: int, samples: Any, sample_rate: int) -> WrittenAudio:
    try:
        import numpy
    except ImportError as exception:
        raise AudioValidationError("runtime_dependency_missing") from exception

    if not isinstance(sample_rate, int) or not 16_000 <= sample_rate <= 96_000:
        raise AudioValidationError("invalid_sample_rate")

    try:
        waveform = numpy.asarray(samples, dtype=numpy.float32).squeeze()
    except Exception as exception:
        raise AudioValidationError("invalid_waveform") from exception
    if waveform.ndim != 1:
        raise AudioValidationError("invalid_waveform_channels")
    if waveform.size < sample_rate // 10 or waveform.size > sample_rate * 120:
        raise AudioValidationError("invalid_waveform_duration")
    if not bool(numpy.isfinite(waveform).all()):
        raise AudioValidationError("invalid_waveform_values")
    peak = float(numpy.max(numpy.abs(waveform)))
    if peak < 1e-6 or peak > 16:
        raise AudioValidationError("invalid_waveform_level")

    pcm = numpy.rint(numpy.clip(waveform, -1.0, 1.0) * 32_767.0).astype("<i2", copy=False)
    duplicate = os.dup(descriptor)
    try:
        with os.fdopen(duplicate, "wb", closefd=True) as output:
            duplicate = -1
            with wave.open(output, "wb") as wav:
                wav.setnchannels(1)
                wav.setsampwidth(2)
                wav.setframerate(sample_rate)
                wav.writeframes(pcm.tobytes(order="C"))
            output.flush()
            os.fsync(output.fileno())
    finally:
        if duplicate >= 0:
            os.close(duplicate)

    return WrittenAudio(
        sample_rate=sample_rate,
        duration_ms=round(int(waveform.size) * 1000 / sample_rate),
        sample_count=int(waveform.size),
    )
