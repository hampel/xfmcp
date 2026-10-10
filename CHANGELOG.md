# Changelog

## [Unreleased]

### Added

- A stdio MCP server, `xfmcp mcp:start xenforo`, over the read-only endpoints of the
  Hampel/AdminApi XenForo add-on.
- Ten tools: `list-forums`, `forum-access`, `error-log-summary`, `error-log-entries`, `add-ons`,
  `cron-entries`, `job-queue`, `who-is-online`, `forum-options` and `forum-configuration`.
  All are marked read-only.
- Option and configuration values, with secrets removed and each removal explained, from a
  forum whose key holds the add-on's values scope; shape only from one whose key does not.
- Lookup of particular options by id and configuration settings by key.
- A forum inventory in TOML, with a read key and an optional detail key per forum.
  `error-log-entries` is offered only when some forum has a detail key.
- An ISO 8601 time beside every Unix timestamp, in UTC and optionally in a second zone.
- `app:config`, which prints the resolved configuration without printing a key.
- `app:validate`, which checks the inventory and proves each forum's keys are the kind
  they are meant to be.
