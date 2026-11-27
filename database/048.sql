-- Immutable statement parser revisions, import provenance and independent review events.
CREATE TABLE bank_formats (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL REFERENCES organizations(id),
 name TEXT NOT NULL CHECK(length(name) BETWEEN 1 AND 120), revision INTEGER NOT NULL CHECK(revision>0),
 configuration TEXT NOT NULL, actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(organization_id,id), UNIQUE(organization_id,name,revision)
);
CREATE TABLE bank_import_sources (
 statement_id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, format_id INTEGER,
 format_snapshot TEXT NOT NULL, raw_digest TEXT NOT NULL CHECK(length(raw_digest)=64),
 FOREIGN KEY(organization_id,statement_id) REFERENCES bank_statements(organization_id,id),
 FOREIGN KEY(organization_id,format_id) REFERENCES bank_formats(organization_id,id)
);
CREATE TABLE bank_discrepancy_reviews (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, statement_id INTEGER NOT NULL, row_id INTEGER NOT NULL,
 row_version INTEGER NOT NULL CHECK(row_version>0), evidence_digest TEXT NOT NULL CHECK(length(evidence_digest)=64),
 decision TEXT NOT NULL CHECK(decision IN ('approved','rejected')), note TEXT NOT NULL CHECK(length(note) BETWEEN 1 AND 500),
 actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 FOREIGN KEY(organization_id,statement_id) REFERENCES bank_statements(organization_id,id),
 FOREIGN KEY(statement_id,row_id) REFERENCES bank_rows(statement_id,id)
);
CREATE INDEX bank_discrepancy_review_row ON bank_discrepancy_reviews(row_id,id);
