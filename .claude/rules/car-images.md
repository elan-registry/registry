---
paths:
  - "usersc/classes/Car/**"
  - "usersc/classes/Resize.php"
  - "app/api/cars/**"
  - "app/owner/cars/**"
  - "app/admin/**/*image*"
---

# Car image storage

- `cars.image` is a JSON array of bare filenames (e.g. `["abc123.jpg"]`).
- Files live at `userimages/{carid}/{filename}`. Resized variants are
  `{basename}-resized-{size}.{ext}` (sizes: 100, 300, 768, 1024, 2048).
- New uploads land in `userimages/temp/` and move to `userimages/{carid}/` on
  success.
- On car merge, `CarImageRelocator` moves all files (base and variants) from
  the source car's directory to the target's, renames on collision, and
  appends the source's filenames to the target's `cars.image`.
- Use `CarImageProcessor` to decode images, `CarRepository::updateImage()` to
  write image data, and `CarImageRelocator` to move image files during a merge.
