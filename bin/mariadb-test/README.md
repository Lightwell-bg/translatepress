# MariaDB harness (real WordPress, real MariaDB)

Re-runnable scenarios on a real MariaDB with real WordPress. Not part of the plugin ZIP. It refuses to run unless
`DB_HOST` is `127.0.0.1:3307` and `DB_NAME` starts with `lc_` (see `guard.php`).

Status: the harness was cut down together with the 0.4.x prefix migration code (the plugin is stored under the fixed
`lingocanvas_*` names only) and was run and fixed afterwards (`fresh`, `upgrade`, and the data-preserving `snap` / `swap` /
`compare` / `uninstall` steps below). The 0.3.x/0.4.x migration, rollback and failure scenarios (u1-u6, u3cli, f1, f2, r1, r2)
and their seed scripts were removed on purpose.

Extra sites (created by hand outside the repo, copies of a migrated site: WordPress folder + database dump, `wp config set DB_NAME`):
`fresh` (port 8093, `lc_fresh`, reset-able), `u42` (8094, a migrated site with the 0.4.2 build in place; NOT reset-able) and
`u42un` (8095, a copy used for the uninstall step). Windows `curl.exe` does not read `/d/...` paths: files are always given
relative to the working directory.

Upgrade-with-data flow on `u42`: `admin u42` and `snap u42 before` (with 0.4.2) -> `swap u42` (working tree code) -> `admin u42`
-> `compare u42 before` (fingerprint of the `lingocanvas_*` / `mlp_*` tables without `last_seen_at`, options, `_lingocanvas_*`
meta, flag files, and status + body of up to 22 front-end URLs). `uninstall u42un` checks that only `lingocanvas_*` goes
(tables incl. `lingocanvas_bak_*`, options incl. `lingocanvas_bak_*_options`) and `mlp_*` stays byte-identical.

## Environment (outside the repo)

A portable MariaDB 10.11 on `127.0.0.1:3307` only, two WordPress sites and WP-CLI, all under `D:\LingoCanvasTestEnv`
(override with `LC_ENV=/d/...`): `env.sh` (shell helpers), `env-start.ps1` / `env-stop.ps1` (MariaDB),
`plugins/0.4.2/lingocanvas` (the 0.4.2 build for the upgrade scenario), `credentials.txt` (never in the repo).
Sites: `up0312` (port 8091) and `up0314` (port 8092).

## Usage (Git Bash)

```
bin/mariadb-test/run.sh all up0314       # reset + fresh + reset + upgrade
bin/mariadb-test/run.sh fresh up0314     # one step (reset first)
```

Steps:

- `reset` drops and recreates only the database `lc_<site>` and that site's wp-content data, installs WordPress;
- `fresh` installs this repository's code as a new plugin and activates it: exactly the four `lingocanvas_*` tables,
  `lingocanvas_db_version` = `Schema::VERSION`, default settings, no service options of earlier versions, only the
  `lingocanvas/v1` REST namespace, front page, sitemap and admin answer 200; then uninstall with the consent flag
  drops the tables and the `lingocanvas_*` options;
- `upgrade` installs the 0.4.2 build, activates it, saves the table row counts, replaces the folder by this repository's
  code, makes one visitor and one admin request and checks that the tables, row counts and settings are unchanged.

Open items for the next run: seed realistic data before `upgrade` (posts, translations, flags), compare front-end HTML
before and after, and add a check that the leftover 0.4.x service options (`lingocanvas_data_prefix`, `lingocanvas_migration`,
`lingocanvas_cleanup_*`, `lingocanvas_objtype_*`) are harmless and disappear on uninstall.

Output lines: `CHECK PASS|FAIL <id> :: evidence`, `INFO ...`; everything is also appended to
`$LC_ENV/results/<site>/run.log`.

## Files

- `guard.php` safety guard; `lib.php` check reporting and table helpers; `verify.php` checks that need the plugin loaded;
- `router.php` router for `php -S`; `run.sh` driver.
