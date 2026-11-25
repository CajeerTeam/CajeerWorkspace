-- Migration ledger: legacy rows are adopted only after checking the old schema.
CREATE TABLE schema_migrations (
    version INTEGER PRIMARY KEY CHECK (version > 0),
    sha256 TEXT NOT NULL CHECK (length(sha256)=64),
    origin TEXT NOT NULL CHECK (origin IN ('legacy','applied')),
    recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
