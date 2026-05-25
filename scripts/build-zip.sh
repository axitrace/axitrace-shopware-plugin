#!/usr/bin/env bash
# Build the AxiTrace Shopware 6 plugin distribution ZIP.
# Produces:
#   landing-page/public/downloads/axitrace-shopware-plugin-latest.zip
#   landing-page/public/downloads/axitrace-shopware-plugin-latest.zip.sha256
#
# ZIP structure (extracts to AxitraceShopware6/):
#   AxitraceShopware6/composer.json
#   AxitraceShopware6/src/...
#   AxitraceShopware6/README.md
#   AxitraceShopware6/LICENSE.md
#   AxitraceShopware6/CHANGELOG.md
#
# Excluded: composer.lock, vendor/, .git/, tests/, .github/, scripts/, phpunit.xml, .gitignore, .idea/
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "${PLUGIN_DIR}/.." && pwd)"
DOWNLOADS_DIR="${REPO_ROOT}/landing-page/public/downloads"
TMP_DIR="$(mktemp -d)"
STAGING="${TMP_DIR}/AxitraceShopware6"

mkdir -p "${STAGING}"
mkdir -p "${DOWNLOADS_DIR}"

# Copy plugin files into the staging directory, excluding dev artifacts.
rsync -a \
    --exclude='vendor/' \
    --exclude='composer.lock' \
    --exclude='tests/' \
    --exclude='.git/' \
    --exclude='.github/' \
    --exclude='scripts/' \
    --exclude='phpunit.xml' \
    --exclude='.gitignore' \
    --exclude='.idea/' \
    --exclude='*.swp' \
    --exclude='*.swo' \
    --exclude='.DS_Store' \
    "${PLUGIN_DIR}/" "${STAGING}/"

# Produce the ZIP from the parent of the staging directory so paths inside the
# archive begin with AxitraceShopware6/...
( cd "${TMP_DIR}" && zip -r -X -q "${DOWNLOADS_DIR}/axitrace-shopware-plugin-latest.zip" "AxitraceShopware6" )

# SHA-256 checksum file (matches `shasum -a 256` output format).
( cd "${DOWNLOADS_DIR}" && shasum -a 256 "axitrace-shopware-plugin-latest.zip" > "axitrace-shopware-plugin-latest.zip.sha256" )

# Report
ZIP_PATH="${DOWNLOADS_DIR}/axitrace-shopware-plugin-latest.zip"
SHA_PATH="${DOWNLOADS_DIR}/axitrace-shopware-plugin-latest.zip.sha256"
echo "Built: ${ZIP_PATH}"
echo "Size:  $(du -h "${ZIP_PATH}" | cut -f1)"
echo "SHA-256: $(cat "${SHA_PATH}")"

# Cleanup
rm -rf "${TMP_DIR}"
