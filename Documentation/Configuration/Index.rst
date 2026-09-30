.. include:: ../Includes.txt

.. _configuration:

=============
Configuration
=============

The extension has no global extension configuration. Its behaviour is
controlled per run via the :ref:`CLI command or Scheduler task options
<usage>`, and per page via the page-level exclusion flag described below.

Page exclusion
===============

Set the **Exclude from "Delete Hidden" cleanup** checkbox
(`tx_delete_hidden_exclude`) on a page — found under the page properties'
*Miscellaneous* tab — to exclude all `tt_content` records on that page from
cleanup.

.. container:: table-row

	Property
		tx_delete_hidden_exclude

	Data type
		boolean (checkbox)

	Description
		When enabled, all `tt_content` records on this page — and on every
		page in its subtree — are skipped by both the CLI command and the
		Scheduler task, regardless of how long they've been hidden.

	Default
		0 (disabled)

Exclusion is inherited down the page tree: enabling it on a page also
excludes all of its descendant pages, even if they are not individually
flagged.
