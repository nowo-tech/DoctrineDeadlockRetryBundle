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
