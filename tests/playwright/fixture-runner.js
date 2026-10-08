// Runs a PHP seed fixture that needs the application database. The local
// stack is Docker only (DB_HOST=db does not resolve on the host), so the
// runner runs the fixture in the app container. It uses the host `php` only
// when the docker command is not installed. Any other Docker failure (for
// example, a stopped stack or a fixture error) stops the run with the Docker
// output, so a host `php` error cannot hide the real cause.

const { execFileSync } = require('node:child_process');
const path = require('node:path');

const REPO_ROOT = path.join(__dirname, '..', '..');
const EXEC_OPTIONS = { cwd: REPO_ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] };

/**
 * Run a PHP fixture and return its stdout.
 * @param {string} fixtureRel Fixture path relative to the repo root
 * @param {string[]} args Fixture arguments (for example ['--cleanup'])
 * @returns {string} The fixture stdout
 * @throws {Error} When the fixture fails in Docker, or when docker is missing and host `php` fails
 */
function runPhpFixture(fixtureRel, args = []) {
    try {
        return execFileSync(
            'docker',
            ['compose', 'exec', '-T', '-u', 'www-data', 'app', 'php', fixtureRel, ...args],
            EXEC_OPTIONS
        );
    } catch (error) {
        if (error.code !== 'ENOENT') {
            throw new Error(
                `Could not run ${fixtureRel} in the app container (exit ${error.status ?? error.signal}).\n`
                + `stdout:\n${error.stdout || ''}\nstderr:\n${error.stderr || ''}`
            );
        }
    }

    try {
        return execFileSync('php', [path.join(REPO_ROOT, fixtureRel), ...args], EXEC_OPTIONS);
    } catch (error) {
        throw new Error(
            `docker is not installed, and host php could not run ${fixtureRel} (${error.status ?? error.code}).\n`
            + `stdout:\n${error.stdout || ''}\nstderr:\n${error.stderr || ''}`
        );
    }
}

module.exports = { runPhpFixture };
