CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('owner','manager','developer','accountant','legal','viewer')),
    password_hash TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0,1)),
    session_version INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE login_attempts (
    bucket TEXT PRIMARY KEY,
    started INTEGER NOT NULL,
    attempts INTEGER NOT NULL
);
CREATE INDEX login_expiry ON login_attempts(started);
CREATE TABLE projects (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    client TEXT NOT NULL DEFAULT '',
    kind TEXT NOT NULL CHECK (kind IN ('internal','client','oss')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE tasks (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects,
    title TEXT NOT NULL,
    assignee_id INTEGER REFERENCES users,
    due_on TEXT,
    state TEXT NOT NULL DEFAULT 'todo' CHECK (state IN ('todo','doing','done')),
    version INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX tasks_project ON tasks(project_id, state, due_on);
CREATE TABLE cash_entries (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects,
    direction TEXT NOT NULL CHECK (direction IN ('income','expense')),
    amount_minor INTEGER NOT NULL CHECK (typeof(amount_minor)='integer' AND abs(amount_minor) BETWEEN 1 AND 99999999999),
    currency TEXT NOT NULL CHECK (currency IN ('RUB','EUR','USD','PLN','GBP')),
    booked_on TEXT NOT NULL,
    memo TEXT NOT NULL,
    reversal_of INTEGER UNIQUE REFERENCES cash_entries,
    actor_id INTEGER NOT NULL REFERENCES users,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK ((amount_minor > 0 AND reversal_of IS NULL) OR (amount_minor < 0 AND reversal_of IS NOT NULL))
);
CREATE INDEX cash_period ON cash_entries(project_id, booked_on, id);
CREATE TABLE documents (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects,
    title TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('contract','nda','acceptance','amendment','other'))
);
CREATE TABLE document_versions (
    document_id INTEGER NOT NULL REFERENCES documents,
    revision INTEGER NOT NULL CHECK (revision > 0),
    sha256 TEXT NOT NULL CHECK (length(sha256)=64),
    bytes BLOB NOT NULL CHECK (length(bytes) BETWEEN 10 AND 2097152),
    actor_id INTEGER NOT NULL REFERENCES users,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(document_id, revision)
);
CREATE INDEX documents_project ON documents(project_id);
CREATE TABLE project_connections (
    project_id INTEGER NOT NULL REFERENCES projects,
    connection_key TEXT NOT NULL,
    PRIMARY KEY(project_id, connection_key)
);
CREATE TABLE connection_results (
    connection_key TEXT PRIMARY KEY,
    target_hash TEXT NOT NULL,
    ok INTEGER NOT NULL CHECK (ok IN (0,1)),
    summary TEXT NOT NULL,
    checked_at INTEGER NOT NULL
);
CREATE TABLE action_log (
    id INTEGER PRIMARY KEY,
    actor_id INTEGER NOT NULL REFERENCES users,
    request_key TEXT NOT NULL,
    fingerprint TEXT NOT NULL,
    action TEXT NOT NULL,
    object_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(actor_id, request_key)
);
