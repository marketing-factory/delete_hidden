# delete_hidden

TYPO3 extension (v13.4+) that soft-deletes hidden `tt_content` records older than a configurable number of days, including their recursive [b13/container](https://github.com/b13/container) children.

## How it works

1. **FinderService** queries `tt_content` for records where `hidden = 1`, `t3ver_wsid = 0`, and `tstamp` is older than the given threshold. Pages with `tx_delete_hidden_exclude = 1` are skipped.
2. **CleanupService** enforces a safety cap (default 500) and delegates all deletions to TYPO3's `DataHandler` — no direct SQL. Any `DataHandler` error aborts the run immediately.
3. **CleanupCommand** exposes the service as a Symfony Console command.

## CLI usage

```bash
# Preview candidates without deleting (dry run)
typo3 delete_hidden:cleanup --days=90 --dry-run

# Delete hidden content older than 30 days
typo3 delete_hidden:cleanup --days=30

# Scope to a specific page subtree
typo3 delete_hidden:cleanup --days=90 --page=42

# Override the default safety cap of 500
typo3 delete_hidden:cleanup --days=90 --limit=1000

# Verbose output — shows a table of deleted records
typo3 delete_hidden:cleanup --days=90 -v
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--days` | `90` | Minimum age in days for a record to be a candidate |
| `--dry-run` | — | List candidates without performing any deletions |
| `--page` | `0` (all pages) | Restrict to a page subtree by UID |
| `--limit` | `500` | Safety cap — aborts before any deletion when exceeded |

### Exit codes

| Code | Meaning |
|------|---------|
| `0` | Success |
| `1` | Validation error, safety cap exceeded, or DataHandler error |

## Page exclusion

Set `tx_delete_hidden_exclude = 1` on a page to exclude all its content from cleanup.

## Installation

```bash
composer require marketing-factory/delete-hidden
```

Then activate the extension in the TYPO3 backend under **Admin Tools > Extensions** or via CLI:

```bash
typo3 extension:activate delete_hidden
```

## Scheduler task

The extension registers a TYPO3 Scheduler task named **"Delete Hidden Content Elements"**.

1. Navigate to **System > Scheduler** in the TYPO3 backend
2. Add a new task and select **Delete Hidden Content Elements** from the class dropdown
3. Configure the fields:
   - **Days** — minimum age in days for a record to be a candidate (default: 90)
   - **Limit** — maximum records per run (default: 500)
4. Set frequency and save

The Scheduler task always executes (no dry-run mode). If the candidate count exceeds the limit, only the first N records are processed; the rest are handled in subsequent runs.

## Running tests

Tests require the project's Composer dependencies to be installed (the `vendor/` directory at the repository root).

```bash
# From the repository root — run all unit tests for this extension
phpunit -c extensions/delete_hidden/Tests/Build/UnitTests.xml
```

```bash
# From the repository root — run all functional tests for this extension (requires running MySQL database via ddev)
phpunit -c extensions/delete_hidden/Tests/Build/FunctionalTests.xml
```

Test files are located in `Tests/Unit/` and `Tests/Functional/`.

## Requirements

- TYPO3 13.4+
- PHP 8.2+
- [b13/container](https://github.com/b13/container) *(optional)* — required only if the host project uses container elements
