#!/usr/bin/env bash
# Real-WordPress smoke test of this plugin, locally, without Docker / wp-cli:
# WordPress Playground CLI (WASM, latest WP, PHP 8.3, WP_DEBUG on).
#
# Usage (Git Bash, from anywhere):  bash bin/smoke-test.sh
#   SMOKE_SCRATCH=/some/dir  override scratch dir (default: $TMPDIR/wp-mlp-smoke-test)
#
# What it does:
#   1. Copies only the runtime files (wp-mlp.php, uninstall.php, readme.txt, src/,
#      assets/, languages/) into the scratch dir; nothing is written into the plugin tree.
#   2. Reuses the pinned @wp-playground/cli installed by bin/plugin-check.sh when present,
#      otherwise installs it into the scratch dir.
#   3. Runs bin/smoke-test-blueprint.json, whose runPHP step executes bin/smoke-test.php:
#      activation/tables, a post, front-end render in the default language and /en/,
#      every GET route of the mlp/v1 REST namespace, a manual translation + unsafe href
#      rejection, the %i-based repository queries, the bundled ru_RU translation,
#      debug.log scan, and finally the uninstall path.
#   4. Prints a PASS/FAIL line per check; exit code 1 if any check failed.
#      Full JSON result: $SCRATCH/out/smoke.json
# First run downloads WordPress (~1-2 min). Needs Node 24 and internet.
set -euo pipefail

PLAYGROUND_VERSION="3.1.58"
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$HERE")"
SCRATCH="${SMOKE_SCRATCH:-${TMPDIR:-/tmp}/wp-mlp-smoke-test}"
mkdir -p "$SCRATCH"
SCRATCH="$(cd "$SCRATCH" && pwd)"
win() { if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else echo "$1"; fi; }

# 1. clean runtime copy
CLEAN="$SCRATCH/clean/wp-mlp"
rm -rf "$SCRATCH/clean" "$SCRATCH/out"
mkdir -p "$CLEAN" "$SCRATCH/out"
for item in wp-mlp.php uninstall.php readme.txt src assets languages; do
	[ -e "$ROOT/$item" ] && cp -r "$ROOT/$item" "$CLEAN/"
done
cp "$HERE/smoke-test.php" "$HERE/smoke-test-render.php" "$SCRATCH/out/"

# 2. playground cli (reuse the plugin-check install if there is one)
TOOL="$SCRATCH/tool"
PC_TOOL="${TMPDIR:-/tmp}/wp-mlp-plugin-check/tool"
if [ -f "$PC_TOOL/node_modules/@wp-playground/cli/cli.js" ]; then
	TOOL="$PC_TOOL"
elif [ ! -f "$TOOL/node_modules/@wp-playground/cli/cli.js" ]; then
	mkdir -p "$TOOL"
	echo '{}' > "$TOOL/package.json"
	(cd "$TOOL" && npm install --no-audit --no-fund "@wp-playground/cli@$PLAYGROUND_VERSION" >/dev/null 2>&1)
fi

# 3. run
LOG="$SCRATCH/run.log"
(cd "$TOOL" && node node_modules/@wp-playground/cli/cli.js run-blueprint \
	--blueprint="$(win "$HERE/smoke-test-blueprint.json")" \
	--mount-dir "$(win "$CLEAN")" /wordpress/wp-content/plugins/wp-mlp \
	--mount-dir "$(win "$SCRATCH/out")" /out) >"$LOG" 2>&1 || { echo "Playground failed, see $LOG"; tail -n 20 "$LOG"; exit 1; }

RESULT="$SCRATCH/out/smoke.json"
[ -s "$RESULT" ] || { echo "No result produced; see $LOG"; tail -n 20 "$LOG"; exit 1; }

# 4. summary
SUMMARY_JS="$SCRATCH/summary.js"
cat > "$SUMMARY_JS" <<'JS'
const fs = require('fs');
const r = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
let fail = 0;
for (const c of r.checks) {
  if (!c.ok) fail++;
  console.log((c.ok ? ((c.note || '').startsWith('SKIP:') ? 'SKIP ' : 'PASS ') : 'FAIL ') + c.name + (c.note ? ' - ' + c.note : ''));
}
if (r.log_lines && r.log_lines.length) {
  console.log('debug.log lines of interest (' + r.log_lines.length + '):');
  r.log_lines.slice(0, 30).forEach((l) => console.log('  ' + l));
}
console.log(fail ? 'SMOKE TEST FAILED (' + fail + ')' : 'SMOKE TEST OK');
process.exit(fail ? 1 : 0);
JS
node "$(win "$SUMMARY_JS")" "$(win "$RESULT")"
