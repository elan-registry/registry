# Elan Registry v2.26.0 Release Notes

**Release Date:** TBD
**Type:** Minor Release - Namespace Migration

## Required Actions After Deployment

None.

## Technical Changes

### Improvements

- **Autoloader test coverage** ([#1255](https://github.com/unibrain1/elanregistry/issues/1255)): Added `SeriesData` reference class and `ReflectionClass` path assertion to `AutoloaderTest`, ensuring the `ElanRegistry\Reference\` prefix mapping is verified against the correct directory.

## Issues Resolved

- [#1255](https://github.com/unibrain1/elanregistry/issues/1255) — AutoloaderTest: add path assertion for ElanRegistry\Reference\ prefix mapping
