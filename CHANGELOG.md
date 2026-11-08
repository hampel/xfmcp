# Changelog

## [Unreleased]

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
