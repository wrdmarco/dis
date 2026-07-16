#!/usr/bin/env python3
"""Descriptor-based path operations for privileged DIS maintenance scripts."""

from __future__ import annotations

import argparse
import errno
import grp
import os
import pwd
import stat
import subprocess
import sys
from collections.abc import Iterator


DIRECTORY_FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
ENTRY_FLAGS = os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC


class SecurePathError(RuntimeError):
    pass


def normalized_components(path: str) -> list[str]:
    if not path.startswith("/"):
        raise SecurePathError(f"path must be absolute: {path}")
    normalized = os.path.normpath(path)
    if normalized != path.rstrip("/") and not (normalized == "/" and path == "/"):
        raise SecurePathError(f"path is not normalized: {path}")
    return [part for part in normalized.split("/") if part]


def require_root_controlled(fd: int, display_path: str) -> None:
    metadata = os.fstat(fd)
    if metadata.st_uid != 0:
        raise SecurePathError(f"ancestor must be owned by root: {display_path}")
    if stat.S_IMODE(metadata.st_mode) & 0o022:
        raise SecurePathError(f"ancestor may not be group- or world-writable: {display_path}")


def open_directory_chain(path: str, *, final_may_be_writable: bool) -> int:
    components = normalized_components(path)
    current_fd = os.open("/", DIRECTORY_FLAGS)
    current_path = "/"
    try:
        require_root_controlled(current_fd, current_path)
        for index, component in enumerate(components):
            next_fd = os.open(component, DIRECTORY_FLAGS, dir_fd=current_fd)
            os.close(current_fd)
            current_fd = next_fd
            current_path = os.path.join(current_path, component)
            if index < len(components) - 1 or not final_may_be_writable:
                require_root_controlled(current_fd, current_path)
        return current_fd
    except BaseException:
        os.close(current_fd)
        raise


def split_parent(path: str) -> tuple[str, str]:
    components = normalized_components(path)
    if not components:
        raise SecurePathError("the filesystem root cannot be managed as a leaf")
    leaf = components[-1]
    parent = "/" + "/".join(components[:-1]) if len(components) > 1 else "/"
    return parent, leaf


def resolve_uid(owner: str) -> int:
    return int(owner) if owner.isdecimal() else pwd.getpwnam(owner).pw_uid


def resolve_gid(group: str) -> int:
    return int(group) if group.isdecimal() else grp.getgrnam(group).gr_gid


def ensure_directory(path: str, owner: str, group: str, mode: int) -> None:
    parent, leaf = split_parent(path)
    parent_fd = open_directory_chain(parent, final_may_be_writable=False)
    child_fd: int | None = None
    try:
        try:
            child_fd = os.open(leaf, DIRECTORY_FLAGS, dir_fd=parent_fd)
        except FileNotFoundError:
            os.mkdir(leaf, mode=0o700, dir_fd=parent_fd)
            child_fd = os.open(leaf, DIRECTORY_FLAGS, dir_fd=parent_fd)
        metadata = os.fstat(child_fd)
        if not stat.S_ISDIR(metadata.st_mode):
            raise SecurePathError(f"managed path is not a directory: {path}")
        os.fchown(child_fd, resolve_uid(owner), resolve_gid(group))
        os.fchmod(child_fd, mode)
    finally:
        if child_fd is not None:
            os.close(child_fd)
        os.close(parent_fd)


def opened_entries(directory_fd: int) -> Iterator[tuple[str, int, os.stat_result]]:
    with os.scandir(directory_fd) as entries:
        names = [entry.name for entry in entries]
    for name in names:
        try:
            entry_fd = os.open(name, ENTRY_FLAGS, dir_fd=directory_fd)
        except OSError as error:
            if error.errno in {errno.ELOOP, errno.ENXIO, errno.ENODEV}:
                raise SecurePathError(f"unsafe runtime entry: {name}") from error
            raise
        yield name, entry_fd, os.fstat(entry_fd)


def inspect_tree(
    directory_fd: int,
    display_path: str,
    *,
    repair: tuple[int, int, int, int] | None,
    expected_device: int,
) -> None:
    for name, entry_fd, metadata in opened_entries(directory_fd):
        child_path = f"{display_path.rstrip('/')}/{name}"
        try:
            if metadata.st_dev != expected_device:
                raise SecurePathError(f"mounted runtime subtree is not allowed: {child_path}")
            if stat.S_ISDIR(metadata.st_mode):
                if repair is not None:
                    uid, gid, directory_mode, _ = repair
                    os.fchown(entry_fd, uid, gid)
                    os.fchmod(entry_fd, directory_mode)
                inspect_tree(entry_fd, child_path, repair=repair, expected_device=expected_device)
            elif stat.S_ISREG(metadata.st_mode):
                if metadata.st_nlink != 1:
                    raise SecurePathError(f"hard-linked runtime file is not allowed: {child_path}")
                if repair is not None:
                    uid, gid, _, file_mode = repair
                    os.fchown(entry_fd, uid, gid)
                    os.fchmod(entry_fd, file_mode)
            else:
                raise SecurePathError(f"special or symbolic runtime entry is not allowed: {child_path}")
        finally:
            os.close(entry_fd)


def validate_tree(path: str) -> None:
    directory_fd = open_directory_chain(path, final_may_be_writable=True)
    try:
        inspect_tree(directory_fd, path, repair=None, expected_device=os.fstat(directory_fd).st_dev)
    finally:
        os.close(directory_fd)


def repair_tree(path: str, owner: str, group: str, directory_mode: int, file_mode: int) -> None:
    directory_fd = open_directory_chain(path, final_may_be_writable=True)
    uid = resolve_uid(owner)
    gid = resolve_gid(group)
    try:
        # Freeze the top-level directory before walking descendants so the
        # former unprivileged owner cannot create new names during repair.
        os.fchown(directory_fd, uid, gid)
        os.fchmod(directory_fd, directory_mode)
        inspect_tree(
            directory_fd,
            path,
            repair=(uid, gid, directory_mode, file_mode),
            expected_device=os.fstat(directory_fd).st_dev,
        )
    finally:
        os.close(directory_fd)


def write_file(path: str, owner: str, group: str, mode: int) -> None:
    parent, leaf = split_parent(path)
    parent_fd = open_directory_chain(parent, final_may_be_writable=False)
    temporary_name = f".{leaf}.tmp.{os.urandom(16).hex()}"
    temporary_fd: int | None = None
    installed = False
    try:
        temporary_fd = os.open(
            temporary_name,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
            0o600,
            dir_fd=parent_fd,
        )
        total = 0
        while True:
            block = sys.stdin.buffer.read(1024 * 1024)
            if not block:
                break
            total += len(block)
            if total > 16 * 1024 * 1024:
                raise SecurePathError("managed file input exceeds 16 MiB")
            view = memoryview(block)
            while view:
                written = os.write(temporary_fd, view)
                view = view[written:]
        os.fchown(temporary_fd, resolve_uid(owner), resolve_gid(group))
        os.fchmod(temporary_fd, mode)
        os.fsync(temporary_fd)
        os.close(temporary_fd)
        temporary_fd = None
        os.replace(
            temporary_name,
            leaf,
            src_dir_fd=parent_fd,
            dst_dir_fd=parent_fd,
        )
        installed = True
        os.fsync(parent_fd)
    finally:
        if temporary_fd is not None:
            os.close(temporary_fd)
        if not installed:
            try:
                os.unlink(temporary_name, dir_fd=parent_fd)
            except FileNotFoundError:
                pass
        os.close(parent_fd)


def set_acl(fd: int, user: str, permissions: str, *, default: bool = False) -> None:
    command = ["/usr/bin/setfacl"]
    if default:
        command.append("-d")
    command.extend(["-m", f"u:{user}:{permissions}", f"/proc/self/fd/{fd}"])
    subprocess.run(
        command,
        check=True,
        close_fds=True,
        pass_fds=(fd,),
        env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"},
        stdout=subprocess.DEVNULL,
        stderr=subprocess.PIPE,
        text=True,
    )


def acl_tree_entries(
    directory_fd: int,
    display_path: str,
    user: str,
    directory_permissions: str,
    file_permissions: str,
    expected_device: int,
) -> None:
    for name, entry_fd, metadata in opened_entries(directory_fd):
        child_path = f"{display_path.rstrip('/')}/{name}"
        try:
            if metadata.st_dev != expected_device:
                raise SecurePathError(f"mounted runtime subtree is not allowed: {child_path}")
            if stat.S_ISDIR(metadata.st_mode):
                acl_tree_entries(
                    entry_fd,
                    child_path,
                    user,
                    directory_permissions,
                    file_permissions,
                    expected_device,
                )
                set_acl(entry_fd, user, directory_permissions)
                set_acl(entry_fd, user, directory_permissions, default=True)
            elif stat.S_ISREG(metadata.st_mode):
                if metadata.st_nlink != 1:
                    raise SecurePathError(f"hard-linked runtime file is not allowed: {child_path}")
                set_acl(entry_fd, user, file_permissions)
            else:
                raise SecurePathError(f"special or symbolic runtime entry is not allowed: {child_path}")
        finally:
            os.close(entry_fd)


def apply_acl_tree(
    path: str,
    user: str,
    directory_permissions: str,
    file_permissions: str,
) -> None:
    directory_fd = open_directory_chain(path, final_may_be_writable=True)
    try:
        expected_device = os.fstat(directory_fd).st_dev
        acl_tree_entries(
            directory_fd,
            path,
            user,
            directory_permissions,
            file_permissions,
            expected_device,
        )
        set_acl(directory_fd, user, directory_permissions)
        set_acl(directory_fd, user, directory_permissions, default=True)
    finally:
        os.close(directory_fd)


def remove_directory_contents(directory_fd: int, display_path: str, expected_device: int) -> None:
    with os.scandir(directory_fd) as entries:
        names = [entry.name for entry in entries]
    for name in names:
        metadata = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        child_path = f"{display_path.rstrip('/')}/{name}"
        if stat.S_ISLNK(metadata.st_mode):
            os.unlink(name, dir_fd=directory_fd)
            continue
        if stat.S_ISDIR(metadata.st_mode):
            child_fd = os.open(name, DIRECTORY_FLAGS, dir_fd=directory_fd)
            try:
                if os.fstat(child_fd).st_dev != expected_device:
                    raise SecurePathError(f"mounted runtime subtree is not allowed: {child_path}")
                remove_directory_contents(child_fd, child_path, expected_device)
            finally:
                os.close(child_fd)
            os.rmdir(name, dir_fd=directory_fd)
            continue
        os.unlink(name, dir_fd=directory_fd)


def remove_tree(path: str) -> None:
    parent, leaf = split_parent(path)
    parent_fd = open_directory_chain(parent, final_may_be_writable=False)
    child_fd: int | None = None
    try:
        child_fd = os.open(leaf, DIRECTORY_FLAGS, dir_fd=parent_fd)
        expected_device = os.fstat(child_fd).st_dev
        if expected_device != os.fstat(parent_fd).st_dev:
            raise SecurePathError(f"mounted runtime tree may not be removed: {path}")
        remove_directory_contents(child_fd, path, expected_device)
        os.close(child_fd)
        child_fd = None
        os.rmdir(leaf, dir_fd=parent_fd)
    finally:
        if child_fd is not None:
            os.close(child_fd)
        os.close(parent_fd)


def remove_destination_entry(directory_fd: int, name: str, expected_device: int) -> None:
    metadata = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
    if stat.S_ISDIR(metadata.st_mode) and not stat.S_ISLNK(metadata.st_mode):
        child_fd = os.open(name, DIRECTORY_FLAGS, dir_fd=directory_fd)
        try:
            if os.fstat(child_fd).st_dev != expected_device:
                raise SecurePathError(f"mounted destination subtree is not allowed: {name}")
            remove_directory_contents(child_fd, name, expected_device)
        finally:
            os.close(child_fd)
        os.rmdir(name, dir_fd=directory_fd)
    else:
        os.unlink(name, dir_fd=directory_fd)


def copy_tree_entries(
    source_fd: int,
    destination_fd: int,
    source_path: str,
    source_device: int,
    destination_device: int,
) -> None:
    with os.scandir(source_fd) as entries:
        names = [entry.name for entry in entries]
    for name in names:
        try:
            source_entry_fd = os.open(name, ENTRY_FLAGS, dir_fd=source_fd)
        except OSError as error:
            raise SecurePathError(f"unsafe source entry during copy: {source_path}/{name}") from error
        metadata = os.fstat(source_entry_fd)
        try:
            if metadata.st_dev != source_device:
                raise SecurePathError(f"mounted source subtree is not allowed: {source_path}/{name}")
            try:
                destination_metadata = os.stat(name, dir_fd=destination_fd, follow_symlinks=False)
            except FileNotFoundError:
                destination_metadata = None

            if stat.S_ISDIR(metadata.st_mode):
                if destination_metadata is not None and not stat.S_ISDIR(destination_metadata.st_mode):
                    remove_destination_entry(destination_fd, name, destination_device)
                    destination_metadata = None
                if destination_metadata is None:
                    os.mkdir(name, mode=0o700, dir_fd=destination_fd)
                destination_entry_fd = os.open(name, DIRECTORY_FLAGS, dir_fd=destination_fd)
                try:
                    if os.fstat(destination_entry_fd).st_dev != destination_device:
                        raise SecurePathError(f"mounted destination subtree is not allowed: {name}")
                    copy_tree_entries(
                        source_entry_fd,
                        destination_entry_fd,
                        f"{source_path.rstrip('/')}/{name}",
                        source_device,
                        destination_device,
                    )
                finally:
                    os.close(destination_entry_fd)
            elif stat.S_ISREG(metadata.st_mode):
                if metadata.st_nlink != 1:
                    raise SecurePathError(f"hard-linked source file is not allowed: {source_path}/{name}")
                if destination_metadata is not None:
                    remove_destination_entry(destination_fd, name, destination_device)
                destination_entry_fd = os.open(
                    name,
                    os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                    0o600,
                    dir_fd=destination_fd,
                )
                try:
                    while True:
                        block = os.read(source_entry_fd, 1024 * 1024)
                        if not block:
                            break
                        view = memoryview(block)
                        while view:
                            written = os.write(destination_entry_fd, view)
                            view = view[written:]
                finally:
                    os.close(destination_entry_fd)
            else:
                raise SecurePathError(f"special or symbolic source entry is not allowed: {source_path}/{name}")
        finally:
            os.close(source_entry_fd)


def copy_tree(source: str, destination: str) -> None:
    source_fd = open_directory_chain(source, final_may_be_writable=True)
    destination_fd = open_directory_chain(destination, final_may_be_writable=False)
    try:
        copy_tree_entries(
            source_fd,
            destination_fd,
            source,
            os.fstat(source_fd).st_dev,
            os.fstat(destination_fd).st_dev,
        )
    finally:
        os.close(destination_fd)
        os.close(source_fd)


def sync_tree_entries(directory_fd: int, display_path: str, expected_device: int) -> None:
    for name, entry_fd, metadata in opened_entries(directory_fd):
        child_path = f"{display_path.rstrip('/')}/{name}"
        try:
            if metadata.st_dev != expected_device:
                raise SecurePathError(f"mounted backup subtree is not allowed: {child_path}")
            if stat.S_ISDIR(metadata.st_mode):
                sync_tree_entries(entry_fd, child_path, expected_device)
                os.fsync(entry_fd)
            elif stat.S_ISREG(metadata.st_mode):
                if metadata.st_nlink != 1:
                    raise SecurePathError(f"hard-linked backup file is not allowed: {child_path}")
                os.fsync(entry_fd)
            else:
                raise SecurePathError(f"special or symbolic backup entry is not allowed: {child_path}")
        finally:
            os.close(entry_fd)


def sync_tree(path: str) -> None:
    parent, leaf = split_parent(path)
    parent_fd = open_directory_chain(parent, final_may_be_writable=False)
    directory_fd: int | None = None
    try:
        directory_fd = os.open(leaf, DIRECTORY_FLAGS, dir_fd=parent_fd)
        expected_device = os.fstat(directory_fd).st_dev
        if expected_device != os.fstat(parent_fd).st_dev:
            raise SecurePathError(f"mounted backup tree must share its parent filesystem: {path}")
        sync_tree_entries(directory_fd, path, expected_device)
        os.fsync(directory_fd)
        os.fsync(parent_fd)
    finally:
        if directory_fd is not None:
            os.close(directory_fd)
        os.close(parent_fd)


def parse_mode(value: str) -> int:
    try:
        mode = int(value, 8)
    except ValueError as error:
        raise argparse.ArgumentTypeError("mode must be octal") from error
    if mode < 0 or mode > 0o7777:
        raise argparse.ArgumentTypeError("mode is outside the supported range")
    return mode


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser()
    subcommands = result.add_subparsers(dest="command", required=True)

    verify = subcommands.add_parser("verify-parent")
    verify.add_argument("path")

    ensure = subcommands.add_parser("ensure-dir")
    ensure.add_argument("path")
    ensure.add_argument("owner")
    ensure.add_argument("group")
    ensure.add_argument("mode", type=parse_mode)

    validate = subcommands.add_parser("validate-tree")
    validate.add_argument("path")

    repair = subcommands.add_parser("repair-tree")
    repair.add_argument("path")
    repair.add_argument("owner")
    repair.add_argument("group")
    repair.add_argument("directory_mode", type=parse_mode)
    repair.add_argument("file_mode", type=parse_mode)

    write = subcommands.add_parser("write-file")
    write.add_argument("path")
    write.add_argument("owner")
    write.add_argument("group")
    write.add_argument("mode", type=parse_mode)

    acl = subcommands.add_parser("acl-tree")
    acl.add_argument("path")
    acl.add_argument("user")
    acl.add_argument("directory_permissions", choices=["---", "--x", "r--", "r-x", "rw-", "rwx"])
    acl.add_argument("file_permissions", choices=["---", "r--", "rw-"])

    remove = subcommands.add_parser("remove-tree")
    remove.add_argument("path")

    copy = subcommands.add_parser("copy-tree")
    copy.add_argument("source")
    copy.add_argument("destination")

    sync = subcommands.add_parser("sync-tree")
    sync.add_argument("path")
    return result


def main() -> int:
    arguments = parser().parse_args()
    try:
        if arguments.command == "verify-parent":
            parent, _ = split_parent(arguments.path)
            descriptor = open_directory_chain(parent, final_may_be_writable=False)
            os.close(descriptor)
        elif arguments.command == "ensure-dir":
            ensure_directory(arguments.path, arguments.owner, arguments.group, arguments.mode)
        elif arguments.command == "validate-tree":
            validate_tree(arguments.path)
        elif arguments.command == "repair-tree":
            repair_tree(
                arguments.path,
                arguments.owner,
                arguments.group,
                arguments.directory_mode,
                arguments.file_mode,
            )
        elif arguments.command == "write-file":
            write_file(arguments.path, arguments.owner, arguments.group, arguments.mode)
        elif arguments.command == "acl-tree":
            apply_acl_tree(
                arguments.path,
                arguments.user,
                arguments.directory_permissions,
                arguments.file_permissions,
            )
        elif arguments.command == "remove-tree":
            remove_tree(arguments.path)
        elif arguments.command == "copy-tree":
            copy_tree(arguments.source, arguments.destination)
        elif arguments.command == "sync-tree":
            sync_tree(arguments.path)
        else:
            raise SecurePathError("unknown command")
    except (OSError, KeyError, subprocess.SubprocessError, SecurePathError) as error:
        print(f"[dis:error] secure path operation failed: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
