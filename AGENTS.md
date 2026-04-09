# Event Logger Plugins

Monorepo of WordPress plugins for high-throughput async event processing, request lifecycle logging, and real-time streaming with flame graph generation.

## Monorepo Structure

| Plugin | Purpose |
|--------|---------|
| `newspack-event-logger` | Core - firehose, partitions, SSE streaming |
| `newspack-event-dashboards` | Dashboard UI for event logs |
| `newspack-event-aggregator` | SSE aggregation from remote servers (StreamMerger) |
| `newspack-event-jobs` | Background job queue processing |
| `newspack-performance-logger` | Performance-specific logging hooks |
| `newspack-performance-dashboards` | Performance dashboard UI |
| `newspack-performance-gyroscope` | Request timeline visualization |
| `newspack-performance-request-log` | Request log viewer |
| `newspack-performance-workers` | Background workers (FlameBuilder, RequestBuilder) |
| `newspack-performance-aggregator` | Hub logic (settings sync, health check) |

See `ARCHITECTURE.md` for full system architecture and `API.md` for REST endpoint reference.

## Commands

```bash
# Build all dashboards
npm run build

# Build specific dashboard
npm run build:dashboards      # event-dashboards
npm run build:performance     # performance-dashboards
npm run build:gyroscope       # gyroscope
npm run build:request-log     # request-log
npm run build:aggregator      # event-aggregator

# Watch mode (development)
npm run watch

# Lint
npm run lint:js
npm run lint:scss
npm run lint:php

# Fix
npm run fix:js
npm run fix:scss
npm run fix:php

# Version bump (from dndocker root)
dndocker/tools/bump-event-logger-version.sh <version>

# Release workflow
# 1. Update CHANGELOG.md with new version and changes
# 2. Bump version across all plugin headers + package.json:
dndocker/tools/bump-event-logger-version.sh <version>
# 3. Commit the fix + version bump
# 4. Build release artifacts:
./build-release.sh          # outputs to release/
# 5. Tag, push, and create GitHub release with zips:
git tag v<version>
git push origin trunk --tags
gh release create v<version> release/*.zip release/00-newspack-profiler.php --title "v<version>" --notes "changelog here"
```

## Testing

```bash
# Check PHP syntax
docker exec eve-pyrobase1-1 php -l /usr/src/newspack-event-logger/includes/class-firehose.php

# Check WordPress error log
docker exec eve-pyrobase1-1 tail -f /var/log/apache2/error.log

# Filter logs using WP-CLI
docker exec -t eve-pyrobase1-1 wp --allow-root --path=/var/www/html eventlog reqgrep /calendar
docker exec -t eve-pyrobase1-1 wp --allow-root --path=/var/www/html eventlog reqgrep --follow

# Test REST API
curl -s "http://localhost:8080/wp-json/event-logger/v1/firehose/status"
curl -s "http://localhost:8080/wp-json/event-aggregator/v1/status"

# Check firehose logs
docker exec eve-pyrobase1-1 ls -la /tmp/event-logger/logs/firehose/
docker exec eve-pyrobase1-1 cat /tmp/event-logger/logs/firehose/p0/0.log | head -20
```

## Code Style

**WordPress VIP Go Coding Standards** (enforced by `phpcs.xml.dist`):
- `snake_case` for functions/variables
- Yoda conditions: `if ( 'value' === $var )`
- `[]` arrays, arrow functions, spread operator: allowed
- Tab indentation, spaces inside parentheses
- PHPDoc blocks for public methods

**React/JS**:
- Use `@wordpress/element` for React (not direct import)
- Use `@wordpress/api-fetch` for REST calls
- Follow wp-scripts lint rules

**Commits**: Conventional commits (`fix:`, `feat:`, `refactor:`). **MUST** update `CHANGELOG.md` in every commit that changes behavior — never commit without a changelog entry.

**Version bumps**: This is a monorepo — the version appears in 11+ plugin headers and `package.json`. Do NOT edit version numbers by hand.

## Architecture Decisions

These are intentional - do not "fix" them:

1. **No database writes at runtime** - All logging to filesystem via Firehose. This is for performance.
2. **PIPE_BUF atomic writes** - Firehose relies on POSIX guarantee that writes under 4096 bytes to append-mode files are atomic. No locking needed for concurrent writers.
3. **JobIntake for large payloads** - Payloads exceeding 4KB MUST use `JobIntake::queue()` which acquires a lock and supports writes up to 10MB. LogManager silently truncates data over 4KB.
4. **Partition-based parallelism** - URL-based CRC32 partitioning. Each partition has independent segment files.
5. **Hub/spoke aggregation** - StreamMerger pulls firehose entries from remote spokes to hub via SSE. Jobs in `firehose.log` get aggregated; jobs in `jobintake.log` stay local.
6. **Config via file + WP options** - `event-logger-config.php` for deployment config, WP options for runtime settings. Config files override WP options.

## Key Files

### Core (`newspack-event-logger/includes/`)

| File | Purpose |
|------|---------|
| `class-firehose.php` | Partitioned segmented log writer |
| `class-firehose-reader.php` | Streaming reader with offset tracking |
| `class-grail.php` | Real-time request state machine for SSE |
| `class-log-manager.php` | Per-request JSONL writer (PIPE_BUF limit) |
| `class-core.php` | WordPress lifecycle hook instrumentation |
| `class-config.php` | Config loader (file + WP options) |

### Jobs (`newspack-event-jobs/includes/`)

| File | Purpose |
|------|---------|
| `class-job-intake.php` | Locked write path for large jobs |
| `class-job-router.php` | Routes jobs from intake to handlers |
| `class-job-worker.php` | Executes registered job handlers |
| `class-lock.php` | mkdir-based advisory locking with heartbeat |

### Workers (`newspack-performance-workers/includes/cron/`)

| File | Purpose |
|------|---------|
| `class-request-builder.php` | Reconstructs requests from firehose |
| `class-flame-builder.php` | Generates flame graph data |
| `class-supervisor.php` | Worker health monitor and spawner |

### Aggregation (`newspack-event-aggregator/includes/`)

| File | Purpose |
|------|---------|
| `class-sse-client.php` | SSE client for remote server connections |
| `class-server-registry.php` | Remote server configuration |
| `cron/class-stream-merger.php` | Merges remote SSE streams into local firehose |

## Common Pitfalls

- **CRITICAL: PIPE_BUF limit** - LogManager silently replaces data over 4KB with `['truncated' => true]`, destroying job payloads. Check payload size BEFORE calling `LogManager::message('job', ...)`. Use `JobIntake::queue()` for anything that might exceed 4KB.
- **Cross-plugin callers** - `newspack-pyrobase` and `newspack-nuclear-gyrobase` call LogManager directly. Search ALL dependent plugins before changing LogManager's API.
- **Hub vs spoke behavior** - `enable_workers` config determines if a node processes jobs locally. `aggregator_servers` config determines if it pulls from remotes. Don't assume all nodes are hubs.
- **`event_logger_job_handlers` vs `event_logger_remote_job_handlers`** - Local handlers run on every node via JobWorker. Remote handlers run only on the hub after aggregator rewrites `k:"job"` to `k:"remote_job"`.
- **Firehose vs JobIntake routing** - Jobs that need hub aggregation MUST go through `firehose.log` (via LogManager). Jobs that only need local processing can use `jobintake.log` (via JobIntake). Using the wrong path silently loses jobs.
- **SettingsSync payloads** - Options like `log_events` (50+ hook names) can exceed 4KB. SettingsSync uses JobIntake for this reason.
- **Lock contention** - JobIntake uses a single lock (`job-intake.lock.d`). The static `JobIntake::queue()` helper acquires and releases per call. For batch writes, use an instance and call `close()` when done.

## References

- **Architecture**: `ARCHITECTURE.md` (full system design, data flow diagrams)
- **API**: `API.md` (REST endpoint reference, request/response formats)
- **Changelog**: `CHANGELOG.md`
