-- Entity books are partitions of the existing paired journal, not a second cash ledger.
CREATE TABLE finance_book_projects (
 project_id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, entity_id INTEGER NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(project_id,entity_id), FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id),
 FOREIGN KEY(organization_id,entity_id) REFERENCES legal_entities(organization_id,id)
);
CREATE TABLE finance_book_allocations (
 entry_id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, entity_id INTEGER NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 FOREIGN KEY(organization_id,entry_id) REFERENCES accounting_entries(organization_id,id),
 FOREIGN KEY(organization_id,entity_id) REFERENCES legal_entities(organization_id,id)
);
CREATE TABLE finance_book_periods (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, entity_id INTEGER NOT NULL,
 starts_on TEXT NOT NULL, ends_on TEXT NOT NULL, closed_at INTEGER, closed_by INTEGER REFERENCES users(id),
 UNIQUE(entity_id,starts_on), FOREIGN KEY(organization_id,entity_id) REFERENCES legal_entities(organization_id,id),
 CHECK(starts_on<=ends_on), CHECK((closed_at IS NULL AND closed_by IS NULL) OR (closed_at IS NOT NULL AND closed_by IS NOT NULL))
);
CREATE TABLE supplier_bills (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, project_id INTEGER NOT NULL, entity_id INTEGER NOT NULL,
 supplier_id INTEGER NOT NULL, reference TEXT NOT NULL, booked_on TEXT NOT NULL, due_on TEXT NOT NULL,
 currency TEXT NOT NULL CHECK(currency IN ('RUB','EUR','USD','PLN','GBP')),
 amount_minor INTEGER NOT NULL CHECK(typeof(amount_minor)='integer' AND amount_minor BETWEEN 1 AND 99999999999),
 entry_id INTEGER NOT NULL UNIQUE, cancelled_entry_id INTEGER UNIQUE, actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(entity_id,supplier_id,reference), UNIQUE(id,project_id), CHECK(due_on>=booked_on),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id),
 FOREIGN KEY(project_id,entity_id) REFERENCES finance_book_projects(project_id,entity_id),
 FOREIGN KEY(organization_id,supplier_id) REFERENCES counterparties(organization_id,id),
 FOREIGN KEY(organization_id,entry_id) REFERENCES accounting_entries(organization_id,id),
 FOREIGN KEY(organization_id,cancelled_entry_id) REFERENCES accounting_entries(organization_id,id)
);
CREATE TABLE supplier_payments (
 request_id INTEGER PRIMARY KEY, bill_id INTEGER NOT NULL, project_id INTEGER NOT NULL,
 reference TEXT NOT NULL, kind TEXT NOT NULL CHECK(kind IN ('payment','refund')), original_request_id INTEGER, UNIQUE(bill_id,reference), UNIQUE(bill_id,request_id),
 CHECK((kind='payment' AND original_request_id IS NULL) OR (kind='refund' AND original_request_id IS NOT NULL)),
 FOREIGN KEY(bill_id,original_request_id) REFERENCES supplier_payments(bill_id,request_id),
 FOREIGN KEY(project_id,request_id) REFERENCES payment_requests(project_id,id),
 FOREIGN KEY(bill_id,project_id) REFERENCES supplier_bills(id,project_id)
);
CREATE INDEX supplier_bills_entity ON supplier_bills(entity_id,due_on,id);
CREATE INDEX supplier_payments_bill ON supplier_payments(bill_id,request_id);
