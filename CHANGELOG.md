# Changelog

## [Unreleased]

### Added

- A stdio MCP server, `xfmcp mcp:start xenforo`, over the read-only endpoints of the
  Hampel/AdminApi XenForo add-on.
- Eight tools: `list-forums`, `error-log-summary`, `error-log-entries`, `add-ons`,
  `cron-entries`, `job-queue`, `forum-options` and `forum-configuration`. All are marked
  read-only and idempotent.
- A forum inventory in TOML, with a read key and an optional detail key per forum.
  `error-log-entries` is offered only when some forum has a detail key.
- An ISO 8601 time beside every Unix timestamp, in UTC and optionally in a second zone.
- `app:config`, which prints the resolved configuration without printing a key.
- `app:validate`, which checks the inventory and proves each forum's keys are the kind
  they are meant to be.
