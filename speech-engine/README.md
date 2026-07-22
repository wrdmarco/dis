# DIS self-hosted speech engine

This service provides the CPU-only server speech used by DIS. It is deliberately
separate from the Laravel queue and push workers: an alarm can wait at most ten
seconds for already cached audio and is then released without server audio. The
mobile clients always keep their fixed `nl-NL` device voice as the operational
fallback when TTS is enabled.

## Supported models

The runtime accepts only the immutable catalog in `dis_tts_engine/catalog.py`.
Model revisions, every required file size and every SHA-256 digest are pinned in
source. Model weights are downloaded only after an authorized administrator
starts an installation; weights are never committed to this repository.

- Chatterbox Multilingual V3 — MIT, roughly 3.2 GB, voice profile required.
- VoxCPM2 — Apache-2.0, roughly 5.0 GB, fixed Dutch female speaker anchor or a
  consented voice profile. The built-in anchor is generated once with the pinned
  voice recipe, retained under the private engine state root and reused as both
  the timbre reference and prompt for every utterance.

Only `nl-NL` is accepted. There is no Flemish fallback and no client-selectable
voice or speaking rate. Server-side rate adjustment is applied when the final
AAC/M4A asset is encoded.

## CPU expectations

The production target is Python 3.11 on Linux x86-64. Both official runtimes
support CPU execution; neither official VoxCPM2 runtime nor this service uses
Intel integrated graphics. Controlled QEMU measurements showed that uncached
generation misses the ten-second alarm gate by a wide margin (Chatterbox warm
RTF about 12; VoxCPM2 slower still). Those figures are not an i9-13900 hardware
benchmark, but they make cache pre-generation a correctness requirement rather
than an optional optimization.

DIS therefore caches semantic phrases and immutable composites. It never joins
individually synthesized words. Every phrase uses a speaker-scoped deterministic
seed and is normalized to the same speech loudness before it enters the cache.
Saving an incident can prewarm the configured templates, while a cold miss
remains fail-safe and cannot delay push delivery.

## Runtime boundary

Laravel communicates over a framed JSON protocol on an AF_UNIX socket. Text and
voice references enter through a private staging directory and are consumed
with no-follow, single-link checks. The engine returns validated mono PCM WAV;
Laravel applies two-pass EBU R128 normalization and validates and encodes the
final AAC/M4A object. The pinned audio-recipe revision is checked at the database,
staging-job and engine protocol boundaries so mixed releases cannot claim the
new consistency contract.

Model downloads run in a dedicated child process. A real alarm atomically
cancels and waits for any installation before synthesis starts, and installations
are rejected while synthesis is active. Runtime synthesis never accesses the
internet. Generated cache objects are reproducible and excluded from backups;
encrypted consented voice references are retained by the application backup.
