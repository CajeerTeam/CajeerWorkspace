CREATE TABLE sprints (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id),
 title TEXT NOT NULL CHECK(length(title) BETWEEN 1 AND 180), starts_on TEXT NOT NULL, ends_on TEXT NOT NULL,
 state TEXT NOT NULL DEFAULT 'planned' CHECK(state IN ('planned','active','closed')),
 version INTEGER NOT NULL DEFAULT 1 CHECK(typeof(version)='integer' AND version>0), CHECK(starts_on<=ends_on), UNIQUE(project_id,id)
);
CREATE INDEX sprints_project ON sprints(project_id,starts_on,ends_on);
CREATE TABLE sprint_tasks (
 sprint_id INTEGER NOT NULL, project_id INTEGER NOT NULL, task_id INTEGER NOT NULL,
 estimate_minutes INTEGER NOT NULL CHECK(typeof(estimate_minutes)='integer' AND estimate_minutes BETWEEN 0 AND 60000),
 completed_on_close INTEGER CHECK(completed_on_close IS NULL OR (typeof(completed_on_close)='integer' AND completed_on_close IN (0,1))),
 PRIMARY KEY(sprint_id,task_id), FOREIGN KEY(project_id,sprint_id) REFERENCES sprints(project_id,id),
 FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id)
);
CREATE INDEX sprint_tasks_task ON sprint_tasks(project_id,task_id);
CREATE TABLE project_availability (
 project_id INTEGER NOT NULL REFERENCES projects(id), user_id INTEGER NOT NULL REFERENCES users(id),
 week_on TEXT NOT NULL, minutes INTEGER NOT NULL CHECK(typeof(minutes)='integer' AND minutes BETWEEN 0 AND 10080),
 version INTEGER NOT NULL CHECK(typeof(version)='integer' AND version>0), PRIMARY KEY(project_id,user_id,week_on)
);
CREATE TABLE project_rates (
 project_id INTEGER PRIMARY KEY REFERENCES projects(id), mode TEXT NOT NULL CHECK(mode IN ('hourly','fixed')),
 currency TEXT NOT NULL CHECK(currency IN ('RUB','EUR','USD','PLN','GBP')),
 amount_minor INTEGER NOT NULL CHECK(typeof(amount_minor)='integer' AND amount_minor BETWEEN 1 AND 99999999999), version INTEGER NOT NULL CHECK(typeof(version)='integer' AND version>0)
);
CREATE TABLE timesheets (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id), user_id INTEGER NOT NULL REFERENCES users(id),
 week_on TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','submitted','approved','rejected')),
 version INTEGER NOT NULL DEFAULT 1 CHECK(typeof(version)='integer' AND version>0),
 rate_mode TEXT CHECK(rate_mode IN ('hourly','fixed')), rate_currency TEXT CHECK(rate_currency IN ('RUB','EUR','USD','PLN','GBP')),
 rate_minor INTEGER CHECK(rate_minor IS NULL OR (typeof(rate_minor)='integer' AND rate_minor BETWEEN 1 AND 99999999999)), rate_version INTEGER CHECK(rate_version IS NULL OR (typeof(rate_version)='integer' AND rate_version>0)),
 total_minutes INTEGER CHECK(total_minutes IS NULL OR (typeof(total_minutes)='integer' AND total_minutes BETWEEN 1 AND 10080)), cost_minor INTEGER CHECK(cost_minor IS NULL OR (typeof(cost_minor)='integer' AND cost_minor>=0)),
 submitted_at INTEGER, decided_at INTEGER, decided_by INTEGER REFERENCES users(id), decision_note TEXT NOT NULL DEFAULT '',
 UNIQUE(project_id,user_id,week_on), UNIQUE(project_id,id),
 CHECK((rate_mode IS NULL AND rate_currency IS NULL AND rate_minor IS NULL AND rate_version IS NULL) OR (rate_mode IS NOT NULL AND rate_currency IS NOT NULL AND rate_minor IS NOT NULL AND rate_version IS NOT NULL)),
 CHECK((rate_mode IS NOT NULL AND rate_mode='hourly' AND cost_minor IS NOT NULL) OR ((rate_mode IS NULL OR rate_mode='fixed') AND cost_minor IS NULL)),
 CHECK((status='draft' AND submitted_at IS NULL AND total_minutes IS NULL AND rate_mode IS NULL) OR (status<>'draft' AND submitted_at IS NOT NULL AND total_minutes IS NOT NULL)),
 CHECK((status IN ('approved','rejected') AND decided_at IS NOT NULL AND decided_by IS NOT NULL AND decided_by<>user_id) OR (status IN ('draft','submitted') AND decided_at IS NULL AND decided_by IS NULL))
);
CREATE INDEX timesheets_project_week ON timesheets(project_id,week_on,status,id);
CREATE INDEX timesheets_user_week ON timesheets(user_id,week_on);
CREATE TABLE time_entries (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, sheet_id INTEGER NOT NULL, task_id INTEGER NOT NULL,
 worked_on TEXT NOT NULL, minutes INTEGER NOT NULL CHECK(typeof(minutes)='integer' AND minutes BETWEEN 1 AND 1440), note TEXT NOT NULL CHECK(length(note)<=500),
 UNIQUE(sheet_id,task_id,worked_on), FOREIGN KEY(project_id,sheet_id) REFERENCES timesheets(project_id,id),
 FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id)
);
CREATE INDEX time_entries_sheet_day ON time_entries(sheet_id,worked_on);
