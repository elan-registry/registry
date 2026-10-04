// tests/playwright/fixture-runner.js
//
// Runs a PHP seed fixture that needs the application database. The local
// stack is Docker only (DB_HOST=db does not resolve on the host), so the
// runner tries the app container first and falls back to the host `php`.

const { execFileSync } = require('node:child_process');
const path = require('node:path');

const REPO_ROOT = path.join(__dirname, '..', '..');

/**
 * Run a PHP fixture and return its stdout.
 * @param {string} fixtureRel Fixture path relative to the repo root
 * @param {string[]} args Fixture arguments (for example ['--cleanup'])
 * @returns {string} The fixture stdout
 */
function runPhpFixture(fixtureRel, args = []) {
    const attempts = [
        ['docker', ['compose', 'exec', '-T', '-u', 'www-data', 'app', 'php', fixtureRel, ...args]],
        ['php', [path.join(REPO_ROOT, fixtureRel), ...args]],
    ];
    const errors = [];
    for (const [cmd, cmdArgs] of attempts) {
        try {
            return execFileSync(cmd, cmdArgs, { cwd: REPO_ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
        } catch (error) {
            errors.push(`${cmd}: ${error.stderr || error.message}`);
        }
    }
    throw new Error(`Could not run ${fixtureRel}:\n${errors.join('\n')}`);
}

module.exports = { runPhpFixture };
