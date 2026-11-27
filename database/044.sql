-- Immutable baseline revisions and project-scoped change requests.
CREATE TABLE pm_scope_versions (
 project_id INTEGER NOT NULL REFERENCES projects(id), version INTEGER NOT NULL CHECK(version>0),
 scope TEXT NOT NULL CHECK(length(scope) BETWEEN 1 AND 4000), calendar_json TEXT NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 PRIMARY KEY(project_id,version)
);
CREATE TABLE pm_milestone_dependencies (
 project_id INTEGER NOT NULL, milestone_id INTEGER NOT NULL, prerequisite_id INTEGER NOT NULL,
 PRIMARY KEY(project_id,milestone_id,prerequisite_id), CHECK(milestone_id<>prerequisite_id),
 FOREIGN KEY(project_id,milestone_id) REFERENCES milestones(project_id,id),
 FOREIGN KEY(project_id,prerequisite_id) REFERENCES milestones(project_id,id)
);
CREATE TABLE pm_changes (
 project_id INTEGER NOT NULL REFERENCES projects(id), number INTEGER NOT NULL CHECK(number>0),
 baseline_version INTEGER NOT NULL, milestone_id INTEGER,
 order_id INTEGER, title TEXT NOT NULL CHECK(length(title) BETWEEN 1 AND 180),
 proposed_scope TEXT NOT NULL CHECK(length(proposed_scope) BETWEEN 1 AND 4000), reason TEXT NOT NULL,
 calendar_before TEXT NOT NULL, calendar_after TEXT NOT NULL,
 currency TEXT CHECK(currency IN ('RUB','EUR','USD','PLN','GBP')), amount_minor INTEGER NOT NULL CHECK(amount_minor BETWEEN 0 AND 99999999999), payment_due TEXT,
 status TEXT NOT NULL CHECK(status IN ('pending','accepted','rejected','applied','cancelled')),
 actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 decided_guest INTEGER, decided_at INTEGER, decision_note TEXT NOT NULL DEFAULT '',
 applied_by INTEGER REFERENCES users(id), applied_at INTEGER, invoice_id INTEGER,
 PRIMARY KEY(project_id,number),
 FOREIGN KEY(project_id,baseline_version) REFERENCES pm_scope_versions(project_id,version),
 FOREIGN KEY(project_id,milestone_id) REFERENCES milestones(project_id,id),
 FOREIGN KEY(project_id,order_id) REFERENCES client_orders(project_id,id),
 FOREIGN KEY(project_id,invoice_id) REFERENCES invoices(project_id,id), UNIQUE(invoice_id),
 FOREIGN KEY(project_id,decided_guest) REFERENCES guest_invites(project_id,id),
 CHECK((decided_guest IS NULL AND decided_at IS NULL) OR (decided_guest IS NOT NULL AND decided_at IS NOT NULL AND order_id IS NOT NULL)),
 CHECK((status='applied' AND applied_by IS NOT NULL AND applied_at IS NOT NULL) OR (status<>'applied' AND applied_by IS NULL AND applied_at IS NULL)),
 CHECK(status NOT IN ('accepted','rejected') OR decided_guest IS NOT NULL),
 CHECK(status<>'applied' OR order_id IS NULL OR decided_guest IS NOT NULL),
 CHECK(invoice_id IS NULL OR (status='applied' AND order_id IS NOT NULL AND amount_minor>0)),
 CHECK((order_id IS NULL AND amount_minor=0 AND currency IS NULL AND payment_due IS NULL) OR (order_id IS NOT NULL AND currency IS NOT NULL AND payment_due IS NOT NULL))
);
CREATE INDEX pm_changes_order ON pm_changes(order_id,number);
