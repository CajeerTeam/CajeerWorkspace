-- Contract cards pin existing document revisions; no rewrite of historical PDF bytes.
CREATE UNIQUE INDEX contract_document_project ON documents(project_id,id);
CREATE TABLE contracts (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, project_id INTEGER NOT NULL,
 entity_id INTEGER NOT NULL, counterparty_id INTEGER NOT NULL,
 reference TEXT NOT NULL CHECK(length(reference) BETWEEN 1 AND 120),
 version INTEGER NOT NULL DEFAULT 1 CHECK(version>0), created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(organization_id,reference), UNIQUE(project_id,id),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id),
 FOREIGN KEY(organization_id,entity_id) REFERENCES legal_entities(organization_id,id),
 FOREIGN KEY(organization_id,counterparty_id) REFERENCES counterparties(organization_id,id)
);
CREATE TABLE contract_revisions (
 id INTEGER PRIMARY KEY, contract_id INTEGER NOT NULL, project_id INTEGER NOT NULL, revision INTEGER NOT NULL CHECK(revision>0),
 title TEXT NOT NULL CHECK(length(title) BETWEEN 1 AND 180), party_snapshot TEXT NOT NULL,
 responsible_id INTEGER NOT NULL REFERENCES users(id), seller_signer TEXT NOT NULL, seller_authority TEXT NOT NULL,
 buyer_signer TEXT NOT NULL, buyer_authority TEXT NOT NULL,
 currency TEXT NOT NULL CHECK(currency IN ('RUB','EUR','USD','PLN','GBP')),
 amount_minor INTEGER NOT NULL CHECK(typeof(amount_minor)='integer' AND amount_minor BETWEEN 0 AND 99999999999),
 signed_on TEXT NOT NULL, starts_on TEXT NOT NULL, ends_on TEXT NOT NULL, notice_on TEXT NOT NULL,
 renewal_terms TEXT NOT NULL, document_id INTEGER NOT NULL, document_revision INTEGER NOT NULL,
 document_hash TEXT NOT NULL CHECK(length(document_hash)=64), created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 activated_by INTEGER REFERENCES users(id), activated_at INTEGER, activation_note TEXT,
 UNIQUE(contract_id,revision), UNIQUE(project_id,contract_id,id),
 FOREIGN KEY(project_id,contract_id) REFERENCES contracts(project_id,id),
 FOREIGN KEY(project_id,document_id) REFERENCES documents(project_id,id),
 FOREIGN KEY(document_id,document_revision) REFERENCES document_versions(document_id,revision),
 CHECK(ends_on>=starts_on AND ends_on>=signed_on AND notice_on>=signed_on AND notice_on<=ends_on),
 CHECK((activated_by IS NULL AND activated_at IS NULL AND activation_note IS NULL) OR (activated_by IS NOT NULL AND activated_at IS NOT NULL AND length(activation_note)>0 AND activation_note IS NOT NULL))
);
CREATE TABLE contract_attachments (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, contract_id INTEGER NOT NULL, revision_id INTEGER NOT NULL,
 document_id INTEGER NOT NULL, document_revision INTEGER NOT NULL, document_hash TEXT NOT NULL CHECK(length(document_hash)=64),
 label TEXT NOT NULL CHECK(length(label) BETWEEN 1 AND 180),
 UNIQUE(revision_id,document_id,document_revision),
 FOREIGN KEY(project_id,contract_id,revision_id) REFERENCES contract_revisions(project_id,contract_id,id),
 FOREIGN KEY(project_id,document_id) REFERENCES documents(project_id,id),
 FOREIGN KEY(document_id,document_revision) REFERENCES document_versions(document_id,revision)
);
CREATE TABLE contract_obligations (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, contract_id INTEGER NOT NULL, revision_id INTEGER NOT NULL,
 title TEXT NOT NULL CHECK(length(title) BETWEEN 1 AND 180), due_on TEXT NOT NULL,
 invoice_id INTEGER UNIQUE, plan_id INTEGER UNIQUE,
 state TEXT NOT NULL DEFAULT 'open' CHECK(state IN ('open','completed','cancelled')),
 created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 decided_by INTEGER REFERENCES users(id), decided_at INTEGER, decision_note TEXT,
 FOREIGN KEY(project_id,contract_id,revision_id) REFERENCES contract_revisions(project_id,contract_id,id),
 FOREIGN KEY(project_id,invoice_id) REFERENCES invoices(project_id,id),
 FOREIGN KEY(project_id,plan_id) REFERENCES economics_plans(project_id,id),
 CHECK(invoice_id IS NULL OR plan_id IS NULL),
 CHECK((state='open' AND decided_by IS NULL AND decided_at IS NULL AND decision_note IS NULL) OR (state<>'open' AND decided_by IS NOT NULL AND decided_at IS NOT NULL AND decision_note IS NOT NULL AND length(decision_note)>0))
);
CREATE INDEX contract_project ON contracts(project_id,id);
CREATE INDEX contract_obligation_due ON contract_obligations(project_id,state,due_on);
