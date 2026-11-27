-- AI policy, UTC quota reservation and optional personal answer history.
CREATE TABLE ai_org_policies (
 organization_id INTEGER PRIMARY KEY REFERENCES organizations(id) ON DELETE CASCADE,
 data_types TEXT NOT NULL, model TEXT NOT NULL,
 daily_quota INTEGER NOT NULL CHECK(daily_quota BETWEEN 0 AND 1000),
 retention_days INTEGER NOT NULL CHECK(retention_days BETWEEN 0 AND 30),
 version INTEGER NOT NULL CHECK(version>0)
);
CREATE TABLE ai_daily_usage (
 organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
 day_on TEXT NOT NULL, requests INTEGER NOT NULL CHECK(requests>0), PRIMARY KEY(organization_id,day_on)
);
CREATE TABLE ai_history (
 handle TEXT PRIMARY KEY CHECK(length(handle)=48),
 organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
 user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 project_id INTEGER NOT NULL, topic TEXT NOT NULL, term TEXT NOT NULL,
 context_hash TEXT NOT NULL CHECK(length(context_hash)=64), answer TEXT NOT NULL, refs TEXT NOT NULL,
 observed_at INTEGER NOT NULL, expires_at INTEGER NOT NULL CHECK(expires_at>observed_at),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id) ON DELETE CASCADE
);
CREATE INDEX ai_history_expiry ON ai_history(expires_at);
CREATE INDEX ai_history_person ON ai_history(organization_id,user_id,project_id,observed_at);
