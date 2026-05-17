---
name: deploy-mantia
description: Deploy Mantia PHP changes to mantia3.wordpress.com, flush wp-cache, and run the e2e suite. Default ships ONLY files modified in the current git diff (working tree + staged), which is the right thing 95% of the time. Pass "all" to mirror everything in includes/. User-only — this writes to a live server, so the user must invoke it.
disable-model-invocation: true
---

# /deploy-mantia

Cuts the rsync + cache flush + e2e ritual to a single command.

## What it does

1. Picks the file set to ship:
   - **Default** — `git diff --name-only HEAD` filtered to `*.php` under `includes/`. Adds any untracked PHP files under `includes/` too (`git ls-files --others --exclude-standard`).
   - **`all`** — every `*.php` under `includes/` and the plugin entry `mantia.php`.
2. `rsync -avz` the selected files preserving their directory structure to `mantia3.wordpress.com@ssh.wp.com:/srv/htdocs/wp-content/plugins/mantia/`.
3. `ssh ... wp --skip-themes cache flush` so the deployed code takes effect immediately.
4. Runs `MANTIA_TARGET=ssh MANTIA_SSH='mantia3.wordpress.com@ssh.wp.com' bin/e2e.sh` (full suite) and reports the final PASS/FAIL line.

## When to use

- After editing `includes/*.php` and wanting a quick "did it work on the real install?" loop.
- Before a commit, as a smoke check.
- NOT for tests/ changes — `bin/e2e.sh` already rsyncs `tests/` and `bin/` itself.

## Shell

```bash
set -euo pipefail
SSH_HOST='mantia3.wordpress.com@ssh.wp.com'
KEY='~/.ssh/id_ed25519'
REMOTE_ROOT='/srv/htdocs/wp-content/plugins/mantia'

# Pick file set
if [[ "${1:-}" == "all" ]]; then
  mapfile -t FILES < <(find includes -name '*.php' -print; echo mantia.php)
else
  mapfile -t FILES < <(
    { git diff --name-only HEAD -- 'includes/*.php' 'mantia.php';
      git ls-files --others --exclude-standard -- 'includes/*.php' 'mantia.php'; } \
    | sort -u
  )
fi

if [[ ${#FILES[@]} -eq 0 ]]; then
  echo "Nothing to deploy. Working tree is clean for includes/ and mantia.php."
  exit 0
fi

echo "▶ Deploying ${#FILES[@]} file(s) to mantia3:"
printf '  %s\n' "${FILES[@]}"

# rsync preserving paths (R = relative)
rsync -avzR -e "ssh -i $KEY" "${FILES[@]}" "${SSH_HOST}:${REMOTE_ROOT}/" 2>&1 | tail -5

echo
echo "▶ Flushing cache"
ssh -i "$KEY" "$SSH_HOST" 'wp --skip-themes cache flush 2>&1 | tail -1'

echo
echo "▶ Running e2e suite"
MANTIA_TARGET=ssh MANTIA_SSH="$SSH_HOST" bin/e2e.sh 2>&1 | tail -3
```

## Failure modes

- **Permission denied (publickey)** → the key path is wrong or `ssh-add` hasn't loaded `~/.ssh/id_ed25519` yet (it has a passphrase). Run `ssh-add ~/.ssh/id_ed25519` and retry.
- **`bin/e2e.sh` reports a FAIL** → flaky state contamination between scenarios is the usual culprit. Re-run the failing scenario alone (`bin/e2e.sh <name>`) before assuming a real regression.
- **`wp cache flush` fails** → ignore; nginx will pick up the new files on the next request anyway. The flush is best-effort.
