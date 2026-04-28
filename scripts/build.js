'use strict';

const esbuild = require('esbuild');

const jsFiles = [
  'app/assets/js/api-client.js',
  'app/assets/js/statistics.js',
  'app/assets/js/location-picker.js',
  'app/assets/js/car_details.js',
  'app/assets/js/imagedisplay.js',
  'app/assets/js/highlightDifferences.js',
  'app/assets/js/model-loader.js',
  'app/admin/assets/manage-consolidated.js',
  'app/admin/assets/backup-operations.js',
];

const cssFiles = [
  'app/assets/css/edit_car.css',
  'app/assets/css/location-picker.css',
  'app/admin/assets/manage-consolidated.css',
];

Promise.all([
  ...jsFiles.map(f => esbuild.build({ entryPoints: [f], minify: true, outfile: f.replace(/\.js$/, '.min.js') })),
  ...cssFiles.map(f => esbuild.build({ entryPoints: [f], minify: true, outfile: f.replace(/\.css$/, '.min.css') })),
]).then(() => {
  console.log(`Built ${jsFiles.length + cssFiles.length} files.`);
}).catch((err) => {
  console.error('Build failed:', err?.message ?? err);
  process.exit(1);
});
