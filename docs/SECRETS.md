# Secrets: what never goes into Git, where it lives instead, and how this repository enforces it

This repository is **public**. Anything committed to it — on any branch, in any file, even if it is deleted in the next commit — must be treated as published to the world and permanently compromised.

## 1. The rule

**Never commit:**

- passwords of any kind (WordPress, database, mailbox, admin, QA/test accounts, a password passed as a command-line flag);
- API credentials and keys, access / refresh / bearer / personal-access tokens (Laravel Sanctum tokens, Hostinger API tokens, Hostinger file-browser upload sessions, Cloudflare tokens);
- webhook and signing secrets, and shared secrets such as `REVALIDATE_SECRET`;
- private keys and certificates (`*.pem`, `*.ppk`, `*.p12`, `*.pfx`, SSH keys);
- a real `.env` file — production **or** local — and any file whose job is to hold credentials (`wp-config.php`, `auth.json`, `*credentials*.txt`, `.netrc`);
- database credentials, SMTP / IMAP mailbox passwords, OAuth client secrets, the Laravel `APP_KEY`;
- one-time links that carry a token (committee registration links, password-reset links);
- saved assistant/tool state that records command lines (see section 4);
- screenshots, logs or exports that show any of the above.

**Allowed:** `.env.example` and other `*.example` templates containing only **variable names with empty, `null` or `<descriptive placeholder>` values**, and identifiers that are public by design (a site URL, a public client ID). When in doubt, it is a secret.

## 2. Where real secrets live

| Where | What lives there |
|---|---|
| Your machine | `admin-erp/.env`, `institutional/.env.local` and similar — all ignored by Git. Upload/session credentials for tooling go in env files **outside the repository** and are deleted afterwards. |
| Production, Laravel (admin.provatferi.org) | The `.env` in the private application directory on the host (outside the web root), created by the deploy tooling's contract — never part of a release archive. |
| Production, Next.js (provatferi.org) | The Node.js environment variables of the Hostinger site. The Hostinger API **replaces the whole set** on every write, so never change one variable in isolation. |
| CI | GitHub Actions *repository secrets*, only if a workflow ever needs one (none does today). |

## 3. New integrations (bKash, SSLCOMMERZ, bank APIs, Hostinger Mail, Cloudflare, …)

- Code reads every credential through `config/*.php` → `env('PROVIDER_…')`. A config default is never a real value.
- `.env.example` gets the variable **name** with an empty value and a comment saying where the value comes from. Nothing else about the secret goes into Git.
- Name them so the scanner recognises a mistake: `<PROVIDER>_<WHAT>_SECRET`, `_PASSWORD`, `_PASSWD`, `_API_KEY`, `_API_TOKEN`, `_ACCESS_TOKEN`, `_WEBHOOK_SECRET`, `_PRIVATE_KEY`. A literal value assigned to any such name fails the commit and CI.
- Sandbox / test-mode credentials are credentials. They are often the same as, or one step from, the live ones. Same rules.
- Never put a secret in a URL query string (it lands in access logs), never log it, never write it into a deploy state file or a report.
- Test-account logins are passed at run time (arguments or environment) and never stored in the repository, docs or state files.

## 4. AI-assistant and tooling hygiene

Claude Code saves **every command line you approve** into `.claude/settings.json` / `.claude/settings.local.json`, verbatim — including a command that carried a password or a token. A tracked copy of that file is how a WordPress admin password reached this public history in 2026. Therefore:

- both files are ignored (`.gitignore`) and the pre-commit hook refuses them by name even if someone force-adds them;
- do not put a secret **in** a command. Read it from an env file, a file descriptor or stdin, and do not choose "always allow" for a command that contains one;
- anything an assistant prints in a session (tool output, transcripts under `~/.claude/`) is exposed to that session's logs — prefer redacted output and rotate anything that was shown;
- run `bash scripts/secret-scan.sh tree` now and then: it scans the files Git *would* let you commit, so a secret-bearing file is found before anyone runs `git add .`.

## 5. What enforces this

| Layer | How |
|---|---|
| Scanner | [gitleaks](https://github.com/gitleaks/gitleaks), pinned and checksum-verified by `scripts/install-gitleaks.sh`. Policy: `.gitleaks.toml` — gitleaks' default rules plus project rules for the shapes that leaked here and the ones the next integrations bring. |
| Before a commit | `.githooks/pre-commit` runs `scripts/secret-scan.sh staged`: scanner **and** a block-list of file names that must never be committed. Fails closed if the scanner is missing. |
| On GitHub | `.github/workflows/secret-scan.yml` scans every commit of every branch and tag on each push and pull request. |
| By hand | `scripts/secret-scan.sh staged \| history \| tree` |
| Ignore rules | `.gitignore` (root, `admin-erp/`, `institutional/`) covers `.env*`, key files, `wp-config.php`, credential dumps and `.claude/settings*.json`. `*.example` templates stay visible. |

**One-time set-up on each machine** (about a minute):

```bash
bash scripts/install-gitleaks.sh        # installs the pinned scanner under ~/.provatferi-tools/gitleaks
git config core.hooksPath .githooks     # turns the pre-commit hook on for this clone
bash scripts/secret-scan.sh history     # should print "no leaks found"
```

Recommended on GitHub (owner, repository settings): turn on *secret scanning* and *push protection*, and require the `Secret scan` check on `main`.

## 6. A false positive

Prefer fixing the file (use a placeholder). If the value truly is not a secret, add the **narrowest** allow-list in `.gitleaks.toml`: the exact value or shape **and** the one file (and, for an old commit, the one commit), with a description saying why it is harmless. Never allow-list a directory, a file type or `*` for current code, and never allow-list a real secret — rotate it.

## 7. If a secret is committed anyway

1. **Rotate or revoke it first.** It is compromised from the moment it is pushed; removing it from Git does not un-publish it.
2. Check where it was valid and whether it was used (access logs, the service's own audit trail).
3. Remove it from the tree and add the ignore rule that would have prevented it.
4. Rewrite history only with a backup in hand and only what is necessary (see the record below), then force-push with a lease. A rewrite is hygiene; rotation is the fix.

| Credential | How to rotate |
|---|---|
| WordPress admin password | Reset it in WordPress (or WP-CLI). Replace the eight salts in `wp-config.php` to log everyone out. |
| Laravel Sanctum token | Delete its row in `personal_access_tokens` — these tokens do not expire. |
| Committee registration link | Set `revoked_at` on the link (or revoke it in the admin panel). |
| `REVALIDATE_SECRET` | Change it in `admin-erp/.env` and in the Next.js site's environment **together**, then restart both. |
| Hostinger API token / file-browser session | Revoke the token in hPanel; upload sessions expire after about six hours. |
| Database or mailbox password | Change it in hPanel, then in the app's `.env`. |
| Cloudflare / payment-provider keys | Roll the key in the provider's dashboard. |

## 8. Record: 2026-10-09 clean-up

A tracked `.claude/settings.local.json` carried a local-development WordPress admin password and an expired Hostinger file-browser session, and an early commit contained the local development site's `wp-config.php` (database defaults and authentication salts). Neither the password nor the salts were in use anywhere that was checked, production included, and the Hostinger session had long expired.

History was rewritten to remove exactly those two paths (`.claude/settings.local.json`, `cms/wp-config.php`). All 202 commits kept their authors, dates, messages and parent structure, and every tree is identical apart from those two paths; **every commit after the first has a new SHA**. `main` and both tags were force-pushed with a lease. The commits recorded in the deploy state files map as follows:

| What | Before | After |
|---|---|---|
| `main` at the time | `fbc5e0cd3632` | `63123eec7abb` |
| Receipts release (live) | `03e2e70391bc` | `bf731094b6f7` |
| Admin-dates release | `f8279064ac6f` | `3c23064a3676` |
| Previous site build | `9a26ed554d50` | `a492ac5302fb` |
| Tag `admin-erp-phase1-baseline` | `a84362e7d309` | `6c24bb0f3fad` |
| Tag `institutional-v2-baseline` | `47013d8a55ca` | `27147ffbb3c3` |

Release identifiers recorded on the server (for example `03e2e70391bc-2026-10-0821384`) keep their old SHA; use the table to find the commit. The deploy tooling is unaffected: it only needs the commit being built to be on `origin/main`.

**Everyone with a clone must re-clone, or reset it:**

```bash
git fetch origin
git checkout main && git reset --hard origin/main
git tag -d admin-erp-phase1-baseline institutional-v2-baseline && git fetch --tags --force
git reflog expire --expire=now --all && git gc --prune=now      # drops the old objects locally
```

A branch started from the old history: `git rebase --onto origin/main <old-base-commit> <branch>` (the old→new map for every commit is held by the owner). Never merge or push the old `main` back — it would republish the removed files, and CI will fail it.

GitHub can keep serving a removed commit by its SHA for a while; the owner can ask GitHub Support to purge cached views and run garbage collection. Anyone who cloned before 2026-10-09 may still hold the old objects — which is why the credentials were checked and not merely deleted.
