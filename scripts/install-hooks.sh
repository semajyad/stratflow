#!/bin/sh
# Install StratFlow git hooks from scripts/hooks/ into .git/hooks/.
#
# Safe to re-run — idempotent. Copies all hooks and makes them executable.
# Called automatically by scripts/agent/session-start.py on each agent session.
#
# Usage:
#   sh scripts/install-hooks.sh
#   sh scripts/install-hooks.sh --quiet

set -e

QUIET=0
for arg in "$@"; do
    case "$arg" in
        --quiet|-q) QUIET=1 ;;
    esac
done

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"
if [ -z "$REPO_ROOT" ]; then
    echo "install-hooks: not inside a git repository" >&2
    exit 1
fi

HOOKS_SRC="$REPO_ROOT/scripts/hooks"
HOOKS_DST="$(git rev-parse --git-common-dir)/hooks"

if [ ! -d "$HOOKS_SRC" ]; then
    echo "install-hooks: scripts/hooks/ not found at $HOOKS_SRC" >&2
    exit 1
fi

INSTALLED=0
for src in "$HOOKS_SRC"/*; do
    name="$(basename "$src")"
    dst="$HOOKS_DST/$name"
    cp "$src" "$dst"
    chmod +x "$dst"
    INSTALLED=$((INSTALLED + 1))
    [ "$QUIET" = "0" ] && echo "[install-hooks] installed: $name"
done

[ "$QUIET" = "0" ] && echo "[install-hooks] $INSTALLED hooks installed to $HOOKS_DST"
