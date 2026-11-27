# Testing

This package provides a consistent set of [Composer](https://getcomposer.org/) scripts for local validation.

Tool references:

- [Actionlint](https://github.com/rhysd/actionlint) for GitHub Actions workflow linting.
- [Codespell](https://github.com/codespell-project/codespell) for spelling checks.
- [Composer Require Checker](https://github.com/maglnet/ComposerRequireChecker) for dependency definition checks.
- [Easy Coding Standard (ECS)](https://github.com/easy-coding-standard/easy-coding-standard) for coding standards.
- [EditorConfig Checker](https://github.com/editorconfig-checker/editorconfig-checker) for EditorConfig compliance.
- [Gitleaks](https://github.com/gitleaks/gitleaks) for secret scanning.
- [Infection](https://infection.github.io/) for mutation testing.
- [Markdownlint-cli2](https://github.com/DavidAnson/markdownlint-cli2) for Markdown linting.
- [PHPStan](https://phpstan.org/) for static analysis.
- [PHPUnit](https://phpunit.de/) for unit tests.
- [Prettier](https://prettier.io/) for Markdown and YAML formatting checks.
- [Rector](https://github.com/rectorphp/rector) for automated refactoring.
- [Yamllint](https://github.com/adrienverge/yamllint) for YAML linting.
- [Zizmor](https://github.com/zizmorcore/zizmor) for GitHub Actions security audits.

## Automated refactoring (Rector)

Run Rector to apply automated code refactoring. This command modifies files.

```bash
composer rector
```

Preview the refactoring without modifying files.

```bash
composer rector-check
```

## Coding standards (ECS)

Check coding standards without modifying files.

```bash
composer ecs-check
```

Apply coding standard fixes. Review the changes before keeping them.

```bash
composer ecs
```

## Dependency definition check

Verify that runtime dependencies are correctly declared in `composer.json`.

```bash
composer check-dependencies
```

## Mutation testing (Infection)

Run mutation testing.

```bash
composer mutation
```

Run mutation testing with PHPStan static analysis enabled.

```bash
composer mutation-static
```

Infection runs the `sqlite` group, so it needs no database service. CI enforces the thresholds in `infection.json5`.

## Repository quality checks

CI runs actionlint, codespell, EditorConfig Checker, markdownlint-cli2, Prettier and yamllint through the shared
[yii2-framework/actions](https://github.com/yii2-framework/actions) quality workflow. The repository's formatting rules
stay in `.editorconfig`.

Make actionlint and ShellCheck available on `PATH` to lint workflows locally.

```bash
composer actionlint
```

## Static analysis (PHPStan)

Run static analysis.

```bash
composer static
```

## Unit tests (PHPUnit)

The test suite uses SQLite in memory. Install dependencies for one Yii release at a time and repeat with the other
version to check compatibility.

```bash
composer update --with yiisoft/yii2:2.0.55
composer tests
```

Use `22.0.x-dev` in place of `2.0.55` for the Yii 22 check. To generate a Clover report with PCOV or Xdebug, run:

```bash
composer tests -- --coverage-clover runtime/coverage.xml
```

SQLite runs with `composer tests` and does not need a database service. The other drivers have their own workflows.
Composer requires only `pdo_sqlite`; each other group also needs its PHP PDO driver: `pdo_mysql` for `mysql`,
`pdo_pgsql` for `pgsql`, `pdo_sqlsrv` for `mssql` and `pdo_oci` for `oci`. To run a group locally, start that driver's
disposable database and run the matching command:

```bash
vendor/bin/phpunit --group mysql
vendor/bin/phpunit --group pgsql
vendor/bin/phpunit --group mssql
vendor/bin/phpunit --group oci
```

Connection defaults match each workflow's test service. To override a connection, set `SOFT_DELETE_TEST_<DRIVER>_DSN`,
`SOFT_DELETE_TEST_<DRIVER>_USERNAME`, or `SOFT_DELETE_TEST_<DRIVER>_PASSWORD`, using `MYSQL`, `PGSQL`, `MSSQL`, or
`ORACLE` as the driver name. Use an empty database dedicated to tests.

## Workflow security checks

CI runs zizmor and Gitleaks through the shared security workflow. Gitleaks uses its default rules. The Gitleaks scan
requires `GITLEAKS_LICENSE` in the workflow.

Make zizmor available on `PATH` to audit the workflows locally with the same pedantic persona.

```bash
composer zizmor
```

Zizmor needs GitHub API access for its online audits. Use the offline flag to run without API access.

```bash
composer zizmor -- --offline
```

Report hosted CI execution and its PHP/OS matrix separately from these local commands. Keep generated reports in ignored
`internal/`.

## Passing extra arguments

Composer scripts support forwarding additional arguments using `--`.

Run PHPUnit with code coverage report generation.

```bash
composer tests -- --coverage-html internal/coverage
```

Run PHPStan with a different memory limit.

```bash
composer static -- --memory-limit=512M
```

[Back to documentation](../README.md#documentation)
