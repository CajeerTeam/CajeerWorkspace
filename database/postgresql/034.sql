-- An absent row inherits the installation policy. Only SSO may tighten it.
CREATE TABLE organization_auth_policies (
    organization_id BIGINT PRIMARY KEY REFERENCES organizations(id) ON DELETE CASCADE,
    mode TEXT NOT NULL CHECK (mode = 'required')
);
