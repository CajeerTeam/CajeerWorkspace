-- Personal preferences and acknowledgements; object content is never copied.
CREATE TABLE reminder_subscriptions (
 id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, project_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
 kind TEXT NOT NULL CHECK(kind IN ('tasks','milestones','sprints','invoices','contract_notice','contract_end','obligations','incidents')),
 lead_days INTEGER NOT NULL CHECK(typeof(lead_days)='integer' AND lead_days BETWEEN 0 AND 90), assigned_only INTEGER NOT NULL CHECK(assigned_only IN (0,1)),
 updated_at INTEGER NOT NULL,
 UNIQUE(organization_id,project_id,user_id,kind),
 FOREIGN KEY(organization_id,project_id) REFERENCES projects(organization_id,id),
 FOREIGN KEY(organization_id,user_id) REFERENCES organization_members(organization_id,user_id) ON DELETE CASCADE,
 CHECK(assigned_only=0 OR kind IN ('tasks','contract_notice','contract_end','incidents'))
);
CREATE INDEX reminder_subscriptions_user ON reminder_subscriptions(organization_id,user_id,kind,project_id);
CREATE TABLE reminder_reads (
 id INTEGER PRIMARY KEY, subscription_id INTEGER NOT NULL REFERENCES reminder_subscriptions(id) ON DELETE CASCADE,
 object_id INTEGER NOT NULL, deadline_key TEXT NOT NULL CHECK(length(deadline_key) BETWEEN 1 AND 32),
 read_at INTEGER NOT NULL, UNIQUE(subscription_id,object_id,deadline_key)
);
