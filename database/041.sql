-- One contribution can be scheduled independently for multiple maintenance branches.
CREATE TABLE oss_lines (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES oss_projects(project_id),
 label TEXT NOT NULL, branch TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
 version INTEGER NOT NULL DEFAULT 1 CHECK(version>0), UNIQUE(project_id,id), UNIQUE(project_id,branch)
);
CREATE TABLE oss_line_releases (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, line_id INTEGER NOT NULL,
 tag TEXT NOT NULL, commit_sha TEXT NOT NULL CHECK(length(commit_sha)=40), github_release_id INTEGER NOT NULL,
 title TEXT NOT NULL, notes TEXT NOT NULL, published_at INTEGER NOT NULL, published_by INTEGER NOT NULL REFERENCES users(id),
 UNIQUE(project_id,tag), UNIQUE(project_id,line_id,id),
 FOREIGN KEY(project_id,line_id) REFERENCES oss_lines(project_id,id)
);
CREATE TABLE oss_line_fixes (
 project_id INTEGER NOT NULL, line_id INTEGER NOT NULL, contribution_id INTEGER NOT NULL,
 state TEXT NOT NULL CHECK(state IN ('planned','deferred','rejected','ready','released')),
 pr_number INTEGER, reason TEXT NOT NULL DEFAULT '', version INTEGER NOT NULL DEFAULT 1 CHECK(version>0),
 release_id INTEGER, merge_sha TEXT, public_note TEXT, updated_at INTEGER NOT NULL,
 PRIMARY KEY(line_id,contribution_id),
 FOREIGN KEY(project_id,line_id) REFERENCES oss_lines(project_id,id),
 FOREIGN KEY(project_id,contribution_id) REFERENCES oss_contributions(project_id,id),
 FOREIGN KEY(project_id,line_id,release_id) REFERENCES oss_line_releases(project_id,line_id,id),
 CHECK(pr_number IS NULL OR pr_number>0),
 CHECK(state NOT IN ('ready','released') OR pr_number IS NOT NULL),
 CHECK((state='released' AND release_id IS NOT NULL AND merge_sha IS NOT NULL AND length(merge_sha)=40 AND public_note IS NOT NULL) OR (state<>'released' AND release_id IS NULL AND merge_sha IS NULL AND public_note IS NULL))
);
CREATE INDEX oss_line_queue ON oss_line_fixes(project_id,line_id,state,contribution_id);
CREATE INDEX oss_line_author ON oss_line_fixes(project_id,contribution_id,line_id);
CREATE INDEX oss_line_changelog ON oss_line_releases(project_id,id);
