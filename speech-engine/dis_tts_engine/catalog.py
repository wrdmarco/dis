from __future__ import annotations

import hashlib
from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class WeightFile:
    relative_path: str
    size_bytes: int
    sha256: str


@dataclass(frozen=True, slots=True)
class ModelSpec:
    model_id: str
    display_name: str
    adapter: str
    repository: str
    revision: str
    parameter_count: int
    license_spdx: str
    weight_files: tuple[WeightFile, ...]
    allow_patterns: tuple[str, ...]
    built_in_voice_design_revision: str | None = None

    @property
    def download_bytes(self) -> int:
        return sum(item.size_bytes for item in self.weight_files)

    @property
    def weights_sha256(self) -> str:
        digest = hashlib.sha256()
        for item in sorted(self.weight_files, key=lambda entry: entry.relative_path):
            digest.update(item.relative_path.encode("utf-8"))
            digest.update(b"\0")
            digest.update(str(item.size_bytes).encode("ascii"))
            digest.update(b"\0")
            digest.update(item.sha256.encode("ascii"))
            digest.update(b"\n")
        return digest.hexdigest()


CHATTERBOX_MULTILINGUAL_V3 = ModelSpec(
    model_id="chatterbox_multilingual_v3",
    display_name="Chatterbox Multilingual V3",
    adapter="chatterbox_v3",
    repository="ResembleAI/chatterbox",
    revision="5bb1f6ee58e50c3b8d408bc82a6d3740c2db6e18",
    parameter_count=500_000_000,
    license_spdx="MIT",
    weight_files=(
        WeightFile("Cangjie5_TC.json", 1_920_163, "7073fd9de919443ae88e0bd2449917a65fe54898a4413ed1edcc4b67f28bce8c"),
        WeightFile("conds.pt", 107_374, "6552d70568833628ba019c6b03459e77fe71ca197d5c560cef9411bee9d87f4e"),
        WeightFile("grapheme_mtl_merged_expanded_v1.json", 69_989, "69632f47220a788a52ce2661d096453c5655e9bf25289d89a8d832c46ee07dbf"),
        WeightFile("s3gen.pt", 1_057_165_844, "9b9ff07e60b20c136e2b1b3d7563a24604e8d2c4c267888d1ee929dd0151d2a3"),
        WeightFile("t3_mtl23ls_v3.safetensors", 2_143_989_928, "5abca8321ede76f8e61f1cc0d19aea6c946b28871017ce8726f8a69203f05953"),
        WeightFile("ve.pt", 5_698_626, "4b16d836bc598509860f6fa068165a8bb5e9ac84f05582dfcf278a5a372879f1"),
    ),
    allow_patterns=(
        "Cangjie5_TC.json",
        "conds.pt",
        "grapheme_mtl_merged_expanded_v1.json",
        "s3gen.pt",
        "t3_mtl23ls_v3.safetensors",
        "ve.pt",
    ),
)


VOXCPM2 = ModelSpec(
    model_id="voxcpm2",
    display_name="VoxCPM2",
    adapter="voxcpm2",
    repository="openbmb/VoxCPM2",
    revision="bffb3df5a29440629464e5e839f4d214c8714c3d",
    parameter_count=2_000_000_000,
    license_spdx="Apache-2.0",
    weight_files=(
        WeightFile("audiovae.pth", 376_951_122, "94b5d51e107e0507d4acc976cfdadb64edd6fd06d1f751dadbf2fd1594274bf1"),
        WeightFile("config.json", 4_336, "405f0dcd92f7feba6011ed4eac5c8d4f74cba9712f07fd5cfa3063bbdd95402c"),
        WeightFile("model.safetensors", 4_580_080_592, "f7f964cfa9da23653baec6e6f7750719977ad944ed9f95fe52fe3a620506891d"),
        WeightFile("special_tokens_map.json", 1_632, "068594063e37662c02b21acf42ebb334ef6a74fb810e68a2368f88f08351de76"),
        WeightFile("tokenization_voxcpm2.py", 2_895, "84489ea32b6ee0cae22ed5480cacb6df85c46624c3119be9a2021c3649a12729"),
        WeightFile("tokenizer.json", 3_676_772, "f8984687e4a92a3503d521396d454b7d68e9fdaab2a0288eb3536c7c1aa4bc20"),
        WeightFile("tokenizer_config.json", 5_059, "e78a3ebb48a0b9437efd1823b6b726c823da89e49dd8bcc90c02419d9baa772b"),
    ),
    allow_patterns=(
        "audiovae.pth",
        "config.json",
        "model.safetensors",
        "special_tokens_map.json",
        "tokenization_voxcpm2.py",
        "tokenizer.json",
        "tokenizer_config.json",
    ),
    built_in_voice_design_revision="voxcpm2-nl-nl-female-pa-v2",
)


MODEL_SPECS: dict[str, ModelSpec] = {
    CHATTERBOX_MULTILINGUAL_V3.model_id: CHATTERBOX_MULTILINGUAL_V3,
    VOXCPM2.model_id: VOXCPM2,
}


def model_spec(model_id: object) -> ModelSpec:
    if not isinstance(model_id, str) or model_id not in MODEL_SPECS:
        raise KeyError("model_not_allowlisted")
    return MODEL_SPECS[model_id]
