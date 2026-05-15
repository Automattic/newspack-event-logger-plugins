#!/bin/bash
#
# Run PHPUnit tests with code coverage
#
# Usage:
#   ./run_coverage.sh              # Run all tests with coverage
#   ./run_coverage.sh --filter X   # Run specific test
#
# Coverage report is written to /volumes/pyrobase/tmp/event-logger-coverage/
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# Ensure xdebug coverage mode is enabled
export XDEBUG_MODE=coverage

# Set test environment
export LOCAL_EVENT_LOGGER_CONF="$SCRIPT_DIR/event-logger-test-config.php"

# Clean up any previous test artifacts
rm -rf /tmp/event-logger-test 2>/dev/null
rm -rf /tmp/event-logger-test-firehose 2>/dev/null
rm -rf /tmp/event-logger-test-locks 2>/dev/null

# Run PHPUnit with coverage
phpunit --configuration phpunit.xml \
    --coverage-clover /volumes/pyrobase/tmp/event-logger-coverage/clover.xml \
    --coverage-html /volumes/pyrobase/tmp/event-logger-coverage \
	--enforce-time-limit \
    "$@"

echo ""
echo "Coverage report: /volumes/pyrobase/tmp/event-logger-coverage/index.html"
