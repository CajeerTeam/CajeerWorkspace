-- Capacity calendar and team attribution; existing project allocations and actual time are unchanged.
CREATE TABLE capacity_weeks (
 organization_id INTEGER NOT NULL REFERENCES organizations(id), user_id INTEGER NOT NULL REFERENCES users(id), week_on TEXT NOT NULL,
 mon INTEGER NOT NULL CHECK(typeof(mon)='integer' AND mon BETWEEN 0 AND 1440), tue INTEGER NOT NULL CHECK(typeof(tue)='integer' AND tue BETWEEN 0 AND 1440), wed INTEGER NOT NULL CHECK(typeof(wed)='integer' AND wed BETWEEN 0 AND 1440), thu INTEGER NOT NULL CHECK(typeof(thu)='integer' AND thu BETWEEN 0 AND 1440), fri INTEGER NOT NULL CHECK(typeof(fri)='integer' AND fri BETWEEN 0 AND 1440), sat INTEGER NOT NULL CHECK(typeof(sat)='integer' AND sat BETWEEN 0 AND 1440), sun INTEGER NOT NULL CHECK(typeof(sun)='integer' AND sun BETWEEN 0 AND 1440),
 reason TEXT NOT NULL CHECK(length(reason)<=500), actor_id INTEGER NOT NULL REFERENCES users(id), updated_at INTEGER NOT NULL,
 PRIMARY KEY(organization_id,user_id,week_on)
);
CREATE TABLE capacity_teams (
 organization_id INTEGER NOT NULL, project_id INTEGER NOT NULL, user_id INTEGER NOT NULL, week_on TEXT NOT NULL, team_id INTEGER NOT NULL,
 PRIMARY KEY(project_id,user_id,week_on),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id),
 FOREIGN KEY(organization_id,team_id) REFERENCES teams(organization_id,id) ON DELETE CASCADE,
 FOREIGN KEY(project_id,user_id,week_on) REFERENCES project_availability(project_id,user_id,week_on) ON DELETE CASCADE
);
CREATE INDEX capacity_teams_org_week ON capacity_teams(organization_id,week_on,team_id);
CREATE INDEX capacity_availability_user_week ON project_availability(user_id,week_on);
CREATE INDEX capacity_time_task ON time_entries(task_id);
