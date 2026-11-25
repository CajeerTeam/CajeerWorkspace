-- Explicit publication boundary: no internal task/project text is copied automatically.
CREATE TABLE oss_projects (
    project_id INTEGER PRIMARY KEY REFERENCES projects(id),
    slug TEXT NOT NULL UNIQUE CHECK(length(slug) BETWEEN 3 AND 80),
    public_title TEXT NOT NULL CHECK(length(public_title) BETWEEN 1 AND 180),
    description TEXT NOT NULL CHECK(length(description) BETWEEN 1 AND 4000),
    upstream TEXT NOT NULL CHECK(length(upstream) BETWEEN 3 AND 140),
    published INTEGER NOT NULL DEFAULT 0 CHECK(published IN (0,1)),
    version INTEGER NOT NULL DEFAULT 1 CHECK(version>0)
);
CREATE TABLE oss_milestones (
    project_id INTEGER NOT NULL REFERENCES oss_projects(project_id),
    milestone_id INTEGER NOT NULL,
    public_title TEXT NOT NULL CHECK(length(public_title) BETWEEN 1 AND 180),
    description TEXT NOT NULL CHECK(length(description) BETWEEN 1 AND 4000),
    due_on TEXT,
    state TEXT NOT NULL CHECK(state IN ('open','closed')),
    version INTEGER NOT NULL DEFAULT 1 CHECK(version>0),
    PRIMARY KEY(project_id,milestone_id),
    FOREIGN KEY(project_id,milestone_id) REFERENCES milestones(project_id,id)
);
CREATE TABLE oss_contributors (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES oss_projects(project_id),
    display_name TEXT NOT NULL CHECK(length(display_name) BETWEEN 1 AND 120),
    token_hash TEXT NOT NULL UNIQUE CHECK(length(token_hash)=64),
    expires_at INTEGER NOT NULL,
    accepted_at INTEGER,
    access_until INTEGER,
    revoked_at INTEGER,
    created_by INTEGER NOT NULL REFERENCES users(id),
    UNIQUE(project_id,id),
    CHECK((accepted_at IS NULL AND access_until IS NULL) OR (accepted_at IS NOT NULL AND access_until IS NOT NULL AND access_until>accepted_at))
);
CREATE TABLE oss_task_links (
    task_id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES oss_projects(project_id),
    upstream_url TEXT NOT NULL CHECK(length(upstream_url) BETWEEN 1 AND 220),
    FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id),
    UNIQUE(project_id,task_id)
);
CREATE TABLE oss_contributions (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES oss_projects(project_id),
    contributor_id INTEGER NOT NULL,
    title TEXT NOT NULL CHECK(length(title) BETWEEN 1 AND 180),
    body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 4000),
    upstream_url TEXT NOT NULL CHECK(length(upstream_url) BETWEEN 1 AND 220),
    state TEXT NOT NULL DEFAULT 'new' CHECK(state IN ('new','reviewing','accepted','rejected')),
    task_id INTEGER,
    public_note TEXT NOT NULL DEFAULT '' CHECK(length(public_note)<=4000),
    version INTEGER NOT NULL DEFAULT 1 CHECK(version>0),
    created_at INTEGER NOT NULL,
    UNIQUE(project_id,id), UNIQUE(project_id,upstream_url),
    FOREIGN KEY(project_id,contributor_id) REFERENCES oss_contributors(project_id,id),
    FOREIGN KEY(project_id,task_id) REFERENCES oss_task_links(project_id,task_id),
    CHECK(state<>'accepted' OR (task_id IS NOT NULL AND length(public_note)>0))
);
CREATE INDEX oss_triage ON oss_contributions(project_id,state,id);
CREATE TABLE oss_comments (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL,
    contribution_id INTEGER NOT NULL,
    author_kind TEXT NOT NULL CHECK(author_kind IN ('contributor','maintainer')),
    body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 4000),
    created_at INTEGER NOT NULL,
    FOREIGN KEY(project_id,contribution_id) REFERENCES oss_contributions(project_id,id)
);
CREATE INDEX oss_comments_thread ON oss_comments(project_id,contribution_id,id);
CREATE TABLE oss_releases (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL,
    milestone_id INTEGER NOT NULL,
    tag TEXT NOT NULL CHECK(length(tag) BETWEEN 1 AND 100),
    title TEXT NOT NULL CHECK(length(title) BETWEEN 1 AND 180),
    notes TEXT NOT NULL CHECK(length(notes) BETWEEN 1 AND 4000),
    published_at INTEGER NOT NULL,
    published_by INTEGER NOT NULL REFERENCES users(id),
    UNIQUE(project_id,id), UNIQUE(project_id,tag), UNIQUE(project_id,milestone_id),
    FOREIGN KEY(project_id,milestone_id) REFERENCES oss_milestones(project_id,milestone_id)
);
CREATE TABLE oss_release_items (
    release_id INTEGER NOT NULL,
    project_id INTEGER NOT NULL,
    contribution_id INTEGER NOT NULL UNIQUE,
    public_note TEXT NOT NULL CHECK(length(public_note) BETWEEN 1 AND 4000),
    upstream_url TEXT NOT NULL CHECK(length(upstream_url) BETWEEN 1 AND 220),
    PRIMARY KEY(release_id,contribution_id),
    FOREIGN KEY(project_id,release_id) REFERENCES oss_releases(project_id,id),
    FOREIGN KEY(project_id,contribution_id) REFERENCES oss_contributions(project_id,id)
);
CREATE TABLE oss_requests (
    contributor_id INTEGER NOT NULL REFERENCES oss_contributors(id),
    request_key TEXT NOT NULL CHECK(length(request_key)=32),
    fingerprint TEXT NOT NULL CHECK(length(fingerprint)=64),
    object_id INTEGER NOT NULL,
    PRIMARY KEY(contributor_id,request_key)
);
