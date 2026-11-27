-- Frozen task scope and append-only QA decisions for an exact release/deployment.
CREATE TABLE delivery_task_scope (
 release_id INTEGER NOT NULL, task_id INTEGER NOT NULL, project_id INTEGER NOT NULL,
 title TEXT NOT NULL, criteria TEXT NOT NULL, extra_prs TEXT NOT NULL,
 PRIMARY KEY(release_id,task_id),
 FOREIGN KEY(release_id,task_id) REFERENCES project_release_tasks(release_id,task_id),
 FOREIGN KEY(release_id,project_id) REFERENCES project_releases(id,project_id),
 FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id)
);
CREATE UNIQUE INDEX deployment_delivery_identity ON deployments(id,project_id,release_id);
CREATE TABLE delivery_reviews (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, release_id INTEGER NOT NULL, task_id INTEGER NOT NULL,
 deployment_id INTEGER NOT NULL, actor_id INTEGER NOT NULL REFERENCES users(id),
 request_key TEXT NOT NULL, fingerprint TEXT NOT NULL CHECK(length(fingerprint)=64),
 outcome TEXT NOT NULL CHECK(outcome IN ('passed','failed')), notes TEXT NOT NULL, created_at INTEGER NOT NULL,
 UNIQUE(actor_id,request_key),
 FOREIGN KEY(release_id,task_id) REFERENCES delivery_task_scope(release_id,task_id),
 FOREIGN KEY(deployment_id,project_id,release_id) REFERENCES deployments(id,project_id,release_id),
 FOREIGN KEY(release_id,project_id) REFERENCES project_releases(id,project_id)
);
CREATE INDEX delivery_review_target ON delivery_reviews(release_id,task_id,deployment_id,id);
