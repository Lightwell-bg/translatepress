#!/usr/bin/env bash
# Real-WordPress smoke test of this plugin, locally, without Docker / wp-cli:
# WordPress Playground CLI (WASM, latest WP, PHP 8.3, WP_DEBUG + WP_DEBUG_LOG on).
#
# Usage (Git Bash, from anywhere):  bash bin/smoke-test.sh
#   SMOKE_SCRATCH=/some/dir     override scratch dir (default: $TMPDIR/lingocanvas-smoke-test)
#   SMOKE_SCENARIOS="S1"        run only some scenarios (default: S1 S6)
#   SMOKE_WP=6.4                WordPress version of Playground (default: latest; e.g. 6.4, 6.9, latest)
#   SMOKE_PHP=8.1               PHP version (default: 8.3)
#
# Every scenario runs in a FRESH Playground instance (its own blueprint run, own scratch copy
# of the plugin, own /out dir) with WP_DEBUG and WP_DEBUG_LOG on:
#   S1  Fresh LingoCanvas: activation/tables (exactly the four lingocanvas_* tables, no service options),
#       a post, front-end render in the default language and /en/,
#       every GET route of lingocanvas/v1, manual translation + unsafe href rejection, %i repository queries,
#       bundled ru_RU translation, sitemap (200 XML with xhtml:link hreflang), [lingocanvas_language_switcher]
#       link to /en/, canonical + hreflang on /en/, English and Russian UI + settings page in ru_RU,
#       AI translation through a mocked OpenAI endpoint (mu-plugin fixture, fake key, no network;
#       the key must never appear in responses), the lingocanvas_sitemap_post_ids filter,
#       debug.log scan, finally the uninstall path.
#   S6  "Interface" tab: official translations of third-party gettext strings (fixture plugin
#       lingocanvas-smoke-domain with a Russian .mo: singular, context, 3 plural forms, untranslated string),
#       found through get_translations_for_domain() and not through the plugin's own gettext filters;
#       a saved user override does not leak into the "Official translation" column but shows on the front end;
#       the tab renders in en_US and ru_RU.
#
# What it does:
#   1. Copies only the runtime files (lingocanvas.php, uninstall.php, readme.txt, src/, assets/,
#      languages/) into the scratch dir; nothing is written into the plugin tree.
#      Each scenario gets its own copy (delete_plugins() may remove a mounted folder).
#   2. Reuses the pinned @wp-playground/cli installed by bin/plugin-check.sh when present,
#      otherwise installs it into the scratch dir.
#   3. Runs the blueprints: bin/smoke-test-blueprint.json (S1) and bin/smoke-test-blueprint.cjs
#      (generates S6). The runPHP steps execute bin/smoke-test.php (S1) / bin/smoke-test-s6.php
#      (S6) with the helpers of bin/smoke-test-lib.php; front-end requests are simulated by
#      bin/smoke-test-render.php; the mu-plugin fixture bin/smoke-test-fixtures/mu-plugins/ is
#      installed into S1 only (blueprint step copies it from /out).
#   4. Prints a PASS/FAIL/SKIP line per check and a table of the scenarios; exit code 1 if any check
#      failed or a scenario produced no result. SKIP = environment limitation (Playground runs
#      WordPress on SQLite, where BINARY = UNHEX() lookups fail), the reason is printed.
#      Full JSON result per scenario: $SCRATCH/out/<S>/smoke.json, Playground log: $SCRATCH/run-<S>.log
# First run downloads WordPress (~1-2 min). Needs Node 24 and internet.
set -euo pipefail

PLAYGROUND_VERSION="3.1.58"
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$HERE")"
SCRATCH="${SMOKE_SCRATCH:-${TMPDIR:-/tmp}/lingocanvas-smoke-test}"
SCENARIOS="${SMOKE_SCENARIOS:-S1 S6}"
# WordPress / PHP versions of the Playground instance (read by smoke-test-blueprint.cjs and the S1 patch below).
export SMOKE_WP="${SMOKE_WP:-latest}" SMOKE_PHP="${SMOKE_PHP:-8.3}"
mkdir -p "$SCRATCH"
SCRATCH="$(cd "$SCRATCH" && pwd)"
win() { if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else echo "$1"; fi; }
START_TS=$(date +%s)

# 1. clean sources: runtime copy of this plugin
rm -rf "$SCRATCH/clean" "$SCRATCH/out" "$SCRATCH/run" "$SCRATCH"/run-*.log
mkdir -p "$SCRATCH/clean/lingocanvas" "$SCRATCH/out" "$SCRATCH/run"
for item in lingocanvas.php uninstall.php readme.txt src assets languages; do
	[ -e "$ROOT/$item" ] && cp -r "$ROOT/$item" "$SCRATCH/clean/lingocanvas/"
done

# 2. playground cli (reuse the plugin-check install if there is one)
TOOL="$SCRATCH/tool"
PC_TOOL="${TMPDIR:-/tmp}/lingocanvas-plugin-check/tool"
if [ -f "$PC_TOOL/node_modules/@wp-playground/cli/cli.js" ]; then
	TOOL="$PC_TOOL"
elif [ ! -f "$TOOL/node_modules/@wp-playground/cli/cli.js" ]; then
	mkdir -p "$TOOL"
	echo '{}' > "$TOOL/package.json"
	(cd "$TOOL" && npm install --no-audit --no-fund "@wp-playground/cli@$PLAYGROUND_VERSION" >/dev/null 2>&1)
fi

# 3. run every scenario in a fresh Playground instance
run_scenario() {
	local id="$1"
	local out="$SCRATCH/out/$id" run="$SCRATCH/run/$id" log="$SCRATCH/run-$id.log" blueprint
	mkdir -p "$out" "$run"
	cp "$HERE/smoke-test.php" "$HERE/smoke-test-lib.php" "$HERE/smoke-test-render.php" "$HERE/smoke-test-wpconfig.php" "$out/"
	cp -r "$SCRATCH/clean/lingocanvas" "$run/lingocanvas"

	local mounts=(--mount-dir "$(win "$run/lingocanvas")" /wordpress/wp-content/plugins/lingocanvas)
	cp "$HERE/smoke-test-s6.php" "$out/"
	if [ "$id" = "S1" ]; then
		# The static blueprint gets the requested WordPress / PHP versions.
		blueprint="$run/blueprint.json"
		node -e 'const fs=require("fs");const b=JSON.parse(fs.readFileSync(process.argv[1],"utf8"));b.preferredVersions={php:process.env.SMOKE_PHP,wp:process.env.SMOKE_WP};fs.writeFileSync(process.argv[2],JSON.stringify(b,null,2));' "$(win "$HERE/smoke-test-blueprint.json")" "$(win "$blueprint")"
		# Mounting over wp-content/mu-plugins would hide Playground's own mu-plugin, so the fixture
		# travels through /out and the blueprint copies it into mu-plugins.
		cp "$HERE"/smoke-test-fixtures/mu-plugins/*.php "$out/"
	else
		blueprint="$run/blueprint.json"
		node "$(win "$HERE/smoke-test-blueprint.cjs")" "$id" "$(win "$blueprint")"
		cp "$HERE"/smoke-test-fixtures/s6/*.php "$out/"
	fi
	mounts+=(--mount-dir "$(win "$out")" /out)

	echo "== $id: Playground run started $(date +%H:%M:%S)"
	(cd "$TOOL" && node node_modules/@wp-playground/cli/cli.js run-blueprint \
		--blueprint="$(win "$blueprint")" "${mounts[@]}") >"$log" 2>&1 \
		|| { echo "== $id: Playground exited with an error, see $log"; tail -n 12 "$log"; return 0; }
}

for id in $SCENARIOS; do
	run_scenario "$id"
done

# 4. summary
SUMMARY_JS="$SCRATCH/summary.js"
cat > "$SUMMARY_JS" <<'JS'
const fs = require('fs');
const [dir, ...ids] = process.argv.slice(2);
let fail = 0, pass = 0, skip = 0;
const table = [];
for (const id of ids) {
  const file = `${dir}/${id}/smoke.json`;
  let r = null;
  try { r = JSON.parse(fs.readFileSync(file, 'utf8')); } catch (e) { /* no result */ }
  if (!r || !r.checks || !r.checks.length) {
    console.log(`\n===== ${id} =====\nFAIL ${id}: no result produced (Playground failed before writing ${file})`);
    fail++; table.push([id, 0, 0, 1]); continue;
  }
  console.log(`\n===== ${id} =====`);
  let p = 0, s = 0, f = 0;
  for (const c of r.checks) {
    const note = (c.note || '').length > 400 ? c.note.slice(0, 400) + '...' : (c.note || '');
    if (!c.ok) { f++; console.log('FAIL ' + c.name + (note ? ' - ' + note : '')); }
    else if (note.startsWith('SKIP:')) { s++; console.log('SKIP ' + c.name + ' - ' + note); }
    else { p++; console.log('PASS ' + c.name + (note ? ' - ' + note : '')); }
  }
  if (r.log_lines && r.log_lines.length) {
    console.log(`debug.log lines of interest (${r.log_lines.length}):`);
    r.log_lines.slice(0, 12).forEach((l) => console.log('  ' + l.slice(0, 300)));
  }
  pass += p; skip += s; fail += f; table.push([id, p, s, f]);
}
console.log('\n===== SUMMARY =====');
console.log('scenario  PASS  SKIP  FAIL');
for (const [id, p, s, f] of table) console.log(`${id.padEnd(8)}  ${String(p).padStart(4)}  ${String(s).padStart(4)}  ${String(f).padStart(4)}  ${f ? 'FAIL' : 'OK'}`);
console.log(fail ? `SMOKE TEST FAILED (${fail} failed, ${pass} passed, ${skip} skipped)` : `SMOKE TEST OK (${pass} passed, ${skip} skipped)`);
process.exit(fail ? 1 : 0);
JS
set +e
# shellcheck disable=SC2086
node "$(win "$SUMMARY_JS")" "$(win "$SCRATCH/out")" $SCENARIOS
STATUS=$?
set -e
echo "runtime: $(( $(date +%s) - START_TS ))s"
exit $STATUS
