#!/bin/bash
#
# Build individual plugin release zips for GitHub releases.
#
# Each zip contains a single plugin directory at the root,
# ready for: wp plugin install --force --activate <url>.zip
#
# Usage: ./build-release.sh
# Output: release/<plugin-name>.zip for each plugin
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RELEASE_DIR="${SCRIPT_DIR}/release"
STAGING_DIR="${SCRIPT_DIR}/.release-staging"

# Clean previous builds.
rm -rf "${RELEASE_DIR}" "${STAGING_DIR}"
mkdir -p "${RELEASE_DIR}"

# Build production autoloaders for all plugins.
echo "=== Building autoloaders ==="
for dir in "${SCRIPT_DIR}"/newspack-*/; do
	plugin=$(basename "$dir")
	echo "  ${plugin}"
	(cd "$dir" && rm -rf vendor 2>/dev/null; composer install --no-dev --optimize-autoloader --quiet)
done

# Build JS/CSS if node_modules exists.
if [ -d "${SCRIPT_DIR}/node_modules" ]; then
	echo "=== Building JS/CSS ==="
	(cd "$SCRIPT_DIR" && npm run build)
fi

# Files/dirs to exclude from plugin zips.
EXCLUDE_PATTERNS=(
	"src"
	"composer.json"
	"composer.lock"
	"phpcs.xml.dist"
	".gitkeep"
	".DS_Store"
	"node_modules"
	"*.log"
)

# Build zip for each plugin.
echo "=== Creating release zips ==="
for dir in "${SCRIPT_DIR}"/newspack-*/; do
	plugin=$(basename "$dir")
	echo "  ${plugin}.zip"

	# Stage plugin into a clean directory.
	mkdir -p "${STAGING_DIR}/${plugin}"

	# Copy plugin files, excluding dev artifacts.
	rsync -a \
		--exclude='src' \
		--exclude='composer.json' \
		--exclude='composer.lock' \
		--exclude='phpcs.xml.dist' \
		--exclude='.gitkeep' \
		--exclude='.DS_Store' \
		--exclude='node_modules' \
		--exclude='*.log' \
		"${dir}" "${STAGING_DIR}/${plugin}/"

	# Create zip with plugin dir at root (required for wp plugin install).
	(cd "${STAGING_DIR}" && zip -rq "${RELEASE_DIR}/${plugin}.zip" "${plugin}")

	# Clean staging.
	rm -rf "${STAGING_DIR}/${plugin}"
done

# Build mu-plugin zip (single file).
if [ -f "${SCRIPT_DIR}/00-newspack-profiler.php" ]; then
	echo "  00-newspack-profiler.zip"
	mkdir -p "${STAGING_DIR}/00-newspack-profiler"
	cp "${SCRIPT_DIR}/00-newspack-profiler.php" "${STAGING_DIR}/00-newspack-profiler/"
	(cd "${STAGING_DIR}" && zip -rq "${RELEASE_DIR}/00-newspack-profiler.zip" "00-newspack-profiler")
fi

# Clean up.
rm -rf "${STAGING_DIR}"

echo ""
echo "=== Release zips ==="
ls -lh "${RELEASE_DIR}"/*.zip
