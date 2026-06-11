# Release

## Process

1. Update [CHANGELOG.md](CHANGELOG.md) with the version and date.
2. Run `make release-check` locally (or rely on CI).
3. Tag with `git tag vX.Y.Z` and push tags.
4. GitHub Actions `release.yml` creates the GitHub release from the tag and changelog section.

## Pre-release checklist

- [ ] `composer validate --strict`
- [ ] `make cs-check`
- [ ] `make phpstan`
- [ ] `make test-coverage` (100% PHP lines target)
- [ ] Documentation updated if behaviour or configuration changed

See also [SECURITY.md](SECURITY.md#release-security-checklist-1241).
