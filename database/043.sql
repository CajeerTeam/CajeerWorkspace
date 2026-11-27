-- Preserve the latest legacy budget; earlier overwritten revisions cannot be reconstructed.
CREATE TABLE portfolio_budget_versions (
 budget_id INTEGER NOT NULL REFERENCES economics_budgets(id), version INTEGER NOT NULL CHECK(version>0),
 revenue_minor INTEGER NOT NULL CHECK(revenue_minor BETWEEN 0 AND 99999999999),
 labor_minor INTEGER NOT NULL CHECK(labor_minor BETWEEN 0 AND 99999999999),
 direct_minor INTEGER NOT NULL CHECK(direct_minor BETWEEN 0 AND 99999999999),
 actor_id INTEGER NOT NULL REFERENCES users(id), changed_at INTEGER NOT NULL,
 PRIMARY KEY(budget_id,version)
);
INSERT INTO portfolio_budget_versions SELECT id,version,revenue_minor,labor_minor,direct_minor,actor_id,changed_at FROM economics_budgets;
CREATE TABLE portfolio_decisions (
 project_id INTEGER NOT NULL REFERENCES projects(id), version INTEGER NOT NULL CHECK(version>0),
 state TEXT NOT NULL CHECK(state IN ('proposed','active','stopped')),
 action TEXT NOT NULL CHECK(action IN ('plan','start','continue','stop')),
 goal TEXT NOT NULL, start_conditions TEXT NOT NULL, stop_conditions TEXT NOT NULL,
 reason TEXT NOT NULL, actor_id INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 month_on TEXT NOT NULL, horizon TEXT NOT NULL, snapshot_json TEXT NOT NULL,
 PRIMARY KEY(project_id,version)
);
