-- Service ownership, support intake, incident SLO snapshots and follow-up tasks.
CREATE UNIQUE INDEX ops_deployment_project_identity ON deployments(project_id,id);
CREATE TABLE ops_services (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, site_id INTEGER NOT NULL UNIQUE,
 name TEXT NOT NULL, recovery_minutes INTEGER NOT NULL CHECK(recovery_minutes BETWEEN 1 AND 10080),
 target_bp INTEGER NOT NULL CHECK(target_bp BETWEEN 1 AND 10000), created_at INTEGER NOT NULL,
 version INTEGER NOT NULL DEFAULT 1 CHECK(version>0), UNIQUE(project_id,id),
 FOREIGN KEY(site_id,project_id) REFERENCES infra_sites(id,project_id)
);
CREATE TABLE ops_service_incidents (
 project_id INTEGER NOT NULL, service_id INTEGER NOT NULL, incident_id INTEGER NOT NULL UNIQUE,
 deployment_id INTEGER UNIQUE, recovery_minutes INTEGER NOT NULL CHECK(recovery_minutes BETWEEN 1 AND 10080),
 linked_at INTEGER NOT NULL, PRIMARY KEY(service_id,incident_id), UNIQUE(project_id,service_id,incident_id),
 FOREIGN KEY(project_id,service_id) REFERENCES ops_services(project_id,id),
 FOREIGN KEY(incident_id,project_id) REFERENCES incidents(id,project_id),
 FOREIGN KEY(project_id,deployment_id) REFERENCES deployments(project_id,id)
);
CREATE TABLE ops_support (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, service_id INTEGER NOT NULL, incident_id INTEGER,
 title TEXT NOT NULL, description TEXT NOT NULL, requester TEXT NOT NULL,
 state TEXT NOT NULL DEFAULT 'open' CHECK(state IN ('open','closed')), resolution TEXT NOT NULL DEFAULT '',
 created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL, closed_at INTEGER,
 version INTEGER NOT NULL DEFAULT 1 CHECK(version>0), UNIQUE(project_id,id),
 FOREIGN KEY(project_id,service_id) REFERENCES ops_services(project_id,id),
 FOREIGN KEY(project_id,service_id,incident_id) REFERENCES ops_service_incidents(project_id,service_id,incident_id),
 CHECK((state='open' AND closed_at IS NULL AND resolution='') OR (state='closed' AND closed_at IS NOT NULL AND resolution<>''))
);
CREATE TABLE ops_followups (
 project_id INTEGER NOT NULL, service_id INTEGER NOT NULL, incident_id INTEGER NOT NULL, task_id INTEGER NOT NULL UNIQUE,
 created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 PRIMARY KEY(incident_id,task_id),
 FOREIGN KEY(project_id,service_id,incident_id) REFERENCES ops_service_incidents(project_id,service_id,incident_id),
 FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id)
);
CREATE INDEX ops_support_service ON ops_support(service_id,state,id);
CREATE INDEX ops_incidents_service ON ops_service_incidents(service_id,linked_at);
