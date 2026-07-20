'use strict';

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
  'app/admin/assets/admin-core.js',
  'app/admin/assets/backup-operations.js',
  'app/admin/assets/js/design-system.js',
  'app/admin/assets/js/tab-manage-cars.js',
  'app/admin/assets/js/tab-account-cleanup.js',
  'app/admin/assets/js/tab-owner-mgmt.js',
  'app/admin/assets/js/tab-settings.js',
  'app/admin/assets/js/load-owner-profile.js',
];

const cssFiles = [
  'app/assets/css/edit_car.css',
  'app/assets/css/location-picker.css',
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

  for (const [src, dest] of vendorFiles) {
    fs.copyFileSync(src, dest);
  }
  console.log(`Copied ${vendorFiles.length} vendor files.`);

  // Copy MapLibre GL JS self-hosted assets
  fs.copyFileSync('node_modules/maplibre-gl/dist/maplibre-gl.js', 'usersc/js/maplibre-gl.min.js');
  fs.copyFileSync('node_modules/maplibre-gl/dist/maplibre-gl.css', 'usersc/css/maplibre-gl.css');
  console.log('Copied MapLibre GL JS assets.');

  // Generate VersaTiles Colorful style JSON
  const { colorful } = await import('@versatiles/style');
  const style = colorful({ baseUrl: 'https://tiles.versatiles.org', language: 'en' });
  fs.writeFileSync('usersc/js/versatiles-colorful.json', JSON.stringify(style));
  console.log('Generated usersc/js/versatiles-colorful.json');
}).catch((err) => {
  console.error('Build failed:', err?.message ?? err);
  process.exit(1);
});
