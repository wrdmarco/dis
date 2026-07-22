from __future__ import annotations

import argparse
import os
import stat
from pathlib import Path

from .catalog import model_spec


def main() -> None:
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("--model-id", required=True)
    parser.add_argument("--destination", required=True)
    arguments = parser.parse_args()

    spec = model_spec(arguments.model_id)
    destination = Path(arguments.destination)
    if not destination.is_absolute():
        raise RuntimeError("invalid_download_destination")
    metadata = destination.lstat()
    if not stat.S_ISDIR(metadata.st_mode) or destination.is_symlink() or metadata.st_mode & stat.S_IWOTH:
        raise RuntimeError("invalid_download_destination")

    os.umask(0o027)
    from huggingface_hub import snapshot_download

    snapshot_download(
        repo_id=spec.repository,
        revision=spec.revision,
        allow_patterns=list(spec.allow_patterns),
        local_dir=destination,
        token=False,
        max_workers=4,
    )


if __name__ == "__main__":
    main()
