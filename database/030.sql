-- Dedicated outbound release messages; immutable destination and payload, explicit confirmation.
CREATE TABLE bots_bindings (
 project_id INTEGER PRIMARY KEY,
 organization_id INTEGER NOT NULL,
 origin TEXT NOT NULL,
 community_id TEXT NOT NULL,
 account_id TEXT NOT NULL,
 provider TEXT NOT NULL CHECK(provider IN ('telegram','vk')),
 channel_id TEXT NOT NULL,
 credential_id TEXT NOT NULL,
 identity_hash TEXT NOT NULL CHECK(length(identity_hash)=64),
 active INTEGER NOT NULL CHECK(active IN (0,1)),
 updated_at INTEGER NOT NULL,
 UNIQUE(origin,credential_id), UNIQUE(origin,account_id,channel_id), UNIQUE(project_id,organization_id),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id)
);
CREATE TABLE bots_messages (
 id INTEGER PRIMARY KEY,
 project_id INTEGER NOT NULL,
 organization_id INTEGER NOT NULL,
 release_id INTEGER,
 release_hash TEXT,
 request_key TEXT NOT NULL CHECK(length(request_key)=32),
 remote_id TEXT NOT NULL UNIQUE CHECK(length(remote_id)=42),
 identity_hash TEXT NOT NULL CHECK(length(identity_hash)=64),
 target_revision TEXT NOT NULL,
 message TEXT NOT NULL CHECK(length(message) BETWEEN 1 AND 2000),
 message_hash TEXT NOT NULL CHECK(length(message_hash)=64),
 preview_hash TEXT NOT NULL CHECK(length(preview_hash)=64),
 creator_id INTEGER NOT NULL REFERENCES users(id),
 actor_id INTEGER REFERENCES users(id),
 actor_session INTEGER,
 state TEXT NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','queued','processing','pending','sending','retryable','failed','uncertain','sent','cancelled','closed')),
 command TEXT NOT NULL DEFAULT 'create' CHECK(command IN ('create','retry','cancel','check')),
 claim TEXT,
 lease_until INTEGER,
 available_at INTEGER NOT NULL,
 remote_attempts INTEGER NOT NULL DEFAULT 0 CHECK(remote_attempts BETWEEN 0 AND 8),
 provider_message_id TEXT,
 error_code TEXT NOT NULL DEFAULT '',
 created_at INTEGER NOT NULL,
 updated_at INTEGER NOT NULL,
 UNIQUE(project_id,request_key), UNIQUE(id,project_id),
 FOREIGN KEY(project_id,organization_id) REFERENCES bots_bindings(project_id,organization_id),
 FOREIGN KEY(release_id,project_id) REFERENCES project_releases(id,project_id),
 CHECK((release_id IS NULL AND release_hash IS NULL) OR (release_id IS NOT NULL AND length(release_hash)=64)),
 CHECK((state='processing' AND claim IS NOT NULL AND lease_until IS NOT NULL) OR (state!='processing' AND claim IS NULL AND lease_until IS NULL)),
 CHECK(state!='sent' OR provider_message_id IS NOT NULL)
);
CREATE UNIQUE INDEX bots_release_message ON bots_messages(project_id,release_id) WHERE release_id IS NOT NULL AND state!='cancelled';
CREATE INDEX bots_due ON bots_messages(state,available_at,id);
CREATE TABLE bots_events (
 id INTEGER PRIMARY KEY,
 project_id INTEGER NOT NULL,
 message_id INTEGER NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id),
 state TEXT NOT NULL,
 detail TEXT NOT NULL,
 created_at INTEGER NOT NULL,
 FOREIGN KEY(message_id,project_id) REFERENCES bots_messages(id,project_id)
);
CREATE INDEX bots_events_message ON bots_events(message_id,id);
