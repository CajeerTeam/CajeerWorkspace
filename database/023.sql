-- Explicit retention policy snapshots, legal hold, private objects and disclosure audit.
ALTER TABLE document_versions ADD COLUMN object_key TEXT CHECK(object_key IS NULL OR length(object_key)=64);
ALTER TABLE document_versions ADD COLUMN original_size INTEGER CHECK(original_size IS NULL OR original_size BETWEEN 10 AND 2097152);
ALTER TABLE document_versions ADD COLUMN storage_state TEXT NOT NULL DEFAULT 'inline' CHECK(storage_state IN ('inline','object','purged') AND (storage_state<>'object' OR (object_key IS NOT NULL AND original_size IS NOT NULL)) AND (storage_state<>'purged' OR original_size IS NOT NULL));
ALTER TABLE signing_archive ADD COLUMN object_key TEXT CHECK(object_key IS NULL OR length(object_key)=64);
ALTER TABLE signing_archive ADD COLUMN storage_state TEXT NOT NULL DEFAULT 'inline' CHECK(storage_state IN ('inline','object') AND (storage_state<>'object' OR object_key IS NOT NULL));
CREATE UNIQUE INDEX document_object_key ON document_versions(object_key);
CREATE UNIQUE INDEX signing_object_key ON signing_archive(object_key);
CREATE TABLE retention_policies (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL REFERENCES organizations(id), country TEXT NOT NULL CHECK(length(country)=2),
 kind TEXT NOT NULL CHECK(kind IN ('contract','nda','acceptance','amendment','other')), days INTEGER NOT NULL CHECK(days BETWEEN 1 AND 36525),
 basis TEXT NOT NULL CHECK(length(basis) BETWEEN 1 AND 1000), created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(organization_id,id)
);
CREATE TABLE retention_documents (
 document_id INTEGER PRIMARY KEY REFERENCES documents(id), organization_id INTEGER NOT NULL REFERENCES organizations(id), policy_id INTEGER NOT NULL,
 assigned_by INTEGER NOT NULL REFERENCES users(id), assigned_at INTEGER NOT NULL, starts_on TEXT NOT NULL CHECK(length(starts_on)=10),
 FOREIGN KEY(organization_id,policy_id) REFERENCES retention_policies(organization_id,id)
);
CREATE TABLE retention_holds (
 id INTEGER PRIMARY KEY, document_id INTEGER NOT NULL REFERENCES documents(id), reason TEXT NOT NULL CHECK(length(reason) BETWEEN 1 AND 1000),
 created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL, released_by INTEGER REFERENCES users(id), released_at INTEGER, release_reason TEXT,
 CHECK((released_at IS NULL AND released_by IS NULL AND release_reason IS NULL) OR (released_at IS NOT NULL AND released_by IS NOT NULL AND release_reason IS NOT NULL))
);
CREATE INDEX retention_hold_doc ON retention_holds(document_id,released_at);
CREATE TABLE retention_links (
 id INTEGER PRIMARY KEY, document_id INTEGER NOT NULL, revision INTEGER NOT NULL, package_id INTEGER REFERENCES signing_archive(package_id),
 token_hash TEXT NOT NULL UNIQUE CHECK(length(token_hash)=64), issuer_id INTEGER NOT NULL REFERENCES users(id), request_key TEXT NOT NULL CHECK(length(request_key)=32),
 created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL CHECK(expires_at>created_at), max_uses INTEGER NOT NULL CHECK(max_uses BETWEEN 1 AND 20), uses INTEGER NOT NULL DEFAULT 0 CHECK(uses BETWEEN 0 AND max_uses), revoked_at INTEGER,
 FOREIGN KEY(document_id,revision) REFERENCES document_versions(document_id,revision), UNIQUE(issuer_id,request_key)
);
CREATE INDEX retention_link_doc ON retention_links(document_id,revision);
CREATE TABLE retention_deliveries (
 id INTEGER PRIMARY KEY, document_id INTEGER NOT NULL, revision INTEGER NOT NULL, package_id INTEGER REFERENCES signing_packages(id),
 actor_id INTEGER REFERENCES users(id), guest_id INTEGER REFERENCES guest_invites(id), link_id INTEGER REFERENCES retention_links(id),
 channel TEXT NOT NULL, payload_sha256 TEXT NOT NULL CHECK(length(payload_sha256)=64), bytes_size INTEGER NOT NULL CHECK(bytes_size>0), issued_at INTEGER NOT NULL,
 FOREIGN KEY(document_id,revision) REFERENCES document_versions(document_id,revision),
 CHECK((CASE WHEN actor_id IS NULL THEN 0 ELSE 1 END)+(CASE WHEN guest_id IS NULL THEN 0 ELSE 1 END)+(CASE WHEN link_id IS NULL THEN 0 ELSE 1 END)=1)
);
CREATE INDEX retention_delivery_doc ON retention_deliveries(document_id,id);
