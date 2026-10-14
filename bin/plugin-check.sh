#!/usr/bin/env bash
# Run the official WordPress Plugin Check (PCP) against this plugin, locally,
# without Docker / composer / wp-cli: WordPress Playground CLI (WASM) does it.
#
# Usage (Git Bash, from anywhere):  bash bin/plugin-check.sh
#   PCP_SCRATCH=/some/dir  override scratch dir (default: $TMPDIR/lingocanvas-plugin-check)
#
# What it does:
#   1. Builds a clean runtime copy (lingocanvas.php, uninstall.php, src/, assets/,
#      languages/, readme.txt if present) in the scratch dir. Nothing is written
#      into the plugin tree.
#   2. Installs pinned @wp-playground/cli into the scratch dir (cached).
#   3. Runs bin/plugin-check-blueprint.json: installs pinned Plugin Check 2.1.0,
#      patches one PHPCS call (LOCK_EX is unsupported in Playground's VFS; this
#      only touches the throw-away Plugin Check copy), runs
#      `wp plugin check lingocanvas --categories=plugin_repo --format=json`.
#   4. Prints a summary and the report path ($SCRATCH/out/report.json).
# First run downloads WordPress + Plugin Check (~1-2 min). Needs node + internet.
# Note: the pinned Playground CLI declares node >=24.18 but works on 24.15.
set -euo pipefail

PLAYGROUND_VERSION="3.1.58"
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$HERE")"
SCRATCH="${PCP_SCRATCH:-${TMPDIR:-/tmp}/lingocanvas-plugin-check}"
mkdir -p "$SCRATCH"
SCRATCH="$(cd "$SCRATCH" && pwd)"
win() { if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else echo "$1"; fi; }

# 1. clean copy
CLEAN="$SCRATCH/clean/lingocanvas"
rm -rf "$SCRATCH/clean" "$SCRATCH/out"
mkdir -p "$CLEAN" "$SCRATCH/out"
for item in lingocanvas.php uninstall.php readme.txt src assets languages; do
	[ -e "$ROOT/$item" ] && cp -r "$ROOT/$item" "$CLEAN/"
done

# 2. playground cli
TOOL="$SCRATCH/tool"
if [ ! -f "$TOOL/node_modules/@wp-playground/cli/cli.js" ]; then
	mkdir -p "$TOOL"
	echo '{}' > "$TOOL/package.json"
	(cd "$TOOL" && npm install --no-audit --no-fund "@wp-playground/cli@$PLAYGROUND_VERSION" >/dev/null 2>&1)
fi

# 3. run
LOG="$SCRATCH/run.log"
(cd "$TOOL" && node node_modules/@wp-playground/cli/cli.js run-blueprint \
	--blueprint="$(win "$HERE/plugin-check-blueprint.json")" \
	--mount-dir "$(win "$CLEAN")" /wordpress/wp-content/plugins/lingocanvas \
	--mount-dir "$(win "$SCRATCH/out")" /out) >"$LOG" 2>&1 || { echo "Playground failed, see $LOG"; exit 1; }

REPORT="$SCRATCH/out/report.json"
[ -s "$REPORT" ] || { echo "No report produced (clean = no findings, or failure); see $LOG"; exit 1; }

# 4. summary (report is `FILE: name` lines followed by a JSON array per file)
SUMMARY_JS="$SCRATCH/summary.js"
cat > "$SUMMARY_JS" <<'JS'
const fs = require('fs');
const lines = fs.readFileSync(process.argv[2], 'utf8').split(/\r?\n/);
let file = '', rows = [];
for (const l of lines) {
  if (l.startsWith('FILE: ')) { file = l.slice(6).trim(); continue; }
  if (l.startsWith('[')) { try { for (const r of JSON.parse(l)) rows.push({ ...r, file }); } catch (e) {} }
}
const t = { ERROR: 0, WARNING: 0 }, codes = {};
for (const r of rows) { t[r.type] = (t[r.type] || 0) + 1; const k = r.type + ' ' + r.code; codes[k] = (codes[k] || 0) + 1; }
const parsed = lines.filter((l) => l.startsWith('FILE: ') || l.startsWith('[')).length;
if (parsed === 0) { console.error('Report is malformed: no FILE:/JSON lines found'); process.exit(2); }
console.log('ERROR=' + t.ERROR + ' WARNING=' + t.WARNING);
Object.entries(codes).sort((a, b) => b[1] - a[1]).slice(0, 40).forEach(([k, n]) => console.log(String(n).padStart(5), k));
process.exit((t.ERROR || 0) > 0 ? 1 : 0);
JS
# exit codes: 0 = no errors, 1 = errors found, 2 = report missing/malformed
rc=0
node "$(win "$SUMMARY_JS")" "$(win "$REPORT")" || rc=$?
echo "Report: $REPORT"
exit "$rc"
