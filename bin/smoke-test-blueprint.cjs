#!/usr/bin/env node
// Generates the Playground blueprint of scenario S6 ("Interface" tab) for bin/smoke-test.sh.
//   node bin/smoke-test-blueprint.cjs <S6> <output.json>
// Each step is one PHP request in the same WordPress; phase bodies live in bin/smoke-test-s6.php,
// front-end renders are bin/smoke-test-render.php. S1 uses the static bin/smoke-test-blueprint.json.
'use strict';

const fs = require('fs');

const render = (name, uri) => ({
  step: 'runPHP',
  code: `<?php $_SERVER['REQUEST_URI']='${uri}'; $GLOBALS['MLP_SMOKE_PAGE']='${name}'; require '/out/smoke-test-render.php';`,
});

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
};

const [id, out] = process.argv.slice(2);
if (!scenarios[id] || !out) {
  console.error('usage: smoke-test-blueprint.cjs <S6> <output.json>');
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
