---
name: bump-version
description: Bump Mantia's version, commit, tag, and push --tags so release.yml builds the zip. Args - the new semver (e.g. "0.2.0", no "v" prefix). User-only because it pushes a tag that triggers a GitHub Release.
disable-model-invocation: true
---

# /bump-version

Cuts the manual release prep into one command:

1. Update `Version: X.Y.Z` in `mantia.php` plugin header
2. Update `define( 'MANTIA_VERSION', 'X.Y.Z' )` in `mantia.php`
3. Commit the bump with a conventional message
4. Tag `vX.Y.Z`
5. Push HEAD + tags so `release.yml` fires and attaches the ZIP to the new GitHub Release

## Args

- **Required**: new version, semver-shaped, NO `v` prefix. e.g. `/bump-version 0.2.0`
- The skill validates the format before touching anything; bails on `0.2` or `v0.2.0` with a clear error.

## Pre-flight checks (refuses to proceed on red)

- Working tree must be clean (`git status -s` returns nothing).
- HEAD must be on `main`.
- Local main must be in sync with `origin/main` (no unpushed commits — a tag pointing at an unpushed commit is a footgun).
- New version must be greater than the current one (parses both as `[major, minor, patch]`).

## Shell

```bash
set -euo pipefail

NEW_VERSION="${1:-}"
if [[ -z "$NEW_VERSION" ]]; then
  echo "Usage: /bump-version <X.Y.Z>" >&2; exit 2
fi
if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "❌ Version must be semver X.Y.Z (no 'v' prefix). Got: $NEW_VERSION" >&2; exit 2
fi

# Pre-flight
if [[ -n "$(git status -s)" ]]; then
  echo "❌ Working tree dirty. Commit or stash first." >&2; exit 1
fi
if [[ "$(git branch --show-current)" != "main" ]]; then
  echo "❌ Bump version from main. Checked-out: $(git branch --show-current)" >&2; exit 1
fi
git fetch --quiet
if ! git diff --quiet main origin/main; then
  echo "❌ Local main is out of sync with origin/main. Pull/push first." >&2; exit 1
fi

CURRENT=$(grep -E "define\(\s*'MANTIA_VERSION'" mantia.php | sed -E "s/.*'([^']+)'\s*\)\s*;.*/\1/" | tail -1)
echo "Current: $CURRENT  →  New: $NEW_VERSION"

# Semver ordering check (sort -V is fine for this).
HIGHEST=$(printf '%s\n%s\n' "$CURRENT" "$NEW_VERSION" | sort -V | tail -1)
if [[ "$HIGHEST" != "$NEW_VERSION" ]]; then
  echo "❌ New version must be greater than $CURRENT." >&2; exit 1
fi

# Edit the two locations in mantia.php
sed -i.bak -E "s/^( \* Version: ).*/\1${NEW_VERSION}/" mantia.php
sed -i.bak -E "s/(define\(\s*'MANTIA_VERSION',\s*')[^']+('\s*\)\s*;)/\1${NEW_VERSION}\2/" mantia.php
rm -f mantia.php.bak

git --no-pager diff mantia.php

git add mantia.php
git commit -m "Bump version to ${NEW_VERSION}"
git tag "v${NEW_VERSION}"
git push origin main "v${NEW_VERSION}"

echo
echo "✅ Tagged v${NEW_VERSION}. Watch release.yml: gh run watch"
```

## After the push

`release.yml` triggers on `push --tags v*`. It builds the plugin zip, attaches it to a GitHub Release named `Mantia v${NEW_VERSION}`, and uploads both versioned + slug-only copies. Check progress with `gh run list --branch main` or `gh run watch`.

## Failure modes

- **Tag already exists** → either you bumped twice or `release.yml` already ran. Verify with `gh release view v${NEW_VERSION}`.
- **Pre-flight rejects "out of sync"** → almost always means there are local commits not pushed. `git push` first.
- **release.yml red** → the build step is in `.github/workflows/release.yml`; the most common failure is a missing dep in composer.json `require` (the `composer install --no-dev` step). Fix and re-tag with `--force` ONLY if the tag is unpushed; otherwise cut a patch version.
