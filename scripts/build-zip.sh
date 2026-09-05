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
# Premium-only JSX source (Projects, Team, Webhooks, Analytics admin apps) is
# excluded below. WP.org's Guideline 4 source-code requirement only applies to the
# free zip, and Freemius already strips the compiled *.js/PHP for these via
# the @fs_premium_only markers when it builds the free version — but
# Freemius's "premium version" is otherwise identical to whatever we upload.
# Excluding the raw .jsx here keeps that dev source out of what paying
# customers receive too; the compiled build/*.js stays in (that's what
# actually runs).
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
  --exclude='admin/components/ProjectsApp' \
  --exclude='admin/components/ProjectDetail' \
  --exclude='admin/components/ProjectApprovals' \
  --exclude='admin/components/ProjectFiles' \
  --exclude='admin/components/ProjectMessages' \
  --exclude='admin/components/TeamApp' \
  --exclude='admin/components/WebhooksApp' \
  --exclude='admin/components/AnalyticsApp' \
  . "$TMP_DIR/$SLUG/"

echo "Zipping..."
cd "$TMP_DIR"
zip -r "$ZIP_NAME" "$SLUG" -x "*.DS_Store"
mv "$ZIP_NAME" "$PLUGIN_DIR/"
rm -rf "$TMP_DIR"

echo "Done: ${PLUGIN_DIR}/${ZIP_NAME}"
