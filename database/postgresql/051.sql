-- A support message is bound to the exact ticket version. Incoming feedback is operator-recorded, not a provider receipt.
CREATE TABLE bots_support_links (
 message_id BIGINT PRIMARY KEY, project_id BIGINT NOT NULL, support_id BIGINT NOT NULL,
 support_version BIGINT NOT NULL CHECK(support_version>0),
 FOREIGN KEY(message_id,project_id) REFERENCES bots_messages(id,project_id),
 FOREIGN KEY(project_id,support_id) REFERENCES ops_support(project_id,id)
);
CREATE INDEX bots_support_ticket ON bots_support_links(project_id,support_id,message_id);
CREATE TABLE bots_feedback (
 support_id BIGINT PRIMARY KEY, project_id BIGINT NOT NULL, identity_hash TEXT NOT NULL CHECK(length(identity_hash)=64),
 external_id TEXT NOT NULL CHECK(length(external_id) BETWEEN 1 AND 24),
 fingerprint TEXT NOT NULL CHECK(length(fingerprint)=64), received_at BIGINT NOT NULL,
 recorded_by BIGINT NOT NULL REFERENCES users(id), recorded_at BIGINT NOT NULL,
 UNIQUE(project_id,identity_hash,external_id),
 FOREIGN KEY(project_id,support_id) REFERENCES ops_support(project_id,id),
 FOREIGN KEY(project_id) REFERENCES bots_bindings(project_id)
);
