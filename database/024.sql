-- An Engine installation is exclusively assigned to a single Workspace project.
CREATE TABLE engine_sites (
 id INTEGER PRIMARY KEY,
 organization_id INTEGER NOT NULL,
 project_id INTEGER NOT NULL,
 connection_key TEXT NOT NULL UNIQUE,
 target_hash TEXT NOT NULL UNIQUE CHECK(length(target_hash)=64),
 config_hash TEXT NOT NULL CHECK(length(config_hash)=64),
 name TEXT NOT NULL,
 active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
 created_by INTEGER NOT NULL REFERENCES users(id),
 created_at INTEGER NOT NULL,
 UNIQUE(project_id,id),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id)
);
CREATE TABLE engine_publications (
 id INTEGER PRIMARY KEY,
 project_id INTEGER NOT NULL,
 site_id INTEGER NOT NULL,
 content_type TEXT NOT NULL,
 entry_id TEXT NOT NULL,
 revision INTEGER NOT NULL CHECK(revision>0),
 snapshot TEXT NOT NULL,
 snapshot_hash TEXT NOT NULL CHECK(length(snapshot_hash)=64),
 state TEXT NOT NULL DEFAULT 'ready' CHECK(state IN ('ready','sending','published','conflict','uncertain','observed','cancelled')),
 created_by INTEGER NOT NULL REFERENCES users(id),
 created_at INTEGER NOT NULL,
 confirmed_by INTEGER REFERENCES users(id),
 attempted_at INTEGER,
 updated_at INTEGER NOT NULL,
 result_revision INTEGER,
 public_url TEXT,
 detail TEXT NOT NULL DEFAULT '',
 UNIQUE(site_id,content_type,entry_id,revision),
 FOREIGN KEY(project_id,site_id) REFERENCES engine_sites(project_id,id),
 CHECK(result_revision IS NULL OR result_revision=revision+1),
 CHECK((state IN ('published','observed') AND result_revision IS NOT NULL) OR (state NOT IN ('published','observed') AND result_revision IS NULL)),
 CHECK((confirmed_by IS NULL AND attempted_at IS NULL) OR (confirmed_by IS NOT NULL AND attempted_at IS NOT NULL))
);
CREATE INDEX engine_sites_project ON engine_sites(project_id,id);
CREATE INDEX engine_publications_project ON engine_publications(project_id,id);
