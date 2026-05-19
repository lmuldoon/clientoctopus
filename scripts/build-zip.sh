#!/usr/bin/env bash
set -e

SLUG="clientoctopus"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
ZIP_NAME="${SLUG}.zip"

echo "Building ${SLUG}..."
cd "$PLUGIN_DIR"
npm run build

echo "Copying files..."
rsync -a \
  --exclude='.git' \
  --exclude='.gitignore' \
  --exclude='node_modules' \
  --exclude='scripts' \
  --exclude='*.zip' \
  --exclude='.DS_Store' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='webpack.config.js' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='*.log' \
  --exclude='.idea' \
  --exclude='.vscode' \
  --exclude='admin/components' \
  --exclude='admin/*.jsx' \
  --exclude='portal/components' \
  --exclude='portal/index.jsx' \
  --exclude='portal/portal-globals.js' \
  --exclude='client' \
  . "$TMP_DIR/$SLUG/"

echo "Zipping..."
cd "$TMP_DIR"
zip -r "$ZIP_NAME" "$SLUG" -x "*.DS_Store"
mv "$ZIP_NAME" "$PLUGIN_DIR/"
rm -rf "$TMP_DIR"

echo "Done: ${PLUGIN_DIR}/${ZIP_NAME}"
