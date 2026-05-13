# Event Logger Testing Architecture

## Overview

Event Logger tests run using PHPUnit 11.x with Xdebug for coverage. Tests exercise the core infrastructure classes (Firehose, Lock, LruCache, Config, etc.) without requiring WordPress or a database.

## Test Suites

PHPUnit runs one suite:

| Suite | Directory | Purpose |
|-------|-----------|---------|
| **Unit Tests** | `unit/` | Unit tests for core infrastructure classes |

## Bootstrap

The bootstrap (`bootstrap.php`) provides:

1. Defines `ABSPATH`, `WP_PLUGIN_DIR`
2. Loads Composer autoloaders from each plugin
3. Provides minimal WordPress function stubs (only what's actually called)
4. Sets `LOCAL_EVENT_LOGGER_CONF` to point to test config

### WordPress Function Stubs

The bootstrap stubs ONLY the WordPress functions that tested classes actually call:

- `add_filter`, `apply_filters`, `add_action`, `do_action` - no-ops
- `get_option`, `update_option`, `delete_option` - in-memory array store
- `wp_json_encode` - delegates to `json_encode`
- `sanitize_text_field` - basic `strip_tags` + `trim`
- `esc_html`, `esc_html__`, `esc_url_raw` - basic HTML escaping
- `wp_mkdir_p` - recursive `mkdir`
- `wp_rand` - delegates to `random_int`
- `wp_timezone` - returns UTC

### Test Configuration

`event-logger-test-config.php` provides minimal config:

- Small segment size (1024 bytes) for fast rotation testing
- 1 partition, 2 segments
- Logging disabled
- Time-based retention disabled (max_lifespan = 0)

## Test Classes

### FirehoseTest

Tests the segmented log writer:
- Write and read back data
- Segment rotation when size exceeded
- Segment cleanup (retention by count)
- `hash_to_partition()` determinism and distribution
- `write_raw()` for atomic batch writes
- `allow_large_writes()` flag
- `get_segments()` returns sorted list
- Edge cases: empty writes, oversized writes

### FirehoseReaderTest

Tests the streaming reader:
- `next_offset('start')`, `next_offset('end')`, `next_offset('recent')`
- `read_line()` returns complete lines in order
- `is_caught_up()` detection
- `next_segment()` advances across segments
- Position save/restore via `get_position()` + `next_offset(array)`

### LockTest

Tests mkdir-based advisory locking:
- `acquire()` creates lock directory + heartbeat
- `acquire()` returns false when already locked
- `release()` removes lock directory
- `touch()` updates heartbeat timestamp
- `should_restart()` when PID matches/mismatches
- `force_release()` cleans up all lock files
- Stale lock detection via heartbeat age

### LruCacheTest

Tests bucket-based LRU cache:
- `get()`/`set()` basic operations
- LRU eviction when capacity exceeded
- `delete()` removes entry
- `iterate()` returns all entries newest-first
- `get_state()`/`restore_state()` for persistence
- Bucket rotation mechanics
- `flush()` clears everything

### ConfigTest

Tests configuration loading:
- `load_config()` returns values from test config file
- `get_base_directory()`, `get_logs_directory()`, etc.
- `ensure_path()` creates directories and validates canonical paths
- Config file override via `LOCAL_EVENT_LOGGER_CONF`
- `reset()` clears cached config

## Writing New Tests

### Pattern

```php
namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\SomeClass;

#[\PHPUnit\Framework\Attributes\CoversClass( SomeClass::class )]
class SomeClassTest extends TestCase {

    private string $temp_dir;

    protected function setUp(): void {
        parent::setUp();
        $this->temp_dir = '/tmp/event-logger-test-' . uniqid();
        @mkdir( $this->temp_dir, 0755, true );
    }

    protected function tearDown(): void {
        // Clean up temp files.
        self::rmdir_recursive( $this->temp_dir );
        parent::tearDown();
    }

    public function test_something(): void {
        // Arrange, Act, Assert.
    }

    private static function rmdir_recursive( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }
        @rmdir( $dir );
    }
}
```

### Rules

1. **No WordPress dependency** - Use bootstrap stubs only
2. **Clean up temp files** - Always in `tearDown()`
3. **Test edge cases** - Empty input, boundary conditions, error paths
4. **Use `@covers` or `#[CoversClass]`** - For accurate coverage attribution
5. **Don't test REST/Admin/SSE** - Those need a full WordPress environment

## Running Tests

```bash
# Deploy to container
docker exec eve-pyrobase1-1 /services/pyrobase/setup/newspack-pyrobase.sh

# Run all tests
docker exec eve-pyrobase1-1 bash -lc 'cd /usr/src/newspack-event-logger-plugins/tests && phpunit'

# Run specific test class
docker exec eve-pyrobase1-1 bash -lc 'cd /usr/src/newspack-event-logger-plugins/tests && phpunit --filter FirehoseTest'

# Run with coverage
docker exec eve-pyrobase1-1 bash -lc '/usr/src/newspack-event-logger-plugins/tests/run_coverage.sh'
# Coverage report: /volumes/pyrobase/tmp/event-logger-coverage/index.html
```

## File Locations

```
tests/
├── bootstrap.php                 ← Main bootstrap (WP stubs + autoloaders)
├── event-logger-test-config.php  ← Test configuration overrides
├── phpunit.xml                   ← PHPUnit configuration
├── run_coverage.sh               ← Coverage runner script
├── TESTING.md                    ← This file
└── unit/
    ├── FirehoseTest.php          ← Firehose segmented log writer tests
    ├── FirehoseReaderTest.php    ← Firehose streaming reader tests
    ├── LockTest.php              ← mkdir+heartbeat lock tests
    ├── LruCacheTest.php          ← Bucket-based LRU cache tests
    └── ConfigTest.php            ← Configuration loading tests
```
