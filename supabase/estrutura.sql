BEGIN;

CREATE ROLE portal_interno_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE portal_interno_app SET search_path = pg_catalog;

CREATE SCHEMA portal_interno;
REVOKE ALL ON SCHEMA portal_interno FROM PUBLIC, anon, authenticated, service_role;

CREATE TABLE portal_interno.contas (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    login text NOT NULL UNIQUE CHECK (login ~ '^[a-z0-9][a-z0-9._-]{2,31}$'),
    senha_hash text NOT NULL CHECK (senha_hash LIKE '$argon2id$%'),
    perfil text NOT NULL CHECK (perfil IN ('administrador', 'comum'))
);

ALTER TABLE portal_interno.contas ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE portal_interno.contas FROM PUBLIC, anon, authenticated, service_role;
GRANT USAGE ON SCHEMA portal_interno TO portal_interno_app;
GRANT SELECT, INSERT ON TABLE portal_interno.contas TO portal_interno_app;

-- O PHP autentica as contas. Somente a conexão dedicada pode ler e inserir.
CREATE POLICY portal_ler_contas ON portal_interno.contas
    FOR SELECT TO portal_interno_app USING (true);
CREATE POLICY portal_inserir_contas ON portal_interno.contas
    FOR INSERT TO portal_interno_app WITH CHECK (true);

COMMIT;
