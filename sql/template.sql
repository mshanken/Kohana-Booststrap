-- Run once before applying the SQL patches
-- Installs & configures extensions settings & schema required for all projects
-- Includes versioning schema/functions


BEGIN;

-- Required extensions/settings

SET statement_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET check_function_bodies = false;
SET client_min_messages = warning;


CREATE OR REPLACE PROCEDURAL LANGUAGE plperl;

CREATE EXTENSION IF NOT EXISTS plpgsql WITH SCHEMA pg_catalog;
COMMENT ON EXTENSION plpgsql IS 'PL/pgSQL procedural language';

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;
COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;
COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


SET search_path = public, pg_catalog;


-- Setup Versioning
-- All versioning "stuff" (tables, functions) is in "_v" schema.

-- All functions are defined as 'RETURNS SETOF INT4' to be able to make them to RETURN literaly nothing (0 rows).
-- >> RETURNS VOID<< IS similar, but it still outputs "empty line" in psql when calling.
CREATE SCHEMA _v;
COMMENT ON SCHEMA _v IS 'Schema for versioning data and functionality.';

CREATE TABLE _v.patches (
    patch_name  TEXT        PRIMARY KEY,
    applied_tsz TIMESTAMPTZ NOT NULL DEFAULT now(),
    applied_by  TEXT        NOT NULL,
    requires    TEXT[],
    conflicts   TEXT[]
);
COMMENT ON TABLE _v.patches              IS 'Contains information about what patches are currently applied on database.';
COMMENT ON COLUMN _v.patches.patch_name  IS 'Name of patch, has to be unique for every patch.';
COMMENT ON COLUMN _v.patches.applied_tsz IS 'When the patch was applied.';
COMMENT ON COLUMN _v.patches.applied_by  IS 'Who applied this patch (PostgreSQL username)';
COMMENT ON COLUMN _v.patches.requires    IS 'List of patches that are required for given patch.';
COMMENT ON COLUMN _v.patches.conflicts   IS 'List of patches that conflict with given patch.';

CREATE OR REPLACE FUNCTION _v.register_patch( IN in_patch_name TEXT, IN in_requirements TEXT[], in_conflicts TEXT[], OUT versioning INT4 ) RETURNS setof INT4 AS $$
DECLARE
    t_text   TEXT;
    t_text_a TEXT[];
    i INT4;
BEGIN
    -- Thanks to this we know only one patch will be applied at a time
    LOCK TABLE _v.patches IN EXCLUSIVE MODE;

    SELECT patch_name INTO t_text FROM _v.patches WHERE patch_name = in_patch_name;
    IF FOUND THEN
        RAISE EXCEPTION 'Patch % is already applied!', in_patch_name;
    END IF;

    t_text_a := ARRAY( SELECT patch_name FROM _v.patches WHERE patch_name = any( in_conflicts ) );
    IF array_upper( t_text_a, 1 ) IS NOT NULL THEN
        RAISE EXCEPTION 'Versioning patches conflict. Conflicting patche(s) installed: %.', array_to_string( t_text_a, ', ' );
    END IF;

    IF array_upper( in_requirements, 1 ) IS NOT NULL THEN
        t_text_a := '{}';
        FOR i IN array_lower( in_requirements, 1 ) .. array_upper( in_requirements, 1 ) LOOP
            SELECT patch_name INTO t_text FROM _v.patches WHERE patch_name = in_requirements[i];
            IF NOT FOUND THEN
                t_text_a := t_text_a || in_requirements[i];
            END IF;
        END LOOP;
        IF array_upper( t_text_a, 1 ) IS NOT NULL THEN
            RAISE EXCEPTION 'Missing prerequisite(s): %.', array_to_string( t_text_a, ', ' );
        END IF;
    END IF;

    INSERT INTO _v.patches (patch_name, applied_tsz, applied_by, requires, conflicts ) VALUES ( in_patch_name, now(), current_user, coalesce( in_requirements, '{}' ), coalesce( in_conflicts, '{}' ) );
    RETURN;
END;
$$ language plpgsql;
COMMENT ON FUNCTION _v.register_patch( TEXT, TEXT[], TEXT[] ) IS 'Function to register patches in database. Raises exception if there are conflicts, prerequisites are not installed or the migration has already been installed.';

CREATE OR REPLACE FUNCTION _v.register_patch( TEXT, TEXT[] ) RETURNS setof INT4 AS $$
    SELECT _v.register_patch( $1, $2, NULL );
$$ language sql;
COMMENT ON FUNCTION _v.register_patch( TEXT, TEXT[] ) IS 'Wrapper to allow registration of patches without conflicts.';
CREATE OR REPLACE FUNCTION _v.register_patch( TEXT ) RETURNS setof INT4 AS $$
    SELECT _v.register_patch( $1, NULL, NULL );
$$ language sql;
COMMENT ON FUNCTION _v.register_patch( TEXT ) IS 'Wrapper to allow registration of patches without requirements and conflicts.';

CREATE OR REPLACE FUNCTION _v.unregister_patch( IN in_patch_name TEXT, OUT versioning INT4 ) RETURNS setof INT4 AS $$
DECLARE
    i        INT4;
    t_text_a TEXT[];
BEGIN
    -- Thanks to this we know only one patch will be applied at a time
    LOCK TABLE _v.patches IN EXCLUSIVE MODE;

    t_text_a := ARRAY( SELECT patch_name FROM _v.patches WHERE in_patch_name = ANY( requires ) );
    IF array_upper( t_text_a, 1 ) IS NOT NULL THEN
        RAISE EXCEPTION 'Cannot uninstall %, as it is required by: %.', in_patch_name, array_to_string( t_text_a, ', ' );
    END IF;

    DELETE FROM _v.patches WHERE patch_name = in_patch_name;
    GET DIAGNOSTICS i = ROW_COUNT;
    IF i < 1 THEN
        RAISE EXCEPTION 'Patch % is not installed, so it can''t be uninstalled!', in_patch_name;
    END IF;

    RETURN;
END;
$$ language plpgsql;
COMMENT ON FUNCTION _v.unregister_patch( TEXT ) IS 'Function to unregister patches in database. Dies if the patch is not registered, or if unregistering it would break dependencies.';

COMMIT;

