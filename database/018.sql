-- Management accounting only. No cash or statutory ledger mutations.
CREATE TABLE economics_settings (
 project_id INTEGER PRIMARY KEY REFERENCES projects(id), mode TEXT NOT NULL CHECK(mode IN ('hourly','fixed','none')),
 version INTEGER NOT NULL CHECK(version>0), actor_id INTEGER NOT NULL REFERENCES users(id), changed_at INTEGER NOT NULL
);
CREATE TABLE economics_budgets (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id), month_on TEXT NOT NULL,
 currency TEXT NOT NULL CHECK(currency IN ('RUB','EUR','USD','PLN','GBP')),
 revenue_minor INTEGER NOT NULL CHECK(typeof(revenue_minor)='integer' AND revenue_minor BETWEEN 0 AND 99999999999),
 labor_minor INTEGER NOT NULL CHECK(typeof(labor_minor)='integer' AND labor_minor BETWEEN 0 AND 99999999999),
 direct_minor INTEGER NOT NULL CHECK(typeof(direct_minor)='integer' AND direct_minor BETWEEN 0 AND 99999999999),
 version INTEGER NOT NULL CHECK(version>0), actor_id INTEGER NOT NULL REFERENCES users(id), changed_at INTEGER NOT NULL,
 UNIQUE(project_id,month_on,currency)
);
CREATE TABLE economics_labor_rates (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id), user_id INTEGER NOT NULL REFERENCES users(id),
 effective_on TEXT NOT NULL, currency TEXT NOT NULL CHECK(currency IN ('RUB','EUR','USD','PLN','GBP')),
 hourly_minor INTEGER NOT NULL CHECK(typeof(hourly_minor)='integer' AND hourly_minor BETWEEN 0 AND 99999999999),
 actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL, UNIQUE(project_id,user_id,effective_on)
);
CREATE TABLE economics_cash_classes (
 cash_id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, category TEXT NOT NULL CHECK(category IN ('direct','labor','excluded')),
 reason TEXT NOT NULL CHECK(length(reason) BETWEEN 1 AND 500), version INTEGER NOT NULL CHECK(version>0),
 actor_id INTEGER NOT NULL REFERENCES users(id), changed_at INTEGER NOT NULL,
 FOREIGN KEY(project_id,cash_id) REFERENCES cash_entries(project_id,id)
);
CREATE TABLE economics_plans (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id), direction TEXT NOT NULL CHECK(direction IN ('income','expense')),
 currency TEXT NOT NULL CHECK(currency IN ('RUB','EUR','USD','PLN','GBP')),
 amount_minor INTEGER NOT NULL CHECK(typeof(amount_minor)='integer' AND amount_minor BETWEEN 1 AND 99999999999),
 due_on TEXT NOT NULL, title TEXT NOT NULL CHECK(length(title) BETWEEN 1 AND 180), cancelled INTEGER NOT NULL DEFAULT 0 CHECK(cancelled IN (0,1)),
 version INTEGER NOT NULL DEFAULT 1 CHECK(version>0), actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 UNIQUE(project_id,id)
);
CREATE TABLE economics_plan_cash (
 plan_id INTEGER NOT NULL, project_id INTEGER NOT NULL, cash_id INTEGER NOT NULL UNIQUE,
 actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 PRIMARY KEY(plan_id,cash_id), FOREIGN KEY(project_id,plan_id) REFERENCES economics_plans(project_id,id),
 FOREIGN KEY(project_id,cash_id) REFERENCES cash_entries(project_id,id)
);
CREATE INDEX economics_plans_due ON economics_plans(project_id,due_on,id);
CREATE INDEX economics_labor_lookup ON economics_labor_rates(project_id,user_id,effective_on);
