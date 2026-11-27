-- Review decisions are append-only and pinned to a repository generation and PR head.
CREATE TABLE lead_review_events (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, task_id INTEGER NOT NULL,
 repository_binding INTEGER NOT NULL, generation INTEGER NOT NULL, link_version INTEGER NOT NULL,
 pr_number INTEGER NOT NULL, head_sha TEXT NOT NULL CHECK(length(head_sha)=40),
 reviewer_id INTEGER NOT NULL REFERENCES users(id), actor_id INTEGER NOT NULL REFERENCES users(id),
 previous_id INTEGER UNIQUE,
 operation TEXT NOT NULL CHECK(operation IN ('assign','replace','approved','changes','cancelled')),
 state TEXT NOT NULL CHECK(state IN ('pending','approved','changes','cancelled')),
 note TEXT NOT NULL, created_at INTEGER NOT NULL,
 FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id),
 FOREIGN KEY(repository_binding,project_id) REFERENCES github_repositories(id,project_id),
 UNIQUE(id,project_id,task_id),
 FOREIGN KEY(previous_id,project_id,task_id) REFERENCES lead_review_events(id,project_id,task_id)
);
CREATE INDEX lead_review_task ON lead_review_events(project_id,task_id,id);
CREATE TABLE lead_decisions (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id), task_id INTEGER,
 previous_id INTEGER UNIQUE, actor_id INTEGER NOT NULL REFERENCES users(id),
 title TEXT NOT NULL, context TEXT NOT NULL, resolution TEXT NOT NULL,
 state TEXT NOT NULL CHECK(state IN ('open','accepted','rejected')), note TEXT NOT NULL, created_at INTEGER NOT NULL,
 FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id),
 UNIQUE(id,project_id), FOREIGN KEY(previous_id,project_id) REFERENCES lead_decisions(id,project_id)
);
CREATE INDEX lead_decision_project ON lead_decisions(project_id,id);
CREATE TABLE lead_waits (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, task_id INTEGER NOT NULL, prerequisite_id INTEGER NOT NULL,
 started_at INTEGER NOT NULL, observed_only INTEGER NOT NULL CHECK(observed_only IN (0,1)),
 ended_at INTEGER, end_reason TEXT, reason TEXT NOT NULL DEFAULT '', version INTEGER NOT NULL DEFAULT 1,
 CHECK(ended_at IS NULL OR ended_at>=started_at), CHECK(task_id<>prerequisite_id),
 FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id),
 FOREIGN KEY(project_id,prerequisite_id) REFERENCES tasks(project_id,id)
);
CREATE UNIQUE INDEX lead_wait_open ON lead_waits(project_id,task_id,prerequisite_id) WHERE ended_at IS NULL;
CREATE INDEX lead_wait_project ON lead_waits(project_id,id);
