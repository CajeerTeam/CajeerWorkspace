-- Operational AI metrics, cost reservations and explicitly delegated read-only access.
CREATE TABLE ai_calls (
 handle TEXT PRIMARY KEY CHECK(length(handle)=32),
 organization_id BIGINT NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
 user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 project_id BIGINT NOT NULL, role TEXT NOT NULL, model TEXT NOT NULL, operation TEXT NOT NULL,
 started_at BIGINT NOT NULL, finished_at BIGINT, latency_ms BIGINT,
 status TEXT NOT NULL CHECK(status IN ('running','ok','cancelled','rate_limited','timeout','failed','rejected')),
 cancel_requested BIGINT NOT NULL DEFAULT 0 CHECK(cancel_requested IN (0,1)),
 input_tokens BIGINT, output_tokens BIGINT, reserved_micro BIGINT, cost_micro BIGINT,
 retry_until BIGINT, quality BIGINT CHECK(quality IN (0,1)),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id) ON DELETE CASCADE
);
CREATE INDEX ai_calls_org_time ON ai_calls(organization_id,started_at);
CREATE INDEX ai_calls_user_status ON ai_calls(user_id,status);
CREATE TABLE ai_delegation_policy (
 organization_id BIGINT PRIMARY KEY REFERENCES organizations(id) ON DELETE CASCADE,
 enabled BIGINT NOT NULL CHECK(enabled IN (0,1)), generation BIGINT NOT NULL
);
CREATE TABLE ai_delegations (
 token_hash TEXT PRIMARY KEY CHECK(length(token_hash)=64),
 organization_id BIGINT NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
 user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 project_id BIGINT NOT NULL, session_hash TEXT NOT NULL, session_version BIGINT NOT NULL,
 auth_kind TEXT NOT NULL, generation BIGINT NOT NULL, policy_version BIGINT NOT NULL,
 client_binding TEXT NOT NULL, challenge TEXT NOT NULL, expires_at BIGINT NOT NULL, kind TEXT NOT NULL CHECK(kind IN ('code','token')),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id) ON DELETE CASCADE
);
CREATE INDEX ai_delegations_owner ON ai_delegations(organization_id,user_id,expires_at);
