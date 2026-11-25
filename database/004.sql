CREATE TABLE organizations (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE organization_members (
    organization_id INTEGER NOT NULL REFERENCES organizations(id),
    user_id INTEGER NOT NULL REFERENCES users(id),
    role TEXT NOT NULL CHECK (role IN ('owner','manager','developer','accountant','legal','viewer')),
    PRIMARY KEY(organization_id,user_id)
);
CREATE INDEX organization_members_user ON organization_members(user_id,organization_id);
ALTER TABLE projects ADD COLUMN organization_id INTEGER NOT NULL DEFAULT 1;
INSERT INTO organizations(id,name) VALUES(1,'Workspace');
INSERT INTO organization_members(organization_id,user_id,role) SELECT 1,id,role FROM users;
CREATE INDEX projects_org ON projects(organization_id,id);
CREATE TABLE teams (
    id INTEGER PRIMARY KEY,
    organization_id INTEGER NOT NULL REFERENCES organizations(id),
    name TEXT NOT NULL,
    UNIQUE(organization_id,name), UNIQUE(organization_id,id)
);
CREATE TABLE team_members (
    organization_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    PRIMARY KEY(team_id,user_id),
    FOREIGN KEY(organization_id,team_id) REFERENCES teams(organization_id,id),
    FOREIGN KEY(organization_id,user_id) REFERENCES organization_members(organization_id,user_id)
);
CREATE UNIQUE INDEX projects_org_id ON projects(organization_id,id);
CREATE TABLE project_members (
    organization_id INTEGER NOT NULL,
    project_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    PRIMARY KEY(project_id,user_id),
    FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id),
    FOREIGN KEY(organization_id,user_id) REFERENCES organization_members(organization_id,user_id)
);
CREATE INDEX project_members_user ON project_members(user_id,project_id);
-- Preserve the existing internal team's access to all legacy projects.
INSERT INTO project_members(organization_id,project_id,user_id) SELECT 1,p.id,u.id FROM projects p CROSS JOIN users u;
CREATE TABLE invitations (
    id INTEGER PRIMARY KEY,
    organization_id INTEGER NOT NULL REFERENCES organizations(id),
    email TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('owner','manager','developer','accountant','legal','viewer')),
    token_hash TEXT NOT NULL UNIQUE CHECK(length(token_hash)=64),
    expires_at INTEGER NOT NULL,
    created_by INTEGER NOT NULL REFERENCES users(id),
    accepted_at INTEGER,
    accepted_by INTEGER REFERENCES users(id),
    revoked_at INTEGER,
    CHECK ((accepted_at IS NULL AND accepted_by IS NULL) OR (accepted_at IS NOT NULL AND accepted_by IS NOT NULL))
);
CREATE INDEX invitations_org ON invitations(organization_id,id);
CREATE TABLE organization_connections (
    connection_key TEXT PRIMARY KEY,
    organization_id INTEGER NOT NULL REFERENCES organizations(id)
);
INSERT INTO organization_connections SELECT connection_key,1 FROM (SELECT connection_key FROM project_connections UNION SELECT connection_key FROM connection_results UNION SELECT connection_key FROM connection_checks) AS legacy_keys;
CREATE TRIGGER projects_org_insert BEFORE INSERT ON projects WHEN NOT EXISTS (SELECT 1 FROM organizations WHERE id=NEW.organization_id) BEGIN SELECT RAISE(ABORT,'unknown organization'); END;
CREATE TRIGGER projects_org_update BEFORE UPDATE OF organization_id ON projects WHEN NEW.organization_id<>OLD.organization_id BEGIN SELECT RAISE(ABORT,'organization is immutable'); END;
CREATE TRIGGER organizations_no_delete BEFORE DELETE ON organizations BEGIN SELECT RAISE(ABORT,'organization deletion is not supported'); END;
