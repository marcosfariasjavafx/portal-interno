BEGIN;

CREATE TABLE portal_interno.sessoes (
    chave text PRIMARY KEY CHECK (chave ~ '^[a-f0-9]{64}$'),
    conta_id uuid NOT NULL REFERENCES portal_interno.contas (id) ON DELETE CASCADE,
    expira_em timestamptz NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL '8 hours'),
    inativa_em timestamptz NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL '15 minutes'),
    cadastro_confirmado boolean NOT NULL DEFAULT false,
    CHECK (inativa_em <= expira_em)
);

CREATE INDEX sessoes_conta_idx ON portal_interno.sessoes (conta_id);
CREATE INDEX sessoes_inativa_idx ON portal_interno.sessoes (inativa_em);

ALTER TABLE portal_interno.sessoes ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE portal_interno.sessoes FROM PUBLIC, anon, authenticated, service_role;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE portal_interno.sessoes TO portal_interno_app;

CREATE POLICY portal_gerenciar_sessoes ON portal_interno.sessoes
    FOR ALL TO portal_interno_app USING (true) WITH CHECK (true);

COMMIT;
