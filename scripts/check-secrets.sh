#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
task_root="$(pwd)"
task_scanner="${GITLEAKS_BIN:-$task_root/storage/app/private/tools/gitleaks}"
if [[ ! -x "$task_scanner" ]] || [[ "$("$task_scanner" version)" != 8.30.1 ]]; then
    printf '%s\n' 'Install the pinned scanner first: bash scripts/install-secret-scanner.sh' >&2
    exit 1
fi

# Ignore rules alone cannot protect a file that was explicitly staged.
task_forbidden=0
while IFS= read -r -d '' task_file; do
    case "${task_file##*/}" in
        .env.example|.env.*.example) ;;
        .env|.env.*|auth.json)
            printf 'Refusing tracked credential file: %s\n' "$task_file" >&2
            task_forbidden=1 ;;
    esac
    case "$task_file" in
        deploy/secrets/*)
            printf 'Refusing tracked deployment secret: %s\n' "$task_file" >&2
            task_forbidden=1 ;;
    esac
done < <(git ls-files -z)
if (( task_forbidden )); then exit 1; fi

task_flags=(--config "$task_root/.gitleaks.toml" --gitleaks-ignore-path "$task_root/.gitleaksignore"
    --ignore-gitleaks-allow --redact=100 --no-banner --no-color)
"$task_scanner" git "${task_flags[@]}" --log-opts=--all "$task_root"

# Check only Git's index, never private runtime storage, .env or dependencies.
# This also catches additions staged before their first commit.
task_staging="$(mktemp -d /tmp/oneshop-secret-index.XXXXXXXX)"
trap 'rm -rf "$task_staging"' EXIT
chmod 0700 "$task_staging"
git checkout-index --all --prefix="$task_staging/"
"$task_scanner" dir "${task_flags[@]}" "$task_staging"
printf '%s\n' 'Secret checks passed for full Git history and staged source.'
