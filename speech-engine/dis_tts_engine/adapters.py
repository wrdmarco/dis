from __future__ import annotations

import gc
import hashlib
import random
import time
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterator, Protocol

_TORCH_CONFIGURED = False


class AdapterError(Exception):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


@dataclass(frozen=True, slots=True)
class SynthesizedWaveform:
    samples: Any
    sample_rate: int


class SpeechModelAdapter(Protocol):
    def synthesize(
        self,
        text: str,
        locale: str,
        reference_path: Path | None,
        reference_transcript: str | None,
        deadline_monotonic: float,
    ) -> SynthesizedWaveform: ...

    def close(self) -> None: ...


class ChatterboxV3Adapter:
    def __init__(self, model_path: Path) -> None:
        try:
            _configure_torch_threads()
            from chatterbox.mtl_tts import ChatterboxMultilingualTTS

            self._model = ChatterboxMultilingualTTS.from_local(
                model_path,
                device="cpu",
                t3_model="v3",
            )
        except Exception as exception:
            raise AdapterError("model_load_failed") from exception

    def synthesize(
        self,
        text: str,
        locale: str,
        reference_path: Path | None,
        reference_transcript: str | None,
        deadline_monotonic: float,
    ) -> SynthesizedWaveform:
        del reference_transcript
        if locale != "nl-NL":
            raise AdapterError("unsupported_locale")
        try:
            waveform = self._model.generate(
                text,
                language_id="nl",
                audio_prompt_path=str(reference_path) if reference_path is not None else None,
                exaggeration=0.5,
                cfg_weight=0.5,
            )
            if time.monotonic() > deadline_monotonic:
                raise AdapterError("synthesis_deadline_exceeded")
            samples = waveform.squeeze().detach().float().cpu().numpy()
            return SynthesizedWaveform(samples=samples, sample_rate=int(self._model.sr))
        except AdapterError:
            raise
        except Exception as exception:
            raise AdapterError("synthesis_failed") from exception

    def close(self) -> None:
        self._model = None
        gc.collect()


class VoxCpm2Adapter:
    def __init__(self, model_path: Path) -> None:
        try:
            _configure_torch_threads()
            from voxcpm import VoxCPM

            self._model = VoxCPM.from_pretrained(
                str(model_path),
                load_denoiser=False,
                local_files_only=True,
                optimize=False,
                device="cpu",
            )
        except Exception as exception:
            raise AdapterError("model_load_failed") from exception

    def synthesize(
        self,
        text: str,
        locale: str,
        reference_path: Path | None,
        reference_transcript: str | None,
        deadline_monotonic: float,
    ) -> SynthesizedWaveform:
        if locale != "nl-NL":
            raise AdapterError("unsupported_locale")
        effective_text = text
        if reference_path is None:
            effective_text = f"({DEFAULT_VOXCPM2_VOICE_DESIGN}){text}"
        options: dict[str, Any] = {
            "cfg_value": 2.0,
            "inference_timesteps": 10,
            "max_len": 512,
            "normalize": False,
            "denoise": False,
            "retry_badcase": False,
        }
        if reference_path is not None:
            if not reference_transcript:
                options["reference_wav_path"] = str(reference_path)
            else:
                options["prompt_wav_path"] = str(reference_path)
                options["prompt_text"] = reference_transcript
        try:
            import numpy

            chunks: list[Any] = []
            stream = self._model.generate_streaming(effective_text, **options)
            try:
                for chunk in stream:
                    if time.monotonic() > deadline_monotonic:
                        raise AdapterError("synthesis_deadline_exceeded")
                    chunks.append(chunk)
            finally:
                stream.close()
            if not chunks:
                raise AdapterError("synthesis_failed")
            samples = numpy.concatenate(chunks)
            sample_rate = int(self._model.tts_model.sample_rate)
            return SynthesizedWaveform(samples=samples, sample_rate=sample_rate)
        except AdapterError:
            raise
        except Exception as exception:
            raise AdapterError("synthesis_failed") from exception

    def close(self) -> None:
        self._model = None
        gc.collect()


def create_adapter(adapter: str, model_path: Path) -> SpeechModelAdapter:
    if adapter == "chatterbox_v3":
        return ChatterboxV3Adapter(model_path)
    if adapter == "voxcpm2":
        return VoxCpm2Adapter(model_path)
    raise AdapterError("unsupported_model_adapter")


VOXCPM2_BUILT_IN_VOICE_DESIGN_REVISION = "voxcpm2-nl-nl-female-pa-v1"
DEFAULT_VOXCPM2_VOICE_DESIGN = (
    "A clear, natural adult Dutch female public-address voice, calm and professional, "
    "speaking at a measured but not slow pace"
)


def _configure_torch_threads() -> None:
    global _TORCH_CONFIGURED
    if _TORCH_CONFIGURED:
        return
    import os
    import torch

    raw = os.getenv("DIS_TTS_TORCH_THREADS", "16")
    try:
        threads = int(raw)
    except ValueError as exception:
        raise AdapterError("invalid_runtime_thread_count") from exception
    if not 1 <= threads <= 64:
        raise AdapterError("invalid_runtime_thread_count")
    torch.set_num_threads(threads)
    torch.set_num_interop_threads(1)
    _TORCH_CONFIGURED = True


@contextmanager
def deterministic_inference_seed(text: str, model_id: str) -> Iterator[None]:
    seed = int.from_bytes(
        hashlib.sha256(f"{model_id}\0{text}".encode("utf-8")).digest()[:8],
        byteorder="big",
        signed=False,
    ) % (2**32)
    try:
        import numpy
        import torch
    except ImportError as exception:
        raise AdapterError("runtime_dependency_missing") from exception

    python_state = random.getstate()
    numpy_state = numpy.random.get_state()
    with torch.random.fork_rng(devices=[]):
        try:
            random.seed(seed)
            numpy.random.seed(seed)
            torch.manual_seed(seed)
            yield
        finally:
            random.setstate(python_state)
            numpy.random.set_state(numpy_state)
