#!/usr/bin/env bash
set -e

SLUG="clientoctopus"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
VERSION=$(grep -m1 "Version:" "$PLUGIN_DIR/clientoctopus.php" | awk '{print $NF}' | tr -d '[:space:]')
ZIP_NAME="${SLUG}-${VERSION}.zip"

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
  --exclude='README.md' \
  --exclude='package-lock.json' \
  --exclude='screenshot-*.png' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='*.log' \
  --exclude='.idea' \
  --exclude='.vscode' \
  . "$TMP_DIR/$SLUG/"

echo "Zipping..."
cd "$TMP_DIR"
zip -r "$ZIP_NAME" "$SLUG" -x "*.DS_Store"
mv "$ZIP_NAME" "$PLUGIN_DIR/"
rm -rf "$TMP_DIR"

echo "Done: ${PLUGIN_DIR}/${ZIP_NAME}"
