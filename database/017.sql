-- Bank observations are separate from the existing cash journal. No automatic posting on import.
CREATE TABLE bank_accounts (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, entity_id INTEGER NOT NULL,
 name TEXT NOT NULL CHECK(length(name) BETWEEN 1 AND 180), account_ref TEXT NOT NULL CHECK(length(account_ref) BETWEEN 1 AND 180),
 currency TEXT NOT NULL CHECK(currency IN ('RUB','EUR','USD','PLN','GBP')),
 UNIQUE(organization_id,id), UNIQUE(organization_id,account_ref,currency),
 FOREIGN KEY(organization_id,entity_id) REFERENCES legal_entities(organization_id,id)
);
CREATE TABLE bank_rates (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL REFERENCES organizations(id), rate_on TEXT NOT NULL,
 base_currency TEXT NOT NULL CHECK(base_currency IN ('RUB','EUR','USD','PLN','GBP')),
 quote_currency TEXT NOT NULL CHECK(quote_currency IN ('RUB','EUR','USD','PLN','GBP')),
 rate_scaled INTEGER NOT NULL CHECK(typeof(rate_scaled)='integer' AND rate_scaled BETWEEN 1 AND 9999999999),
 source TEXT NOT NULL CHECK(length(source) BETWEEN 1 AND 500), actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(organization_id,id), UNIQUE(organization_id,rate_on,base_currency,quote_currency), CHECK(base_currency<>quote_currency)
);
CREATE TABLE bank_statements (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
 starts_on TEXT NOT NULL, ends_on TEXT NOT NULL, opening_minor INTEGER NOT NULL CHECK(typeof(opening_minor)='integer' AND opening_minor BETWEEN -99999999999 AND 99999999999),
 closing_minor INTEGER NOT NULL CHECK(typeof(closing_minor)='integer' AND closing_minor BETWEEN -99999999999 AND 99999999999),
 digest TEXT NOT NULL CHECK(length(digest)=64), actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(organization_id,id), UNIQUE(account_id,id), UNIQUE(account_id,digest), UNIQUE(account_id,starts_on), CHECK(starts_on<=ends_on),
 FOREIGN KEY(organization_id,account_id) REFERENCES bank_accounts(organization_id,id)
);
CREATE TABLE bank_rows (
 id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL, statement_id INTEGER NOT NULL,
 external_ref TEXT NOT NULL CHECK(length(external_ref) BETWEEN 1 AND 180), booked_on TEXT NOT NULL,
 net_minor INTEGER NOT NULL CHECK(typeof(net_minor)='integer' AND net_minor BETWEEN -99999999999 AND 99999999999 AND net_minor<>0),
 fee_minor INTEGER NOT NULL CHECK(typeof(fee_minor)='integer' AND fee_minor BETWEEN 0 AND 99999999999),
 description TEXT NOT NULL CHECK(length(description) BETWEEN 1 AND 500), version INTEGER NOT NULL DEFAULT 1 CHECK(version>0),
 UNIQUE(account_id,external_ref), UNIQUE(statement_id,id), CHECK(net_minor+fee_minor BETWEEN -99999999999 AND 99999999999),
 FOREIGN KEY(account_id,statement_id) REFERENCES bank_statements(account_id,id)
);
CREATE TABLE bank_allocations (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, statement_id INTEGER NOT NULL, row_id INTEGER NOT NULL,
 component TEXT NOT NULL CHECK(component IN ('principal','fee')), project_id INTEGER NOT NULL, cash_id INTEGER NOT NULL UNIQUE,
 rate_id INTEGER, equivalent_minor INTEGER NOT NULL CHECK(typeof(equivalent_minor)='integer' AND equivalent_minor BETWEEN -99999999999 AND 99999999999 AND equivalent_minor<>0),
 variance_minor INTEGER NOT NULL CHECK(typeof(variance_minor)='integer' AND variance_minor BETWEEN -99999999999 AND 99999999999),
 explanation TEXT NOT NULL DEFAULT '', actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(row_id,component), FOREIGN KEY(organization_id,statement_id) REFERENCES bank_statements(organization_id,id),
 FOREIGN KEY(statement_id,row_id) REFERENCES bank_rows(statement_id,id), FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id),
 FOREIGN KEY(project_id,cash_id) REFERENCES cash_entries(project_id,id), FOREIGN KEY(organization_id,rate_id) REFERENCES bank_rates(organization_id,id),
 CHECK(variance_minor=0 OR (rate_id IS NOT NULL AND length(explanation)>0)), CHECK(component<>'fee' OR (rate_id IS NULL AND variance_minor=0 AND equivalent_minor<0))
);
CREATE TABLE bank_reconciliations (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, statement_id INTEGER NOT NULL,
 digest TEXT NOT NULL CHECK(length(digest)=64), report TEXT NOT NULL, ok INTEGER NOT NULL CHECK(ok IN (0,1)),
 actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 FOREIGN KEY(organization_id,statement_id) REFERENCES bank_statements(organization_id,id)
);
CREATE INDEX bank_statement_rows ON bank_rows(statement_id,id);
CREATE INDEX bank_reconcile_history ON bank_reconciliations(statement_id,id);
CREATE TABLE bank_match_history (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, statement_id INTEGER NOT NULL, row_id INTEGER NOT NULL,
 event TEXT NOT NULL CHECK(event IN ('matched','unmatched')), evidence TEXT NOT NULL, note TEXT NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 FOREIGN KEY(organization_id,statement_id) REFERENCES bank_statements(organization_id,id), FOREIGN KEY(statement_id,row_id) REFERENCES bank_rows(statement_id,id)
);
CREATE INDEX bank_match_history_row ON bank_match_history(row_id,id);
