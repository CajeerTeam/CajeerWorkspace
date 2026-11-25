-- Planning metadata is nullable only through empty legacy strings; no invented acceptance criteria.
ALTER TABLE tasks ADD COLUMN priority INTEGER NOT NULL DEFAULT 3 CHECK(typeof(priority)='integer' AND priority BETWEEN 1 AND 4);
ALTER TABLE tasks ADD COLUMN result_customer TEXT NOT NULL DEFAULT '' CHECK(length(result_customer)<=180);
ALTER TABLE tasks ADD COLUMN acceptance_criteria TEXT NOT NULL DEFAULT '' CHECK(length(acceptance_criteria)<=4000);
CREATE UNIQUE INDEX tasks_project_identity ON tasks(project_id,id);
CREATE INDEX tasks_planning_order ON tasks(project_id,priority,due_on,id);
CREATE TABLE milestones (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    title TEXT NOT NULL CHECK(length(title) BETWEEN 1 AND 180),
    due_on TEXT,
    state TEXT NOT NULL DEFAULT 'open' CHECK(state IN ('open','closed')),
    version INTEGER NOT NULL DEFAULT 1 CHECK(version>0),
    UNIQUE(project_id,id)
);
CREATE INDEX milestones_project ON milestones(project_id,state,due_on,id);
CREATE TABLE task_milestones (
    task_id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL,
    milestone_id INTEGER NOT NULL,
    FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id) ON DELETE CASCADE,
    FOREIGN KEY(project_id,milestone_id) REFERENCES milestones(project_id,id)
);
CREATE INDEX task_milestones_milestone ON task_milestones(project_id,milestone_id,task_id);
CREATE TABLE task_dependencies (
    project_id INTEGER NOT NULL,
    task_id INTEGER NOT NULL,
    prerequisite_id INTEGER NOT NULL,
    PRIMARY KEY(task_id,prerequisite_id),
    CHECK(task_id<>prerequisite_id),
    FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id) ON DELETE CASCADE,
    FOREIGN KEY(project_id,prerequisite_id) REFERENCES tasks(project_id,id) ON DELETE CASCADE
);
CREATE INDEX task_dependencies_reverse ON task_dependencies(project_id,prerequisite_id,task_id);
