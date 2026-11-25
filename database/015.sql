-- Invoices are issued documents; cash movements continue to use payment_requests.
CREATE TABLE legal_entities (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL REFERENCES organizations(id),
 name TEXT NOT NULL CHECK(length(name) BETWEEN 1 AND 180), registration TEXT NOT NULL CHECK(length(registration) BETWEEN 1 AND 180),
 address TEXT NOT NULL CHECK(length(address) BETWEEN 1 AND 500), bank_details TEXT NOT NULL DEFAULT '',
 version INTEGER NOT NULL DEFAULT 1 CHECK(version>0), UNIQUE(organization_id,id), UNIQUE(organization_id,registration)
);
CREATE TABLE invoices (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, project_id INTEGER NOT NULL, entity_id INTEGER NOT NULL, counterparty_id INTEGER NOT NULL,
 currency TEXT NOT NULL CHECK(currency IN ('RUB','EUR','USD','PLN','GBP')),
 due_on TEXT NOT NULL, terms TEXT NOT NULL CHECK(length(terms) BETWEEN 1 AND 1000),
 state TEXT NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','issued','void')),
 number_year INTEGER, number_seq INTEGER, issued_on TEXT, issuer_snapshot TEXT, client_snapshot TEXT,
 total_minor INTEGER NOT NULL DEFAULT 0 CHECK(typeof(total_minor)='integer' AND total_minor BETWEEN 0 AND 99999999999),
 created_by INTEGER NOT NULL REFERENCES users(id), issued_by INTEGER REFERENCES users(id), voided_by INTEGER REFERENCES users(id), voided_at INTEGER,
 created_at INTEGER NOT NULL, version INTEGER NOT NULL DEFAULT 1 CHECK(version>0),
 UNIQUE(entity_id,number_year,number_seq), UNIQUE(project_id,id), UNIQUE(entity_id,id),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id),
 FOREIGN KEY(organization_id,entity_id) REFERENCES legal_entities(organization_id,id),
 FOREIGN KEY(organization_id,counterparty_id) REFERENCES counterparties(organization_id,id),
 CHECK((state='draft' AND number_year IS NULL AND number_seq IS NULL AND issued_on IS NULL AND issued_by IS NULL AND issuer_snapshot IS NULL AND client_snapshot IS NULL) OR (state IN ('issued','void') AND number_year IS NOT NULL AND number_seq IS NOT NULL AND number_year BETWEEN 1 AND 9999 AND number_seq>0 AND issued_on IS NOT NULL AND issued_by IS NOT NULL AND issuer_snapshot IS NOT NULL AND client_snapshot IS NOT NULL AND total_minor>0)),
 CHECK((state='void' AND voided_by IS NOT NULL AND voided_at IS NOT NULL) OR (state<>'void' AND voided_by IS NULL AND voided_at IS NULL))
);
CREATE INDEX invoice_project ON invoices(project_id,id);
CREATE TABLE invoice_lines (
 id INTEGER PRIMARY KEY, invoice_id INTEGER NOT NULL REFERENCES invoices(id), description TEXT NOT NULL CHECK(length(description) BETWEEN 1 AND 500),
 quantity INTEGER NOT NULL CHECK(quantity BETWEEN 1 AND 100000), unit_minor INTEGER NOT NULL CHECK(typeof(unit_minor)='integer' AND unit_minor BETWEEN 1 AND 99999999999),
 CHECK(quantity*unit_minor<=99999999999)
);
CREATE INDEX invoice_line_invoice ON invoice_lines(invoice_id,id);
CREATE UNIQUE INDEX invoice_request_project_identity ON payment_requests(project_id,id);
CREATE TABLE invoice_movements (
 id INTEGER PRIMARY KEY, invoice_id INTEGER NOT NULL, project_id INTEGER NOT NULL, entity_id INTEGER NOT NULL, request_id INTEGER NOT NULL UNIQUE,
 kind TEXT NOT NULL CHECK(kind IN ('receipt','refund')), reference TEXT NOT NULL CHECK(length(reference) BETWEEN 1 AND 180), original_id INTEGER,
 UNIQUE(entity_id,kind,reference), UNIQUE(invoice_id,id),
 FOREIGN KEY(project_id,invoice_id) REFERENCES invoices(project_id,id), FOREIGN KEY(entity_id,invoice_id) REFERENCES invoices(entity_id,id), FOREIGN KEY(project_id,request_id) REFERENCES payment_requests(project_id,id),
 FOREIGN KEY(invoice_id,original_id) REFERENCES invoice_movements(invoice_id,id),
 CHECK((kind='receipt' AND original_id IS NULL) OR (kind='refund' AND original_id IS NOT NULL))
);
CREATE INDEX invoice_movements_invoice ON invoice_movements(invoice_id,id);
