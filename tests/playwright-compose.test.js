'use strict';

/**
 * Guards the `cron` service in docker-compose.yml. cron.php runs real
 * scheduled jobs, such as the verification batch send, so the service must
 * stay behind the `cron` profile. A plain `docker compose up -d` must not
 * start it (#2180).
 *
 * js-yaml is not a direct dependency. markdownlint-cli2 installs it, so this
 * test reuses it instead of adding a YAML parser to package.json.
 *
 * Run with: node --test tests/playwright-compose.test.js
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const yaml = require('js-yaml');

const composePath = path.join(__dirname, '..', 'docker-compose.yml');

test('the cron service is behind the cron profile', () => {
    const compose = yaml.load(fs.readFileSync(composePath, 'utf8'));
    const cron = compose.services?.cron;

    assert.ok(cron, 'docker-compose.yml must define a cron service');
    assert.ok(Array.isArray(cron.profiles), 'the cron service must have a profiles list');
    assert.ok(cron.profiles.includes('cron'), 'the cron service profiles must include "cron"');
});
