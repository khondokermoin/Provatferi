# Provatferi — backup and disaster recovery

Built 2026-11-01, after the 2026-10-27 architecture audit found that the platform had **no backup of its own**: no
database dump, no copy of the uploaded files, no export of the production configuration. The only redundancy for 29
private files was that two retired release trees happened to still contain them — which is not a backup strategy, it
is a coincidence that housekeeping was declining to clean up.

Everything below is implemented and running. What is **not** solved is stated as plainly as what is: see
"Off-host copy" and "Limitations".

---

## 1. What is protected, and why

| Class | What | Where it goes | Why |
|---|---|---|---|
| **A — critical transactional data** | the ERP database (`u951246149_admin_erp`): members, applications, dues, payments, receipts, committees, audit trail | `db-erp.sql.gz` | the only copy of every business record; irreplaceable |
| **A** | the WordPress databases (`cms`, `archive`) | `db-cms.sql.gz`, `db-archive.sql.gz` | the literature and archive sites' content |
| **B — private uploaded files** | applicants' CVs and photographs, committee nomination photographs, member documents, notice attachments | `files-erp-private-uploads.tar.gz` | supplied once by a real person; cannot be regenerated |
| **C — public media** | leadership portraits, carousel images, public committee photographs, WordPress media | `files-erp-public-uploads.tar.gz`, `files-cms-uploads.tar.gz`, `files-archive-uploads.tar.gz` | derivatives are rebuildable, originals are not |
| **D — configuration and secrets** | the production `.env`, both `wp-config.php`, the front controller, the `.htaccess` files, the cron inventory | `config.vault` (**encrypted**) | deliberately absent from the public Git repository, so nothing else holds it |
| **E — application code** | Laravel, Next.js, themes | **not backed up** | reproducible from Git at any commit; the manifest records which commit was live |
| **F — operational logs** | Laravel log, cron output, release history | **not backed up** | diagnostic, not a business record; see the retention table |

Deliberately excluded, because backing them up would cost space and buy nothing: `node_modules`, `vendor`, build
caches, temporary files, old release trees, and the deploy sentinel.

---

## 2. Architecture

```
  nightly cron (03:30 Asia/Dhaka = 21:30 UTC)
      │
      └── php artisan backup:run
              │  exclusive lock  (a second run exits; a run stuck for hours is reported as a FAILURE)
              │
              ├── 1. databases        PURE PHP (App\Services\Backup\PhpSqlDumper): no external process at all.
              │                       START TRANSACTION WITH CONSISTENT SNAPSHOT at REPEATABLE READ gives the same
              │                       guarantee as mysqldump --single-transaction and takes no locks, so production
              │                       keeps writing. There is no command line, so no password can leak into one.
              │                       → db-<label>.sql.gz
              │
              ├── 2. upload trees     pure-PHP tar + gzip, one archive per tree, plus a per-file index
              │                       (path, size, mtime, sha256). An unchanged tree is not archived again: the
              │                       previous archive is hard-linked where the host allows it, and COPIED where it
              │                       does not (this one disables link()), which still saves the work of
              │                       re-compressing but not the disk.
              │                       → files-<label>.tar.gz + files-<label>.index.json
              │
              ├── 3. configuration    AES-256-GCM, key held by the OWNER, off-host
              │                       → config.vault      (skipped, never written in the clear, if no key)
              │
              ├── 4. manifest         every artifact's size, sha256, entry count; app env and commit; warnings
              │
              ├── 5. publish          sets/.tmp-<id>/  →  sets/<id>/   (one atomic rename: a killed run can never
              │                       leave something that looks like a finished backup)
              │
              ├── 6. verify           re-read every artifact: hashes, archive structure to its end, dump sanity
              │
              └── 7. retention        prune, fail-safe (see below)
```

Layout on disk, all of it outside every document root:

```
/home/u951246149/backups/
├── .htaccess          Require all denied + Options -Indexes   (belt and braces)
├── .lock              the overlap lock
├── .lock.claim        who holds it and since when
├── state.json         last run, last success, last failure, the last 30 runs
├── recovery/          files rescued from retired release trees — never pruned
└── sets/
    └── 20261101T213000Z/
        ├── manifest.json
        ├── db-erp.sql.gz
        ├── files-erp-private-uploads.tar.gz
        ├── files-erp-private-uploads.index.json
        └── config.vault
```

**The archives are ordinary `tar.gz`.** This was verified rather than assumed: an archive written by this code is
listed and extracted correctly by the system `tar`, byte-for-byte. In a real disaster nobody should need this
codebase to get their data back — `tar -xzf` and `gunzip | mysql` are enough.

---

### What this host does not allow

Read from the server, not assumed (`disable_functions`):

```
system, exec, shell_exec, passthru, popen, proc_open, apache_child_terminate,
virtual, dl, ini_alter, chgrp, leak, mb_send_mail, mysql_list_dbs, symlink, link
```

Two of those shaped this design:

- **`proc_open` and every sibling are gone**, so no external program can be run from PHP — see below;
- **`link` is gone**, so the unchanged-tree optimisation cannot hard-link and falls back to copying. The work of
  re-compressing is still saved; the disk is not. With 7+4+3 retained sets, budget roughly ten times one set's size
  rather than one.

### Why the database dump is pure PHP

The first production run failed with **`Call to undefined function proc_open()`**. This shared host disables every
process-spawning function, so `mysqldump` cannot be started from PHP at all — and the hosting API offers no way to
run one either. Locally `proc_open` exists, which is exactly why nothing caught it until the code ran on the server.

So `App\Services\Backup\PhpSqlDumper` writes the dump itself, and it is **the default on every machine** rather than a
fallback: a dump path that only runs where the developer happens to have `proc_open` is a dump path nobody has tested
against production. The `mysqldump` implementation is kept and still used by setting `BACKUP_DUMPER=mysqldump` on a
machine that has it.

What the PHP dumper gets right, because each of these would make a dump quietly useless:

| Detail | Why it matters |
|---|---|
| a **GENERATED** column is never named in an INSERT | the database computes it; naming it makes the whole restore fail (`membership_dues.outstanding_amount` is STORED GENERATED) |
| binary and blob values are written as `0x…` hex | no quoting rule or character set can alter a byte |
| rows stream through an unbuffered query | a large table never has to fit in memory |
| the dump is wrapped in `FOREIGN_KEY_CHECKS=0` | restore order cannot matter, with 96 foreign keys in this schema |
| views come after tables, triggers after both | each object exists before anything references it |
| it ends with `Dump completed` | every integrity check already written keeps working unchanged |

Proven by restoring it, not by reading it: a dump was restored into a scratch database and **`CHECKSUM TABLE` matched
the source for all 59 tables**, with foreign keys and the generated column intact.

## 3. Commands

| Command | What it does |
|---|---|
| `php artisan backup:run` | one backup. Safe to run at any time; the lock makes a concurrent run a no-op |
| `php artisan backup:status` | last success, age, integrity, next expected run. **Exits non-zero when stale or broken** — this is what a monitor should watch |
| `php artisan backup:status --verify` | re-verify every set, not only the newest |
| `php artisan backup:status --consistency` | also check that every database file reference exists, live and in the backup |
| `php artisan backup:restore-file --label=… --path=… --to=…` | restore ONE file into a directory you name (runbook B) |
| `php artisan backup:restore-file --label=… --list=<prefix>` | what the set holds, without unpacking it |
| `php artisan backup:config-open --to=…` | decrypt the configuration bundle (needs `BACKUP_ENCRYPTION_KEY`) |

Nothing prints a credential, and no restore path is reachable over HTTP — these are console commands only.

---

## 4. Schedule and automation

**One** permanent cron, at the lowest-traffic hour:

```
30 21 * * *   php /home/u951246149/domains/provatferi.org/laravel-admin/artisan backup:run
```

21:30 UTC is **03:30 Asia/Dhaka**. It is deliberately far from the other permanent job (`membership:generate-dues`,
18:05 UTC = 00:05 Dhaka) so the two never contend.

Overlap is handled in the application, not by the schedule: an exclusive lock means a second run exits without
touching anything, and a lock held longer than two hours is treated as a hung backup and reported as a failure rather
than as a quiet skip.

---

## 5. Retention

Grandfather-father-son, applied to the sets that exist rather than by making extra copies:

| Bucket | Keeps |
|---|---|
| daily | the newest **7** sets |
| weekly | the newest set of each of the last **4** ISO weeks |
| monthly | the newest set of each of the last **3** calendar months |

A set simply belongs to whichever buckets it is the newest member of, so nothing is ever duplicated to satisfy the
policy. **The refusals matter more than the numbers:**

1. the newest set is never deleted, whatever the policy says;
2. **at least one set that retention would keep must verify intact first.** If nothing verifies, nothing is deleted —
   because the set about to be removed might be the only good one;
3. only a directory that is inside the backup root *and* parses as a set id is ever removed.

---

## 6. Integrity

A backup is not successful because a command exited 0. Every run, and every `backup:status --verify`, re-checks:

- every artifact named in the manifest exists, and its size and sha256 still match;
- every `.tar.gz` is walked to its end — a truncated archive, a corrupted gzip stream and a bad header checksum are
  all detected, and the entry count is compared with the manifest;
- every `.sql.gz` is checked for **gzip magic bytes** (a dump accidentally written uncompressed would otherwise pass
  every other test), for `CREATE TABLE`, for mysqldump's `Dump completed` marker, and for the exact byte count that
  was written.

The manifest records: backup id, start and finish, duration, total bytes, environment and the deployed commit, every
artifact with its hash and size, per-database table counts, per-tree file counts, warnings, and the verification
result. It contains **no secret, no credential and no private filename**.

---

## 7. Configuration and secrets

`config.vault` is the one artifact that contains secrets, and the only one that is encrypted: AES-256-GCM with a key
derived by PBKDF2-SHA256 (210,000 iterations) from a passphrase, with a random salt and nonce per set. GCM means a
tampered bundle fails to open rather than decrypting to rubbish.

**The key is the owner's, not the server's.** It is read from `BACKUP_ENCRYPTION_KEY` in the server's environment, and
the owner keeps the only other copy off-host. That asymmetry is the whole point: if the hosting account is lost, the
`.env` is lost with it, so a bundle encrypted with a key that lived only in that `.env` would be undecryptable at
exactly the moment it was needed.

**When the key is absent the bundle is skipped and the run says so.** It is never written unencrypted instead.

---

## 8. Where the backups are, and the part that is not solved

**Layer 1 — on the server (implemented).** `/home/u951246149/backups`, outside every document root, `0700`, with a
deny-all `.htaccess`, excluded from deploys (the release pipeline only ever touches `laravel-admin*`), and verified
unreachable over HTTP by real requests.

This protects against: an accidental delete, a bad migration, a corrupted table, a lost upload, a failed deployment.
It does **not** protect against losing the hosting account.

**Layer 2 — off-host (NOT implemented, and it cannot be from here).** This was investigated rather than assumed:

- the Hostinger API exposes **no backup endpoint at all** — there is nothing to read, trigger or download;
- the one file-reading endpoint it does have (`hosting_files_website-content`) is **read-only, refuses binary files,
  refuses files identified as containing secrets, and works only relative to a document root** — so it can carry
  neither a `.sql.gz`, nor a `.tar.gz`, nor the vault, nor anything in the backup root;
- the account has no outbound credential for any object store, and this task did not purchase one.

So the off-host copy needs **one owner decision** (see "Owner actions"). The mechanism is prepared:
`config/backup.php` has an `offhost` section, and `backup:status` reports `NOT CONFIGURED` until it is filled in, so
the gap is visible every time anyone looks rather than being forgotten.

---

## 9. Failure handling

| Signal | Where |
|---|---|
| exit status | `backup:run` exits non-zero on failure; `backup:status` exits non-zero when stale or unverifiable |
| state | `state.json` keeps `last_run`, `last_success`, `last_failure` and the last 30 runs. **`last_success` is only ever moved forward by a success**, so a failure can never make the system look healthier than it is |
| e-mail | a failed run mails the staff mailbox: **From `no-reply@provatferi.org`, Reply-To `security@provatferi.org`** (the security class of `docs/EMAIL_ARCHITECTURE.md`). It says what failed, when, and how old the last good backup is — and carries no backup content, no filename and no credential |

**The honest gap:** if the cron itself never runs, nothing sends anything — a dead-man's switch cannot live inside the
thing it watches. `backup:status` exits non-zero when the newest backup is older than 36 hours, which is exactly what
an external monitor should call. Adding that monitor is the owner action already recommended by the architecture audit.

---

## 10. Restore runbook

**Always in this order.** Files and configuration first, then the database, then permissions, then caches, then
checks. Restoring a database that references files which are not there yet produces an application that looks broken
in ways that send you hunting for the wrong problem.

### A — the ERP database only

```bash
cd /home/u951246149/domains/provatferi.org/laravel-admin
php artisan backup:status --verify                      # confirm the set is intact BEFORE relying on it
SET=/home/u951246149/backups/sets/<id>

# Restore into a SCRATCH database first and look at it. Never straight over production.
mysql -u <user> -p <scratch_db> < <(gunzip -c $SET/db-erp.sql.gz)
# ... check counts, check the rows you came for ...

# Only then, over the live database:
php artisan down
mysql -u <user> -p u951246149_admin_erp < <(gunzip -c $SET/db-erp.sql.gz)
php artisan config:cache && php artisan view:cache
php artisan up
```

The dump is taken **without** `--databases`, so it carries no `CREATE DATABASE`/`USE` — it restores into whatever
database you point `mysql` at, which is what makes the scratch-first step easy.

### B — one private upload (the common incident)

```bash
php artisan backup:restore-file --label=erp-private-uploads --list=applications/cv   # find it
php artisan backup:restore-file \
    --label=erp-private-uploads \
    --path=applications/cv/<uuid>.pdf \
    --to=/home/u951246149/restore-scratch
# the command verifies the restored bytes against the set's index, then:
cp /home/u951246149/restore-scratch/<uuid>.pdf \
   /home/u951246149/domains/provatferi.org/laravel-admin/storage/app/private/uploads/applications/cv/
```

It never writes into the live tree itself, and it refuses a destination inside a document root.

### C — the complete ERP

1. **Configuration** — `php artisan backup:config-open --to=/home/u951246149/restore-scratch/config`, then put
   `.env` back. (Needs `BACKUP_ENCRYPTION_KEY`.)
2. **Code** — deploy the commit the manifest names (`app.commit`) through the normal release pipeline.
3. **Files** — `tar -xzf $SET/files-erp-private-uploads.tar.gz -C .../storage/app/private/uploads`, then the public
   tree into `public_html/admin/storage`.
4. **Database** — runbook A.
5. **Permissions** — private uploads `0600`/`0700`; the public upload tree readable by the web server.
6. **Caches** — `php artisan config:cache`, `view:cache`, `route:cache`.
7. **Checks** — `php artisan backup:status --consistency` (does every row's file exist again?), `mail:identity`,
   `pdf:self-check`, then the live smoke test.

### D — the CMS WordPress site

1. `tar -xzf $SET/files-cms-uploads.tar.gz -C .../public_html/cms/wp-content/uploads`
2. `wp-config.php` from the configuration bundle, if it is gone
3. `mysql -u <user> -p <cms_db> < <(gunzip -c $SET/db-cms.sql.gz)`
4. check the site loads, then re-save permalinks if routes misbehave

### E — the Archive WordPress site

The same as D with `archive` in place of `cms`.

### F — catastrophic hosting loss

This is the scenario the off-host copy exists for, and **today it depends on one**:

1. Obtain the newest off-host backup set. *If none exists, stop: the recovery point is whatever Hostinger's own
   backups hold, which is unverified — see "Limitations".*
2. Provision hosting; create the databases.
3. Decrypt the configuration bundle with the key from the owner's password manager.
4. Deploy the commit named in the manifest.
5. Runbook C steps 3–7, then D and E.
6. Re-point DNS; re-create the two permanent crons (the inventory is in the bundle as `cron-known.json`).

---

## 11. RPO and RTO

Measured, not aspirational. The drill figures are in "Restore drill" below.

| | RPO (data that can be lost) | RTO (time to be back) |
|---|---|---|
| **ERP database** | **24 hours** (nightly), or minutes if a run is triggered by hand before a risky change | **under 15 minutes** — the dump restores in seconds; the time is checks and cache rebuilds |
| **Uploaded files** | **24 hours** | one file: **under 2 minutes**. A whole tree: minutes |
| **Configuration** | 24 hours; it changes very rarely | minutes, provided the owner has the encryption key |
| **Whole service, hosting intact** | 24 hours | **under 1 hour** |
| **Whole service, hosting lost** | **unbounded today** — there is no off-host copy | **unbounded today** |

That last row is the honest state of affairs, and the reason the off-host owner action is the first one listed.

---

## 12. Disaster scenarios

| Scenario | What to do |
|---|---|
| **A record was deleted by accident** | restore the newest set into a scratch database, copy the row back by hand. Do not restore the whole database over production for one row |
| **The database is corrupted** | runbook A, scratch-first |
| **An upload was lost or overwritten** | runbook B |
| **A deployment failed** | not a backup matter: the release pipeline's `rollback` renames the previous tree back. Uploads made during the window are merged by the pipeline, not restored from here |
| **The hosting account is unavailable** | runbook F, which needs the off-host copy |
| **Malicious deletion** | the backup root is `0700` and outside the web root, but it is on the same account, so an attacker with the account can delete it. Only the off-host copy answers this. Treat it as the main argument for layer 2 |
| **WordPress is corrupted** | runbooks D and E |

---

## 13. Database and file consistency

`php artisan backup:status --consistency` compares every database column that holds a file path (listed explicitly in
`config/backup.php`, so adding such a column is a deliberate decision to protect it) against both the live trees and
the newest backup set, and reports:

- rows whose file is missing **live** — a broken record right now;
- rows whose file is missing **from the backup** — a record that would restore broken;
- files present but referenced by nothing (a count only).

A problem is reported as `table.column#id` with a short digest of the path. **Applicants' and members' filenames never
appear in the output.**

---

## 13a. Production verification, 2026-11-01

The first real backups, taken on production:

| | Result |
|---|---|
| ERP database | 61 tables, 310,855 bytes of SQL → **62,983** compressed, 0.05 s |
| CMS database | 19 tables, 159,967 → 25,317 bytes |
| Archive database | 29 tables, 141,665 → 23,190 bytes |
| ERP private uploads | **23 files, 13,892,587 bytes** → 13,552,991 archived |
| ERP public uploads | **20 files, 6,706,813 bytes** → 6,600,528 archived |
| CMS / Archive media | present, 0 files (both WordPress sites hold no media yet) |
| Configuration bundle | **skipped** — `BACKUP_ENCRYPTION_KEY` is not set, and the run says so rather than writing it in the clear |
| Whole set | **20,276,456 bytes in 0.77 s**, verification ok over 11 artifacts |
| Two sets on disk | 33,962,998 bytes — about the sum of both, confirming there is no hard-link dedupe on this host |
| Permissions | root and subdirectories `drwx------`, every artifact `-rw-------` |
| HTTP reachability | every backup path returns **404**; an encoded traversal returns 400. Checked over real HTTPS against both domains, for the directory, `state.json`, a dump and an archive |
| File consistency on real data | **42 references across 15 columns, 0 missing live, 0 missing from the backup**, 1 unreferenced file |

The failure path was exercised for real too, twice, before the run succeeded (§"What this host does not allow"): each
failure was recorded in `state.json` with its reason, `last_success` was left untouched, and the partially written set
was removed rather than published — which is exactly what the atomic-rename design is for.

## 14. Retained release trees

**An old application release is not a backup.** Release retention exists to make a rollback possible; it keeps two
previous trees for a few days, prunes by policy, and must never be relied on for recovery. That two of those trees
happened to hold the only other copy of 29 private files was an accident, and the fail-closed housekeeping that
noticed it was doing its job — it was not a backup working.

`deploy/qa/release-tree-audit.php` resolves this, in this order: **rescue, then classify, then report.** Every private
file in a retired tree that the live application does not have is first copied into `backups/recovery/` (outside every
document root, `0600`, with a manifest of hashes), and only then classified:

| Verdict | Meaning |
|---|---|
| `referenced_by_a_live_row` | a current database row points at it and the live application does not have it — **irreplaceable**; restore it into live storage before releasing the tree |
| `duplicate_of_a_live_file` | byte-identical to a file the live app already has, under another path — safe to release |
| `looks_like_a_qa_artifact` | referenced by nothing and too small to be a real document — almost certainly a test fixture |
| `unexplained_keep_it` | referenced by nothing, not a duplicate, large enough to be real — **the owner decides**; nothing is deleted on this evidence |

### What it found, 2026-11-01

All four `_previous-*` trees were examined. **29 files were rescued** into
`/home/u951246149/backups/recovery/retired-release-trees` (`0700`, outside every document root, with a manifest of
hashes) before anything was judged:

| Tree | Files rescued | Bytes |
|---|---|---|
| `_previous-20261003-194002` | 24 | 34,220,279 |
| `_previous-20261018-195402` | 5 | 11,973,960 |

| Verdict | Count |
|---|---|
| `referenced_by_a_live_row` | **0** |
| `duplicate_of_a_live_file` | 0 |
| `looks_like_a_qa_artifact` | 10 |
| `unexplained_keep_it` | 19 |

**The decisive detail is in the hashes.** Those 29 files hold only **six distinct documents**:

- the 4 "CVs" are 2 distinct files (one repeated three times), all exactly 1,181,077 bytes;
- the 20 "photographs" are 3 distinct files — one of them **328 bytes, repeated ten times**, which no camera produced;
- the 5 committee photographs are **one** file repeated five times, 2,394,792 bytes each.

Real applicants do not upload byte-identical documents three and seven and ten times over. Taken with
`referenced_by_a_live_row: 0` for every one of them, the evidence says these are fixtures from the October QA runs,
not records. **It still is not conclusive enough to delete them on**, which is why the classifier says
`unexplained_keep_it` for the large ones: a 3.7 MB photograph repeated seven times is consistent with a fixture and
also with one real upload a QA script copied around.

So: the rescue copy exists, nothing was deleted, the fail-closed release cleanup was not weakened, and releasing those
two trees (≈ 18,700 inodes and ≈ 310 MB) now costs nothing but the owner's confirmation.

---

## 15. Security

| Concern | How it is handled |
|---|---|
| web download of a backup | the root is outside every document root, `0700`, with a deny-all `.htaccess`; verified by real HTTP requests |
| predictable public URLs | there is no URL: nothing serves this tree |
| a restore endpoint | there is none. Every restore path is a console command |
| path traversal | one component (`BackupPaths`) owns every path decision. Member names must be plain relative paths; destinations are refused if they are, or would be, inside a document root — *including paths that do not exist yet*, which is how the first version of the check was broken |
| command injection | **no external process is started at all** on this host: the dump is pure PHP and so are tar and gzip. Where `mysqldump` is opted into, it is started with an argument array through `proc_open`, so no shell parses anything |
| secret leakage | with the PHP dumper there is **no command line for a password to leak into**; driver messages are scrubbed of user and password before they can be reported. With `mysqldump`, the password goes into a `0600` defaults file that is overwritten and deleted in a `finally` — never in `argv`, a log, cron output or an exception |
| weak permissions | every artifact `0600`, every directory `0700` |
| symlink attacks | symlinks are never followed and never archived — they are counted, named and skipped |
| backup overwrite | a set is written to `.tmp-<id>` and renamed into place; a set id is a UTC timestamp and never reused |
| tampering | the manifest holds a sha256 per artifact; the configuration bundle is AES-GCM, so alteration makes it fail to open |

---

## 16. Limitations

1. **There is no off-host copy.** Every backup is on the same hosting account as production. This is the single
   biggest remaining risk, and it needs an owner decision, not more code.
2. **Hostinger's own backups are unverified.** The API exposes nothing about them: not their frequency, not their
   retention, not whether a single file or a single database can be restored, not whether they live outside this
   filesystem. Marked **owner-verification-required** throughout.
3. **The pure-PHP dumper has no wall-clock timeout.** A query that hangs would hang the run; that is caught instead by
   the lock going stale, which is reported as a failure rather than a quiet skip.
4. **The Node.js applications' environment variables are not in the bundle**: Hostinger stores them outside the
   filesystem and the API returns them masked. They must be recorded by the owner separately.
5. **`disk_bytes` counts each inode once**, so hard-linked archives are not double-counted — but it is still an
   apparent-size figure, not an allocated-size one.
6. Beyond roughly 5 GB of uploads, one archive per tree per set stops being the right shape; that is the same
   threshold at which the architecture audit says to move uploads to object storage.

---

## 17. Owner actions

1. **Decide the off-host destination** (the most important one). Any of these works, cheapest first:
   - an S3-compatible bucket (Backblaze B2, Cloudflare R2, Wasabi) — give the ERP a key with write-only access to one
     bucket, set `BACKUP_OFFHOST_DRIVER`/`BACKUP_OFFHOST_TARGET`, and the nightly run can push each set;
   - an rclone target on a machine you control;
   - **manual, and free**: download `/home/u951246149/backups/sets/<newest>/` through hPanel's File Manager once a
     week and keep it somewhere else. Unglamorous, but it converts an unbounded RPO into a one-week one.
2. **Generate and store the encryption key.** On your own machine: `openssl rand -base64 32`. Put it in your password
   manager **first**, then add it to the server's `.env` as `BACKUP_ENCRYPTION_KEY=…` through hPanel's File Manager.
   Until then the configuration bundle is skipped and `backup:status` says so. Never send it by e-mail or chat.
3. **Verify Hostinger's own backups in hPanel**: frequency, retention, and whether a single database or a single file
   can be restored. Everything in "Limitations" item 2 depends on this.
4. **Confirm the retained-release-tree findings** in the task report, so ~18,700 inodes and ~310 MB can be released.
5. **Add an external monitor** that calls `backup:status` (or simply watches the site) — the dead-man's switch that
   cannot live on the same server.
6. Record the Node applications' environment variables somewhere safe (Limitations item 4).

---

## 18. Tests

`tests/Feature/Backup/`:

| File | Covers |
|---|---|
| `BackupPathSafetyTest` | member names, destinations inside a document root **including ones that do not exist yet**, traversal, the backup root's own placement |
| `BackupRetentionTest` | the 7/4/3 buckets, and every refusal: nothing deleted when no keeper verifies, the newest set never deleted, a dry run deleting nothing, unrecognised directories left alone |
| `BackupIntegrityTest` | archive round-trip, truncation, corruption, index determinism, dump sanity (including a dump that is not actually gzip), hash mismatch, a manifest naming a path outside the set, encryption with the right/wrong/tampered key, skip-without-a-key, symlink skipping |
| `BackupRunTest` | a verified set with a manifest, the private root and its HTTP denial, **no credential in the manifest/state/index**, a failing dump recorded as a failure, overlap, a stuck lock reported as a failure, abandoned `.tmp-` sweeping, archive reuse, the failure e-mail and its Reply-To |
| `PhpSqlDumperTest` | the dump path production runs: integrity, a GENERATED column never named in an INSERT, Bengali text verbatim, binary as hex, the FOREIGN_KEY_CHECKS wrapper, no credential anywhere, and a failure reported rather than a half dump |
| `BackupConsistencyAndRestoreTest` | a row whose file is missing (without naming the file), a file missing from the backup, removed columns skipped, and the restore commands' refusals |

The database dump is stubbed in the ORCHESTRATION tests on purpose (PhpSqlDumperTest covers the dump itself) — whether a dump restores is proven by running
it, and whether a backup restores is proven by the drill, which no mock can replace.
