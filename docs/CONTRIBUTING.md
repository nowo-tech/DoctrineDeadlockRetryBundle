# Contributing

Contributions are welcome. Open an issue or pull request on [GitHub](https://github.com/nowo-tech/DoctrineDeadlockRetryBundle) (Composer: `nowo-tech/doctrine-deadlock-retry-bundle`).

- Follow PSR-12 and the project PHP-CS-Fixer configuration.
- Add tests for new behaviour under `tests/Unit/` and `tests/Integration/`.
- Keep documentation in English and in sync with code changes.

```bash
make install
make cs-check
make test
make phpstan
make rector-dry
make release-check
```

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
