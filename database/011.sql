-- One explicitly authorized repository per project, no cross-tenant sharing.
CREATE TABLE github_repositories (
 id INTEGER PRIMARY KEY,
 project_id INTEGER NOT NULL UNIQUE REFERENCES projects(id),
 repository_id INTEGER NOT NULL UNIQUE CHECK(repository_id>0),
 installation_id INTEGER NOT NULL CHECK(installation_id>0),
 full_name TEXT NOT NULL,
 active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
 generation INTEGER NOT NULL DEFAULT 1 CHECK(generation>0),
 checked_at INTEGER,
 last_error TEXT NOT NULL DEFAULT '',
 UNIQUE(id,project_id)
);
CREATE INDEX github_installation ON github_repositories(installation_id,active);
CREATE TABLE github_task_links (
 task_id INTEGER PRIMARY KEY,
 project_id INTEGER NOT NULL,
 repository_binding INTEGER NOT NULL,
 kind TEXT NOT NULL CHECK(kind IN ('issue','pull')),
 number INTEGER NOT NULL CHECK(number>0),
 version INTEGER NOT NULL DEFAULT 1 CHECK(version>0),
 snapshot TEXT,
 synced_at INTEGER,
 last_error TEXT NOT NULL DEFAULT '',
 FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id),
 FOREIGN KEY(repository_binding,project_id) REFERENCES github_repositories(id,project_id),
 UNIQUE(repository_binding,task_id)
);
CREATE INDEX github_task_remote ON github_task_links(repository_binding,kind,number);
CREATE TABLE github_deliveries (
 delivery_id TEXT PRIMARY KEY,
 body_hash TEXT NOT NULL,
 event TEXT NOT NULL,
 installation_id INTEGER NOT NULL,
 received_at INTEGER NOT NULL
);
CREATE INDEX github_deliveries_received ON github_deliveries(received_at);
CREATE TABLE github_jobs (
 id INTEGER PRIMARY KEY,
 repository_binding INTEGER NOT NULL REFERENCES github_repositories(id),
 generation INTEGER NOT NULL,
 task_id INTEGER,
 link_version INTEGER,
 state TEXT NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','running','done','dead','cancelled')),
 attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts>=0),
 available_at INTEGER NOT NULL,
 lease_until INTEGER,
 claim TEXT,
 last_error TEXT NOT NULL DEFAULT '',
 created_at INTEGER NOT NULL,
 finished_at INTEGER,
 CHECK((task_id IS NULL AND link_version IS NULL) OR (task_id IS NOT NULL AND link_version IS NOT NULL)),
 CHECK((state='running' AND lease_until IS NOT NULL AND claim IS NOT NULL) OR (state!='running' AND lease_until IS NULL AND claim IS NULL))
);
CREATE INDEX github_jobs_ready ON github_jobs(state,available_at,id);
CREATE INDEX github_jobs_repository ON github_jobs(repository_binding,state,task_id);
