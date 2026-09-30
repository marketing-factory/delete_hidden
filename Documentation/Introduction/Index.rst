.. include:: ../Includes.txt

.. _introduction:

============
Introduction
============

What does it do?
=================

This extension soft-deletes hidden `tt_content` records that have been
hidden for longer than a configurable number of days, together with their
recursive `b13/container <https://github.com/b13/container>`__ children —
so nested container content doesn't turn into orphaned records once its
parent is gone.

The cleanup logic is available both as a CLI command
(`typo3 delete_hidden:cleanup`) and as a TYPO3 Scheduler task, so it can be
run interactively (with a `--dry-run` preview) or scheduled to run
automatically.

How it works
=============

1. `FinderService` queries `tt_content` for records where `hidden = 1`,
   `t3ver_wsid = 0`, and `tstamp` is older than the given threshold. It then
   traverses `tx_container_parent` to also collect the (recursive) container
   children of every match, regardless of their own hidden flag or age.
   Pages flagged with `tx_delete_hidden_exclude = 1` — and their entire page
   subtree — are skipped.
2. `CleanupService` enforces a safety cap (default 500 records per run) and
   delegates every deletion to TYPO3's `DataHandler` — no direct SQL is ever
   used. Any `DataHandler` error aborts the run immediately and is reported
   back to the caller.
3. `CleanupCommand` exposes the service as a Symfony Console command, and
   `CleanupTask` exposes it as a Scheduler task.

Why is this needed?
====================

Editors frequently hide content elements instead of deleting them —
temporarily, "just in case", or because deleting felt too permanent at the
time. Over the years this leaves behind a growing number of hidden records
that nobody removes, bloating the database and cluttering exports, backups,
and searches. This extension gives editors and integrators a safe,
predictable way to soft-delete genuinely stale hidden content — with a
dry-run preview, a per-run safety cap, and a page-level opt-out for content
that must never be touched automatically.

Screenshots
===========

This extension has no backend module or user interface — it works entirely
through the CLI command and the Scheduler task.
