#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

# Project-private installation; never modifies system packages or other projects.
if [[ "$(uname -s)/$(uname -m)" != Linux/x86_64 ]]; then
    printf '%s\n' 'This pinned installer requires Linux x86_64.' >&2
    exit 1
fi
task_tools="$(pwd)/storage/app/private/tools"
task_staging="$(mktemp -d /tmp/oneshop-gitleaks.XXXXXXXX)"
trap 'rm -rf "$task_staging"' EXIT
umask 077
mkdir -p "$task_tools"
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
    'https://github.com/gitleaks/gitleaks/releases/download/v8.30.1/gitleaks_8.30.1_linux_x64.tar.gz' \
    --output "$task_staging/scanner.tar.gz"
(
    cd "$task_staging"
    printf '%s\n' '551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb  scanner.tar.gz' | sha256sum --check --status
    tar -xzf scanner.tar.gz gitleaks
)
install -m 0700 "$task_staging/gitleaks" "$task_tools/gitleaks"
printf '%s\n' 'Installed checksum-verified Gitleaks 8.30.1 in project-private storage.'
