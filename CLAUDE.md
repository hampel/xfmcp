# CLAUDE.md

Guidance for a session working in this repository.

## What this is

`xfmcp` is a Laravel Zero 13 CLI (PHP 8.3+) that runs an MCP server over stdio. Its tools are
the read-only endpoints of the Hampel/AdminApi XenForo add-on, reached through
`hampel/xenforo-api`, so an agent can ask a forum about its error log, add-ons, cron, job
queue, who is online, its other logs, the approval queue, user upgrades, options and
configuration.

**The add-on's `README.md` is the contract**: every endpoint, parameter and field. This
repository does not restate it. `README.md` here is the operator's document; this file is why
the code is the way it is.

**It has not been released.** `CLAUDE.local.md`, gitignored, records where it runs, which
forum it was proved against and what is still undecided.

## Commands

```bash
php xfmcp app:config            # resolved configuration, and the .env actually read
php xfmcp app:validate          # inventory, each forum, each key's kind
php xfmcp mcp:start xenforo     # the server; speaks JSON-RPC on stdin and stdout

composer check                  # phpstan, then pest
vendor/bin/pest --filter='headline'
composer build                  # release phar into builds/, refusing dev dependencies
```

## The split this tool is one half of

**The add-on is the stable half and this is the half meant to churn.** The add-on returns
facts and is installed on production forums, so changing it means an upgrade on every one.
Tool names, descriptions, ordering, headlines and notes are all here, where rewording one
costs a rebuild on one workstation. When an agent misreads an answer, the fix belongs in
`app/Mcp/Tools` or `App\Support\Present` — not in a request to change the add-on.

**Authorisation is finished on the forum, and this tool must not add its own.** The add-on
refuses a super user key, then asks for the scope, then for an admin permission held by the
key's own user. The only decision made here is which of a forum's two keys a call is sent
with. A check added on this side would be a second copy of a rule, and the two would drift.

**The add-on's member lookups have no tool here, by decision.** Since its 1.0.0a8 the add-on
can look up members by IP address, upgrade records, moderators, administrators and log entries,
each on its own scope and each writing an admin log row. Simon decided on 2026-10-24 not to
expose them for now: every answer this tool gives is counts or settings, the one exception
being error entries behind their own key. Adding them is a decision, not a gap.

## Stdio only, and stdout is the protocol

**`bootstrap/ai.php` calls `Mcp::local()` and must never call `Mcp::web()`.** Nothing in this
tool listens on a port; that is the reason it is a Laravel Zero application.
`tests/Feature/ServerTest.php` asserts the registered servers are exactly `['xenforo']`.

**Under `mcp:start`, anything written to stdout that is not JSON-RPC breaks the client.**
`App\Exceptions\Handler` sends a rendered exception to stderr, and `bootstrap/app.php`
catches a malformed `.env` itself, because that fails before the handler exists. Both are
rebound or written in `bootstrap/app.php`; neither is redundant.

**Nothing is logged, deliberately.** There is no `config/logging.php` and no `Log::` call:
the tool is driven by an agent that reads every answer, a forum records each key's
`last_use_date`, and a log here would be a second place for a forum's data to rest.
`report()` in `ForumGateway` goes to a null logger; measured 2026-10-08, it writes nothing to
stdout or disk.

## A failure is a tool error with a sentence in it

**Every refusal the add-on makes is a 403, a forum without it is a 404, and a WAF in front of
a forum can answer either**, so the status says almost nothing. `ForumGateway::explain()`
reads XenForo's error code and names whose problem it is:

| the forum answered | it means |
|---|---|
| `endpoint_not_found` | the add-on is not installed there |
| `hampel_adminapi_user_api_key_required` | a super user or guest key |
| `missing_scope` | the key lacks the scope for that endpoint |
| `do_not_have_permission` | the key's administrator lacks the admin permission |
| a 4xx with no `errors[]` | something in front of the forum answered, not XenForo |

**A tool returns `Response::error()`, never an exception and never an empty result.** An
exception becomes a JSON-RPC protocol error that tells the agent nothing; an empty result
reads as a healthy forum. `tests/Feature/FailureTest.php` holds each case to its sentence.

**An explanation never carries a response body or an arbitrary exception message.** XenForo's
own error messages are passed on, because they are fixed phrases; a body can be a WAF page
and a throwable's message can hold a URL.

## The inventory is read on demand

**`config/xfmcp.php` holds the inventory's path and nothing else.** Every table in the file
carries a live API key, so `App\Support\ForumInventory` parses it when a tool or a check asks
and never at config load, which would put every key in the config repository for
`--version`. It is a singleton, and it parses again whenever the file's modification time or
size changes: the server is one process for the life of a client's session, so a parse kept
for the life of the process hid a newly added forum until the client reconnected.

**A changed inventory reaches the tools at once, but not the tool list.** Whether
`error-log-entries` is offered is decided when the client asks for the list, and a client asks
once. Adding the first `detail_key` to an inventory that had none still needs a reconnect.

**A parse error never quotes the parser.** The TOML parser names the token it stopped on, which
in this file can be part of a key, so `ForumInventory` reports a fixed sentence and chains
nothing. `ForumInventoryTest` holds it to that.

**`key` alone, never `key` with `user`.** In `hampel/xenforo-api-laravel` that pair builds a
super user key, which the add-on refuses with a 403 that reads like a broken forum.
`ForumInventory::problem()` rejects a table that has a `user` line.

**Configured is not the same as working, and the two are different tools.** `list-forums`
reads the inventory and contacts nothing, so its flag is `detail_key_configured` - it was
`error_detail` until 2026-10-10, when an agent told its user a forum could return error
entries on the strength of it and the forum refused the key. `forum-access` is the tool that
proves it, with the same no-entry probe `app:validate` uses, because the one tool that returns
personal data must not be the way to find out whether it works.

**`ErrorLogEntries::shouldRegister()` hides the detail tool when no forum has a
`detail_key`.** A server that cannot read an error message should not advertise a tool that
does.

## Presentation rules, and the misreading behind each

All in `App\Support\Present` and the tool classes. Each exists because an agent given the raw
response got something wrong, in trials run against the add-on before this tool existed.

- **Every timestamp gains `_iso`.** Everything is Unix seconds in UTC; an agent read a cron
  rule's `"hours": [3]` as local time. Keys are matched by name (`now`, `since`, `until`,
  `next_run`, anything ending `_date`) and by being a plausible timestamp, so a count is never
  annotated.
- **The error log leads with `headline`.** The default window is 24 hours, and a quiet day
  looked like an empty log.
- **`by_origin_add_on` and `by_location` come before `by_add_on`**, which is last. Where an
  error surfaced is not whose error it is.
- **Every option and setting gains `disclosure`: `shown`, `partly_withheld` or `withheld`.**
  A null was ambiguous twice over. Without the values scope, `value: null` meant both
  withheld and null; with it, a withheld part is a null *inside* the value, told from a real
  one only by `redactions` naming its path. So a value withheld whole loses its `value` key,
  a part withheld from inside one becomes `[withheld]`, and each redaction gains `because`.
  `Present::disclosure()` handles both modes; a null that survives it is a null.
- **A secret that holds nothing reads `[withheld: not set]`.** The add-on withholds a secret
  whether or not it is set, and each redaction says which. A blank SMTP password is an
  answer - there is no login - and must not look like a hidden one, or like an empty string.
- **The values headline counts the entries hidden only because their add-on has not declared
  its secrets.** Until add-ons declare, that is most of what is withheld, and without the
  count a forum full of undeclared add-ons reads as a forum full of secrets.
- **A redaction is placed by its `segments`, never by splitting its `path`.** A key can
  contain a dot - a hostname, a template name - so the dotted path cannot be taken apart
  again. `Present::mark()` only ever replaces a place that exists, and creates nothing.
- **Options and configuration lead with which mode the forum answered in**, using the
  add-on's own `mode_reason`. A missing scope and a missing admin permission are different
  fixes, and "the tool could only say smtp" is what prompted values in the first place.
- **`notes` says what an empty answer is worth**, because absence is not health and a
  description alone did not stop an agent overclaiming it.

- **`forum-access` asks every summary endpoint, not one.** Each has its own admin permission,
  so add-ons answering said nothing about logs or upgrades; on 2026-10-24 three dev forums
  answered every older summary and refused all three new ones. A refusal names the permission
  from `AdminApi::PERMISSIONS`, which is kept in step with the add-on by hand.

- **`who-is-online` is the one tool not marked idempotent.** It is a snapshot; two calls are
  two answers.

- **Options and settings can be asked for by name, and the answer is the same whatever the
  forum's add-on version.** An add-on before 1.0.0a6 ignores `option_id` and `key` and returns
  its list, with no `not_found` in the reply - that absence is the test. The request is sent
  without `changed`, so the ignored case returns everything and the tool picks out what was
  asked for itself. It exists because an agent asked which of five forums had friendly URLs
  on read 213 entries and inferred two answers from an option being absent from the changed
  list.

Changing one of these changes what an agent concludes. Add a test with the change.

## `app:validate` proves the kind of each key

**The values scope on a read key is a choice, not an excess.** `hampel_admin_api:values`
widens what the read key's own two endpoints show, so `app:validate` reports it and does not
warn. Any other extra scope still warns. The scope is not sufficient, though: the key's user also needs
admin permissions, and lacking one the forum answers 200 with shape only. So the command asks
both endpoints and reads `mode`, warning with the forum's own `mode_reason`. That reason is
quoted, never matched on - the add-on has already added one.

**A key with too much is the failure worth catching, and it is silent otherwise.** For each
forum the command asks `index` what the key is, then confirms each key is *refused* by the
other's endpoint. It relies on the add-on checking the scope before the parameter: a read key
calling `error-entries` with no `error_id` gets `403 missing_scope`, a detail key gets
`400 hampel_adminapi_error_id_required`. The add-on pins that order with a test of its own.
No probe names an entry, so nothing is read from a log.

**The scope check needs `hampel/xenforo-api` 1.2.2 or later, which is what `composer.json`
requires.** XenForo sends a key's `scopes` as a map of name to `true` where its documentation
promises a list, and before 1.2.2 the package read only the list, so `hasScope()` was false
for every scoped key and this command failed a correct one. Found here on 2026-10-08 and
fixed upstream the same day; do not lower the constraint.

**It is the command to extend when a new dependency on the environment appears.** Four
markers, the same as the sibling tools: `[ ok ]`, `[warn]`, `[fail]`, and blank for a check
that did not apply. A skip is not a pass. Warnings exit 0.

## Conventions

- PSR-12 through Pint's defaults; `vendor/bin/pint` before committing.
- Configuration through `config()`, never `env()` outside `config/`. **Never an `env()` call
  in `config/app.php`**: `app:build` compiles that file as a literal.
- `Hampel\XenForo\Api\Laravel\XenForoServiceProvider` is listed by hand in `config/app.php`.
  Laravel Zero ignores package discovery; without the line `Http::fake()` misses and a test
  reaches a real forum. `tests/Feature/NoNetworkTest.php` fakes nothing to prove it holds.
- Assert on command output with `runCommand()` in `tests/Pest.php`, not
  `expectsOutputToContain()`.
- No committed file names a real forum, host or path. Fixtures use `forum.example.test` and
  `Acme/AddOnA`.

## Fixtures are captured, not written

**`tests/Fixtures/*.json` are real responses**, captured from a forum running the add-on on
2026-10-08, then trimmed and with add-on names replaced. A fixture written from the code that
consumes it can only agree with that code. To refresh one, capture the endpoint again and
apply the same replacement; do not edit a shape by hand to make a test pass.

The two `index-*.json` files are the exception: a real `index` response with the site's own
details replaced by hand.

## Known gaps

- **The MCP component's `make:mcp-*` and `mcp:inspector` commands are still listed**, in the
  compiled binary too. `remove` in `config/commands.php` cannot reach them: it matches on
  class and these are registered lazily. Two attempts to filter them in `App\Kernel` did not
  take. They are harmless and useless there.
- **A compiled binary run from `/` prints its default inventory path as `//forums.toml`.**
  Cosmetic; `app:validate` warns about that path regardless.
- **No release workflow.** CI runs `composer check` and nothing publishes a binary.
