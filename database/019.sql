-- UK domestic standard-rated B2B services: reviewed seller profile, immutable VAT invoice and credit note.
CREATE TABLE uk_vat_profiles (
 entity_id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL,
 vat_number TEXT NOT NULL CHECK(length(vat_number)=9), proprietor TEXT NOT NULL CHECK(length(proprietor) BETWEEN 1 AND 180),
 contact TEXT NOT NULL CHECK(length(contact) BETWEEN 1 AND 180), service_address TEXT NOT NULL CHECK(length(service_address) BETWEEN 1 AND 500),
 effective_on TEXT NOT NULL, reviewed_until TEXT NOT NULL CHECK(reviewed_until>=effective_on),
 entity_version INTEGER NOT NULL CHECK(entity_version>0), version INTEGER NOT NULL CHECK(version>0),
 prepared_by INTEGER NOT NULL REFERENCES users(id), prepared_at INTEGER NOT NULL, approved_by INTEGER REFERENCES users(id), approved_at INTEGER,
 review_reference TEXT NOT NULL DEFAULT '', UNIQUE(organization_id,vat_number),
 FOREIGN KEY(organization_id,entity_id) REFERENCES legal_entities(organization_id,id),
 CHECK((approved_by IS NULL AND approved_at IS NULL AND review_reference='') OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND approved_by<>prepared_by AND length(review_reference)>0))
);
CREATE TABLE uk_vat_documents (
 invoice_id INTEGER PRIMARY KEY REFERENCES invoices(id), profile_version INTEGER NOT NULL CHECK(profile_version>0),
 rule_version TEXT NOT NULL CHECK(rule_version='UK-STD-2026-11-25'), tax_point TEXT NOT NULL,
 net_minor INTEGER NOT NULL CHECK(typeof(net_minor)='integer' AND net_minor BETWEEN 1 AND 99999999999),
 vat_minor INTEGER NOT NULL CHECK(typeof(vat_minor)='integer' AND vat_minor=net_minor/5), gross_minor INTEGER NOT NULL CHECK(typeof(gross_minor)='integer' AND gross_minor=net_minor+vat_minor AND gross_minor<=99999999999),
 buyer_address TEXT NOT NULL CHECK(length(buyer_address) BETWEEN 1 AND 500),
 profile_snapshot TEXT NOT NULL CHECK(length(profile_snapshot)>0),
 credit_on TEXT, credit_reason TEXT, credit_by INTEGER REFERENCES users(id),
 CHECK((credit_on IS NULL AND credit_reason IS NULL AND credit_by IS NULL) OR (credit_on IS NOT NULL AND credit_on>=tax_point AND credit_reason IS NOT NULL AND length(credit_reason)>0 AND credit_by IS NOT NULL))
);
CREATE INDEX uk_vat_tax_point ON uk_vat_documents(tax_point,invoice_id);
