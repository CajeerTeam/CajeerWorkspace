-- Infrastructure registry. Remote observations are independent of operator-approved assignments.
CREATE TABLE infra_hosts (
 id INTEGER PRIMARY KEY,
 organization_id INTEGER NOT NULL REFERENCES organizations(id),
 name TEXT NOT NULL,
 panel_key TEXT NOT NULL UNIQUE,
 panel_target TEXT NOT NULL UNIQUE CHECK(length(panel_target)=64),
 panel_hash TEXT NOT NULL CHECK(length(panel_hash)=64),
 panel_api TEXT NOT NULL CHECK(panel_api IN ('v2','legacy')),
 servers_key TEXT NOT NULL,
 servers_target TEXT NOT NULL CHECK(length(servers_target)=64),
 servers_hash TEXT NOT NULL CHECK(length(servers_hash)=64),
 workspace_uuid TEXT NOT NULL CHECK(length(workspace_uuid)=36),
 asset_uuid TEXT NOT NULL CHECK(length(asset_uuid)=36),
 version INTEGER NOT NULL DEFAULT 1 CHECK(version>0),
 sync_token TEXT,
 UNIQUE(organization_id,id), UNIQUE(servers_key,asset_uuid), UNIQUE(servers_target,asset_uuid)
);
CREATE TABLE infra_environments (
 id INTEGER PRIMARY KEY,
 organization_id INTEGER NOT NULL,
 project_id INTEGER NOT NULL,
 kind TEXT NOT NULL CHECK(kind IN ('dev','staging','prod')),
 name TEXT NOT NULL,
 UNIQUE(project_id,kind), UNIQUE(organization_id,id), UNIQUE(organization_id,project_id,id),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id)
);
CREATE TABLE infra_sites (
 id INTEGER PRIMARY KEY,
 organization_id INTEGER NOT NULL,
 project_id INTEGER NOT NULL,
 environment_id INTEGER NOT NULL,
 host_id INTEGER NOT NULL,
 remote_id INTEGER NOT NULL CHECK(remote_id>0),
 site_name TEXT NOT NULL,
 UNIQUE(host_id,remote_id), UNIQUE(host_id,site_name),
 FOREIGN KEY(organization_id,project_id,environment_id) REFERENCES infra_environments(organization_id,project_id,id),
 FOREIGN KEY(organization_id,host_id) REFERENCES infra_hosts(organization_id,id)
);
CREATE INDEX infra_sites_project ON infra_sites(project_id,environment_id);
CREATE TABLE infra_observations (
 host_id INTEGER NOT NULL REFERENCES infra_hosts(id) ON DELETE CASCADE,
 source TEXT NOT NULL CHECK(source IN ('aapanel','servers')),
 attempt_at INTEGER NOT NULL,
 success_at INTEGER,
 snapshot TEXT,
 error TEXT NOT NULL DEFAULT '',
 PRIMARY KEY(host_id,source),
 CHECK((success_at IS NULL AND snapshot IS NULL) OR (success_at IS NOT NULL AND snapshot IS NOT NULL))
);
CREATE TABLE infra_operation_owners (
 host_id INTEGER NOT NULL REFERENCES infra_hosts(id) ON DELETE CASCADE,
 operation TEXT NOT NULL CHECK(operation IN ('host.restart','host.backup','site.configure','certificate.renew','site.deploy')),
 provider TEXT NOT NULL CHECK(provider IN ('aapanel','servers')),
 responsible_id INTEGER NOT NULL REFERENCES users(id),
 PRIMARY KEY(host_id,operation)
);
