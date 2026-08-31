# Contributing to FlowSMTP

## Branching model

| Branch | Purpose |
| --- | --- |
| `main` | Stable, release-ready code. Only updated by merging `develop` (or hotfixes) via PR. |
| `develop` | Integration branch. All feature work lands here first via PR. |
| `feature/*` | One branch per feature or fix, created from `develop`. |
| `hotfix/*` | Urgent fixes branched from `main`, merged back to both `main` and `develop`. |

## Workflow

1. **Branch** — create `feature/<short-name>` from `develop`:
   ```bash
   git checkout develop && git pull
   git checkout -b feature/my-change
   ```
2. **Commit** — small, focused commits using [Conventional Commits](https://www.conventionalcommits.org/):
   `feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`
3. **Push & open a PR** into `develop`. Fill in the PR template.
4. **CI must pass** — the PHP lint workflow runs automatically on every PR.
5. **Review & merge** — squash-merge preferred to keep history clean.
6. **Release** — when `develop` is stable, open a PR `develop → main`, bump the version in `flow-smtp.php` and `readme.txt`, then tag:
   ```bash
   git tag v0.2.0 && git push --tags
   ```

## Coding standards

- WordPress PHP coding standards (tabs, Yoda conditions, escaping/sanitizing).
- Prefix everything: `flowsmtp_` / `FlowSMTP_`.
- All DB queries via `$wpdb->prepare()`.
- Every AJAX handler needs a nonce **and** a capability check.

## Recommended repo settings (one-time, manual)

- Set `develop` as the default branch (Settings → General).
- Add a branch protection rule for `main`: require a pull request before merging and require the **PHP Lint** status check to pass.
