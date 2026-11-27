-- Capacity calendar and team attribution; existing project allocations and actual time are unchanged.
CREATE TABLE capacity_weeks (
 organization_id BIGINT NOT NULL REFERENCES organizations(id), user_id BIGINT NOT NULL REFERENCES users(id), week_on TEXT NOT NULL,
 mon BIGINT NOT NULL CHECK(mon BETWEEN 0 AND 1440), tue BIGINT NOT NULL CHECK(tue BETWEEN 0 AND 1440), wed BIGINT NOT NULL CHECK(wed BETWEEN 0 AND 1440), thu BIGINT NOT NULL CHECK(thu BETWEEN 0 AND 1440), fri BIGINT NOT NULL CHECK(fri BETWEEN 0 AND 1440), sat BIGINT NOT NULL CHECK(sat BETWEEN 0 AND 1440), sun BIGINT NOT NULL CHECK(sun BETWEEN 0 AND 1440),
 reason TEXT NOT NULL CHECK(length(reason)<=500), actor_id BIGINT NOT NULL REFERENCES users(id), updated_at BIGINT NOT NULL,
 PRIMARY KEY(organization_id,user_id,week_on)
);
CREATE TABLE capacity_teams (
 organization_id BIGINT NOT NULL, project_id BIGINT NOT NULL, user_id BIGINT NOT NULL, week_on TEXT NOT NULL, team_id BIGINT NOT NULL,
 PRIMARY KEY(project_id,user_id,week_on),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id),
 FOREIGN KEY(organization_id,team_id) REFERENCES teams(organization_id,id) ON DELETE CASCADE,
 FOREIGN KEY(project_id,user_id,week_on) REFERENCES project_availability(project_id,user_id,week_on) ON DELETE CASCADE
);
CREATE INDEX capacity_teams_org_week ON capacity_teams(organization_id,week_on,team_id);
CREATE INDEX capacity_availability_user_week ON project_availability(user_id,week_on);
CREATE INDEX capacity_time_task ON time_entries(task_id);
