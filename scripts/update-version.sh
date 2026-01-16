#!/bin/bash

# Update VERSION file in development
# Run this after creating new tags

git describe --tags > VERSION
echo "VERSION file updated: $(cat VERSION)"