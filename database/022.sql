-- One signing package per approved revision; provider evidence is retained independently of later drafts.
CREATE TABLE signing_packages (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, preparation_id INTEGER NOT NULL, revision_id INTEGER NOT NULL UNIQUE,
 nonce TEXT NOT NULL UNIQUE CHECK(length(nonce)=64), evidence_hash TEXT NOT NULL CHECK(length(evidence_hash)=64),
 signers_json TEXT NOT NULL, approval_json TEXT NOT NULL, test_mode INTEGER NOT NULL CHECK(test_mode IN (0,1)),
 state TEXT NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','sending','uncertain','pending','partial','declined','provider_error','archived','cancelled')),
 remote_id TEXT UNIQUE, send_receipt TEXT, status_receipt TEXT, last_error TEXT NOT NULL DEFAULT '',
 claim TEXT, lease_until INTEGER, next_check INTEGER NOT NULL DEFAULT 0, created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 FOREIGN KEY(project_id,preparation_id,revision_id) REFERENCES preparation_revisions(project_id,preparation_id,id),
 CHECK((claim IS NULL AND lease_until IS NULL) OR (claim IS NOT NULL AND lease_until IS NOT NULL)),
 CHECK(remote_id IS NOT NULL OR state NOT IN ('pending','partial','declined','provider_error','archived'))
);
CREATE INDEX signing_due ON signing_packages(state,next_check,id);
CREATE INDEX signing_project ON signing_packages(project_id,id);
CREATE TABLE signing_archive (
 package_id INTEGER PRIMARY KEY REFERENCES signing_packages(id), pdf_base64 TEXT NOT NULL,
 pdf_sha256 TEXT NOT NULL CHECK(length(pdf_sha256)=64), pdf_size INTEGER NOT NULL CHECK(pdf_size BETWEEN 1 AND 16777216),
 status_json TEXT NOT NULL, status_sha256 TEXT NOT NULL CHECK(length(status_sha256)=64), archived_at INTEGER NOT NULL
);
CREATE TABLE signing_callbacks (
 id INTEGER PRIMARY KEY, package_id INTEGER NOT NULL REFERENCES signing_packages(id),
 payload_sha256 TEXT NOT NULL UNIQUE CHECK(length(payload_sha256)=64), event_type TEXT NOT NULL, event_time INTEGER NOT NULL,
 payload TEXT NOT NULL, content_signature TEXT NOT NULL, received_at INTEGER NOT NULL
);
CREATE INDEX signing_callback_package ON signing_callbacks(package_id,id);
CREATE TABLE signing_receipts (
 id INTEGER PRIMARY KEY, package_id INTEGER NOT NULL REFERENCES signing_packages(id),
 payload_sha256 TEXT NOT NULL CHECK(length(payload_sha256)=64), payload TEXT NOT NULL, received_at INTEGER NOT NULL,
 UNIQUE(package_id,payload_sha256)
);
