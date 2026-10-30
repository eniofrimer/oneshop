"""Exercise the real scanner and pre-push hook using disposable local repositories."""

import base64
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile


source = Path(__file__).resolve().parents[2]
scanner = Path(os.environ.get("GITLEAKS_BIN", source / "storage/app/private/tools/gitleaks")).resolve()
environment = {**os.environ, "GITLEAKS_BIN": str(scanner), "GIT_CONFIG_GLOBAL": "/dev/null"}
environment.pop("GIT_DIR", None)
environment.pop("GIT_WORK_TREE", None)
environment.pop("GIT_INDEX_FILE", None)


def run(root, args, success=True):
    result = subprocess.run(args, cwd=root, env=environment, capture_output=True, text=True)
    if (result.returncode == 0) != success:
        # Never put scanner output or a generated fixture in an exception/log.
        raise RuntimeError("Unexpected secret-guard result for command: " + args[0])
    return result.stdout + result.stderr


def fixture(root):
    root.mkdir()
    for relative in ["scripts/check-secrets.sh", ".githooks/pre-push", ".gitleaks.toml", ".gitleaksignore", ".gitignore"]:
        target = root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source / relative, target)
    (root / ".githooks/pre-push").chmod(0o700)
    run(root, ["git", "init", "--quiet", "--initial-branch=main"])
    run(root, ["git", "config", "user.name", "Synthetic scanner test"])
    run(root, ["git", "config", "user.email", "scanner@example.test"])
    run(root, ["git", "add", "."])
    run(root, ["git", "commit", "--quiet", "-m", "Clean fixture"])
    run(root, ["bash", "scripts/check-secrets.sh"])


with tempfile.TemporaryDirectory(prefix="oneshop-secret-guard-") as directory:
    base = Path(directory)
    # These ephemeral strings are only local positive controls, never issued by a provider.
    cases = [
        ("webhook", "fixture.txt", "whsec_" + secrets.token_hex(24)),
        ("test-api", "fixture.txt", "sk_" + "test_" + secrets.token_hex(24)),
        ("live-api", "fixture.txt", "sk_" + "live_" + secrets.token_hex(24)),
        ("laravel", "fixture.txt", "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()),
        ("environment", ".env.local", "APP_NAME=synthetic"),
        ("composer-auth", "auth.json", "{}"),
        ("deployment", "deploy/secrets/merchant.txt", "synthetic"),
    ]
    for label, filename, value in cases:
        root = base / label
        fixture(root)
        path = root / filename
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(value + "\n")
        run(root, ["git", "add", "--force", filename])
        output = run(root, ["bash", "scripts/check-secrets.sh"], success=False)
        if filename == "fixture.txt" and value in output:
            raise RuntimeError("Scanner failed to redact a synthetic positive control")

    root = base / "push"
    fixture(root)
    remote = base / "remote.git"
    run(base, ["git", "init", "--bare", "--quiet", str(remote)])
    run(root, ["git", "config", "core.hooksPath", ".githooks"])
    run(root, ["git", "remote", "add", "origin", str(remote)])
    run(root, ["git", "push", "--quiet", "origin", "main"])
    before = run(remote, ["git", "rev-parse", "refs/heads/main"]).strip()
    (root / "fixture.txt").write_text("whsec_" + secrets.token_hex(24) + "\n")
    run(root, ["git", "add", "fixture.txt"])
    run(root, ["git", "commit", "--quiet", "-m", "Synthetic positive control"])
    run(root, ["git", "push", "--quiet", "origin", "main"], success=False)
    after = run(remote, ["git", "rev-parse", "refs/heads/main"]).strip()
    if before != after:
        raise RuntimeError("Pre-push hook failed to protect the disposable remote")

print("Secret guard passed: seven staged controls rejected, output redacted, unsafe local push blocked.")
