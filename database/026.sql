-- Forum links contain only identifiers; content and ACLs remain at the provider.
CREATE TABLE forum_sources (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, project_id INTEGER NOT NULL,
 connection_key TEXT NOT NULL, config_hash TEXT NOT NULL CHECK(length(config_hash)=64), board_id TEXT NOT NULL CHECK(length(board_id)=36),
 active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)), created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(project_id,connection_key,board_id), UNIQUE(project_id,id), FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id)
);
CREATE TABLE forum_topics (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, source_id INTEGER NOT NULL,
 task_id INTEGER, release_id INTEGER, thread_id TEXT CHECK(thread_id IS NULL OR length(thread_id)=36),
 submission_id TEXT CHECK(submission_id IS NULL OR length(submission_id)=36),
 state TEXT NOT NULL CHECK(state IN ('sending','pending','uncertain','linked','rejected')),
 actor_id INTEGER NOT NULL REFERENCES users(id), forum_actor TEXT NOT NULL CHECK(length(forum_actor)=36), created_at INTEGER NOT NULL,
 CHECK((task_id IS NULL)<>(release_id IS NULL)), CHECK(state<>'linked' OR thread_id IS NOT NULL), CHECK(state<>'pending' OR submission_id IS NOT NULL),
 UNIQUE(source_id,task_id), UNIQUE(source_id,release_id), UNIQUE(source_id,thread_id), UNIQUE(project_id,source_id,id),
 FOREIGN KEY(project_id,source_id) REFERENCES forum_sources(project_id,id), FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id), FOREIGN KEY(release_id,project_id) REFERENCES project_releases(id,project_id)
);
CREATE TABLE forum_operations (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, source_id INTEGER NOT NULL, topic_id INTEGER NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id), forum_actor TEXT NOT NULL CHECK(length(forum_actor)=36), request_key TEXT NOT NULL CHECK(length(request_key)=32),
 operation TEXT NOT NULL CHECK(operation IN ('create','reply','lock','unlock','pin','unpin','report','watch','unwatch')),
 payload_hash TEXT NOT NULL CHECK(length(payload_hash)=64), state TEXT NOT NULL CHECK(state IN ('sending','accepted','pending','uncertain','rejected')),
 remote_id TEXT CHECK(remote_id IS NULL OR length(remote_id)=36), created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 UNIQUE(actor_id,request_key), FOREIGN KEY(project_id,source_id,topic_id) REFERENCES forum_topics(project_id,source_id,id)
);
CREATE INDEX forum_topics_project ON forum_topics(project_id,source_id,id);
CREATE INDEX forum_operations_topic ON forum_operations(topic_id,id);
