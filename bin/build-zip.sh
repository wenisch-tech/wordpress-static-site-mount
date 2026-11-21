#!/usr/bin/env bash
# Build a release zip without dev/test files (honours .distignore).
set -euo pipefail
cd "$(dirname "$0")/.."
out="${1:-stasimo-static-site-mount.zip}"
tmp="$(mktemp -d)"
mkdir "$tmp/stasimo-static-site-mount"
rsync -a --exclude-from=.distignore ./ "$tmp/stasimo-static-site-mount/"
(cd "$tmp" && python3 -c "import shutil;shutil.make_archive('pkg','zip','.','stasimo-static-site-mount')")
mv "$tmp/pkg.zip" "$out"
echo "$out"
