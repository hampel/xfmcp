# xfmcp — MCP server for XenForo forum operations

An [MCP](https://modelcontextprotocol.io) server that lets an agent ask a XenForo forum about
its operational state: what is in the server error log, which add-ons are installed, whether
cron and the job queue are running, and which options and `config.php` settings are set. It
is read-only, and it talks over stdio, so nothing listens on a port.

It is a client of the Hampel/AdminApi XenForo add-on, which adds
those read-only endpoints to a forum's REST API. This tool turns them into MCP tools and
shapes the answers for a reader that cannot ask a follow-up question.

## Requirements

- PHP 8.3 or later, on the machine the agent runs on
- Each forum: XenForo 2.3.0 or later with the Hampel/AdminApi add-on installed
- An API key per forum, created as described under *Forums*

Nothing is installed on a forum's server beyond the add-on.

## Installation

Build the binary from a checkout, and put it on your path:

```bash
composer install
composer build
sudo install -m 0755 builds/xfmcp /usr/local/bin/xfmcp
```

Or run it in place, without building:

```bash
php xfmcp app:config
```

Then:

1. Write the forum inventory — see *Forums*.
2. Tell the binary where it is — see *Configuration*. A checkout finds `storage/forums.toml`
   by itself.
3. Run `xfmcp app:validate` and read every line.
4. Register the server with your MCP client — see *Usage*.

## Forums

**The inventory is a TOML file with one table per forum, and it holds live API keys.** Keep it
outside any repository and make it mode `0600`. `forums.toml.example` is the template.

```toml
[community]
name = "Community Forum"
url  = "https://forum.example.com"
key  = "…"
detail_key = "…"
```

| setting | meaning |
|---|---|
| table name | the id every tool takes. Lowercase |
| `url` | the board URL, or its `/api` URL |
| `key` | a User key holding only the `hampel_admin_api:read` scope |
| `detail_key` | optional: a second User key holding only `hampel_admin_api:error_detail` |
| `name` | optional: a label shown by `list-forums` |

### Create the keys on the forum

**What a key may read is decided on the forum, not here.** The add-on refuses a super user
key, then asks for the scope, then for an admin permission held by the key's own user. So:

1. Under *Groups & permissions > Administrators*, give an administrator the add-on's
   permissions for the endpoints the key should reach.
2. Under *Setup > API keys*, create a key of type **User** for that administrator with only
   the `hampel_admin_api:read` scope. That is `key`.
3. If error messages should be readable, create a second User key with only
   `hampel_admin_api:error_detail`, for an administrator who also holds XenForo's own
   *View logs* permission. That is `detail_key`.

The add-on's own README documents the permissions. `php cmd.php admin-api:validate` on the
forum checks its side.

### Two keys, and why

**`error-log-entries` is the one tool that returns content**: an error's message and stack
trace, which can quote a member's input, an email address or SQL. Everything else returns
counts and shape. Giving that one tool its own key means a forum with no `detail_key` cannot
be asked for an error message at all, and a server whose inventory has no `detail_key`
anywhere does not offer the tool.

One key holding both scopes works, and `app:validate` warns about it.

## Configuration

Every setting has a default, so a checkout needs no `.env`. `.env.example` documents each one.

| variable | default | meaning |
|---|---|---|
| `XFMCP_FORUMS_PATH` | `storage/forums.toml` | the forum inventory. Set it absolutely for an installed binary |
| `APP_TIMEZONE` | `UTC` | a second zone to show each time in |
| `XENFORO_TIMEOUT` | `10` | seconds a request to a forum may take |
| `XENFORO_CONNECT_TIMEOUT` | `5` | seconds to wait for a connection |

The environment file is looked for beside the binary, then at the path in `XFMCP_ENV`, then in
the project directory, then at `/etc/xfmcp/.env`. `xfmcp app:config` prints the one it read.

**An installed binary must be told where the inventory is.** A compiled binary resolves its
default against the working directory, and an MCP client starts the server from a directory
of its own choosing. Either set `XFMCP_FORUMS_PATH` in an environment file the binary finds,
or pass it in the client's configuration as below.

## Usage

The server is one command:

```bash
xfmcp mcp:start xenforo
```

It reads JSON-RPC on stdin and writes it on stdout, so it is started by an MCP client and not
by hand. For Claude Code:

```bash
claude mcp add xenforo --env XFMCP_FORUMS_PATH=/path/to/forums.toml -- xfmcp mcp:start xenforo
```

For a client configured with JSON:

```json
{
    "mcpServers": {
        "xenforo": {
            "command": "xfmcp",
            "args": ["mcp:start", "xenforo"],
            "env": {"XFMCP_FORUMS_PATH": "/path/to/forums.toml"}
        }
    }
}
```

To try the tools by hand, the MCP Inspector runs the same command:

```bash
npx @modelcontextprotocol/inspector xfmcp mcp:start xenforo
```

## Tools

Every tool but the first takes `forum`, an id from `list-forums`.

| tool | answers |
|---|---|
| `list-forums` | which forums are configured, and which offer error detail |
| `error-log-summary` | how many errors, of what kind, from where, and how many members met one |
| `error-log-entries` | the message and stack trace of one error and others like it |
| `add-ons` | what is installed at which version, and any install or upgrade left unfinished |
| `cron-entries` | every cron entry, and whether cron is running at all |
| `job-queue` | the queue by job class, and where failed jobs failed |
| `forum-options` | which options are set and changed, with values only where they are safe to show |
| `forum-configuration` | which `config.php` settings are in force; never a string |

All of them are marked read-only and idempotent.

### What the tools add to the forum's answer

- **An ISO 8601 time beside every timestamp.** The forum reports Unix seconds in UTC; each
  `x_date` gains `x_date_iso`, and `x_date_local` when `APP_TIMEZONE` is set.
- **A `headline`** on the error log and cron answers, and `notes` where an answer is easy to
  misread — an empty window on a log that is not empty, a forum that does not record failed
  jobs.
- **`withheld: true`** on an option or setting whose value the add-on will not disclose, in
  place of a `value: null` that reads as empty.
- **Errors as sentences.** A forum that is unreachable, a forum without the add-on and a key
  the forum refused are three different problems, and each is reported as what it is.

### What they cannot tell you

**A clean answer means nothing was recorded, which is weaker than nothing being wrong.** A
fault that logs no error does not appear in the error log, and a forum that is down cannot
answer at all. Three further limits come from XenForo itself:

- A production forum records failed jobs only in debug mode, so `job-queue` says when an empty
  list means nothing.
- XenForo 2.3.0 does not log errors raised from the command line.
- Options are reported as stored. A `config.php` that overrides an option in code is not
  reflected.

## Commands

```bash
xfmcp app:config             # the resolved configuration; never prints a key
xfmcp app:validate           # check the inventory, each forum and each key
xfmcp app:validate --offline # the same without contacting any forum
```

**`app:validate` proves each key is the kind it is meant to be.** For every forum it asks the
forum what the key is, reads the add-on inventory with the read key, and then confirms each
key is *refused* by the other's endpoint. It sends only `GET` requests, and the two aimed at
the error entries endpoint name no entry, so nothing is read from the log.

| marker | meaning | exit code |
|---|---|---|
| `[ ok ]` | checked, and fine | 0 |
| `[warn]` | worth knowing; the tool works — a key holding more than it needs is one | 0 |
| `[fail]` | the tool will not work for this forum | 1 |
| `[    ]` | not checked. A skip is not a pass | 0 |

## Development

```bash
composer check    # static analysis, then the suite
composer build    # compile builds/xfmcp without dev dependencies
```

The suite never contacts a forum. Its fixtures are responses captured from a real forum, with
add-on names replaced.

## Licence

MIT — see `LICENSE`.
