# ADR-021: Trust the devkit Plugin on a Protected Tag

## Status

Accepted

## Date

2026-10-28

## Context

Registry moves its Claude Code workflow to the `flow` plugin in
[unibrain1/devkit](https://github.com/unibrain1/devkit). A session that loads
`flow@devkit` runs devkit code on the contributor's machine: hooks, scripts,
and agent prompts. Later, the Claude CI workflows will also come from devkit
and run with the repository secrets.

Registry is public and has outside contributors. So Registry must get new
devkit code only through a Registry PR that a person reviews. The PR review
of #2545 asked for this decision in writing.

Facts:

- The devkit marketplace lists `flow` at `./plugins/flow`. The marketplace
  ref therefore pins the flow code too.
- The third-party `asd-ste100` plugin is pinned to a full commit SHA inside
  the devkit marketplace.
- A git tag can move unless a rule stops it.
- The marketplace `ref` does not take a commit SHA. Claude Code clones with
  `git clone --branch <ref>`. A test on 2026-10-28 cloned tag `v0.1.0` and
  failed for the SHA of the same commit.

## Decision

1. `.claude/settings.json` pins the devkit marketplace to a release tag, for
   example `"ref": "v0.1.0"`.
2. devkit has a tag ruleset ("release tags") for `refs/tags/v*`. It blocks
   update, deletion, and force push of a tag, with no bypass actors. A tag
   therefore points at one commit for its full life.
3. A pin bump is a Registry PR at a milestone boundary. Before the merge, a
   maintainer reads the devkit diff between the old tag and the new tag.
4. Dependabot ignores `unibrain1/devkit`, so a bump never arrives at a random
   time.
5. The private-path guard stays in Registry too
   (`.claude/hooks/guard-private-paths.sh`). flow ships the same guard, but a
   session without the plugin must still block a forced `git add` of
   `docs/plans/` or `_noupload/`.
6. When the Claude CI callers move to devkit, they pin the reusable
   workflows to the full commit SHA of the same tag, with the tag in a
   comment. This is the same rule as for other third-party actions.

## Consequences

- A change in devkit reaches Registry only through a reviewed Registry PR.
- The protection of the plugin pin depends on a devkit repository setting.
  A devkit admin can turn the ruleset off. The owner of devkit is also the
  Registry owner, so this adds no new person to the trust boundary.
- Each contributor accepts the devkit marketplace in the trust prompt one
  time.

## Alternatives Considered

- **Tag pin with no tag rule.** Easy to read, but the tag can move. Registry
  could then get new code with no review. Rejected.
- **Commit SHA pin for the plugin.** The content could not change, and no
  setting is needed. The marketplace `ref` does not take a SHA (see Context).
  Not possible.
- **Mirror devkit in the `elan-registry` org.** The org would own the
  boundary, but a second copy needs a sync job, which brings back the drift
  that devkit removes. The same owner controls both, so the gain is small.
  Rejected.
- **Copy flow into Registry.** No outside trust, but each repo then keeps its
  own copy again. Rejected.

## References

- [devkit design](https://github.com/unibrain1/devkit/blob/main/docs/design.html),
  section 7 "Versions and updates" and section 8 "The main-match rule".
- PR #2545 (the move to flow).
