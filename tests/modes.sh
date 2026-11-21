#!/usr/bin/env bash
# Checks the two content modes against a running test environment (tests/run.sh up).
# A filtered mount must not output scripts, event handlers, javascript: links, frames or forms; a trusted mount prints the page as it is.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
BASE="${1:-http://localhost:8089}"
fail=0
has()  { grep -qF -- "$2" <<<"$1"; }
yes()  { if has "$1" "$2"; then echo "ok   $3"; else echo "FAIL $3 (missing: $2)"; fail=1; fi; }
no()   { if has "$1" "$2"; then echo "FAIL $3 (found: $2)"; fail=1; else echo "ok   $3"; fi; }

"$HERE/run.sh" wp stasimo add modes-filtered --prefix=modes/filtered --preset=generic >/dev/null
"$HERE/run.sh" wp stasimo add modes-trusted  --prefix=modes/trusted  --preset=generic --trusted >/dev/null
"$HERE/run.sh" wp stasimo deploy modes-filtered /fixtures/hostile-docs >/dev/null
"$HERE/run.sh" wp stasimo deploy modes-trusted  /fixtures/hostile-docs >/dev/null

F="$(curl -s "$BASE/modes/filtered/")"
T="$(curl -s "$BASE/modes/trusted/")"

echo "--- filtered mount"
no  "$F" 'headScript'        "head script removed"
no  "$F" 'bodyScript'        "body script removed (and its text does not leak)"
no  "$F" 'evilBody'          "onload on <body> removed"
no  "$F" 'evilHtml'          "onclick on <html> removed"
no  "$F" 'evilImg'           "onerror on <img> removed"
no  "$F" 'javascript:'         "javascript: link removed"
no  "$F" "alert('link')"     "javascript: stylesheet link ignored"
no  "$F" '<iframe'           "iframe removed"
no  "$F" '<form'             "form removed"
yes "$F" '<h1>Hello from a static file</h1>' "content kept"
yes "$F" 'https://example.com/ok'            "ordinary link kept"
yes "$F" '<path'                             "simple SVG kept"
yes "$F" '<table>'                           "table kept"
yes "$F" 'color:red'                         "inline style attribute kept"
yes "$F" "modes/filtered/assets/site.css"    "stylesheet link resolved and printed by WordPress"
yes "$F" '.fine'                             "inline <style> block kept"
yes "$F" '<title>Hostile page'               "title written by the plugin"
yes "$F" 'rel="canonical" href="'"$BASE"'/modes/filtered/"' "canonical written by the plugin"

echo "--- trusted mount"
yes "$T" 'headScript'        "head script kept"
yes "$T" 'bodyScript'        "body script kept"
yes "$T" 'evilBody'          "onload kept"
yes "$T" '<iframe'           "iframe kept"
yes "$T" '<h1>Hello from a static file</h1>' "content kept"

echo "--- trusted needs a deliberate choice"
S="$("$HERE/run.sh" wp eval 'echo var_export(Stasimo_Mounts::get("modes-filtered")["trusted"],true),",",var_export(Stasimo_Mounts::get("modes-trusted")["trusted"],true);')"
[ "$S" = "false,true" ] && echo "ok   stored flags: filtered=false trusted=true" || { echo "FAIL stored flags ($S)"; fail=1; }
exit $fail
