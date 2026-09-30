.. include:: ../Includes.txt

.. _installation:

============
Installation
============

Install the extension via Composer::

	composer require mfd/delete-hidden

Requirements
============

* PHP 8.2+
* TYPO3 13.4+
* `b13/container <https://github.com/b13/container>`__ *(optional)* —
  required only if the host project uses container elements

After installation, activate the extension in the TYPO3 backend under
**Admin Tools > Extensions**, or via CLI::

	typo3 extension:activate delete_hidden

No database updates or further setup steps are required. To restrict
automatic cleanup on specific pages, see :ref:`Configuration <configuration>`.
