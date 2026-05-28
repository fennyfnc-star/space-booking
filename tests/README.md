# Space Booking Test Contract

This test folder is intentionally split into automated checks and manual diagnostics.

## Automated (authoritative for regressions)

- `api.test.ts` (Vitest)
- `phpunit/TestSuiteContractTest.php` (PHPUnit test discovery contract)

Run:

```bash
npm run test:run
```

For PHPUnit (when a local PHPUnit binary is installed):

```bash
vendor/bin/phpunit -c phpunit.xml
```

## Manual diagnostics (not CI-grade)

All ad-hoc browser/CLI verification scripts are under `tests/manual/`.

- These scripts can mutate WordPress data.
- They are useful for local investigation.
- They are not considered deterministic regression gates.

## Rules for new tests

1. Put deterministic automated PHP tests in `tests/phpunit/*Test.php`.
2. Put deterministic frontend tests in `tests/*.test.ts` (or `*.test.tsx`).
3. Put one-off debugging scripts only in `tests/manual/`.
4. Do not add executable PHP scripts directly under `tests/` root.
