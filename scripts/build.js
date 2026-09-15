'use strict';

// NOTE: Any npm package this script (or a package it calls, like esbuild)
// needs at build time must stay in package.json's "dependencies", not
// "devDependencies". Deploy runs `npm ci --omit=dev` before invoking this
// script (see scripts/server-hooks/post-receive, ADR-018), which deletes
// devDependencies entirely.
const esbuild = require('esbuild');
const fs = require('fs');

const jsFiles = [
  'app/assets/js/api-client.js',
  'app/assets/js/statistics.js',
  'app/assets/js/location-picker.js',
  'app/assets/js/car_details.js',
  'app/assets/js/imagedisplay.js',
  'app/assets/js/highlightDifferences.js',
  'app/assets/js/model-loader.js',
  'app/assets/js/car-showcase.js',
  'app/assets/js/car-list.js',
  'app/assets/js/factory-list.js',
  'app/assets/js/car-details-map.js',
  'app/assets/js/car-edit.js',
  'app/assets/js/contact-form.js',
  'app/assets/js/join-form-beacon.js',
  'app/assets/js/turnstile-reset.js',
  'app/admin/assets/admin-core.js',
  'app/admin/assets/backup-operations.js',
  'app/admin/assets/js/design-system.js',
  'app/admin/assets/js/tab-manage-cars.js',
  'app/admin/assets/js/tab-account-cleanup.js',
  'app/admin/assets/js/tab-owner-mgmt.js',
  'app/admin/assets/js/tab-verification.js',
  'app/admin/assets/js/load-owner-profile.js',
];

const cssFiles = [
  'app/assets/css/edit_car.css',
  'app/assets/css/location-picker.css',
  'app/assets/css/document-content.css',
  'app/admin/assets/admin-core.css',
];

const vendorFiles = [
  ['node_modules/filepond/dist/filepond.min.js', 'usersc/js/filepond.min.js'],
  ['node_modules/filepond/dist/filepond.min.css', 'usersc/css/filepond.min.css'],
  ['node_modules/filepond-plugin-image-exif-orientation/dist/filepond-plugin-image-exif-orientation.min.js', 'usersc/js/filepond-plugin-image-exif-orientation.min.js'],
  ['node_modules/filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.min.js', 'usersc/js/filepond-plugin-file-validate-type.min.js'],
  ['node_modules/filepond-plugin-file-validate-size/dist/filepond-plugin-file-validate-size.min.js', 'usersc/js/filepond-plugin-file-validate-size.min.js'],
  ['node_modules/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.js', 'usersc/js/filepond-plugin-image-preview.min.js'],
  ['node_modules/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css', 'usersc/css/filepond-plugin-image-preview.min.css'],
  ['node_modules/filepond-plugin-image-resize/dist/filepond-plugin-image-resize.min.js', 'usersc/js/filepond-plugin-image-resize.min.js'],
  ['node_modules/filepond-plugin-image-transform/dist/filepond-plugin-image-transform.min.js', 'usersc/js/filepond-plugin-image-transform.min.js'],
];

Promise.all([
  ...jsFiles.map(f => esbuild.build({ entryPoints: [f], minify: true, outfile: f.replace(/\.js$/, '.min.js') })),
  ...cssFiles.map(f => esbuild.build({ entryPoints: [f], minify: true, outfile: f.replace(/\.css$/, '.min.css') })),
]).then(async () => {
  console.log(`Built ${jsFiles.length + cssFiles.length} files.`);

  // usersc/js and usersc/css are gitignored build output (ADR-018) — they
  // won't exist on a fresh clone/checkout, so create them before writing.
  fs.mkdirSync('usersc/js', { recursive: true });
  fs.mkdirSync('usersc/css', { recursive: true });

  for (const [src, dest] of vendorFiles) {
    fs.copyFileSync(src, dest);
  }
  console.log(`Copied ${vendorFiles.length} vendor files.`);

  // Bundle MapLibre GL JS as a flat global (6.x ships ESM-only, no UMD
  // bundle) plus its co-located worker file and CSS.
  await esbuild.build({
    entryPoints: ['scripts/maplibre-entry.mjs'],
    bundle: true,
    format: 'iife',
    globalName: 'maplibregl',
    minify: true,
    outfile: 'usersc/js/maplibre-gl.min.js',
  });
  // .js (not .mjs) — some servers (e.g. MAMP/Apache with no .mjs MIME
  // mapping, or serving .mjs with X-Content-Type-Options: nosniff and no
  // Content-Type) silently hang Chrome's `type: 'module'` Worker constructor
  // forever (map never renders, no console error). MapLibre's worker bundle
  // itself imports a second sibling chunk (maplibre-gl-shared.mjs) — that
  // chunk must be copied under the same .js rename, and the worker's own
  // import statement rewritten to match, or the worker's internal module
  // import 404s.
  const workerSrc = fs.readFileSync('node_modules/maplibre-gl/dist/maplibre-gl-worker.mjs', 'utf8');
  // Collect the sibling chunk names referenced by relative imports (e.g.
  // "./maplibre-gl-shared.mjs" -> "maplibre-gl-shared") from the *original*
  // source before any rewriting, so the assertion below checks something the
  // replace hasn't already normalized away. Checking the post-replace string
  // with the same pattern used to produce it is a no-op — it can never fail.
  const referencedChunks = [...workerSrc.matchAll(/\.\/([\w-]+)\.mjs/g)].map(m => m[1]).sort();
  const expectedChunks = ['maplibre-gl-shared'];
  if (referencedChunks.join(',') !== expectedChunks.join(',')) {
    throw new Error(
      `maplibre-gl-worker.mjs references sibling chunk(s) [${referencedChunks.join(', ')}], ` +
      `expected [${expectedChunks.join(', ')}] — MapLibre likely changed its worker chunk ` +
      'layout; update scripts/build.js to vendor the new chunk(s) under .js names (see git ' +
      'history for why .mjs breaks on MAMP/Apache).'
    );
  }
  const rewrittenWorkerSrc = workerSrc
    .replace(/\.\/([\w-]+)\.mjs/g, './$1.js')
    // Strip the trailing sourcemap comment — it points at a .mjs.map file
    // that isn't vendored, which would otherwise 404 in browser devtools.
    .replace(/\n?\/\/# sourceMappingURL=.*\.mjs\.map\s*$/, '\n');
  fs.writeFileSync('usersc/js/maplibre-gl-worker.js', rewrittenWorkerSrc);
  const sharedSrc = fs.readFileSync('node_modules/maplibre-gl/dist/maplibre-gl-shared.mjs', 'utf8');
  fs.writeFileSync(
    'usersc/js/maplibre-gl-shared.js',
    sharedSrc.replace(/\n?\/\/# sourceMappingURL=.*\.mjs\.map\s*$/, '\n')
  );
  fs.copyFileSync('node_modules/maplibre-gl/dist/maplibre-gl.css', 'usersc/css/maplibre-gl.css');
  console.log('Copied MapLibre GL JS assets.');

  // Copy Chart.js self-hosted asset
  fs.copyFileSync('node_modules/chart.js/dist/chart.umd.min.js', 'usersc/js/chart.umd.min.js');
  console.log('Copied Chart.js asset.');

  // DataTables: each -bs5 package is a thin Bootstrap 5 styling wrapper —
  // the functional code lives in the corresponding non-bs5 core package.
  // Concatenate core + bs5 wrapper per extension (each is a self-contained
  // UMD IIFE, safe to concatenate) so app pages keep loading one script tag
  // per extension. Only Core, FixedHeader, and Responsive are used in app
  // JS (see ADR-011) — Buttons/ColVis are intentionally excluded.
  const concatFiles = (sources, dest) =>
    fs.writeFileSync(dest, sources.map(src => fs.readFileSync(src, 'utf8')).join(''));

  concatFiles(
    [
      'node_modules/datatables.net/js/dataTables.min.js',
      'node_modules/datatables.net-bs5/js/dataTables.bootstrap5.min.js',
    ],
    'usersc/js/datatables.min.js'
  );
  concatFiles(
    [
      'node_modules/datatables.net-fixedheader/js/dataTables.fixedHeader.min.js',
      'node_modules/datatables.net-fixedheader-bs5/js/fixedHeader.bootstrap5.min.js',
    ],
    'usersc/js/datatables-fixedheader.min.js'
  );
  concatFiles(
    [
      'node_modules/datatables.net-responsive/js/dataTables.responsive.min.js',
      'node_modules/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js',
    ],
    'usersc/js/datatables-responsive.min.js'
  );
  concatFiles(
    [
      'node_modules/datatables.net-bs5/css/dataTables.bootstrap5.min.css',
      'node_modules/datatables.net-fixedheader-bs5/css/fixedHeader.bootstrap5.min.css',
      'node_modules/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css',
    ],
    'usersc/css/datatables.min.css'
  );
  console.log('Vendored DataTables (core, fixedheader, responsive) assets.');

  // Generate VersaTiles Colorful style JSON
  const { colorful } = await import('@versatiles/style');
  const style = colorful({ baseUrl: 'https://tiles.versatiles.org', language: 'en' });
  fs.writeFileSync('usersc/js/versatiles-colorful.json', JSON.stringify(style));
  console.log('Generated usersc/js/versatiles-colorful.json');
}).catch((err) => {
  console.error('Build failed:', err?.stack ?? err?.message ?? err);
  process.exit(1);
});
