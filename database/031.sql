-- Project incident lifecycle. Signals contain only normalized, allowlisted evidence.
CREATE TABLE incident_policies (
 project_id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL,
 responsible_id INTEGER NOT NULL REFERENCES users(id),
 response_minutes INTEGER NOT NULL CHECK(response_minutes BETWEEN 1 AND 10080),
 active INTEGER NOT NULL CHECK(active IN (0,1)), updated_at INTEGER NOT NULL,
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id)
);
CREATE TABLE incidents (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, project_id INTEGER NOT NULL,
 source TEXT NOT NULL CHECK(source IN ('servers','aapanel','logs')),
 fingerprint TEXT NOT NULL CHECK(length(fingerprint)=64), condition_code TEXT NOT NULL,
 object_id INTEGER NOT NULL, environment TEXT CHECK(environment IN ('dev','staging','prod')),
 release_id INTEGER, responsible_id INTEGER NOT NULL REFERENCES users(id),
 state TEXT NOT NULL CHECK(state IN ('open','acknowledged','resolved')),
 severity TEXT NOT NULL CHECK(severity IN ('error','critical')),
 detected_at INTEGER NOT NULL, first_signal_at INTEGER NOT NULL, last_signal_at INTEGER NOT NULL,
 response_due INTEGER NOT NULL CHECK(response_due>detected_at), acknowledged_at INTEGER,
 resolved_at INTEGER, verification_at INTEGER, verification_mode TEXT CHECK(verification_mode IN ('source','manual')),
 version INTEGER NOT NULL DEFAULT 1 CHECK(version>0),
 UNIQUE(id,project_id), FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id),
 FOREIGN KEY(release_id,project_id) REFERENCES project_releases(id,project_id),
 CHECK(last_signal_at>=first_signal_at),
 CHECK((state='open' AND acknowledged_at IS NULL) OR (state!='open' AND acknowledged_at IS NOT NULL)),
 CHECK((state='resolved' AND resolved_at IS NOT NULL AND verification_at IS NOT NULL AND verification_mode IS NOT NULL) OR (state!='resolved' AND resolved_at IS NULL AND verification_at IS NULL AND verification_mode IS NULL))
);
CREATE UNIQUE INDEX incident_active_fingerprint ON incidents(project_id,fingerprint) WHERE state!='resolved';
CREATE INDEX incident_project_state ON incidents(project_id,state,response_due,id);
CREATE TABLE incident_signals (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, incident_id INTEGER NOT NULL,
 signal_key TEXT NOT NULL CHECK(length(signal_key)=64), observed_at INTEGER NOT NULL,
 evidence TEXT NOT NULL, UNIQUE(project_id,signal_key),
 FOREIGN KEY(incident_id,project_id) REFERENCES incidents(id,project_id)
);
CREATE TABLE incident_events (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, incident_id INTEGER NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id), event TEXT NOT NULL CHECK(event IN ('opened','signal','assigned','linked','acknowledged','resolved')),
 detail TEXT NOT NULL, created_at INTEGER NOT NULL,
 FOREIGN KEY(incident_id,project_id) REFERENCES incidents(id,project_id)
);
