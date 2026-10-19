#!/usr/bin/env bash
# Driver of the MariaDB scenarios on real WordPress (see README.md in this folder).
#
#   bin/mariadb-test/run.sh <command> <site>
#
# Commands:
#   reset     drop and recreate the database lc_<site> and the site's wp-content data, install WordPress
#   fresh     install THIS code (the repo working tree) as a new plugin, activate it, verify tables/options/REST/front end, uninstall
#   upgrade   install the 0.4.2 build ($LC_ENV/plugins/0.4.2/lingocanvas), activate, save row counts, replace the folder by
#             THIS code, one admin request, verify nothing was lost
#   all       reset + fresh + reset + upgrade
#
# STATE: slimmed down after the 0.4.x migration code was removed. These scenarios were NOT run after that change:
# the first run has to be supervised and the checks adjusted if the environment helpers behave differently.
#
# Sites: up0312 (port 8091), up0314 (port 8092). Needs the local test environment (default D:\LingoCanvasTestEnv,
# override with LC_ENV=/d/...). Only talks to the dedicated MariaDB on 127.0.0.1:3307 and to databases named lc_*.

set -u
export MSYS_NO_PATHCONV=1

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LC_ENV="${LC_ENV:-/d/LingoCanvasTestEnv}"
# shellcheck disable=SC1091
. "$LC_ENV/env.sh"

RUNNER_WIN="$(cygpath -w "$REPO/bin/mariadb-test")"
RESULTS="$LC_ENV/results"
PLUGINS="$LC_ENV/plugins"
CMD="${1:-}"
SITE="${2:-}"

case "$SITE" in
	up0312) PORT=8091 ;;
	up0314) PORT=8092 ;;
	fresh) PORT=8093 ;;
	u42) PORT=8094 ;;
	u42un) PORT=8095 ;;
	*) echo "usage: run.sh <reset|fresh|upgrade|all|snap|compare|swap|uninstall> <up0312|up0314|u42|u42un>"; exit 2 ;;
esac

DB="lc_$SITE"
WP_DIR="$LC_ENV/wordpress/$SITE"
BASE="http://127.0.0.1:$PORT"
OUT="$RESULTS/$SITE"
mkdir -p "$OUT"
LOG="$OUT/run.log"
PW="$LC_TEST_PW"

# --- helpers ------------------------------------------------------------------------------------
say() { echo "== $*" | tee -a "$LOG"; }

# wp eval-file of a harness script: ev <site> <script> [args...]; output is logged and printed.
ev() {
	local site="$1" script="$2"; shift 2
	lcwp "$site" eval-file "$RUNNER_WIN\\$script" "$@" 2>&1 | tee -a "$LOG"
}

sql() { lcsql "$DB" -N -B -e "$1"; }

check() { # check <id> <ok 0/1> <detail>
	if [ "$2" = "0" ]; then echo "CHECK PASS $1 :: $3"; else echo "CHECK FAIL $1 :: $3"; fi | tee -a "$LOG"
}

# Logs in as admin and keeps the cookie jar in $OUT/jar.txt (curl is run inside $OUT).
admin_login() {
	cd "$OUT" || return 1
	rm -f jar.txt
	curl -s -c jar.txt -b jar.txt -H "Cookie: wordpress_test_cookie=WP%20Cookie%20check" \
		--data-urlencode "log=admin" --data-urlencode "pwd=$PW" --data-urlencode "wp-submit=Log In" \
		--data-urlencode "redirect_to=$BASE/wp-admin/" --data-urlencode "testcookie=1" \
		-o NUL -w "login HTTP %{http_code}\n" "$BASE/wp-login.php" | tee -a "$LOG"
}

http_code() { ( cd "$OUT" && curl -s -o NUL -w "%{http_code}" "$@" ); } # http_code <curl args>

debug_log_errors() { # count fatal-looking lines in debug.log
	local f="$WP_DIR/wp-content/debug.log"
	[ -f "$f" ] || { echo 0; return; }
	# Lines caused by the WP-CLI phar itself (PHP 8.5 deprecations) are not the plugin's.
	grep -v "wp-cli.phar" "$f" | grep -c -E "PHP (Fatal|Parse|Warning|Notice|Deprecated)|WordPress database error" || true
}

# Copies the runtime files of this repository (the same set as the ZIP) into the site's plugin folder.
install_repo_code() {
	local dest="$WP_DIR/wp-content/plugins/lingocanvas" item
	rm -rf "$dest"; mkdir -p "$dest"
	for item in lingocanvas.php uninstall.php readme.txt src assets languages; do
		[ -e "$REPO/$item" ] && cp -r "$REPO/$item" "$dest/"
	done
}

# --- commands -----------------------------------------------------------------------------------
cmd_reset() {
	# Recreates ONE scenario site (its lc_<site> database and its wp-content data). Never touches MariaDB itself,
	# the other site, the downloads or the plugin builds.
	say "reset $SITE"
	lcunserve "$PORT" >/dev/null 2>&1
	# Only the scenario sites; u42 / u42un hold migrated data and are never reset.
	case "$DB" in lc_up03*|lc_fresh) ;; *) echo "refusing to reset database $DB"; exit 1 ;; esac
	lcsql -e "DROP DATABASE IF EXISTS \`${DB}\`; CREATE DATABASE \`${DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
	rm -rf "$WP_DIR/wp-content/uploads" "$WP_DIR/wp-content/plugins/lingocanvas" "$WP_DIR/wp-content/debug.log"
	lcwp "$SITE" core install --url="$BASE" --title="LC $SITE" --admin_user=admin --admin_password="$PW" --admin_email=admin@example.test --skip-email 2>&1 | tail -1 | tee -a "$LOG"
	lcwp "$SITE" rewrite structure '/%postname%/' 2>&1 | tee -a "$LOG"
}

cmd_fresh() {
	say "FRESH install of the repository code ($SITE)"
	install_repo_code
	lcwp "$SITE" plugin activate lingocanvas 2>&1 | tee -a "$LOG"
	lcserve "$SITE" "$PORT" | tee -a "$LOG"
	ev "$SITE" verify.php fresh FRESH
	check FRESH.front_page_200 "$([ "$(http_code "$BASE/")" = "200" ] && echo 0 || echo 1)" "GET / -> $(http_code "$BASE/")"
	check FRESH.sitemap_200 "$([ "$(http_code "$BASE/lingocanvas-sitemap.xml")" = "200" ] && echo 0 || echo 1)" "GET /lingocanvas-sitemap.xml -> $(http_code "$BASE/lingocanvas-sitemap.xml")"
	admin_login
	# http_code runs inside $OUT, so the cookie jar is a relative name (the native curl.exe does not read /d/... paths).
	check FRESH.admin_200 "$([ "$(http_code -b jar.txt "$BASE/wp-admin/index.php")" = "200" ] && echo 0 || echo 1)" "GET /wp-admin/index.php -> $(http_code -b jar.txt "$BASE/wp-admin/index.php")"
	# Content, string discovery (editors only: the default "discover_from_visitors" is off), REST save, /en/ serves it.
	local pid nonce body="$OUT/en.html"
	pid="$(lcwp "$SITE" post create --post_type=post --post_status=publish --post_title="Привет мир" --post_name=privet --post_content='<p>Это первый абзац теста.</p><p>[lingocanvas_language_switcher]</p>' --porcelain)"
	( cd "$OUT" && for u in / /privet/ /en/ /en/privet/; do curl -s -b jar.txt -o NUL "$BASE$u"; done )
	local sid
	sid="$(sql "SELECT id FROM wp_lingocanvas_sources WHERE source_text='Это первый абзац теста.' LIMIT 1")"
	check FRESH.string_discovered "$([ -n "$sid" ] && echo 0 || echo 1)" "source id for the first paragraph: ${sid:-none}"
	nonce="$(cd "$OUT" && curl -s -b jar.txt "$BASE/wp-admin/admin-ajax.php?action=rest-nonce")"
	( cd "$OUT" && curl -s -b jar.txt -X PUT -H "X-WP-Nonce: $nonce" -H "Content-Type: application/json" -d '{"translated_text":"This is the first paragraph of the test."}' -o NUL "$BASE/index.php?rest_route=/lingocanvas/v1/translations/$sid/en" )
	check FRESH.rest_save_needs_login "$([ "$(http_code -X PUT -H 'Content-Type: application/json' -d '{"translated_text":"x"}' "$BASE/index.php?rest_route=/lingocanvas/v1/translations/$sid/en")" = "401" ] && echo 0 || echo 1)" "anonymous PUT -> 401"
	# Default mode "manual": a post is shown on /en/ only after it is made visible there.
	( cd "$OUT" && curl -s -b jar.txt -X POST -H "X-WP-Nonce: $nonce" -H "Content-Type: application/json" -d "{\"ids\":[$pid],\"locale\":\"en\",\"visible\":true}" -o NUL "$BASE/index.php?rest_route=/lingocanvas/v1/posts/visibility" )
	( cd "$OUT" && curl -s -o en.html "$BASE/en/privet/" )
	check FRESH.en_serves_translation "$(grep -q 'This is the first paragraph of the test.' "$body" && echo 0 || echo 1)" "/en/privet/ contains the saved translation"
	check FRESH.shortcode_renders "$(grep -q 'mlp-language-switcher' "$body" && echo 0 || echo 1)" "[lingocanvas_language_switcher] output on /en/privet/"
	check FRESH.sitemap_has_en "$([ "$(curl -s "$BASE/lingocanvas-sitemap.xml" | grep -c '/en/')" -gt 0 ] && echo 0 || echo 1)" "sitemap lists /en/ URLs"
	lcwp "$SITE" eval 'ob_start(); the_widget( \LingoCanvas\Frontend\LanguageSwitcherWidget::class ); echo str_contains( ob_get_clean(), "language-switcher" ) ? "CHECK PASS FRESH.widget_renders :: the_widget output\n" : "CHECK FAIL FRESH.widget_renders :: empty\n";' 2>&1 | tee -a "$LOG"
	# Uninstall with the owner's consent flag: all plugin tables and options go.
	lcwp "$SITE" eval '$s = (array) get_option( "lingocanvas_settings", array() ); $s["delete_data_on_uninstall"] = true; update_option( "lingocanvas_settings", $s );' 2>&1 | tee -a "$LOG"
	lcwp "$SITE" plugin deactivate lingocanvas 2>&1 | tee -a "$LOG"
	lcwp "$SITE" plugin uninstall lingocanvas 2>&1 | tee -a "$LOG"
	check FRESH.uninstall_dropped_tables "$([ "$(sql "SHOW TABLES LIKE 'wp\\_lingocanvas\\_%'" | wc -l)" -eq 0 ] && echo 0 || echo 1)" "remaining plugin tables: $(sql "SHOW TABLES LIKE 'wp\\_lingocanvas\\_%'" | wc -l)"
	check FRESH.uninstall_removed_options "$([ "$(sql "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'lingocanvas\\_%'")" = "0" ] && echo 0 || echo 1)" "remaining lingocanvas_* options: $(sql "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'lingocanvas\\_%'")"
	say "FRESH done; debug.log problem lines: $(debug_log_errors)"
}

cmd_upgrade() {
	local build="$PLUGINS/0.4.2/lingocanvas"
	say "UPGRADE 0.4.2 -> repository code ($SITE)"
	if [ ! -d "$build" ]; then
		# Unpack the released build from dist/ once (the folder inside the ZIP is lingocanvas/).
		mkdir -p "$PLUGINS/0.4.2" && ( cd "$PLUGINS/0.4.2" && unzip -q -o "$REPO/dist/lingocanvas-0.4.2.zip" ) 2>/dev/null
	fi
	[ -d "$build" ] || { echo "no 0.4.2 build at $build (and no dist/lingocanvas-0.4.2.zip)"; exit 1; }
	rm -rf "$WP_DIR/wp-content/plugins/lingocanvas"
	cp -r "$build" "$WP_DIR/wp-content/plugins/lingocanvas"
	lcwp "$SITE" plugin activate lingocanvas 2>&1 | tee -a "$LOG"
	lcserve "$SITE" "$PORT" | tee -a "$LOG"
	# A visitor request and an admin request on the old build create strings and finish its own housekeeping.
	curl -s -o NUL "$BASE/"; curl -s -o NUL "$BASE/"
	admin_login
	( cd "$OUT" && curl -s -b jar.txt -o NUL "$BASE/wp-admin/index.php" )
	ev "$SITE" verify.php counts-save UPGRADE "$(cygpath -w "$OUT/upgrade_counts.json")"
	install_repo_code
	curl -s -o NUL "$BASE/"
	( cd "$OUT" && curl -s -b jar.txt -o NUL "$BASE/wp-admin/index.php" )
	ev "$SITE" verify.php upgrade UPGRADE "$(cygpath -w "$OUT/upgrade_counts.json")"
	check UPGRADE.front_page_200 "$([ "$(http_code "$BASE/")" = "200" ] && echo 0 || echo 1)" "GET / -> $(http_code "$BASE/")"
	say "UPGRADE done; debug.log problem lines: $(debug_log_errors)"
}

# Snapshot of the data and the front end of a site that already runs some plugin build:
#   snap <tag>   fingerprint of lingocanvas_* / mlp_* data (verify.php fp-save) + status and body of the sitemap URLs (max 20)
#                and a 404 page, as anonymous visitor, into $OUT/snap_<tag>/
snap_pages() {
	local tag="$1" dir="$OUT/snap_$1" url n=0
	rm -rf "$dir"; mkdir -p "$dir"
	{
		echo "$BASE/"
		echo "$BASE/en/does-not-exist-404/"
		curl -s "$BASE/lingocanvas-sitemap.xml" | grep -o '<loc>[^<]*</loc>' | sed 's/<[^>]*>//g' | sort -u
	} | awk '!seen[$0]++' | head -22 | tr -d '\r' | ( cd "$dir" && while read -r url; do
		n=$((n + 1))
		# relative -o: the native curl.exe does not understand /d/... paths
		curl -s -o "$n.body" -w "$url %{http_code}\n" "$url" >> index.txt
	done )
	ev "$SITE" verify.php fp-save "SNAP.$tag" "$(cygpath -w "$dir/fp.json")"
}

# compare <tag>: current state vs. snap_<tag>: data fingerprint (via verify.php) and every page (status + body).
compare_pages() {
	local tag="$1" dir="$OUT/snap_$1" now="$OUT/now_$1" n=0 url code diffs=0
	rm -rf "$now"; mkdir -p "$now"
	while read -r url code; do
		n=$((n + 1))
		( cd "$now" && curl -s -o "$n.body" -w "$url %{http_code}\n" "$url" >> index.txt )
	done < "$dir/index.txt"
	if cmp -s "$dir/index.txt" "$now/index.txt"; then check "COMPARE.$tag.statuses" 0 "$n URLs, same statuses"; else check "COMPARE.$tag.statuses" 1 "$(diff "$dir/index.txt" "$now/index.txt" | head -3)"; fi
	for url in $(seq 1 "$n"); do cmp -s "$dir/$url.body" "$now/$url.body" || { diffs=$((diffs + 1)); echo "  body differs: $(sed -n "${url}p" "$dir/index.txt")" | tee -a "$LOG"; }; done
	check "COMPARE.$tag.bodies" "$([ "$diffs" -eq 0 ] && echo 0 || echo 1)" "$n pages, $diffs differ"
	ev "$SITE" verify.php fp-compare "COMPARE.$tag" "$(cygpath -w "$dir/fp.json")"
}

cmd_all() {
	: > "$LOG"
	cmd_reset; cmd_fresh
	cmd_reset; cmd_upgrade
	say "SUMMARY"
	grep -c '^CHECK PASS' "$LOG" | sed 's/^/passed checks: /'
	grep -c '^CHECK FAIL' "$LOG" | sed 's/^/failed checks: /'
	grep '^CHECK FAIL' "$LOG" | cut -c1-300
}

# swap: replaces the plugin folder by this repository's code, then one admin request (the site must be served).
cmd_swap() {
	say "SWAP plugin folder -> repository code ($SITE)"
	install_repo_code
	lcserve "$SITE" "$PORT" | tee -a "$LOG"
}

# uninstall: owner's consent flag, deactivate, uninstall. Only lingocanvas_* data (tables incl. lingocanvas_bak_*, options)
# may go; the old mlp_* tables and options must stay exactly as they were.
cmd_uninstall() {
	say "UNINSTALL with consent ($SITE)"
	local mlp_t mlp_o
	mlp_t="$(sql "SELECT GROUP_CONCAT(table_name ORDER BY table_name) FROM information_schema.tables WHERE table_schema='$DB' AND table_name LIKE 'wp\\_mlp\\_%'")"
	mlp_o="$(sql "SELECT MD5(GROUP_CONCAT(CONCAT(option_name,'=',option_value) ORDER BY option_name)) FROM wp_options WHERE option_name LIKE 'mlp\\_%'")"
	local mlp_rows
	mlp_rows="$(for t in $(echo "$mlp_t" | tr ',' ' '); do sql "SELECT COUNT(*) FROM $t"; done | tr '\n' ',')"
	info="before: lingocanvas tables $(sql "SHOW TABLES LIKE 'wp\\_lingocanvas\\_%'" | wc -l), options $(sql "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'lingocanvas\\_%'")"
	say "$info"
	lcwp "$SITE" eval '$s = (array) get_option( "lingocanvas_settings", array() ); $s["delete_data_on_uninstall"] = true; update_option( "lingocanvas_settings", $s );' 2>&1 | tee -a "$LOG"
	lcwp "$SITE" plugin deactivate lingocanvas 2>&1 | tee -a "$LOG"
	lcwp "$SITE" plugin uninstall lingocanvas 2>&1 | tee -a "$LOG"
	check UNINSTALL.lingocanvas_tables_gone "$([ "$(sql "SHOW TABLES LIKE 'wp\\_lingocanvas\\_%'" | wc -l)" -eq 0 ] && echo 0 || echo 1)" "remaining wp_lingocanvas_* tables: $(sql "SHOW TABLES LIKE 'wp\\_lingocanvas\\_%'" | wc -l)"
	check UNINSTALL.lingocanvas_options_gone "$([ "$(sql "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'lingocanvas\\_%'")" = "0" ] && echo 0 || echo 1)" "remaining lingocanvas_* options: $(sql "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'lingocanvas\\_%'")"
	check UNINSTALL.mlp_tables_untouched "$([ "$(sql "SELECT GROUP_CONCAT(table_name ORDER BY table_name) FROM information_schema.tables WHERE table_schema='$DB' AND table_name LIKE 'wp\\_mlp\\_%'")" = "$mlp_t" ] && echo 0 || echo 1)" "tables: $mlp_t"
	check UNINSTALL.mlp_rows_untouched "$([ "$(for t in $(echo "$mlp_t" | tr ',' ' '); do sql "SELECT COUNT(*) FROM $t"; done | tr '\n' ',')" = "$mlp_rows" ] && echo 0 || echo 1)" "rows: $mlp_rows"
	check UNINSTALL.mlp_options_untouched "$([ "$(sql "SELECT MD5(GROUP_CONCAT(CONCAT(option_name,'=',option_value) ORDER BY option_name)) FROM wp_options WHERE option_name LIKE 'mlp\\_%'")" = "$mlp_o" ] && echo 0 || echo 1)" "mlp_* options hash $mlp_o"
}

# admin: logs in and opens the dashboard and the plugin's settings page; reports status and PHP problem lines.
cmd_admin() {
	admin_login
	local code page
	for page in index.php "admin.php?page=lingocanvas"; do
		code="$(cd "$OUT" && curl -s -b jar.txt -o "admin_$$.html" -w "%{http_code}" "$BASE/wp-admin/$page")"
		check "ADMIN.$page" "$([ "$code" = "200" ] && echo 0 || echo 1)" "HTTP $code"
	done
	# The settings page must render the form (not a fatal error page).
	check ADMIN.settings_form "$(grep -q 'lingocanvas_settings\|name="lingocanvas' "$OUT/admin_$$.html" && echo 0 || echo 1)" "settings form markup present"
	rm -f "$OUT/admin_$$.html"
}

case "$CMD" in
	admin) cmd_admin ;;
	snap) snap_pages "${3:-before}" ;;
	compare) compare_pages "${3:-before}" ;;
	swap) cmd_swap ;;
	uninstall) cmd_uninstall ;;
	reset) cmd_reset ;;
	fresh) cmd_fresh ;;
	upgrade) cmd_upgrade ;;
	all) cmd_all ;;
	*) echo "commands: reset fresh upgrade all"; exit 2 ;;
esac
