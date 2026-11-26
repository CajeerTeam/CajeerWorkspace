-- Persistent reconciliation outbox and shared worker admission / failure journal.
CREATE TABLE integration_rates (
 provider TEXT PRIMARY KEY CHECK(provider IN ('github','logs','bots','sign','infra')),
 per_minute INTEGER NOT NULL CHECK(per_minute BETWEEN 1 AND 600),
 credit INTEGER NOT NULL CHECK(credit>=0 AND credit<=per_minute*60),
 updated_at INTEGER NOT NULL
);
CREATE TABLE integration_jobs (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, project_id INTEGER NOT NULL,
 host_id INTEGER NOT NULL, actor_id INTEGER NOT NULL REFERENCES users(id), actor_session INTEGER NOT NULL, target_hash TEXT NOT NULL CHECK(length(target_hash)=64),
 state TEXT NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','running','done','dead','cancelled')),
 attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 8),
 available_at INTEGER NOT NULL, claim TEXT, lease_until INTEGER, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id),
 CHECK((state='running' AND claim IS NOT NULL AND lease_until IS NOT NULL) OR (state!='running' AND claim IS NULL AND lease_until IS NULL))
);
CREATE UNIQUE INDEX integration_jobs_live ON integration_jobs(project_id,host_id) WHERE state IN ('pending','running');
CREATE INDEX integration_jobs_due ON integration_jobs(state,available_at,id);
CREATE TABLE integration_failures (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id),
 provider TEXT NOT NULL CHECK(provider IN ('github','logs','bots','sign','infra')),
 object_id INTEGER NOT NULL CHECK(object_id>0), failures INTEGER NOT NULL CHECK(failures>=0),
 updated_at INTEGER NOT NULL, UNIQUE(project_id,provider,object_id)
);
CREATE TABLE integration_journal (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id),
 provider TEXT NOT NULL CHECK(provider IN ('github','logs','bots','sign','infra')),
 object_id INTEGER NOT NULL CHECK(object_id>0),
 outcome TEXT NOT NULL CHECK(outcome IN ('retry','dead','uncertain','recovered','requeued','scheduled','cancelled')),
 code TEXT NOT NULL CHECK(length(code) BETWEEN 1 AND 40),
 failures INTEGER NOT NULL CHECK(failures>=0), created_at INTEGER NOT NULL
);
CREATE INDEX integration_journal_project ON integration_journal(project_id,id);
CREATE INDEX integration_journal_object ON integration_journal(project_id,provider,object_id,id);
