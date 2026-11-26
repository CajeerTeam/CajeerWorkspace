-- Staff session registry: only hashes of opaque browser handles are stored.
CREATE TABLE staff_sessions (
 handle TEXT PRIMARY KEY CHECK(length(handle)=64),
 user_id INTEGER NOT NULL REFERENCES users(id),
 kind TEXT NOT NULL CHECK(kind IN ('local','emergency','oidc')),
 session_version INTEGER NOT NULL,
 created_at INTEGER NOT NULL, seen_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
 revoked_at INTEGER,
 CHECK(created_at<=seen_at AND seen_at<=expires_at)
);
CREATE INDEX staff_sessions_user ON staff_sessions(user_id,expires_at);
CREATE TABLE identity_history (
 event_key TEXT PRIMARY KEY CHECK(length(event_key)=64),
 user_id INTEGER NOT NULL REFERENCES users(id),
 actor TEXT NOT NULL CHECK(actor IN ('self','operator','invitation')),
 action TEXT NOT NULL,
 occurred_at INTEGER NOT NULL,
 reason TEXT NOT NULL DEFAULT ''
);
CREATE INDEX identity_history_user ON identity_history(user_id,occurred_at);
