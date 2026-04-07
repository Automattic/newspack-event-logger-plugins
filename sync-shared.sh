#!/bin/bash
#
# Sync shared hooks and utilities to plugin directories.
#
# Canonical sources live in src/shared/. This script copies them to each
# plugin that needs them.  Run after editing any file in src/shared/.
#

set -euo pipefail
cd "$(dirname "$0")"

HOOKS=src/shared/hooks
UTILS=src/shared/utils

HEADER="// Synced from src/shared/ by sync-shared.sh — edit the canonical source, not this copy."

sync() {
	local src="$1"; shift
	local name; name=$(basename "$src")
	for dest in "$@"; do
		mkdir -p "$dest"
		printf '%s\n' "$HEADER" > "$dest/$name"
		cat "$src" >> "$dest/$name"
	done
}

# --- Hooks ---

sync "$HOOKS/usePageVisibility.js" \
	newspack-event-dashboards/src/shared/hooks/ \
	newspack-performance-dashboards/src/shared/hooks/ \
	newspack-performance-request-log/src/shared/hooks/ \
	newspack-performance-gyroscope/src/shared/hooks/

sync "$HOOKS/useAdminMenuWidth.js" \
	newspack-event-dashboards/src/shared/hooks/ \
	newspack-event-aggregator/src/shared/hooks/ \
	newspack-performance-dashboards/src/shared/hooks/ \
	newspack-performance-request-log/src/shared/hooks/ \
	newspack-performance-gyroscope/src/shared/hooks/

sync "$HOOKS/useFirehoseConnection.js" \
	newspack-event-dashboards/src/shared/hooks/ \
	newspack-performance-dashboards/src/shared/hooks/ \
	newspack-performance-request-log/src/shared/hooks/ \
	newspack-performance-gyroscope/src/shared/hooks/

sync "$HOOKS/useVirtualization.js" \
	newspack-event-dashboards/src/shared/hooks/ \
	newspack-performance-dashboards/src/shared/hooks/ \
	newspack-performance-request-log/src/shared/hooks/

# --- Utilities ---

sync "$UTILS/formatUtils.js" \
	newspack-performance-dashboards/src/shared/utils/ \
	newspack-performance-request-log/src/shared/utils/ \
	newspack-performance-gyroscope/src/shared/utils/

sync "$UTILS/fnv1a.js" \
	newspack-performance-request-log/src/shared/utils/ \
	newspack-performance-gyroscope/src/shared/utils/

echo "Shared files synced."
