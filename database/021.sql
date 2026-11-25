-- Project-scoped managed document preparation; immutable revision and decision snapshots.
CREATE TABLE preparation_templates (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id),
 kind TEXT NOT NULL CHECK(kind IN ('contract','nda','specification','acceptance','amendment')),
 revision INTEGER NOT NULL CHECK(revision>0), body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 16000),
 sha256 TEXT NOT NULL CHECK(length(sha256)=64), created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(project_id,kind,revision), UNIQUE(project_id,id,kind)
);
CREATE TABLE preparations (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id),
 kind TEXT NOT NULL CHECK(kind IN ('contract','nda','specification','acceptance','amendment')),
 title TEXT NOT NULL CHECK(length(title) BETWEEN 1 AND 150), editor_id INTEGER NOT NULL REFERENCES users(id),
 lawyer_id INTEGER NOT NULL REFERENCES users(id), approver_id INTEGER NOT NULL REFERENCES users(id),
 state TEXT NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','legal_review','approval','approved','changes','cancelled')),
 version INTEGER NOT NULL DEFAULT 1 CHECK(version>0), created_at INTEGER NOT NULL,
 UNIQUE(project_id,id,kind), CHECK(editor_id<>lawyer_id AND lawyer_id<>approver_id AND editor_id<>approver_id)
);
CREATE TABLE preparation_revisions (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, preparation_id INTEGER NOT NULL, kind TEXT NOT NULL,
 revision INTEGER NOT NULL CHECK(revision>0), template_id INTEGER NOT NULL, body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 16000),
 document_id INTEGER, document_revision INTEGER, document_hash TEXT,
 evidence_hash TEXT NOT NULL CHECK(length(evidence_hash)=64), created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(preparation_id,revision), UNIQUE(project_id,preparation_id,id),
 FOREIGN KEY(project_id,preparation_id,kind) REFERENCES preparations(project_id,id,kind),
 FOREIGN KEY(project_id,template_id,kind) REFERENCES preparation_templates(project_id,id,kind),
 FOREIGN KEY(project_id,document_id) REFERENCES documents(project_id,id),
 FOREIGN KEY(document_id,document_revision) REFERENCES document_versions(document_id,revision),
 CHECK((document_id IS NULL AND document_revision IS NULL AND document_hash IS NULL) OR (document_id IS NOT NULL AND document_revision IS NOT NULL AND document_hash IS NOT NULL AND length(document_hash)=64))
);
CREATE TABLE preparation_events (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, preparation_id INTEGER NOT NULL, revision_id INTEGER NOT NULL,
 decision TEXT NOT NULL CHECK(decision IN ('create','revise','submit','legal_accept','return','approve','cancel','assign')),
 evidence_hash TEXT NOT NULL CHECK(length(evidence_hash)=64), actor_id INTEGER NOT NULL REFERENCES users(id),
 route_snapshot TEXT NOT NULL, note TEXT NOT NULL CHECK(length(note) BETWEEN 1 AND 2000), created_at INTEGER NOT NULL,
 FOREIGN KEY(project_id,preparation_id,revision_id) REFERENCES preparation_revisions(project_id,preparation_id,id)
);
CREATE INDEX preparation_project ON preparations(project_id,state,id);
CREATE INDEX preparation_event_history ON preparation_events(preparation_id,id);
