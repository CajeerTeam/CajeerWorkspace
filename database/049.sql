-- Contract-specific verified authority records and immutable activation evidence.
CREATE TABLE contract_authorities (
 id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, contract_id INTEGER NOT NULL,
 side TEXT NOT NULL CHECK(side IN ('seller','buyer')), signer TEXT NOT NULL, basis TEXT NOT NULL,
 party_digest TEXT NOT NULL CHECK(length(party_digest)=64),
 valid_from TEXT NOT NULL, valid_until TEXT NOT NULL, verified_by INTEGER NOT NULL REFERENCES users(id),
 verified_at INTEGER NOT NULL, revoked_by INTEGER REFERENCES users(id), revoked_at INTEGER, revoke_reason TEXT,
 UNIQUE(project_id,contract_id,id), FOREIGN KEY(project_id,contract_id) REFERENCES contracts(project_id,id),
 CHECK(valid_from<=valid_until), CHECK((revoked_at IS NULL AND revoked_by IS NULL AND revoke_reason IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revoke_reason IS NOT NULL))
);
CREATE TABLE contract_activation_evidence (
 revision_id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, contract_id INTEGER NOT NULL,
 seller_authority_id INTEGER NOT NULL, buyer_authority_id INTEGER NOT NULL, previous_revision_id INTEGER,
 terms_snapshot TEXT NOT NULL, authority_snapshot TEXT NOT NULL,
 FOREIGN KEY(project_id,contract_id,revision_id) REFERENCES contract_revisions(project_id,contract_id,id),
 FOREIGN KEY(project_id,contract_id,previous_revision_id) REFERENCES contract_revisions(project_id,contract_id,id),
 FOREIGN KEY(project_id,contract_id,seller_authority_id) REFERENCES contract_authorities(project_id,contract_id,id),
 FOREIGN KEY(project_id,contract_id,buyer_authority_id) REFERENCES contract_authorities(project_id,contract_id,id)
);
CREATE UNIQUE INDEX contract_obligation_identity ON contract_obligations(project_id,contract_id,revision_id,id);
CREATE TABLE contract_controls (
 task_id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, contract_id INTEGER NOT NULL, revision_id INTEGER NOT NULL,
 obligation_id INTEGER UNIQUE, kind TEXT NOT NULL CHECK(kind IN ('notice','expiry','obligation')),
 due_on TEXT NOT NULL, created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL,
 FOREIGN KEY(project_id,task_id) REFERENCES tasks(project_id,id),
 FOREIGN KEY(project_id,contract_id,revision_id) REFERENCES contract_revisions(project_id,contract_id,id),
 FOREIGN KEY(project_id,contract_id,revision_id,obligation_id) REFERENCES contract_obligations(project_id,contract_id,revision_id,id),
 CHECK((kind='obligation' AND obligation_id IS NOT NULL) OR (kind<>'obligation' AND obligation_id IS NULL))
);
CREATE UNIQUE INDEX contract_revision_control ON contract_controls(revision_id,kind) WHERE obligation_id IS NULL;
CREATE INDEX contract_authorities_card ON contract_authorities(contract_id,side,id);
