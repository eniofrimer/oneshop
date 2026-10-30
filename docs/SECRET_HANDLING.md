# Secret handling and the 30 October 2026 alert

GitHub [secret scanning alert #1](https://github.com/eniofrimer/oneshop/security/secret-scanning/1) identified a fixed, human-written synthetic Stripe webhook fixture in three test locations. It was not issued by Stripe. A fully redacted Gitleaks scan of all available Git history, with an added webhook rule, found nine historical occurrences of related synthetic API/webhook fixtures, including negative tests rejecting live-mode configuration. No additional findings appeared. This is the scope of the executed scan, not a guarantee that a scanner detects every possible secret.

Tests now generate ephemeral credentials using `random_bytes`; webhook signature tests sign with the same per-test configuration used by the real SDK verifier. Provider HTTP requests remain intercepted by the test transport. Real merchant keys belong only in private installation configuration and must never be pasted into documentation, issues, screenshots or chat.

## Before committing or pushing

From the Ubuntu project checkout:

```sh
bash scripts/install-secret-scanner.sh
git config --local core.hooksPath .githooks
bash scripts/check-secrets.sh
```

The installer downloads Gitleaks **8.30.1**, verifies the pinned release archive SHA-256 and installs only in ignored project-private storage. The script checks all available Git history and a temporary copy of staged files. It does not scan private runtime data, installed dependencies or untracked `.env` files. It refuses tracked `.env` variants, Composer `auth.json` and `deploy/secrets` files, while allowing example environment templates through content scanning. Log output is fully redacted.

The pre-push hook runs the same script and fails closed when the scanner is missing or a finding appears. Hooks apply only to checkouts where configured and can be bypassed; they are not GitHub enforcement. The **Secret scanning** workflow repeats detection on pushes and pull requests. CI runs after upload, so the local check is necessary before pushing.

Default detector rules remain enabled, with additional rules for Stripe webhook signing secrets and Laravel encryption keys. `.gitleaksignore` contains only nine exact historical commit/file/rule/line fingerprints of the reviewed synthetic fixtures. New occurrences, even in tests, are not exempt. History was not rewritten. A disposable local-repository test proves that staged synthetic controls are rejected, output is redacted and an actual push to a local bare repository is blocked without changing its branch.

If a real secret is ever found, removing the source text is insufficient: revoke or rotate it with the issuing service and assess where it was used. Application encryption keys need a separate data-preserving rotation plan. Never rotate a working installation's encryption key merely to silence a test-fixture alert.

References: [Gitleaks commands and configuration](https://github.com/gitleaks/gitleaks), [GitHub secret scanning](https://docs.github.com/en/code-security/secret-scanning/introduction/about-secret-scanning).
