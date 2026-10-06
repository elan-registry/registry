<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * Turns a stock UserSpice 6.1.4 schema into the ElanRegistry schema.
 *
 * This is the first migration in the chain and the schema-of-record for every
 * new environment. Provisioning is a two-step process:
 *
 *   1. `mysql < database/vendor/userspice-6.1.4-base.sql` — the vendored,
 *      unmodified stock UserSpice 6.1.4 structure (58 tables). Applied
 *      directly, not through Phinx, because it is third-party schema this
 *      project did not author.
 *   2. `composer migrate` — this migration, then the deltas that follow it.
 *
 * Scope — 13 new tables, 3 audit triggers, and updates on 55 of the 58 stock
 * tables (50 charset/collation conversions, 16 with structural drift beyond
 * collation). 
 *
 * DDL note: MySQL issues an implicit commit on every DDL statement, so none of
 * this can be wrapped in a transaction. A failure part-way through leaves the
 * schema partially converted; the fix is to drop the database and re-provision,
 * not to patch by hand.
 */
final class AddElanregistryBaseline extends AbstractMigration
{
    /**
     * Stock tables whose only difference from the ElanRegistry schema is charset and
     * collation.
     *
     * `CONVERT TO CHARACTER SET` rewrites the table default and every text
     * column in one statement, which is why several hundred per-column
     * differences collapse into 50 lines here.
     *
     * Same REVIEW bucket: `cars.chassis` is `utf8mb4_unicode_ci` while
     * `car_transfer_requests` (and `plg_sendinblue`) end up `utf8mb4_0900_ai_ci`
     * — a different mismatch, ElanRegistry-owned tables against each other, not
     * against stock. No current code joins/compares across that boundary, so
     * nothing breaks today, but the first `JOIN ... ON c.chassis =
     * ctr.submitted_chassis` would throw "Illegal mix of collations."
     */
    private const COLLATION_CONVERSIONS = [
        'utf8mb4_unicode_ci' => [
            'audit', 'crons', 'crons_logs', 'email', 'groups_menus', 'keys', 'logs',
            'menus', 'message_threads', 'messages', 'pages', 'permission_page_matches',
            'permissions', 'profiles', 'settings', 'updates', 'us_announcements',
            'us_fingerprint_assets', 'us_fingerprints', 'us_form_validation',
            'us_form_views', 'us_forms', 'us_ip_blacklist', 'us_ip_list',
            'us_ip_whitelist', 'us_management', 'us_menu_items', 'us_menus',
            'us_plugin_hooks', 'us_plugins', 'us_saas_levels', 'us_saas_orgs',
            'us_user_sessions', 'user_permission_matches', 'users', 'users_online',
            'users_session',
        ],
    ];

    public function up(): void
    {
        $this->refusePreExistingEnvironment();
        $this->createRegistryTables();
        $this->createCarAuditTriggers();
        // $this->convertSharedTableCollations(); Do not do for now - test to see if it is needed TODO
        $this->alignSharedTableStructure();
    }

    /**
     * Dev/prod predate Phinx and must never run this migration for real — they
     * get a manual `phinxlog` stamp instead (docs/development/DEPLOYMENT.md).
     * Without this check, that safety is purely an accident of statement order:
     * `createRegistryTables()` happens to run first and its `CREATE TABLE
     * car_models` would fail loudly on an environment that already has one. If
     * `up()` is ever reordered, a later step (e.g. the collation conversion)
     * could run first instead and silently narrow already-correct utf8mb4
     * columns toward utf8mb3 on a live database. Make the invariant explicit
     * rather than relying on which private method happens to run first.
     *
     * `cars` existing is ambiguous by itself: it's true both for a genuine
     * dev/prod environment (predates Phinx, needs a manual stamp) AND for a
     * fresh environment where this migration's own DDL failed part-way through
     * a prior run — `up()` cannot be transactional (see the class docblock), so
     * `createRegistryTables()`'s tables (including `cars`) persist even if a
     * later step in the same run then fails. Stamping phinxlog in the second
     * case would mark a partially-converted schema as fully baselined. There
     * is no single reliable signal that distinguishes the two across every
     * possible failure point in `up()`, so the message below gives the
     * operator a concrete way to tell them apart instead of guessing.
     */
    private function refusePreExistingEnvironment(): void
    {
        $preExisting = $this->fetchRow(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars'"
        );
        if ((int) ($preExisting['cnt'] ?? 0) > 0) {
            throw new RuntimeException(
                'AddElanregistryBaseline: `cars` already exists on this environment. This means one '
                . 'of two things: (1) this is a genuine dev/prod environment that predates Phinx — if '
                . 'so, stamp phinxlog manually instead of running this migration (see '
                . 'docs/development/DEPLOYMENT.md, "One-Time: Stamping the ElanRegistry Baseline '
                . 'Migration"); or (2) a PRIOR run of this exact migration failed part-way through — '
                . 'DDL cannot be rolled back, so `cars` and other tables it created can persist even '
                . 'though the run as a whole did not complete or get recorded in phinxlog. If you did '
                . 'not already have a converted UserSpice install before running `composer migrate` '
                . 'today, this is case (2): do NOT stamp phinxlog — drop the schema and re-run '
                . 'scripts/provision-schema.sh instead.'
            );
        }
    }

    /**
     * Rolling a baseline back means destroying every ElanRegistry table, which
     * is never the right answer to a problem. Fresh environments re-provision
     * from scratch (`scripts/provision-schema.sh`); existing environments never
     * run this migration at all — they are stamped into `phinxlog` by hand as
     * already-applied (see docs/development/DEPLOYMENT.md).
     *
     * `generate-baseline-diff.php` does emit a full reverse-to-stock `down()`
     * if one is ever genuinely needed. It is deliberately not committed here:
     * a thousand lines of destructive DDL that nothing should ever run is a
     * foot-gun, not a safety net.
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'The ElanRegistry baseline cannot be rolled back — doing so would drop every '
            . 'registry table. To rebuild a local or test environment, drop the schema and '
            . 're-run scripts/provision-schema.sh instead.'
        );
    }

    /**
     * The 13 tables ElanRegistry adds on top of stock UserSpice.
     *
     * DDL is verbatim `SHOW CREATE TABLE` output from dev with the
     * AUTO_INCREMENT counters removed, so a fresh environment starts every
     * sequence at 1.
     */
    private function createRegistryTables(): void
    {
        // The `_utf8mb3` introducers in `series_normalized` are deliberate. MySQL
        // stores a generated column's expression verbatim, and dev/prod have carried
        // these since the column was created under a utf8mb3 connection. Writing
        // `_utf8mb4` would behave identically — the literals are ASCII — but would
        // leave information_schema.GENERATION_EXPRESSION permanently different between
        // prod and freshly provisioned environments, which is the exact divergence this
        // migration exists to remove. `_utf8mb3` is deprecated in MySQL 8; when it is
        // removed, every environment needs the same fix at once, so that belongs in its
        // own migration rather than being smuggled in here.
        $this->execute(
            "CREATE TABLE `car_models` (
               `id` int unsigned NOT NULL AUTO_INCREMENT,
               `year_available_from` int NOT NULL,
               `year_available_to` int NOT NULL,
               `display_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `human_readable_short` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `series` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `variant` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `type_code` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `model_value` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `series_normalized` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
                   GENERATED ALWAYS AS ((case
                       when (`series` like _utf8mb3'% SE')   then trim(substring_index(`series`,_utf8mb3' SE',1))
                       when (`series` like _utf8mb3'% S/E')  then trim(substring_index(`series`,_utf8mb3' S/E',1))
                       when (`series` like _utf8mb3'%|Race') then trim(substring_index(`series`,_utf8mb3'|Race',1))
                       else `series`
                   end)) STORED,
               PRIMARY KEY (`id`),
               UNIQUE KEY `model_value` (`model_value`),
               UNIQUE KEY `unique_model_combo` (`series`,`variant`,`type_code`),
               KEY `idx_year_range` (`year_available_from`,`year_available_to`),
               KEY `idx_series` (`series`),
               KEY `idx_series_normalized` (`series_normalized`),
               KEY `idx_type_code` (`type_code`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute(
            "CREATE TABLE `car_transfer_requests` (
               `id` int NOT NULL AUTO_INCREMENT,
               `existing_car_id` int NOT NULL,
               `requested_by_user_id` int NOT NULL,
               `request_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
               `status` enum('pending','approved','denied','completed','expired') NOT NULL DEFAULT 'pending',
               `security_token` varchar(64) NOT NULL,
               `expires_at` timestamp NULL DEFAULT NULL,
               `admin_notes` text,
               `current_owner_response_date` timestamp NULL DEFAULT NULL,
               `completed_date` timestamp NULL DEFAULT NULL,
               `denial_reason` text,
               `submitted_model` varchar(30) NOT NULL,
               `submitted_series` varchar(12) NOT NULL,
               `submitted_variant` varchar(15) NOT NULL,
               `submitted_year` varchar(4) NOT NULL,
               `submitted_type` char(3) NOT NULL,
               `submitted_chassis` varchar(15) NOT NULL,
               `submitted_color` varchar(25) DEFAULT NULL,
               `submitted_engine` varchar(15) DEFAULT NULL,
               `submitted_purchasedate` date DEFAULT NULL,
               `submitted_solddate` date DEFAULT NULL,
               `submitted_comments` text,
               `submitted_image` text,
               `submitted_email` varchar(155) DEFAULT NULL,
               `submitted_fname` varchar(155) DEFAULT NULL,
               `submitted_lname` varchar(155) DEFAULT NULL,
               `submitted_city` varchar(100) DEFAULT NULL,
               `submitted_state` varchar(100) DEFAULT NULL,
               `submitted_country` varchar(100) DEFAULT NULL,
               `submitted_website` varchar(100) DEFAULT NULL,
               `created_by` int NOT NULL,
               `modified_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
               COMMENT='Self-service car ownership transfer requests - stores pending transfers when duplicate chassis detected during car entry'"
        );

        // REVIEW (out of scope here, tracked as #1547): `existing_car_id` has no FK
        // to `cars.id`, so deleting a car leaves its transfer-request rows orphaned.
        // Dev itself lacks this constraint, so this is schema fidelity, not a
        // generator gap — reproducing dev's actual state faithfully.

        // `fk_cars_user_id` is an index, not a constraint — it is the leftover
        // half of the foreign key that 20260719120000_drop_cars_user_id_fk
        // removed. Kept so a freshly provisioned schema matches dev exactly.
        $this->execute(
            "CREATE TABLE `cars` (
               `id` int unsigned NOT NULL AUTO_INCREMENT,
               `ctime` timestamp NULL DEFAULT NULL,
               `mtime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               `vericode` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `last_verified` timestamp NULL DEFAULT NULL,
               `model` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `series` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `variant` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `year` smallint unsigned DEFAULT NULL,
               `type` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `chassis` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `chassis_override` tinyint(1) NOT NULL DEFAULT '0',
               `color` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `engine` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `purchasedate` date DEFAULT NULL,
               `solddate` date DEFAULT NULL,
               `comments` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
               `image` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
               `user_id` int DEFAULT NULL,
               `email` varchar(155) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `fname` varchar(155) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `lname` varchar(155) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `join_date` datetime DEFAULT NULL,
               `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `state` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `lat` float DEFAULT NULL,
               `lon` float DEFAULT NULL,
               `website` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               PRIMARY KEY (`id`),
               KEY `idx_cars_chassis` (`chassis`),
               KEY `idx_cars_year` (`year`),
               KEY `idx_cars_series` (`series`),
               KEY `idx_cars_city` (`city`),
               KEY `idx_cars_state` (`state`),
               KEY `idx_cars_country` (`country`),
               KEY `fk_cars_user_id` (`user_id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute(
            "CREATE TABLE `cars_hist` (
               `id` int NOT NULL AUTO_INCREMENT,
               `operation` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `car_id` int unsigned NOT NULL,
               `ctime` timestamp NULL DEFAULT NULL,
               `mtime` timestamp NULL DEFAULT NULL,
               `model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `series` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `variant` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `year` smallint unsigned DEFAULT NULL,
               `type` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `chassis` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `chassis_override` tinyint(1) NOT NULL DEFAULT '0',
               `color` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `engine` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `purchasedate` date DEFAULT NULL,
               `solddate` date DEFAULT NULL,
               `comments` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
               `image` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
               `user_id` int DEFAULT NULL,
               `email` varchar(155) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `fname` varchar(155) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `lname` varchar(155) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `join_date` datetime DEFAULT NULL,
               `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `state` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `lat` float DEFAULT NULL,
               `lon` float DEFAULT NULL,
               `website` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               KEY `idx_cars_hist_car_id` (`car_id`),
               KEY `idx_cars_hist_timestamp` (`timestamp`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // REVIEW (out of scope): `country` is dead code. usersc/join.php
        // SELECTs from it into $countrylist and then never reads the variable.
        // Created here for schema fidelity, left unseeded. Removal is #2304.
        $this->execute(
            "CREATE TABLE `country` (
               `id` int NOT NULL AUTO_INCREMENT,
               `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
               PRIMARY KEY (`id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute(
            "CREATE TABLE `deleted_accounts_archive` (
               `id` int unsigned NOT NULL AUTO_INCREMENT,
               `original_user_id` int unsigned NOT NULL,
               `email` varchar(155) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `username` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `fname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `lname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `join_date` datetime DEFAULT NULL,
               `last_login` datetime DEFAULT NULL,
               `logins` int DEFAULT '0',
               `email_verified` tinyint(1) DEFAULT '0',
               `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `country` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `bio` text COLLATE utf8mb4_unicode_ci,
               `website` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
               `deleted_by` int unsigned NOT NULL,
               `deleted_at` datetime NOT NULL,
               `deletion_type` enum('unverified','verified') COLLATE utf8mb4_unicode_ci NOT NULL,
               `restored_at` datetime DEFAULT NULL,
               `restored_by` int unsigned DEFAULT NULL,
               PRIMARY KEY (`id`),
               KEY `idx_original_user_id` (`original_user_id`),
               KEY `idx_deleted_at` (`deleted_at`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute(
            "CREATE TABLE `elan_factory_info` (
               `id` int NOT NULL AUTO_INCREMENT,
               `year` varchar(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `month` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `batch` varchar(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `type` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `serial` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `suffix` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'After 1970',
               `engineletter` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `enginenumber` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `gearbox` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `color` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `builddate` date NOT NULL COMMENT 'BUILT / INVOICED / 1ST REG',
               `note` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               PRIMARY KEY (`id`),
               KEY `idx_serial` (`serial`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute(
            "CREATE TABLE `fix_script_runs` (
               `id` int NOT NULL AUTO_INCREMENT,
               `script_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
               `completed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               KEY `idx_script_name` (`script_name`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute(
            "CREATE TABLE `plg_sendinblue` (
               `id` int NOT NULL AUTO_INCREMENT,
               `from` varchar(255) DEFAULT NULL,
               `reply` varchar(255) DEFAULT NULL,
               `key` varchar(255) DEFAULT NULL,
               `override` tinyint(1) DEFAULT '0',
               `from_name` varchar(255) DEFAULT NULL,
               PRIMARY KEY (`id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
        );
    }

    /**
     * Audit triggers mirroring every `cars` row change into `cars_hist`.
     *
     * Created after all tables exist so the referenced `cars_hist` is present.
     * `@disable_triggers` on the UPDATE trigger is the project-wide escape
     * hatch for bulk maintenance scripts that must not flood the audit trail.
     *
     * MySQL has no CREATE TRIGGER IF NOT EXISTS, so drop-then-create is the
     * idempotent pattern (same as 20260710120000 and 20260711000000).
     * CREATE TRIGGER also needs SUPER or log_bin_trust_function_creators=1 when
     * binary logging is enabled — enableTrustFunctionCreators()/
     * resetTrustFunctionCreators() bracket the creation calls, matching the
     * precedent in 20260710120000.
     */
    private function createCarAuditTriggers(): void
    {
        $trustFunctionCreatorsSet = $this->enableTrustFunctionCreators();

        $this->execute("DROP TRIGGER IF EXISTS `cars_insert`");
        $this->execute(
            "CREATE TRIGGER `cars_insert` AFTER INSERT ON `cars` FOR EACH ROW BEGIN
                 INSERT INTO cars_hist(
                     operation, car_id, ctime, mtime, model, series, variant,
                     year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
                     image, user_id, email, fname, lname, join_date, city, state, country,
                     lat, lon, website
                 )
                 VALUES (
                     'INSERT', NEW.id, NEW.ctime, NEW.mtime, NEW.model,
                     NEW.series, NEW.variant, NEW.year, NEW.type, NEW.chassis, NEW.chassis_override,
                     NEW.color, NEW.engine, NEW.purchasedate, NEW.solddate, NEW.comments, NEW.image,
                     NEW.user_id, NEW.email, NEW.fname, NEW.lname, NEW.join_date, NEW.city,
                     NEW.state, NEW.country, NEW.lat, NEW.lon, NEW.website
                 );
             END"
        );

        // Note the deliberate asymmetry: every value is OLD.* except
        // chassis_override, which records NEW. This matches dev and prod
        // exactly — reproduced verbatim rather than "fixed" here, since
        // changing audit semantics is not a provisioning change.
        $this->execute("DROP TRIGGER IF EXISTS `cars_update`");
        $this->execute(
            "CREATE TRIGGER `cars_update` AFTER UPDATE ON `cars` FOR EACH ROW BEGIN
                 IF @disable_triggers IS NULL THEN
                     INSERT INTO cars_hist(
                         operation, car_id, ctime, mtime, model, series, variant,
                         year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
                         image, user_id, email, fname, lname, join_date, city, state, country,
                         lat, lon, website
                     )
                     VALUES (
                         'UPDATE', OLD.id, OLD.ctime, OLD.mtime, OLD.model,
                         OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, NEW.chassis_override,
                         OLD.color, OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
                         OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
                         OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
                     );
                 END IF;
             END"
        );

        $this->execute("DROP TRIGGER IF EXISTS `cars_delete`");
        $this->execute(
            "CREATE TRIGGER `cars_delete` AFTER DELETE ON `cars` FOR EACH ROW BEGIN
                 INSERT INTO cars_hist(
                     operation, car_id, ctime, mtime, model, series, variant,
                     year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
                     image, user_id, email, fname, lname, join_date, city, state, country,
                     lat, lon, website
                 )
                 VALUES (
                     'DELETE', OLD.id, OLD.ctime, OLD.mtime, OLD.model,
                     OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, OLD.chassis_override,
                     OLD.color, OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
                     OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
                     OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
                 );
             END"
        );

        $this->resetTrustFunctionCreators($trustFunctionCreatorsSet);
    }

    /**
     * CREATE TRIGGER requires either SUPER privilege or log_bin_trust_function_creators=1
     * when binary logging is enabled. Attempt to set it globally; if the migration user
     * lacks SUPER/SYSTEM_VARIABLES_ADMIN, continue anyway — the variable may already be
     * set globally (common on managed hosting panels), otherwise the DBA must set it in
     * MySQL config (log_bin_trust_function_creators=1 in my.cnf).
     *
     * @return bool True if this call set the variable (and so should reset it afterward).
     */
    private function enableTrustFunctionCreators(): bool
    {
        try {
            $this->execute("SET GLOBAL log_bin_trust_function_creators = 1");
            return true;
        } catch (\RuntimeException $e) {
            if (isset($this->output)) {
                $this->output->writeln(
                    '<comment>Warning: Could not SET GLOBAL log_bin_trust_function_creators=1 '
                    . '— continuing. If CREATE TRIGGER fails below, set this variable in my.cnf.</comment>'
                );
            }
            return false;
        }
    }

    /**
     * Resets log_bin_trust_function_creators if this migration run set it — limits the
     * window of elevated trust to only the trigger creation steps.
     */
    private function resetTrustFunctionCreators(bool $wasSet): void
    {
        if (!$wasSet) {
            return;
        }
        try {
            $this->execute("SET GLOBAL log_bin_trust_function_creators = 0");
        } catch (\Exception $e) {
            if (isset($this->output)) {
                $this->output->writeln(
                    '<comment>Warning: Could not reset log_bin_trust_function_creators=0: '
                    . $e->getMessage()
                    . ' — set it manually in MySQL or my.cnf.</comment>'
                );
            }
        }
    }

    /**
     * Bring the stock tables onto the collation we use.
     *
     * Table names come from a private const of hardcoded strings and the
     * collation is the array key, also hardcoded — nothing here is derived from
     * input. Phinx 0.16 uses PDO::query() rather than prepare(), so bind
     * parameters are unavailable in migrations (see 20260713120000).
     *
     * `CONVERT TO CHARACTER SET` rewrites every row, which re-validates every
     * datetime column against the session's sql_mode. Stock UserSpice ships
     * its seeded `id=1 admin` user with `users.created = '0000-00-00
     * 00:00:00'` (a NOT NULL column with no default) — harmless under a
     * permissive sql_mode, but a hard failure under the NO_ZERO_DATE +
     * STRICT_TRANS_TABLES combination MySQL 8 enables by default. Relax just
     * the zero-date-related modes for the duration of each ALTER, restore
     * immediately after, so strict mode still applies everywhere else in this
     * migration (and to the application at runtime).
     */
    // @phpstan-ignore-next-line method.unused (call site TODO'd out — see up())
    private function convertSharedTableCollations(): void
    {
        foreach (self::COLLATION_CONVERSIONS as $collation => $tables) {
            $charset = explode('_', $collation)[0];
            foreach ($tables as $table) {
                $this->withRelaxedZeroDateSqlMode(function () use ($table, $charset, $collation) {
                    $this->execute(
                        "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET {$charset} COLLATE {$collation}"
                    );
                });
            }
        }
    }

    /**
     * Brackets a single statement with a session sql_mode that has
     * NO_ZERO_DATE, NO_ZERO_IN_DATE, and STRICT_TRANS_TABLES stripped, then
     * restores the original session sql_mode — even if the statement throws.
     *
     * Session-scoped (not GLOBAL): no other connection is affected, and
     * nothing needs resetting if the process dies mid-migration.
     */
    private function withRelaxedZeroDateSqlMode(callable $statement): void
    {
        $original = $this->fetchRow('SELECT @@SESSION.sql_mode AS mode')['mode'] ?? '';

        $relaxed = implode(',', array_filter(
            explode(',', $original),
            static fn (string $mode): bool => !in_array(
                $mode,
                ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'STRICT_TRANS_TABLES'],
                true
            )
        ));

        $this->execute("SET SESSION sql_mode = '{$relaxed}'");
        try {
            $statement();
        } finally {
            $this->execute("SET SESSION sql_mode = '{$original}'");
        }
    }

    /**
     * Structural drift on stock tables: added columns, widened types, changed
     * nullability, and two engine changes.
     *
     * Runs after the collation pass so the explicit per-column
     * CHARACTER SET/COLLATE clauses below land on already-converted tables.
     */
    private function alignSharedTableStructure(): void
    {
        // ── profiles: owner location and website fields ──────────────────────
        $this->execute("ALTER TABLE `profiles` ADD COLUMN `city` varchar(100)  NOT NULL AFTER `bio`");
        $this->execute("ALTER TABLE `profiles` ADD COLUMN `state` varchar(100) NOT NULL AFTER `city`");
        $this->execute("ALTER TABLE `profiles` ADD COLUMN `country` varchar(100) NOT NULL AFTER `state`");
        $this->execute("ALTER TABLE `profiles` ADD COLUMN `lat` float DEFAULT NULL AFTER `country`");
        $this->execute("ALTER TABLE `profiles` ADD COLUMN `lon` float DEFAULT NULL AFTER `lat`");
        $this->execute("ALTER TABLE `profiles` ADD COLUMN `website` varchar(100) DEFAULT NULL AFTER `lon`");

        // ── settings ─────────────────────────────────────────────────────────
        //
        // Only a subset of the `elan_*` columns are actually read by app code
        // today: `elan_image_dir`, `elan_image_max`, `elan_image_upload_max_size`,
        // `elan_image_display_max_size`, `elan_image_thumbnail_sizes`,
        // `elan_admin_emails`, `elan_feedback_email`. All 12 `elan_*_cdn` columns,
        // `elan_backup_age`, `us_css1..3`, and the extra `recap_v3_*` columns have
        // zero references in `app/`/`usersc/` (consistent with ADR-015's move to
        // self-hosted assets) — reproduced here for schema fidelity to dev, not
        // because they're in active use. REVIEW (out of scope here): worth a
        // follow-up cleanup issue to drop the genuinely dead ones.
        $this->execute("ALTER TABLE `settings` ADD COLUMN `us_css1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL AFTER `css_sample`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `us_css2` varchar(255)  NOT NULL AFTER `us_css1`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `us_css3` varchar(255)  NOT NULL AFTER `us_css2`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_backup_age` int NOT NULL AFTER `container_open_class`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_image_dir` mediumtext  NOT NULL AFTER `elan_backup_age`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_image_max` int NOT NULL AFTER `elan_image_dir`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_jquery_cdn` mediumtext  NOT NULL AFTER `elan_image_max`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_bootstrap_js_cdn` mediumtext  NOT NULL AFTER `elan_jquery_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_bootstrap_css_cdn` mediumtext  NOT NULL AFTER `elan_bootstrap_js_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_popper_cdn` mediumtext  NOT NULL AFTER `elan_bootstrap_css_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_fontawesome_cdn` mediumtext  NOT NULL AFTER `elan_popper_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_bootswatch_cdn` mediumtext  NOT NULL AFTER `elan_fontawesome_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_datatables_js_cdn` mediumtext  NOT NULL AFTER `elan_bootswatch_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_datatables_css_cdn` mediumtext  NOT NULL AFTER `elan_datatables_js_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_datepicker_js_cdn` mediumtext  NOT NULL AFTER `elan_datatables_css_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_datepicker_css_cdn` mediumtext  NOT NULL AFTER `elan_datepicker_js_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_jquery_ui_cdn` mediumtext  NOT NULL AFTER `elan_datepicker_css_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_dropzone_js_cdn` mediumtext  NOT NULL AFTER `elan_jquery_ui_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_dropzone_css_cdn` mediumtext  NOT NULL AFTER `elan_dropzone_js_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `fun` mediumtext  AFTER `elan_dropzone_css_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `recap_type` tinyint(1) NOT NULL DEFAULT '2' AFTER `fun`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `recap_version` tinyint(1) NOT NULL DEFAULT '3' AFTER `recap_type`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_image_upload_max_size` decimal(4,2) DEFAULT '2.00' COMMENT 'Maximum upload file size in MB' AFTER `behind_reverse_proxy`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_image_display_max_size` int DEFAULT '2048' COMMENT 'Maximum display image width in pixels' AFTER `elan_image_upload_max_size`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_image_thumbnail_sizes` mediumtext  COMMENT 'Comma-separated thumbnail sizes in pixels' AFTER `elan_image_display_max_size`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_chartjs_cdn` mediumtext  COMMENT 'Chart.js CDN URL for statistics charts' AFTER `elan_image_thumbnail_sizes`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_admin_emails` mediumtext  COMMENT 'Comma-separated admin email addresses for system notifications and administrative alerts' AFTER `elan_chartjs_cdn`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `recap_global` tinyint(1) NOT NULL DEFAULT '1' AFTER `social_login_location`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `recap_hide_badge` tinyint(1) NOT NULL DEFAULT '0' AFTER `recap_global`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `recap_v3_mode` varchar(20)  NOT NULL DEFAULT 'form' AFTER `recap_hide_badge`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `recap_v3_threshold` decimal(3,2) NOT NULL DEFAULT '0.50' AFTER `recap_v3_mode`");
        $this->execute("ALTER TABLE `settings` ADD COLUMN `elan_feedback_email` text  COMMENT 'Email address for receiving user feedback form submissions' AFTER `recap_v3_threshold`");

        // ── timestamp NULL -> NOT NULL ───────────────────────────────────────
        //
        // These tables were created on a server with
        // explicit_defaults_for_timestamp=OFF, which silently makes a bare
        // `timestamp` column NOT NULL. MySQL 8 defaults that variable to ON, so
        // stock 6.1.4 gets NULL instead. Matched to dev for fidelity — but note
        // that with the modern default, inserting an explicit NULL into these
        // columns now errors rather than substituting CURRENT_TIMESTAMP.
        $this->execute("ALTER TABLE `us_php_eol` MODIFY COLUMN `last_checked` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->execute("ALTER TABLE `us_php_known_bad` MODIFY COLUMN `last_checked` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->execute("ALTER TABLE `us_rate_limits` MODIFY COLUMN `attempt_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->execute("ALTER TABLE `us_rate_limit_proxy_settings` MODIFY COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->execute("ALTER TABLE `us_rate_limit_proxy_settings` MODIFY COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        // The only stock column that dev does not have. UserSpice adds it in
        // users/updates/components/2025-08-08a.php and then never uses it —
        // every read path (users/classes/RateLimit.php,
        // users/views/_admin_rate_limits.php, _admin_security_dashboard.php)
        // uses `header_name`. Dev and prod have both lacked it for some time,
        // so dropping it matches the environments that are actually running.
        $this->execute("ALTER TABLE `us_rate_limit_proxy_settings` DROP COLUMN `header`");
        $this->execute("ALTER TABLE `us_totp_secrets` MODIFY COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->execute("ALTER TABLE `us_totp_secrets` MODIFY COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        // $this->alignUsersTable(); - not now testing TODO

        // `users_online.id` is not AUTO_INCREMENT in either schema; dev just
        // carries an explicit zero default.
        $this->execute("ALTER TABLE `users_online` MODIFY COLUMN `id` int NOT NULL DEFAULT '0'");
    }

    /**
     * Type/nullability/default `users` columns must end up with, keyed by
     * column name. This is not the full column list and not an order — it is
     * only the columns whose *definition* differs from what stock 6.1.4
     * creates. `alignUsersTable()` issues a `MODIFY COLUMN` in place for each
     * one and never adds or repositions a column: columns stock doesn't
     * create (e.g. the ElanRegistry additions) are provisioned elsewhere, and
     * columns dev carries at a different ordinal position (or that dev
     * doesn't carry at all — the commented-out entries below) are left
     * exactly where stock puts them.
     *
     * No column carries an explicit CHARACTER SET/COLLATE: the collation pass
     * has already converted the table to utf8mb4_unicode_ci, which every
     * unqualified text column inherits.
     *
     * @var array<string, string>
     */
    private const USERS_COLUMNS = [
        'id'                 => 'int NOT NULL AUTO_INCREMENT',
        'email'              => 'varchar(155) NOT NULL',
        'email_new'          => 'varchar(155) DEFAULT NULL',
        'username'           => 'varchar(255) NOT NULL',
        'password'           => 'varchar(255) DEFAULT NULL',
        'pin'                => 'varchar(255) DEFAULT NULL',
        'fname'              => 'varchar(255) DEFAULT NULL',
        'lname'              => 'varchar(255) DEFAULT NULL',
        // Widened from stock's tinyint(1) — permission levels outgrew a boolean.
        'permissions'        => 'int NOT NULL',
        'logins'             => 'int unsigned NOT NULL',
        'account_owner'      => "tinyint NOT NULL DEFAULT '1'",
        'account_id'         => "int NOT NULL DEFAULT '0'",
        'join_date'          => 'datetime NOT NULL',
        'last_login'         => 'datetime NOT NULL',
        'email_verified'     => "tinyint NOT NULL DEFAULT '0'",
        'vericode'           => 'mediumtext',
        'active'             => 'int NOT NULL',
        'oauth_provider'     => 'mediumtext',
        'oauth_uid'          => 'mediumtext',
        'gender'             => 'varchar(10) DEFAULT NULL',
        'locale'             => 'varchar(10) DEFAULT NULL',
        'gpluslink'          => 'mediumtext',
        'picture'            => 'mediumtext',
        'created'            => 'datetime NOT NULL',
        'modified'           => 'datetime NOT NULL',
        'fb_uid'             => 'mediumtext',
        'un_changed'         => 'int NOT NULL',
        'msg_exempt'         => "int NOT NULL DEFAULT '0'",
        'protected'          => "int NOT NULL DEFAULT '0'",
        'dev_user'           => "int NOT NULL DEFAULT '0'",
        'msg_notification'   => "int NOT NULL DEFAULT '1'",
        'force_pr'           => "int NOT NULL DEFAULT '0'",
        'cloak_allowed'      => "tinyint(1) NOT NULL DEFAULT '0'",
        'vericode_expiry'    => 'datetime DEFAULT NULL',
        'oauth_tos_accepted' => 'tinyint(1) DEFAULT NULL',
        'language'           => "varchar(15) DEFAULT 'en-US'",
        'account_mgr'        => "int DEFAULT '0'",
    ];

    /**
     * Bring each `users` column's type/nullability/default in line
     * with dev, in place.
     *
     * `MODIFY COLUMN` only — no `ADD COLUMN`, no `AFTER` clause. This
     * migration does not add columns to `users` or change column order;
     * a column stock doesn't create is simply skipped (`fetchRow` returns no
     * row), left exactly where and however stock put it. Column names and
     * definitions come from the private const above — hardcoded strings,
     * nothing derived from input. Phinx 0.16 runs migration SQL through
     * PDO::query() rather than prepare(), so bind parameters are unavailable
     * here (see 20260713120000 for the same note).
     */
    // @phpstan-ignore-next-line method.unused (call site TODO'd out — see up())
    private function alignUsersTable(): void
    {
        foreach (self::USERS_COLUMNS as $column => $definition) {
            $exists = $this->fetchRow(
                "SELECT 1
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'users'
                    AND COLUMN_NAME = '{$column}'"
            );
            if (!$exists) {
                continue;
            }

            $this->withRelaxedZeroDateSqlMode(function () use ($column, $definition) {
                $this->execute("ALTER TABLE `users` MODIFY COLUMN `{$column}` {$definition}");
            });
        }
    }
}
