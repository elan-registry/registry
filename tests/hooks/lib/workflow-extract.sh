# shellcheck shell=bash
# Pull text out of a GitHub workflow file for tests/hooks/test-*.sh.
# Source it; do not run it. It lives in lib/ so that the CI loop over
# tests/hooks/test-*.sh skips it.
#
# Why: a test must run the exact `run:` text that the workflow runs. A copy
# in the test drifts. A step is found by job id and exact step name, so a
# rename makes the test fail instead of pass on stale text.
#
# Every function takes the workflow path as the first argument. Each one
# exits non-zero with a message on stderr when the file, job, or step is
# missing, or when PyYAML is missing. There is no skip.
#
#   wf_step_run   <wf> <job> <step-name>  prints the run: text
#   wf_step_env   <wf> <job> <step-name>  prints KEY=VALUE lines of env:
#   wf_claude_args <wf> <job>             prints with.claude_args of the
#                                         step with id claude-review
#   wf_json_schema <wf> <job>             prints the --json-schema value
#                                         from that claude_args text

# wf__extract <mode> <wf> <job> [step-name]
wf__extract() {
    python3 - "$@" <<'PY'
import shlex
import sys

try:
    import yaml
except ImportError:
    sys.stderr.write("workflow-extract: PyYAML is missing (pip install pyyaml)\n")
    sys.exit(1)

mode, wf, job = sys.argv[1], sys.argv[2], sys.argv[3]
name = sys.argv[4] if len(sys.argv) > 4 else None


def die(msg):
    sys.stderr.write("workflow-extract: " + msg + "\n")
    sys.exit(1)


try:
    with open(wf, encoding="utf-8") as handle:
        # BaseLoader keeps every scalar as a raw string. Then `on:`, `true`
        # and numbers reach the test as written in the file.
        doc = yaml.load(handle, Loader=yaml.BaseLoader)
except OSError as err:
    die("cannot read " + wf + ": " + str(err))
except yaml.YAMLError as err:
    die("cannot parse " + wf + ": " + str(err))

jobs = (doc or {}).get("jobs") or {}
if job not in jobs:
    die("job not found: " + job)
steps = jobs[job].get("steps") or []

if mode in ("run", "env"):
    found = [s for s in steps if s.get("name") == name]
    if not found:
        die("step not found: " + job + " / " + str(name))
    step = found[0]
    if mode == "run":
        if "run" not in step:
            die("step has no run: " + job + " / " + str(name))
        text = step["run"]
        sys.stdout.write(text if text.endswith("\n") else text + "\n")
    else:
        env = step.get("env")
        if env is None:
            die("step has no env: " + job + " / " + str(name))
        for key, value in env.items():
            sys.stdout.write(key + "=" + value + "\n")
else:
    found = [s for s in steps if s.get("id") == "claude-review"]
    if not found:
        die("no step with id claude-review in job " + job)
    args = (found[0].get("with") or {}).get("claude_args")
    if args is None:
        die("step claude-review has no with.claude_args in job " + job)
    if mode == "args":
        sys.stdout.write(args if args.endswith("\n") else args + "\n")
    else:
        try:
            words = shlex.split(args)
        except ValueError as err:
            die("cannot split claude_args: " + str(err))
        if "--json-schema" not in words:
            die("no --json-schema in claude_args of job " + job)
        idx = words.index("--json-schema")
        if idx + 1 >= len(words):
            die("--json-schema has no value in job " + job)
        sys.stdout.write(words[idx + 1] + "\n")
PY
}

wf_step_run() { wf__extract run "$@"; }
wf_step_env() { wf__extract env "$@"; }
wf_claude_args() { wf__extract args "$1" "$2"; }
wf_json_schema() { wf__extract schema "$1" "$2"; }
