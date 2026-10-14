#!/usr/bin/env node
// Generates the Playground blueprint of a migration scenario (S2..S5) for bin/smoke-test.sh.
//   node bin/smoke-test-blueprint.cjs <S2|S3|S4|S5> <output.json>
// Each step is one PHP request in the same WordPress; phase bodies live in bin/smoke-test-migration.php,
// front-end renders are bin/smoke-test-render.php. S1 uses the static bin/smoke-test-blueprint.json.
'use strict';

const fs = require('fs');

const php = (phase) => ({
  step: 'runPHP',
  code: `<?php require '/wordpress/wp-load.php'; $GLOBALS['MLP_SMOKE_PHASE']='${phase}'; require '/out/smoke-test-migration.php';`,
});
const render = (name, uri) => ({
  step: 'runPHP',
  code: `<?php $_SERVER['REQUEST_URI']='${uri}'; $GLOBALS['MLP_SMOKE_PAGE']='${name}'; require '/out/smoke-test-render.php';`,
});

// The old plugin with data: activation, posts, one render (collects the strings), two saved translations.
const oldWithData = () => [
  php('old_activate'),
  php('old_data'),
  render('old_en_post', '/en/smoke-post/'),
  php('old_translate'),
];

// S6 phases live in bin/smoke-test-s6.php.
const s6 = (phase) => ({
  step: 'runPHP',
  code: `<?php require '/wordpress/wp-load.php'; $GLOBALS['MLP_SMOKE_PHASE']='${phase}'; require '/out/smoke-test-s6.php';`,
});

const scenarios = {
  S6: () => [
    { step: 'activatePlugin', pluginPath: 'lingocanvas/lingocanvas.php' },
    s6('setup'),
    render('ru_front', '/ru/smoke-s6/'),
    s6('check1'),
    s6('check_ru'),
    render('ru_front_after', '/ru/smoke-s6/'),
    s6('check2'),
  ],
  S2: () => [
    ...oldWithData(),
    php('migrate'),
    php('after_migrate'),
    render('new_default_front', '/'),
    render('new_en_front', '/en/'),
    render('new_en_post', '/en/smoke-post/'),
    php('after_render'),
  ],
  S3: () => [
    ...oldWithData(),
    php('both_force'),
    render('both_en_front', '/en/'),
    render('both_default_front', '/'),
    render('both_en_post', '/en/smoke-post/'),
    php('both_check'),
  ],
  S4: () => [...oldWithData(), php('migrate'), php('after_migrate'), php('delete_old'), php('after_delete_old')],
  S5: () => [...oldWithData(), php('delete_new'), php('after_delete_new')],
};

const [id, out] = process.argv.slice(2);
if (!scenarios[id] || !out) {
  console.error('usage: smoke-test-blueprint.cjs <S2|S3|S4|S5|S6> <output.json>');
  process.exit(2);
}

// Versions come from SMOKE_PHP / SMOKE_WP (bin/smoke-test.sh), defaults 8.3 / latest.
const phpVersion = process.env.SMOKE_PHP || '8.3';
const wpVersion = process.env.SMOKE_WP || 'latest';

const blueprint = {
  landingPage: '/',
  preferredVersions: { php: phpVersion, wp: wpVersion },
  // No Playground auto-login: it answers cookie-less requests (the simulated visitor) with a 302.
  login: false,
  steps: [
    // WP_DEBUG + WP_DEBUG_LOG for every request (defineWpConfigConsts is per PHP worker only).
    { step: 'runPHP', code: "<?php require '/out/smoke-test-wpconfig.php';" },
    { step: 'setSiteOptions', options: { permalink_structure: '/%postname%/' } },
    ...scenarios[id](),
  ],
};

fs.writeFileSync(out, JSON.stringify(blueprint, null, 2));
