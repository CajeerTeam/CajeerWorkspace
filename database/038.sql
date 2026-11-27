-- Operational AI metrics, cost reservations and explicitly delegated read-only access.
CREATE TABLE ai_calls (
 handle TEXT PRIMARY KEY CHECK(length(handle)=32),
 organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
 user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 project_id INTEGER NOT NULL, role TEXT NOT NULL, model TEXT NOT NULL, operation TEXT NOT NULL,
 started_at INTEGER NOT NULL, finished_at INTEGER, latency_ms INTEGER,
 status TEXT NOT NULL CHECK(status IN ('running','ok','cancelled','rate_limited','timeout','failed','rejected')),
 cancel_requested INTEGER NOT NULL DEFAULT 0 CHECK(cancel_requested IN (0,1)),
 input_tokens INTEGER, output_tokens INTEGER, reserved_micro INTEGER, cost_micro INTEGER,
 retry_until INTEGER, quality INTEGER CHECK(quality IN (0,1)),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id) ON DELETE CASCADE
);
CREATE INDEX ai_calls_org_time ON ai_calls(organization_id,started_at);
CREATE INDEX ai_calls_user_status ON ai_calls(user_id,status);
CREATE TABLE ai_delegation_policy (
 organization_id INTEGER PRIMARY KEY REFERENCES organizations(id) ON DELETE CASCADE,
 enabled INTEGER NOT NULL CHECK(enabled IN (0,1)), generation INTEGER NOT NULL
);
CREATE TABLE ai_delegations (
 token_hash TEXT PRIMARY KEY CHECK(length(token_hash)=64),
 organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
 user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 project_id INTEGER NOT NULL, session_hash TEXT NOT NULL, session_version INTEGER NOT NULL,
 auth_kind TEXT NOT NULL, generation INTEGER NOT NULL, policy_version INTEGER NOT NULL,
 client_binding TEXT NOT NULL, challenge TEXT NOT NULL, expires_at INTEGER NOT NULL, kind TEXT NOT NULL CHECK(kind IN ('code','token')),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id) ON DELETE CASCADE
);
CREATE INDEX ai_delegations_owner ON ai_delegations(organization_id,user_id,expires_at);
