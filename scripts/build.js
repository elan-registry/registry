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
  'app/admin/assets/manage-consolidated.js',
  'app/admin/assets/backup-operations.js',
];

const cssFiles = [
  'app/assets/css/edit_car.css',
  'app/assets/css/location-picker.css',
  'app/admin/assets/manage-consolidated.css',
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
]).then(() => {
  console.log(`Built ${jsFiles.length + cssFiles.length} files.`);

  for (const [src, dest] of vendorFiles) {
    fs.copyFileSync(src, dest);
  }
  console.log(`Copied ${vendorFiles.length} vendor files.`);
}).catch((err) => {
  console.error('Build failed:', err?.message ?? err);
  process.exit(1);
});
