# Changelog

## [Unreleased]

## [0.1.4] - 2026-11-09

### Added

- `forum-activity` takes the add-on 1.0.0a15's breakdowns: its headline says how many
  registrations did not come through the register form, the notes say what such an account can
  be and when sign-ups may be among them, and the description explains `by_day`, `by_hour` and
  `by_source`.

### Changed

- `forum-activity` lists only the days and hours with something in them, as the other
  breakdowns do.

## [0.1.3] - 2026-11-09

### Changed

- `forum-options` and `forum-configuration` explain each redaction reason once, in
  `redaction_reasons` ahead of the list, instead of on every redaction, and no longer pass on
  the add-on's `segments`. A 47-option answer is about a quarter smaller.

### Added

- `forum-options` marks the options XenForo rewrites itself with `volatile`, the paths within the
  value that change as a side effect: `jsLastUpdate`, `iconSpriteLastUpdate`,
  `collectServerStats.last_sent` and `xfesConfig.maxClauseCountChecked`.

### Fixed

- `job-queue` no longer calls a failed-jobs list empty when it holds jobs recorded while debug
  mode was on; it says those were recorded then and later failures are missing.

## [0.1.2] - 2026-11-09

### Changed

- `app:validate` accepts a root-owned inventory that its group may read and nothing else may,
  such as `0640`, as a deployment renders it; a file the user owns still needs `0600`.

## [0.1.1] - 2026-11-09

### Fixed

- `app:validate` on Windows: an inventory path with a drive letter or a UNC share is absolute,
  not relative, and the file mode check is skipped there rather than warning on every file.

### Changed

- The README covers installing and registering the server on macOS and Windows.

## [0.1.0] - 2026-11-09

The first release.

### Added

- A stdio MCP server, `xfmcp mcp:start xenforo`, over the read-only endpoints of the
  Hampel/AdminApi XenForo add-on, 1.0.0a12 or later. Nothing listens on a port.
- Fifteen tools, all marked read-only: `list-forums`, `forum-access`, `error-log-summary`,
  `error-log-entries`, `add-ons`, `cron-entries`, `job-queue`, `who-is-online`, `forum-logs`,
  `approval-queue`, `reports`, `forum-activity`, `user-upgrades`, `forum-options` and
  `forum-configuration`.
- A forum inventory in TOML: per forum, a read key holding the summary scopes it is to answer,
  and an optional detail key for error entries. `error-log-entries` is offered only when some
  forum has a detail key.
- A one-sentence headline on most summaries, and notes saying what an empty or partial answer
  is worth.
- `forum-access`, which asks every summary endpoint and names each one a forum refuses, and the
  scope or permission it wants, without reading an error entry.
- A refusal returned as a tool error naming whose problem it is: the add-on missing, the key's
  kind or scopes, the administrator's permission (named by the forum from add-on 1.0.0a13), or
  something in front of the forum answering instead of XenForo.
- Error entries carry the stack trace of the entry asked for only, unless `all_traces` is set.
- Option and configuration values, with secrets removed and each removal explained, from a
  forum whose key holds the add-on's values scope; shape only otherwise, and the answer says
  which. Particular options by id and settings by key.
- Registrations by state in `forum-activity`, naming every state but valid that holds anyone.
  Windows include `since` and exclude `until`, so consecutive windows add up.
- In `who-is-online`, errors split by kind and by action, and the /24 and /16 networks guests
  and recognised robots share, from add-on 1.0.0a14. A field an older forum does not send is
  reported as unknown, not zero.
- Day-by-day breakdowns list only days with something in them.
- An ISO 8601 time beside every Unix timestamp, in UTC and optionally in a second zone, and ages
  measured from the forum's own clock.
- `app:config`, which prints the resolved configuration without printing a key.
- `app:validate`, which checks the inventory and proves each forum's keys are the kind they are
  meant to be. `--offline` skips the forums; `--strict` exits 2 when there are warnings but no
  failures.
