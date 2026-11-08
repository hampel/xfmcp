#!/usr/bin/env bash
#
# Start the server from a compiled binary, send it one initialize request, and
# fail unless every line it writes to stdout is a JSON-RPC message - and, when a
# version is given, unless the server reports that version.
#
#   .github/mcp-smoke.sh ./builds/xfmcp [expected-version]
#
# The inventory points at a file that does not exist, which is the setting most
# likely to make something print. The server exits at the end of its input.

set -euo pipefail

binary=$1
expected=${2:-}

request='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"ci","version":"1"}}}'

out=$(printf '%s\n' "$request" | XFMCP_FORUMS_PATH=/nonexistent/forums.toml timeout 30 "$binary" mcp:start xenforo 2>/dev/null)

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

version = json.loads(lines[0]).get("result", {}).get("serverInfo", {}).get("version")

if expected and version != expected:
    sys.exit("The server says [%s] but the tag is [%s]" % (version, expected))

print("%d JSON-RPC line(s), server version %s" % (len(lines), version))
' "$expected"
