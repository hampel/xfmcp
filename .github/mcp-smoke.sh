#!/usr/bin/env bash
#
# Start the server from a compiled binary, send it initialize and one tools/call,
# and fail unless every line it writes to stdout is a JSON-RPC message, the tool
# call is answered with a result rather than a JSON-RPC error - and, when a
# version is given, unless the server reports that version.
#
#   .github/mcp-smoke.sh ./builds/xfmcp [expected-version]
#
# The inventory points at a file that does not exist, which is the setting most
# likely to make something print. The server exits at the end of its input.
#
# The tool call is list-forums, which reads the inventory and contacts no forum.
# Its answer here is a tool error about the missing file, and that is a pass: it
# is a result. initialize alone is not enough, because it still succeeds when
# every tool call fails - laravel/mcp 1.2 without the validator did exactly that.

set -euo pipefail

binary=$1
expected=${2:-}

initialize='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"ci","version":"1"}}}'
initialized='{"jsonrpc":"2.0","method":"notifications/initialized"}'
call='{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"list-forums","arguments":{}}}'

out=$(printf '%s\n' "$initialize" "$initialized" "$call" | XFMCP_FORUMS_PATH=/nonexistent/forums.toml timeout 30 "$binary" mcp:start xenforo 2>/dev/null)

printf '%s\n' "$out" | python3 -c '
import json, sys

expected = sys.argv[1]
lines = [line for line in sys.stdin.read().splitlines() if line != ""]

if not lines:
    sys.exit("The server wrote nothing to stdout")

for line in lines:
    try:
        message = json.loads(line)
    except ValueError:
        sys.exit("Not JSON-RPC on stdout: " + line[:200])
    if message.get("jsonrpc") != "2.0":
        sys.exit("Not JSON-RPC on stdout: " + line[:200])

messages = {m.get("id"): m for m in map(json.loads, lines) if "id" in m}

if 1 not in messages or 2 not in messages:
    sys.exit("The server did not answer both initialize and tools/call")

if "result" not in messages[2]:
    sys.exit("tools/call was answered with a JSON-RPC error: " + json.dumps(messages[2].get("error"))[:200])

version = messages[1].get("result", {}).get("serverInfo", {}).get("version")

if expected and version != expected:
    sys.exit("The server says [%s] but the tag is [%s]" % (version, expected))

print("%d JSON-RPC line(s), server version %s, tools/call answered" % (len(lines), version))
' "$expected"
