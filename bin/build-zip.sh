#!/usr/bin/env bash
# Build a release zip without dev/test files (honours .distignore).
set -euo pipefail
cd "$(dirname "$0")/.."
out="${1:-static-site-mount.zip}"
tmp="$(mktemp -d)"
mkdir "$tmp/static-site-mount"
rsync -a --exclude-from=.distignore ./ "$tmp/static-site-mount/"
(cd "$tmp" && python3 -c "import shutil;shutil.make_archive('pkg','zip','.','static-site-mount')")
mv "$tmp/pkg.zip" "$out"
echo "$out"
