-- Release acceptance records and frozen task/PR chain. Previous migrations remain immutable.
CREATE TABLE project_releases (
 id INTEGER PRIMARY KEY,
 project_id INTEGER NOT NULL REFERENCES projects(id),
 repository_binding INTEGER NOT NULL,
 repository_generation INTEGER NOT NULL CHECK(repository_generation>0),
 tag TEXT NOT NULL,
 commit_sha TEXT NOT NULL CHECK(length(commit_sha)=40),
 changelog TEXT NOT NULL,
 artifact_name TEXT NOT NULL,
 artifact_sha256 TEXT NOT NULL CHECK(length(artifact_sha256)=64),
 required_checks TEXT NOT NULL,
 responsible_id INTEGER NOT NULL REFERENCES users(id),
 creator_id INTEGER NOT NULL REFERENCES users(id),
 request_key TEXT NOT NULL,
 state TEXT NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','verified','confirmed','cancelled')),
 version INTEGER NOT NULL DEFAULT 1 CHECK(version>0),
 last_error TEXT NOT NULL DEFAULT '',
 evidence TEXT,
 evidence_hash TEXT,
 verified_at INTEGER,
 confirmed_at INTEGER,
 confirmed_by INTEGER REFERENCES users(id),
 created_at INTEGER NOT NULL,
 UNIQUE(project_id,request_key), UNIQUE(id,project_id),
 FOREIGN KEY(repository_binding,project_id) REFERENCES github_repositories(id,project_id),
 CHECK((evidence IS NULL AND evidence_hash IS NULL AND verified_at IS NULL) OR (evidence IS NOT NULL AND evidence_hash IS NOT NULL AND length(evidence_hash)=64 AND verified_at IS NOT NULL)),
 CHECK(state NOT IN ('verified','confirmed') OR evidence IS NOT NULL),
 CHECK((state='confirmed' AND confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL AND confirmed_by=responsible_id) OR (state!='confirmed' AND confirmed_at IS NULL AND confirmed_by IS NULL))
);
CREATE UNIQUE INDEX project_release_live_tag ON project_releases(project_id,tag) WHERE state!='cancelled';
CREATE INDEX project_releases_project ON project_releases(project_id,id);
CREATE TABLE project_release_tasks (
 release_id INTEGER NOT NULL,
 project_id INTEGER NOT NULL,
 task_id INTEGER NOT NULL,
 task_version INTEGER NOT NULL CHECK(task_version>0),
 link_version INTEGER NOT NULL CHECK(link_version>0),
 pr_number INTEGER NOT NULL CHECK(pr_number>0),
 PRIMARY KEY(release_id,task_id),
 FOREIGN KEY(release_id,project_id) REFERENCES project_releases(id,project_id),
 FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id)
);
