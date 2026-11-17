# Ubuntu 26.04 runtime versions

The production VPS runs Ubuntu 26.04 LTS. Align the server and CI on PHP 8.5 and MySQL 8.4. The setup script installs Ubuntu's versioned PHP packages, selects the `php8.5-fpm` Apache configuration, and verifies the default PHP CLI, MySQL server, and CLI `pcntl` support before configuring the crawler. The Windows pilot accepts PHP 8.5 or newer.

Raise the Composer PHP requirement to `^8.5` and refresh the lockfile platform requirement and content hash. The locked development packages already permit PHP 8.5, so this change does not require a dependency version update. PHP-CS-Fixer's current supported-version list includes PHP 8.5; no unsupported-version override is needed. CI validates Composer metadata and runs formatting, static analysis, migrations, and tests on PHP 8.5 with MySQL 8.4.

This supersedes the runtime versions recorded in the earlier deployment decision. It does not change the schema or crawling behavior.
