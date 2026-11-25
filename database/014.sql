-- Deployment requests are bound to an approved release and an exact infrastructure target.
CREATE UNIQUE INDEX infra_site_project_identity ON infra_sites(id,project_id);
CREATE TABLE deployments (
 id INTEGER PRIMARY KEY,
 project_id INTEGER NOT NULL,
 site_id INTEGER NOT NULL,
 release_id INTEGER NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id),
 actor_session INTEGER NOT NULL,
 provider TEXT NOT NULL CHECK(provider IN ('aapanel','servers')),
 target_snapshot TEXT NOT NULL,
 release_hash TEXT NOT NULL CHECK(length(release_hash)=64),
 request_key TEXT NOT NULL,
 execution_key TEXT NOT NULL UNIQUE CHECK(length(execution_key)=64),
 window_start INTEGER NOT NULL,
 window_end INTEGER NOT NULL CHECK(window_end>window_start AND window_end-window_start<=86400),
 state TEXT NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','running','succeeded','rolled_back','failed','cancelled','recovery_required')),
 created_at INTEGER NOT NULL,
 started_at INTEGER,
 finished_at INTEGER,
 attempt TEXT,
 result TEXT,
 UNIQUE(project_id,request_key),
 FOREIGN KEY(site_id,project_id) REFERENCES infra_sites(id,project_id),
 FOREIGN KEY(release_id,project_id) REFERENCES project_releases(id,project_id)
);
CREATE UNIQUE INDEX deployment_active_site ON deployments(site_id) WHERE state IN ('queued','running','recovery_required');
CREATE INDEX deployment_project ON deployments(project_id,id);
