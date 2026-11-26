-- Observed successful authentication scenarios, never raw tokens or subjects.
CREATE TABLE auth_scenario_evidence (
 scenario TEXT NOT NULL CHECK(scenario IN ('staff_login','guest_invite','guest_login','emergency_login','staff_logout','guest_logout','staff_backchannel','guest_backchannel')),
 session_hash TEXT NOT NULL CHECK(length(session_hash)=64),
 config_hash TEXT NOT NULL CHECK(length(config_hash)=64),
 principal_id BIGINT NOT NULL CHECK(principal_id>0),
 organization_id BIGINT NOT NULL CHECK(organization_id>0),
 mode TEXT NOT NULL CHECK(mode IN ('mixed','required')),
 occurred_at BIGINT NOT NULL CHECK(occurred_at>0),
 PRIMARY KEY(scenario,session_hash)
);
CREATE INDEX auth_scenario_config_time ON auth_scenario_evidence(config_hash,occurred_at);
CREATE INDEX auth_scenario_time ON auth_scenario_evidence(occurred_at);
