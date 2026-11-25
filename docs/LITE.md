# CajeerEngineLite 1.6.0 · Workspace 0.7.3

This adapter is independent of the Engine REST API. It uses the native Lite PHP classes from the installed code, not HTTP admin scraping or direct writes to JSON. It supports the supplied upstream commit `c6a52cbbe4fe123dacdf19b1cfd4ed9b15e0a587`, app version 1.6.0, native site schema 14. No changes to Lite are required.

## Provisioning and trust boundary

Install and initialize Lite on a Linux host. The Workspace CLI runs on that same host with access to the Workspace database (local SQLite or shared PostgreSQL) and the site's private storage, under a non-root trusted service account with the same native storage permissions as CMS writers. Do not grant the Workspace web process access to Lite files; only the trusted CLI loads Lite PHP code. Do not use untrusted plugins or installations: PHP code loaded from the CMS has the same privileges as the CLI. Restrict DB/CLI access to trusted operators. Do not place multiple mutually untrusted projects' sites in a shared writable account. Use a dedicated site per project. Separate installations on different servers require a trusted Workspace CLI deployment on each host with its local target available; the commands never execute remote shell commands.

Create a protected, operator-owned JSON file (e.g. mode 0640, group read-only; parents not writable by the web user). Both web and CLI must see the identical file. Root must be an absolute canonical path without symlinks. Do not put it under a public web directory. The `root` and `origin` are allowlisted by the operator, not supplied by browser requests. Example `WORKSPACE_LITE_TARGETS=/etc/workspace/lite-targets.json`:

```json
{
  "docs-site": {
    "root": "/srv/cajeer-lite",
    "organization_id": 1,
    "project_id": 1,
    "origin": "https://site.example.com",
    "version": "1.6.0"
  }
}
```

Use canonical HTTPS origin (loopback HTTP allowed for tests). Actual Lite site settings URL and VERSION must match. Do not use aliases for one installation. A target key, root fingerprint and origin are unique across Workspace. Configuration drift fails closed. Changing a root or origin needs an explicit operator migration, not silent reassignment. Owner binds the target in **Сайты Lite**. Active binding requires a current project `connections` right for viewing. All web operations require fresh MFA; publication requires `manage` and `connections`. All draft data is readable to those project readers; only use dedicated sites whose drafts are appropriate for these users. Disable a binding to revoke access to its local catalog (database backups may retain snapshots).

## Publication

1. Operator runs `php bin/workspace lite:inspect SITE_ID OWNER_ID`. The CLI reads valid schema-14 `site.json` under the same `site.lock` as the CMS, stores only page metadata and body text plus SHA-256 of the whole raw JSON. No passwords, auth state or integration secrets are returned. Snapshot can be used for up to one hour to queue a request. A login or settings change can invalidate it just like a page edit. Re-inspect if it becomes stale. CMS paths that are not ASCII slug paths can be inspected but not published through this version.
2. In Sites Lite a manager/owner sees text preview and confirms an existing draft or selects one page of a doubly reviewed Book edition and an unused path. Book mode preserves exact reviewed Markdown body bytes as plain text, not rendered Markdown, assets or HTML; it rejects pages with media, empty or >60,000 byte body and noncanonical native title. New Book pages are published but not in the navigation by default. Existing pages cannot be overwritten by Book; edit them in Lite's native editor. Publishing a draft uses native `PagesManagement::status`, retaining blocks, SEO, menu and other page fields. Preview of a draft shows plain text, not themed or asset-pinned output.
3. Operator runs `php bin/workspace lite:work JOB_ID` on the target host. Job claims once; checks project rights, target config, raw site hash and page revision or Book approval and current reviewers. Only one outstanding operation per site. Acquires native operation lease (excludes CMS archive/update jobs), then native exclusive site lock. Rejects pending update/import recovery. `BackupService::createLocked()` creates and verifies a native site JSON backup inside the same lock; native checksum and outer file SHA-256 are recorded, along with expected post-commit bytes, **before** calling `SiteRepository::commit()`. Native commit validates schema and uses atomic file replacement. Workspace checks actual resulting bytes before marking publication complete. Failure before mutation is terminal; changed site returns conflict. The URL is a derived route at the moment of local commit, not a remote uptime check.
4. If interrupted between snapshot and completion, `php bin/workspace lite:reconcile JOB_ID` obtains native locks and checks current bytes. Expected preimage means aborted; expected postimage means publication observed; otherwise uncertain blocks the target. Never repeat an uncertain job. If data diverges, preserve filesystem and database evidence and investigate offline; no automatic overwrite or force-unlock is provided. Use Lite's documented backup/recovery commands on an isolated copy first. Jobs and timestamps are audited; a Workspace DB restore cannot undo a CMS change.

## Restore

This operation restores **entire site JSON** from the selected job, not just the affected page. No media, template or code changes are performed by the adapter. Owner requests restore in UI with explicit confirmation. Stop *all* CMS writers (web workers, scheduler, CLI jobs, updates), verify the site's private backup is present and do not resume until verification. The native operation lease does not exclude every independent CLI or admin writer, so `--offline` is an operator attestation and mandatory. Never run recovery on a live writable installation. Run:

```bash
php bin/workspace lite:restore JOB_ID --offline
```

The worker checks the current raw JSON exactly equals that publication's expected result; even an unrelated later edit or login state change refuses restore. It verifies the original backup's checksum, calls native `BackupService::restore()` (preserves current credentials, saves a rescue backup, revokes sessions), checks content against the preimage excluding auth fields, and records restoration. In case of interruption run `lite:reconcile JOB_ID --offline`. If expected restored content is observed it repeats only native session invalidation, not the restore. If divergence persists, the site stays blocked pending manual investigation. Do not override the database state to bypass a conflict. Retain the native backup until recovery is no longer needed. Full off-host backup of code, templates, media, storage and Workspace DB is separately required; JSON snapshots do not protect other files. Secure native backups (they contain sensitive site settings and auth hashes). Follow Lite `docs/BACKUP_RECOVERY.md` for full CMS TAR operations and disaster recovery. Native backup pruning must not remove snapshots referenced by outstanding jobs.

## Acceptance

`python3 tests/lite_schema.py`; `WORKSPACE_LITE_TEST_SOURCE=/path/to/clean/Lite-1.6.0 php tests/lite_db.php`. CI checks out the pinned upstream commit. Test creates a temporary independent installed Lite, exercises native page save, snapshot, rollback, rendered output, Book snapshot and revocation; injected exceptions before/after native commit verify recovery without replay. Test also supports `--postgres` for transfer checks. Production acceptance still requires a staging installation: interrupt the worker process after backup, verify before/after recovery, stop writers and restore, check public route and login; validate multi-host DB/permissions/backup operations. This release does not offer a remote HTTP API, theme rendering in Workspace, media migration, automatic deployment, automatic rollback or S3 backups.
