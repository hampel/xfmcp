# xfmcp — MCP server for XenForo forum operations

An [MCP](https://modelcontextprotocol.io) server that lets an agent ask a XenForo forum about
its operational state: what is in the server error log, which add-ons are installed, whether
cron and the job queue are running, who is online, what the other logs and the moderation
queues hold, registrations and posting, user upgrades, and which options and `config.php`
settings are set. It
is read-only, and it talks over stdio, so nothing listens on a port.

It is a client of the Hampel/AdminApi XenForo add-on, which adds
those read-only endpoints to a forum's REST API. This tool turns them into MCP tools and
shapes the answers for a reader that cannot ask a follow-up question.

## Requirements

- PHP 8.3 or later, on the machine the agent runs on — Linux, macOS or Windows — with the
  `curl`, `mbstring` and `openssl` extensions
- Each forum: XenForo 2.2 or later with the Hampel/AdminApi add-on 1.0.0a12 or later
- An API key per forum, created as described under *Forums*

Nothing is installed on a forum's server beyond the add-on.

## Installation

**From a release.** Each release publishes the binary, `xfmcp-<version>`, and `SHA256SUMS`.
The binary is a PHP archive, the same file on every platform. Download both into one
directory, keeping the binary's name so the checksum matches, then follow the section for your
platform.

### Linux

```bash
sha256sum --ignore-missing -c SHA256SUMS
sudo install -D -m 0755 xfmcp-<version> /usr/local/lib/xfmcp/xfmcp-<version>
sudo ln -sfn /usr/local/lib/xfmcp/xfmcp-<version> /usr/local/bin/xfmcp
```

Keeping each version in `/usr/local/lib/xfmcp/` and linking the one in use makes going back a
matter of moving the link. **Keep that directory free of a `.env`**: an environment file beside
the binary, the real file after the link is followed, outranks every other. Put the settings in
`/etc/xfmcp/.env`, readable by whoever runs the MCP client — see *Configuration*.

### macOS

PHP from Homebrew has the extensions it needs:

```bash
brew install php
shasum -a 256 -c SHA256SUMS
mkdir -p ~/.local/lib/xfmcp ~/.local/bin
install -m 0755 xfmcp-<version> ~/.local/lib/xfmcp/
ln -sfn ~/.local/lib/xfmcp/xfmcp-<version> ~/.local/bin/xfmcp
```

`~/.local/bin` must be on your `PATH` for `xfmcp` to work in a terminal. **An MCP client
started from the Dock or Finder does not read your shell's `PATH`**, so register the server
with full paths: `$(brew --prefix)/bin/php` and the binary. *Usage* shows how.

### Windows

1. **Install PHP 8.3 or later** from [windows.php.net](https://windows.php.net/download/): the
   *x64 Non Thread Safe* zip, unpacked to a directory such as `C:\php`.
2. **Enable the extensions.** Copy `php.ini-production` to `php.ini` in that directory, and in
   it uncomment `extension_dir = "ext"` and the lines for `curl`, `mbstring` and `openssl`.
   `C:\php\php.exe -m` should then list all three.
3. **Check the download and put it somewhere of its own**, in PowerShell:

   ```powershell
   Get-FileHash .\xfmcp-<version> -Algorithm SHA256
   Get-Content .\SHA256SUMS
   New-Item -ItemType Directory -Force "$env:LOCALAPPDATA\xfmcp"
   Move-Item .\xfmcp-<version> "$env:LOCALAPPDATA\xfmcp\"
   ```

   The two hashes must be the same; PowerShell prints its own in upper case.

**Windows does not run the archive by itself**: every command is `php` followed by the path
to the binary, as in `C:\php\php.exe "$env:LOCALAPPDATA\xfmcp\xfmcp-<version>" app:config`.
Where this README says `xfmcp`, read that. There is no `/etc` either, so pass the inventory's
path in the MCP client's configuration — see *Usage*.

### From a checkout



```bash
composer install
composer build
sudo install -m 0755 builds/xfmcp /usr/local/bin/xfmcp
```

`composer build` installs without development dependencies, compiles, and restores them, and
refuses an artefact that still contains them.

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
| `key` | a User key holding the scope of each summary tool wanted, and `hampel_admin_api:values` if values should be shown |
| `detail_key` | optional: a second User key holding only `hampel_admin_api:error_detail` |
| `name` | optional: a label shown by `list-forums` |

### Create the keys on the forum

**What a key may read is decided on the forum, not here.** The add-on refuses a super user
key, then asks for the endpoint's scope on the key, then for an admin permission held by the
key's own administrator. *Permissions* below lists what each tool needs.

1. Under *Groups & permissions > Administrators*, pick the administrator the keys will act
   as and give them *Use the Admin API*. A dedicated administrator holding only that is the
   safest choice; options values and error entries need one core permission each as well.
2. Under *Setup > API keys*, create a key of type **User** for that administrator, with
   *Selected scopes only*, and tick the scope of each tool you want. Tick
   `hampel_admin_api:values` as well if options and configuration should show values. That is
   `key`.
3. If error messages should be readable, create a second User key with only
   `hampel_admin_api:error_detail`. That is `detail_key`.
4. Run `xfmcp app:validate`. It names any scope or permission that is missing.

**"All scopes" reaches none of the add-on's endpoints.** The scopes have to be ticked one by
one, so that a key is never given the member lookups by accident.

`php cmd.php admin-api:validate` on the forum checks the same from its side.

### Permissions

**Each tool needs its own scope on the key, and the key's administrator needs *Use the Admin
API*.** Two need a core permission as well. The names are as they appear in the control
panel.

| tool | key | scope |
|---|---|---|
| `error-log-summary` | `key` | `hampel_admin_api:error_log` |
| `add-ons` | `key` | `hampel_admin_api:add_ons` |
| `cron-entries`, `job-queue` | `key` | `hampel_admin_api:jobs` |
| `who-is-online` | `key` | `hampel_admin_api:online` |
| `forum-logs` | `key` | `hampel_admin_api:logs` |
| `approval-queue` | `key` | `hampel_admin_api:approval_queue` |
| `reports` | `key` | `hampel_admin_api:reports` |
| `forum-activity` | `key` | `hampel_admin_api:activity` |
| `user-upgrades` | `key` | `hampel_admin_api:user_upgrades` |
| `addon-validate` | `key` | `hampel_admin_api:validate`, from the add-on's 1.0.0a16 |
| `forum-options` | `key` | `hampel_admin_api:options` |
| `forum-configuration` | `key` | `hampel_admin_api:config` |
| `error-log-entries` | `detail_key` | `hampel_admin_api:error_detail`, **and** XenForo's *View logs* on the administrator |

**Showing values takes `hampel_admin_api:values` on the same key**, beside `options` or
`config`. Option values also need XenForo's *Manage options* on the administrator.

`list-forums` contacts no forum and needs nothing. `forum-access` needs nothing of its own: it
reports which of the above a forum grants.

**What happens when one is missing:**

- **A scope or permission for a tool:** the tool returns an error naming it — `missing_scope`
  for the key, and for the administrator the permission's name (`do_not_have_permission` before
  the add-on's 1.0.0a13, `hampel_adminapi_admin_permission_required` from it).
- **Anything needed for values:** no error. The forum answers without values, and the answer's
  `headline` says which scope or permission was missing.
- **Either way,** `forum-access` reports it, tool by tool, without reading an error entry.
  `app:validate` checks the keys and values, not each summary's permission.

### Two keys, and why

**`error-log-entries` is the one tool that returns content**: an error's message and stack
trace, which can quote a member's input, an email address or SQL. Everything else returns
counts and shape. Giving that one tool its own key means a forum with no `detail_key` cannot
be asked for an error message at all, and a server whose inventory has no `detail_key`
anywhere does not offer the tool.

One key holding both scopes works, and `app:validate` warns about it.

**Asking for an entry's request is recorded on the forum.** `include_request` adds the
recorded request, the user id and the IP address, and is off by default. From Hampel/AdminApi
1.0.0a9 the forum writes each such call to its admin log as a member lookup, whether or not the
entry exists, so anything that forwards the admin log forwards those calls too. A read without
it records nothing.

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
On macOS and Windows the simplest course is no environment file at all: set
`XFMCP_FORUMS_PATH` in the MCP client's configuration, as *Usage* shows.

**The inventory holds a live API key per forum, so only you should be able to read it.** On
Linux and macOS, `chmod 600` it. A deployment that renders it as root may instead make it
`0640` with the group of the user who runs the server, which keeps the tool from rewriting its
own configuration. `app:validate` accepts either and warns at anything wider. On Windows, PHP cannot see who
may read a file, so `app:validate` skips the check: keep it in your own profile, such as
`%LOCALAPPDATA%\xfmcp\forums.toml`, and check its *Security* properties.

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

**Give an MCP client absolute paths, and on Windows, the interpreter.** A desktop client does
not start from your shell, so it may not find `xfmcp` or `php` on a `PATH`. On macOS:

```bash
claude mcp add xenforo --env XFMCP_FORUMS_PATH="$HOME/.config/xfmcp/forums.toml" \
    -- "$(brew --prefix)/bin/php" "$HOME/.local/lib/xfmcp/xfmcp-<version>" mcp:start xenforo
```

On Windows, in PowerShell:

```powershell
claude mcp add xenforo --env XFMCP_FORUMS_PATH="$env:LOCALAPPDATA/xfmcp/forums.toml" `
    -- C:/php/php.exe "$env:LOCALAPPDATA/xfmcp/xfmcp-<version>" mcp:start xenforo
```

And in JSON, where forward slashes save escaping each backslash:

```json
{
    "mcpServers": {
        "xenforo": {
            "command": "C:/php/php.exe",
            "args": ["C:/Users/you/AppData/Local/xfmcp/xfmcp-<version>", "mcp:start", "xenforo"],
            "env": {"XFMCP_FORUMS_PATH": "C:/Users/you/AppData/Local/xfmcp/forums.toml"}
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
| `list-forums` | which forums are configured, and which have a detail key. Says nothing about whether they work |
| `forum-access` | what one forum will actually answer — each summary, values, error entries — without reading an error entry |
| `error-log-summary` | how many errors, of what kind, from where, and how many members met one |
| `error-log-entries` | the message and stack trace of one error and others like it |
| `add-ons` | what is installed at which version, and any install or upgrade left unfinished |
| `cron-entries` | every cron entry, and whether cron is running at all |
| `job-queue` | the queue by job class, and where failed jobs failed |
| `who-is-online` | how many members, guests and robots are online, and how concentrated the guests are |
| `forum-logs` | how much each of XenForo's 18 logs recorded, or one log's breakdowns: admin, moderator, spam, bounced mail and the rest |
| `approval-queue` | how much awaits approval, of what kind and for how long, and whether moderators are shown the right count |
| `reports` | how many reports wait for a moderator, for how long and since anyone touched one, and whether moderators are shown the right count |
| `forum-activity` | how many members registered and how many threads and posts were made in a window, by the state each is in now |
| `user-upgrades` | how many members hold each upgrade, what ends soon or expired, and what payments were reported |
| `addon-validate` | which add-ons declare their own checks, or how one add-on's checks came out: ok, warn, fail or skipped |
| `forum-options` | the forum's options: what is set, what was changed, and their values with secrets removed |
| `forum-configuration` | the `config.php` settings in force, and their values with secrets removed |

All of them are marked read-only.

### Asking about one setting

`forum-options` takes `option_ids` and `forum-configuration` takes `keys`, and each then
returns exactly what was asked for, changed or not. A name the forum has nothing by comes back
under `not_found`, which is different from a setting at its default. This is the way to answer
"is X switched on, on each forum?" without reading every option.

### Values, and what is withheld

**Whether the last two show values is decided on the forum, by the key.** With the
`hampel_admin_api:values` scope each entry carries its value, and the add-on removes what it
judges to be a secret; without it each entry says only whether it is set. Either way the
answer's `headline` says which, and why.

| an entry's `disclosure` | means |
|---|---|
| `shown` | the value is all there. A null is a null |
| `partly_withheld` | the value is there with parts removed. A removed part reads `[withheld]`, or `[withheld: not set]` when the secret held nothing |
| `withheld` | there is no `value` key. Read `set` for whether it holds anything |

Each removal is listed under `redactions` with its path and the reason. One reason deserves
knowing in advance: the text settings of an add-on that has not declared its secrets to
Hampel/AdminApi are hidden as a precaution, whatever they hold.

### What the tools add to the forum's answer

- **An ISO 8601 time beside every timestamp.** The forum reports Unix seconds in UTC; each
  `x_date` gains `x_date_iso`, and `x_date_local` when `APP_TIMEZONE` is set.
- **A `headline`** on most answers, and `notes` where an answer is easy to misread — an empty
  window on a log that is not empty, a forum that does not record failed jobs.
- **`disclosure`** on every option and setting, in place of nulls that could mean withheld or
  empty.
- **Errors as sentences.** A forum that is unreachable, a forum without the add-on and a key
  the forum refused are three different problems, and each is reported as what it is.

### What they cannot tell you

**A clean answer means nothing was recorded, which is weaker than nothing being wrong.** A
fault that logs no error does not appear in the error log, and a forum that is down cannot
answer at all. Further limits:

- `who-is-online` is a snapshot. Whether a count is too high depends on what is normal for
  that forum, which the tool does not know.
- A production forum records failed jobs only in debug mode, so `job-queue` says when an empty
  list means nothing.
- XenForo 2.3.0 does not log errors raised from the command line.
- XenForo 2.2 has no record of failed jobs and keeps no count of a job's attempts, so on a 2.2
  forum `job-queue` reports those as not recorded, never as zero. Nor does it record a cancelled
  subscription, so `user-upgrades` reports `cancelled` as null there.
- `user-upgrades` payment amounts are each upgrade's list price, not what the provider took.
- `addon-validate` returns each check's outcome, never what it said, so it cannot say why one
  warned or failed: that is the add-on's validate command, on the server. It runs only the checks
  an add-on marks safe to run remotely, and only for add-ons that declare them; a check it did not
  run is `skipped`, never a pass.
- `forum-activity` counts each registration, thread and post in the state it is in now, and
  cannot count what was deleted outright. Its window is at most 31 days.
- `forum-logs` over a long window on a large log is slow, because the forum reads every row in
  the window; the IP log is the worst. Asking for one log returns its whole size whatever the
  window, so the default 24 hours is enough to learn it.
- The add-on's member lookups — members by IP address, upgrade records, moderators,
  administrators and log entries — have no tool here, deliberately. Every answer here is counts
  or settings, never a member.

## Commands

```bash
xfmcp app:config             # the resolved configuration; never prints a key
xfmcp app:validate           # check the inventory, each forum and each key
xfmcp app:validate --offline # the same without contacting any forum
xfmcp app:validate --strict  # exit 2 when there are warnings but no failures
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

| | all fine | warnings only | any failure |
|---|---|---|---|
| `app:validate` | 0 | 0 | 1 |
| `app:validate --strict` | 0 | 2 | 1 |

**1 always means a failure**, with the flag or without it. This is not the monitoring-plugin
numbering, where 1 is a warning. A deploy gate leaves `--strict` off; a monitor that should hear
of a key holding more than it needs passes it.

## Development

```bash
composer check    # static analysis, then the suite
composer build    # compile builds/xfmcp without dev dependencies
```

The suite never contacts a forum. Its fixtures are responses captured from a real forum, with
add-on names replaced.

## Licence

MIT — see `LICENSE`.
