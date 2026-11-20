#!/usr/bin/env bash
# Smoke checks against a running mount. Usage: ./smoke.sh https://example.com solutions/kairos/docs
# Verifies the things that broke in practice: trailing-slash redirects and that every asset the page references loads.
set -euo pipefail
BASE="${1:?base url}"; PREFIX="${2:?mount prefix}"
fail=0
check() { local want="$1" got="$2" what="$3"; if [ "$want" = "$got" ]; then echo "ok   $what"; else echo "FAIL $what (want $want, got $got)"; fail=1; fi; }
check 301 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$PREFIX")" "mount root without slash redirects"
check 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$PREFIX/")" "mount root with slash"
check 404 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$PREFIX/does-not-exist/")" "unknown page is 404"
python3 - "$BASE/$PREFIX" <<'PY' || fail=1
import sys,re,urllib.request,urllib.parse
r=urllib.request.urlopen(sys.argv[1]); final=r.geturl(); html=r.read().decode()
refs=sorted(set(re.findall(r'(?:href|src)="([^"#]+\.(?:css|js|png|svg|json|woff2?))"',html)))
bad=[]
for ref in refs:
    u=urllib.parse.urljoin(final,ref)
    if not u.startswith(sys.argv[1].split('/',3)[0]+'//'+sys.argv[1].split('/')[2]): continue
    try: urllib.request.urlopen(urllib.request.Request(u,method='HEAD'))
    except Exception as e: bad.append((getattr(e,'code','?'),u))
print(f"{'ok  ' if not bad else 'FAIL'} {len(refs)} asset references resolve from the slash-less URL", bad or '')
sys.exit(1 if bad else 0)
PY
exit $fail
