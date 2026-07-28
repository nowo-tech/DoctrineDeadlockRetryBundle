# Release

## Process

1. Update [CHANGELOG.md](CHANGELOG.md) with the version and date; update [UPGRADING.md](UPGRADING.md) when needed.
2. Ensure `gh pr list --state open` is empty (or only valid holds) — `make check-open-prs` / REQ-REL-003.
3. Run `make release-check` locally (or rely on CI).
4. Commit the release, then tag with `git tag -a vX.Y.Z -m "Release vX.Y.Z"` and push commits + tags.
5. GitHub Actions `release.yml` creates the GitHub release from the tag and changelog section.

## Pre-release checklist

- [ ] `composer validate --strict`
- [ ] `make check-open-prs`
- [ ] `make cs-check`
- [ ] `make phpstan`
- [ ] `make test-coverage` (100% PHP lines target)
- [ ] `make demo-smoke` (or rely on `release-check-demos` / CI demo-smoke workflow)
- [ ] Documentation updated if behaviour or configuration changed (CHANGELOG, UPGRADING, README)

See also [SECURITY.md](SECURITY.md#release-security-checklist-1241).

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001). The release commit itself is not covered by an earlier `release-check` run.
