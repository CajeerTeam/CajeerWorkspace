CREATE TABLE project_access (
    project_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('viewer','manager','developer','accountant','finance_reader','payment_creator','payment_approver','legal','legal_reader','integration_reader','operator','auditor')),
    PRIMARY KEY(project_id,user_id),
    FOREIGN KEY(project_id,user_id) REFERENCES project_members(project_id,user_id) ON DELETE CASCADE
);
CREATE TABLE payment_policies (
    project_id INTEGER PRIMARY KEY REFERENCES projects(id),
    dual_control INTEGER NOT NULL CHECK(dual_control IN (0,1))
);
CREATE UNIQUE INDEX cash_entries_project_id ON cash_entries(project_id,id);
CREATE TABLE payment_requests (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    direction TEXT NOT NULL CHECK(direction IN ('income','expense')),
    amount_minor INTEGER NOT NULL CHECK(typeof(amount_minor)='integer' AND abs(amount_minor) BETWEEN 1 AND 99999999999),
    currency TEXT NOT NULL CHECK(currency IN ('RUB','EUR','USD','PLN','GBP')),
    booked_on TEXT NOT NULL,
    memo TEXT NOT NULL,
    reversal_of INTEGER REFERENCES cash_entries(id),
    created_by INTEGER NOT NULL REFERENCES users(id),
    dual_control INTEGER NOT NULL CHECK(dual_control IN (0,1)),
    status TEXT NOT NULL CHECK(status IN ('pending','posted','rejected','cancelled')),
    decided_by INTEGER REFERENCES users(id),
    decided_at TEXT,
    cash_entry_id INTEGER UNIQUE REFERENCES cash_entries(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(project_id,reversal_of) REFERENCES cash_entries(project_id,id),
    FOREIGN KEY(project_id,cash_entry_id) REFERENCES cash_entries(project_id,id),
    CHECK((amount_minor>0 AND reversal_of IS NULL) OR (amount_minor<0 AND reversal_of IS NOT NULL)),
    CHECK((status='pending' AND dual_control=1 AND decided_by IS NULL AND decided_at IS NULL AND cash_entry_id IS NULL) OR (status='posted' AND decided_by IS NOT NULL AND decided_at IS NOT NULL AND cash_entry_id IS NOT NULL AND (dual_control=0 OR decided_by<>created_by)) OR (status IN ('rejected','cancelled') AND dual_control=1 AND decided_by IS NOT NULL AND decided_at IS NOT NULL AND cash_entry_id IS NULL))
);
CREATE INDEX payment_requests_project ON payment_requests(project_id,status,id);
CREATE UNIQUE INDEX payment_pending_reversal ON payment_requests(reversal_of) WHERE status='pending' AND reversal_of IS NOT NULL;
CREATE TABLE audit_events (
    id INTEGER PRIMARY KEY,
    organization_id INTEGER NOT NULL REFERENCES organizations(id),
    project_id INTEGER,
    actor_id INTEGER NOT NULL REFERENCES users(id),
    event TEXT NOT NULL,
    object_id INTEGER NOT NULL,
    detail TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id)
);
CREATE INDEX audit_events_scope ON audit_events(organization_id,project_id,id);
-- Import only events whose original project can be proven from existing records.
INSERT INTO audit_events(organization_id,project_id,actor_id,event,object_id,created_at)
SELECT p.organization_id,p.id,a.actor_id,a.action,a.object_id,a.created_at FROM action_log a JOIN projects p ON p.id=CASE
 WHEN a.action IN ('project.create','connection.attach','connection.detach') THEN a.object_id
 WHEN a.action IN ('task.create','task.state') THEN (SELECT project_id FROM tasks WHERE id=a.object_id)
 WHEN a.action IN ('cash.post','cash.reverse') THEN (SELECT project_id FROM cash_entries WHERE id=a.object_id)
 WHEN a.action='document.version' THEN (SELECT project_id FROM documents WHERE id=a.object_id)
 END ORDER BY a.id;
