#!/usr/bin/env bash
# Real-WordPress smoke test of this plugin, locally, without Docker / wp-cli:
# WordPress Playground CLI (WASM, latest WP, PHP 8.3, WP_DEBUG + WP_DEBUG_LOG on).
#
# Usage (Git Bash, from anywhere):  bash bin/smoke-test.sh
#   SMOKE_SCRATCH=/some/dir     override scratch dir (default: $TMPDIR/lingocanvas-smoke-test)
#   SMOKE_SCENARIOS="S1 S3"     run only some scenarios (default: S1 S2 S3 S4 S5)
#   SMOKE_OLD_ZIP=/path.zip     old "WP Multilang Press" build for S2-S5 (default: dist/_19wp-mlp.zip, folder wp-mlp/)
#
# Every scenario runs in a FRESH Playground instance (its own blueprint run, own scratch copies
# of the plugins, own /out dir) with WP_DEBUG and WP_DEBUG_LOG on:
#   S1  Fresh LingoCanvas: activation/tables, a post, front-end render in the default language and /en/,
#       every GET route of mlp/v1, manual translation + unsafe href rejection, %i repository queries,
#       bundled ru_RU translation, sitemap (200 XML with xhtml:link hreflang), [mlp_language_switcher]
#       link to /en/, canonical + hreflang on /en/, English and Russian UI + settings page in ru_RU,
#       AI translation through a mocked OpenAI endpoint (mu-plugin fixture, fake key, no network;
#       the key must never appear in responses), deprecated hook alias mlp_sitemap_post_ids
#       (both names run, deprecation logged), debug.log scan, finally the uninstall path.
#   S2  Migration from the real old plugin: data created through the old plugin, then
#       activate_plugin('lingocanvas/lingocanvas.php') in one request; next request: data, REST,
#       rewrite rules, routes registered once, /en/ renders.
#   S3  Both plugins active at once: old code runs, LingoCanvas is paused (notice), no duplicates.
#   S4  Deleting the old plugin (delete_data_on_uninstall = true) keeps the data.
#   S5  Deleting LingoCanvas while the old plugin is active keeps the data.
#
# What it does:
#   1. Copies only the runtime files (lingocanvas.php, uninstall.php, readme.txt, src/, assets/,
#      languages/) and unpacks the old plugin ZIP into the scratch dir; nothing is written into the
#      plugin tree. S1-S5 each get their own copy (delete_plugins() may remove a mounted folder).
#   2. Reuses the pinned @wp-playground/cli installed by bin/plugin-check.sh when present,
#      otherwise installs it into the scratch dir.
#   3. Runs the blueprints: bin/smoke-test-blueprint.json (S1) and bin/smoke-test-blueprint.cjs
#      (generates S2-S5). The runPHP steps execute bin/smoke-test.php (S1) / bin/smoke-test-migration.php
#      (S2-S5) with the helpers of bin/smoke-test-lib.php; front-end requests are simulated by
#      bin/smoke-test-render.php; the mu-plugin fixture bin/smoke-test-fixtures/mu-plugins/ is
#      installed into S1 only (blueprint step copies it from /out).
#   4. Prints a PASS/FAIL/SKIP line per check and a table of the scenarios; exit code 1 if any check
#      failed or a scenario produced no result. SKIP = environment limitation (Playground runs
#      WordPress on SQLite, where BINARY = UNHEX() lookups fail), the reason is printed.
#      Full JSON result per scenario: $SCRATCH/out/<S>/smoke.json, Playground log: $SCRATCH/run-<S>.log
# First run downloads WordPress (~1-2 min). Needs Node 24, unzip and internet.
set -euo pipefail

PLAYGROUND_VERSION="3.1.58"
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$HERE")"
SCRATCH="${SMOKE_SCRATCH:-${TMPDIR:-/tmp}/lingocanvas-smoke-test}"
SCENARIOS="${SMOKE_SCENARIOS:-S1 S2 S3 S4 S5}"
OLD_ZIP="${SMOKE_OLD_ZIP:-$ROOT/dist/_19wp-mlp.zip}"
mkdir -p "$SCRATCH"
SCRATCH="$(cd "$SCRATCH" && pwd)"
win() { if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else echo "$1"; fi; }
START_TS=$(date +%s)

# 1. clean sources: runtime copy of this plugin and the unpacked old plugin
rm -rf "$SCRATCH/clean" "$SCRATCH/old" "$SCRATCH/out" "$SCRATCH/run" "$SCRATCH"/run-*.log
mkdir -p "$SCRATCH/clean/lingocanvas" "$SCRATCH/old" "$SCRATCH/out" "$SCRATCH/run"
for item in lingocanvas.php uninstall.php readme.txt src assets languages; do
	[ -e "$ROOT/$item" ] && cp -r "$ROOT/$item" "$SCRATCH/clean/lingocanvas/"
done

case " $SCENARIOS " in
	*" S2 "*|*" S3 "*|*" S4 "*|*" S5 "*)
		[ -f "$OLD_ZIP" ] || { echo "Old plugin ZIP not found: $OLD_ZIP"; exit 1; }
		command -v unzip >/dev/null 2>&1 || { echo "unzip is required to unpack $OLD_ZIP"; exit 1; }
		unzip -q "$OLD_ZIP" -d "$SCRATCH/old"
		[ -f "$SCRATCH/old/wp-mlp/wp-mlp.php" ] || { echo "$OLD_ZIP has no wp-mlp/wp-mlp.php"; exit 1; }
		;;
esac

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
	cp "$HERE/smoke-test.php" "$HERE/smoke-test-migration.php" "$HERE/smoke-test-lib.php" "$HERE/smoke-test-render.php" "$HERE/smoke-test-wpconfig.php" "$out/"
	cp -r "$SCRATCH/clean/lingocanvas" "$run/lingocanvas"

	local mounts=(--mount-dir "$(win "$run/lingocanvas")" /wordpress/wp-content/plugins/lingocanvas)
	if [ "$id" = "S1" ]; then
		blueprint="$HERE/smoke-test-blueprint.json"
		# Mounting over wp-content/mu-plugins would hide Playground's own mu-plugin, so the fixture
		# travels through /out and the blueprint copies it into mu-plugins.
		cp "$HERE"/smoke-test-fixtures/mu-plugins/*.php "$out/"
	else
		blueprint="$run/blueprint.json"
		node "$(win "$HERE/smoke-test-blueprint.cjs")" "$id" "$(win "$blueprint")"
		cp -r "$SCRATCH/old/wp-mlp" "$run/wp-mlp"
		mounts+=(--mount-dir "$(win "$run/wp-mlp")" /wordpress/wp-content/plugins/wp-mlp)
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
