# Adobe Commerce Marketplace submission assets

This folder holds non-code assets used for the Marketplace listing. Marketplace
caps logo size at **5 MB PNG**, screenshots at **5 MB PNG each (2 minimum, 15
maximum)**, long description at **25,989 characters**, short description at
**120 characters**.

| File | Required dimensions | Status |
|------|---------------------|--------|
| `logo.png` | 200×200 minimum, square, PNG | **MISSING — founder to provide** |
| `screenshots/01-admin-config.png` | 1280×720+, PNG, ≤5MB | **MISSING — capture from Docker E2E** |
| `screenshots/02-status-indicator.png` | 1280×720+, PNG, ≤5MB | **MISSING — capture from Docker E2E** |
| `screenshots/03-install-command.png` | 1280×720+, PNG, ≤5MB | **MISSING — capture from Docker E2E** |
| `description.md` | text | ✅ ready (see this folder) |

## How to capture screenshots cheaply

Once `make e2e` is green in `tests/e2e/`, take screenshots of:

1. **Admin config form** — `Stores → Configuration → AxiTrace`, all groups
   expanded. Highlight the Workspace public key field.
2. **Status indicator** — after the test order, the green status with "last
   event N minutes ago".
3. **Install command** — terminal screenshot showing
   `composer require axitrace/module-tracking` succeeding.

## Submission checklist

- [ ] Logo + 2+ screenshots present
- [ ] `description.md` translated to plaintext (Marketplace UI uses BBCode/HTML lite)
- [ ] Pricing model: Free
- [ ] External account requirement disclosed
- [ ] EQP automated review PASSING (run `vendor/bin/phpcs --standard=Magento2 --severity=10 --ignore-annotations .` locally first)
- [ ] Hyva compat package submitted as a separate listing referencing this one
