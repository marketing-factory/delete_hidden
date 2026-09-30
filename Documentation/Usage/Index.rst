.. include:: ../Includes.txt

.. _usage:

=====
Usage
=====

CLI command
============

The extension registers a Symfony Console command::

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

In `--dry-run` mode, the command lists every candidate in a table (UID,
PID, CType, title, age in days, and container parent UID) plus a summary
grouped by page, without performing any deletions.

Options
-------

.. container:: table-row

	Option
		`--days`

	Default
		`90`

	Description
		Minimum age in days for a record to be a candidate.

.. container:: table-row

	Option
		`--dry-run`

	Default
		—

	Description
		List candidates without performing any deletions.

.. container:: table-row

	Option
		`--page`

	Default
		`0` (all pages)

	Description
		Restrict to a page subtree by UID.

.. container:: table-row

	Option
		`--limit`

	Default
		`500`

	Description
		Safety cap — aborts before any deletion when the candidate count
		exceeds this value.

Exit codes
----------

.. container:: table-row

	Code
		`0`

	Meaning
		Success

.. container:: table-row

	Code
		`1`

	Meaning
		Validation error, safety cap exceeded, or DataHandler error

Scheduler task
===============

The extension registers a TYPO3 Scheduler task named
**"Delete Hidden Content Elements"**.

#. Navigate to **System > Scheduler** in the TYPO3 backend.
#. Add a new task and select **Delete Hidden Content Elements** from the
   class dropdown.
#. Configure the fields:

   * **Days** — minimum age in days for a record to be a candidate
     (default: 90)
   * **Limit** — maximum records per run (default: 500)

#. Set the frequency and save.

The Scheduler task always executes (there is no dry-run mode for it). If
the candidate count exceeds the limit, only the first N records are
processed in that run; the remainder is handled by subsequent runs.
