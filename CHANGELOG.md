# Changelog

All notable changes to the `delete_hidden` TYPO3 extension will be documented in this file.

## [1.0.0] - 2026-03-24

### Added
- FinderService: detect hidden `tt_content` records older than a configurable number of days
- FinderService: ancestor-chain traversal for `tx_container_parent` (b13/container support)
- FinderService: workspace guard (`t3ver_wsid = 0` filter)
- FinderService: cycle detection for circular `tx_container_parent` references
- PageExclusionRepository: tree-inherited page exclusion via `tx_delete_hidden_exclude` checkbox
- CleanupService: soft-delete via DataHandler with per-run safety cap (default 500)
- CleanupCommand: `typo3 delete_hidden:cleanup` with `--days`, `--dry-run`, `--page`, `--limit` options
- Scheduler task: "Delete Hidden Content Elements" with configurable days and limit fields
- TCA: `tx_delete_hidden_exclude` checkbox on pages table (Extended tab)
- Unit tests: 35 tests covering FinderService, CleanupService, PageExclusionRepository, and Scheduler task
- Functional tests: 4 tests covering CLI happy path, dry-run, page exclusion, and safety cap
- GitHub Actions CI: unit tests, functional tests, PHPStan, PHPCS on PHP 8.2-8.4
