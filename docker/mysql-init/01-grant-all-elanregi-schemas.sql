-- Registry2 Docker dev environment (issue #2116 spike).
--
-- The official mysql image's MYSQL_USER/MYSQL_DATABASE env vars only grant
-- that user privileges on the ONE database named at container creation
-- (elanregi_spice2, the dev DB) — not on the separate integration-test
-- schema (elanregi_dev_test_2, per .env.test.local) that
-- scripts/provision-schema.sh needs to DROP/CREATE from scratch.
--
-- Broadening the app-scoped user's grants to every elanregi_* schema (dev,
-- test, and any future one) mirrors how this project's actual dev DB user
-- is provisioned outside Docker — see docs/development/DATABASE.md — and
-- avoids handing out root or a separately-tracked root password that
-- changes on every container recreation (MYSQL_RANDOM_ROOT_PASSWORD).
--
-- Runs once, only against a fresh (empty) data directory — files in
-- /docker-entrypoint-initdb.d are ignored on a subsequent `docker compose
-- up` against an existing volume.
GRANT ALL PRIVILEGES ON `elanregi_%`.* TO 'elanregi_spice'@'%';
FLUSH PRIVILEGES;
