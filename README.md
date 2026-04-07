# Event Logger Plugins

Monorepo containing WordPress plugins for request lifecycle logging, performance monitoring, and real-time visualization.

## Plugins

| Plugin | Description |
|--------|-------------|
| **newspack-event-logger** | Core logging infrastructure. Firehose (partitioned append-only log), Memcached integration, configuration, and supervisor for background workers. |
| **newspack-event-dashboards** | Raw Logs viewer and Worker Status admin pages. Shows firehose entries and background worker health. |
| **newspack-event-jobs** | Async job queue. Small jobs written to firehose (k:"job"), large jobs to `jobintake.log` via JobIntake. JobRouter reads both and routes to `jobs.log`, JobWorker dispatches to handlers. |
| **newspack-event-aggregator** | Multi-server log aggregation. SSE clients connect to remote servers, StreamMerger workers multiplex streams per partition, single offsetlog tracks all positions atomically. |
| **newspack-performance-aggregator** | Hub-mode performance management. Settings sync fan-out, HealthCheck discovers remote hooks/events, auto-tuning coordination across servers. |
| **newspack-performance-logger** | Request lifecycle instrumentation. Core hooks into WordPress to time hooks/plugins, Log Manager buffers entries, Hook Categorizer assigns categories. |
| **newspack-performance-workers** | Background workers and auto-tuning. Request Builder aggregates firehose into `requests.log`, Flame Builder generates flame data and memcache stats, StatsStore provides memcache access. |
| **newspack-performance-dashboards** | Performance dashboard UI with flame graphs and URL analytics. REST controllers for dashboard data, admin scripts and styles. |
| **newspack-performance-gyroscope** | Real-time in-flight request monitor. SSE stream shows requests as they execute with color-coded states. |
| **newspack-performance-request-log** | Completed request stream viewer. Real-time scrolling log of finished requests from `requests.log`. |

## Dependencies

```
newspack-event-logger (core - no dependencies)
    |
    +-- newspack-event-dashboards
    +-- newspack-event-jobs
    +-- newspack-event-aggregator
    |       |
    |       +-- newspack-performance-aggregator
    |
    +-- newspack-performance-logger
            |
            +-- newspack-performance-workers
            |       |
            |       +-- newspack-performance-dashboards
            |       +-- newspack-performance-request-log
            |
            +-- newspack-performance-gyroscope
```

- **newspack-event-logger**: Required by all other plugins
- **newspack-event-aggregator**: Aggregates logs from remote servers (optional, for hub deployments)
- **newspack-performance-aggregator**: Hub-mode coordination (requires event-aggregator for server registry)
- **newspack-performance-logger**: Required by workers and gyroscope
- **newspack-performance-workers**: Required by dashboards and request-log (provides `requests.log` via Request Builder)

## Requirements

- PHP 8.0+
- WordPress 6.0+
- Memcached server
- Write access to `/tmp/event-logger/`

## Build Commands

```bash
# Install dependencies
npm install
composer install

# Build all plugin admin UIs
npm run build

# Watch mode (development)
npm run watch

# Build individual plugin autoloaders
composer build:autoloaders

# Lint
npm run lint:js
npm run lint:scss
composer lint
```

## Installation

1. Run `npm install && npm run build` to build admin UIs
2. Run `composer build:autoloaders` to generate autoloaders for each plugin
3. Symlink or copy plugin directories to `wp-content/plugins/`
4. Activate plugins in WordPress admin (respect dependency order)

## Directory Structure

```
newspack-event-logger-plugins/
    newspack-event-logger/           # Core plugin
    newspack-event-dashboards/
    newspack-event-jobs/
    newspack-event-aggregator/       # Multi-server aggregation
    newspack-performance-logger/
    newspack-performance-workers/
    newspack-performance-dashboards/
    newspack-performance-gyroscope/
    newspack-performance-request-log/
    newspack-performance-aggregator/ # Hub-mode coordination
    package.json                     # Unified npm build
    composer.json                    # Shared dev dependencies and autoload
```

Each plugin has its own `composer.json` for autoloading and `vendor/` directory.
