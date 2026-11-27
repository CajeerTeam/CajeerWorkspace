-- Immutable service package revisions and order-specific billing ledger.
CREATE TABLE freelance_packages (
 organization_id BIGINT NOT NULL REFERENCES organizations(id), number BIGINT NOT NULL CHECK(number>0),
 title TEXT NOT NULL, scope TEXT NOT NULL, currency TEXT NOT NULL CHECK(currency IN ('RUB','EUR','USD','PLN','GBP')),
 price_minor BIGINT NOT NULL CHECK(price_minor BETWEEN 1 AND 99999999999), advance_minor BIGINT NOT NULL CHECK(advance_minor BETWEEN 0 AND price_minor),
 monthly_minor BIGINT NOT NULL CHECK(monthly_minor BETWEEN 0 AND 99999999999), service_scope TEXT NOT NULL,
 payment_days BIGINT NOT NULL CHECK(payment_days BETWEEN 1 AND 90), actor_id BIGINT NOT NULL REFERENCES users(id), created_at BIGINT NOT NULL,
 PRIMARY KEY(organization_id,number), CHECK((monthly_minor=0 AND service_scope='') OR (monthly_minor>0 AND length(service_scope)>0))
);
CREATE TABLE freelance_agreements (
 offer_id BIGINT PRIMARY KEY, order_id BIGINT NOT NULL, project_id BIGINT NOT NULL, organization_id BIGINT NOT NULL, package_number BIGINT NOT NULL,
 first_service_on TEXT, stopped_at BIGINT, stopped_by BIGINT REFERENCES users(id),
 FOREIGN KEY(order_id,offer_id) REFERENCES client_offers(order_id,id), FOREIGN KEY(project_id,order_id) REFERENCES client_orders(project_id,id),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id), FOREIGN KEY(organization_id,package_number) REFERENCES freelance_packages(organization_id,number),
 UNIQUE(offer_id,project_id), CHECK((stopped_at IS NULL AND stopped_by IS NULL) OR (stopped_at IS NOT NULL AND stopped_by IS NOT NULL))
);
CREATE TABLE freelance_bills (
 offer_id BIGINT NOT NULL, project_id BIGINT NOT NULL, slot TEXT NOT NULL, invoice_id BIGINT NOT NULL UNIQUE,
 amount_minor BIGINT NOT NULL CHECK(amount_minor BETWEEN 1 AND 99999999999), created_at BIGINT NOT NULL,
 PRIMARY KEY(offer_id,slot), FOREIGN KEY(offer_id,project_id) REFERENCES freelance_agreements(offer_id,project_id), FOREIGN KEY(project_id,invoice_id) REFERENCES invoices(project_id,id)
);
CREATE INDEX freelance_agreements_order ON freelance_agreements(order_id,offer_id);
