# Event Logger Architecture

High-throughput WordPress request lifecycle logging with real-time streaming and flame graph generation.

## Plugin Structure

This monorepo contains 10 WordPress plugins:

| Plugin | Description |
|--------|-------------|
| **newspack-event-logger** | Core infrastructure: Firehose, Config, Lock, Memcached, Supervisor, WorkerBase |
| **newspack-event-dashboards** | Admin UI: Raw Logs viewer, Worker Status dashboard |
| **newspack-event-jobs** | Job queue: JobIntake, JobRouter, JobWorker |
| **newspack-event-aggregator** | Multi-server aggregation: ServerRegistry, RemoteManager, SSEClient, StreamMerger |
| **newspack-performance-aggregator** | Hub-mode coordination: SettingsSync, HealthCheckExtensions |
| **newspack-performance-logger** | Instrumentation: LogManager, Core hooks, HookCategorizer |
| **newspack-performance-workers** | Background workers: RequestBuilder, FlameBuilder, StatsStore, Auto-tuning |
| **newspack-performance-dashboards** | Analytics UI: Performance Dashboard, Flame graphs, URL stats |
| **newspack-performance-gyroscope** | Real-time: InflightTracker, Gyroscope SSE stream |
| **newspack-performance-request-log** | Stream viewer: Request Log SSE stream |

**Dependency Graph:**
```
newspack-event-logger (core - no dependencies)
    │
    ├── newspack-event-dashboards
    ├── newspack-event-jobs
    │       │
    │       └── newspack-event-aggregator (requires: event-logger, event-jobs)
    │
    └── newspack-performance-logger
            │
            ├── newspack-performance-workers
            │       │
            │       ├── newspack-performance-dashboards
            │       └── newspack-performance-request-log
            │
            └── newspack-performance-gyroscope

newspack-performance-aggregator (requires: event-logger, event-jobs, event-aggregator, performance-workers)
```

Note: Gyroscope is independent of Workers/Dashboards - it reads directly from firehose.log.
Note: performance-aggregator has multiple parent dependencies and is shown separately.

## Table of Contents

- [Plugin Structure](#plugin-structure)
- [Overview](#overview)
- [Core Components](#core-components)
  - [Storage Layer](#storage-layer): Firehose, Firehose Reader, Lock
  - [Runtime](#runtime): Log Manager, Event Logger, InflightTracker
  - [Background Workers](#background-workers): Worker Base, Supervisor Base, LogReader, RequestBuilder, FlameBuilder, JobWorker, Supervisor
  - [Supporting Components](#supporting-components): Hook Categorizer, Stats Store
  - [API & UI](#api--ui): REST Controllers, Admin, Admin UI
- [Data Flow](#data-flow)
- [Log Directory Structure](#log-directory-structure)
- [Configuration](#configuration)
- [File Layout](#file-layout)
- [Key Design Principles](#key-design-principles)

**See also:** [API.md](API.md) for public APIs and REST endpoints by plugin

---

## Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           WRITE PATH (Runtime)                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   WordPress Request                                                         │
│         │                                                                   │
│         ▼                                                                   │
│   ┌─────────────┐     ┌─────────────┐     ┌─────────────────────────────┐   │
│   │    Core     │────▶│ Log_Manager │────▶│        Firehose             │   │
│   │             │     │  (JSONL)    │     │  (Partitioned Segmented Log)│   │
│   └─────────────┘     └─────────────┘     └─────────────────────────────┘   │
│                                                    │                        │
│                                                    ▼                        │
│                                           /logs/firehose.log/p0/            │
│                                              {segment_id}.log               │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                          READ PATH (Background)                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   Firehose Logs ─────────────┬──────────────────────────────────────────    │
│          │                   │                                              │
│          ▼                   ▼                                              │
│   ┌──────────────┐    ┌───────────────┐                                     │
│   │   LogReader  │    │InflightTracker│                                     │
│   │ (reads logs) │    │ (SSE stream)  │                                     │
│   └──────┬───────┘    └──────┬────────┘                                     │
│          │                   │                                              │
│    ┌─────┴─────┐             ▼                                              │
│    ▼           ▼       Real-time State                                      │
│ RequestBuilder FlameBuilder (in-flight reqs)                                │
│ (handler)     (handler)                                                     │
│    │           │                                                            │
│    ▼           ▼                                                            │
│ requests.log  flames.log                                                    │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                           API / UI Layer                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   REST API (/event-logger/v1/)                                              │
│   ├── /performance/overview     (dashboard stats, hourly data, leaderboard) │
│   ├── /performance/urls         (URL list with stats, URL detail)           │
│   ├── /performance/requests     (individual request traces)                 │
│   ├── /performance/workers      (background worker status)                  │
│   ├── /performance/registered-hooks  (discovered hooks by category)         │
│   ├── /performance/hook-categories   (category colors and patterns)         │
│   └── /firehose/*               (SSE stream, status)                        │
│                                                                             │
│   Admin UI (React + @wordpress/components)                                  │
│   ├── Performance Dashboard     (URL stats, flame graphs, request traces)   │
│   ├── Gyroscope                 (real-time in-flight requests via SSE)      │
│   ├── Request Log               (real-time completed requests via SSE)      │
│   ├── Worker Status             (background worker monitoring)              │
│   └── Settings Page             (logging config, hook selection)            │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Core Components

### Storage Layer

#### Firehose (`includes/class-firehose.php`)

Partitioned, segmented append-only log optimized for concurrent writes from multiple PHP-FPM workers.

**Key Design Decisions:**
- **Partitioning**: 1 partition by default (configurable). URL hashed to partition for consistent routing.
- **Segmentation**: 64MB segments by default (configurable via `segment_size`), 4 segments retained per partition.
- **Total Storage**: `segment_size × num_segments × num_partitions × num_logs`. Plugins register log count via `event_logger_num_logs` filter:
  - performance-logger: +1 (firehose)
  - performance-workers: +2 (requests, flames)
  - event-logger-jobs: +2 (jobs, jobintake)
- **Segment Naming**: Monotonically increasing IDs (0.log, 1.log, 2.log...) - no gaps or overlaps.
- **Position Format**: `segment_id:offset` - offset is local to segment, not global.
- **Atomic Writes**: Lines under 4KB (PIPE_BUF) are atomic on POSIX systems.
- **No Locking for Writes**: Writers append atomically; readers use `FirehoseReader` to stream data. Position tracking is managed by worker classes like LogReader.

**Directory Structure:**
```
/tmp/event-logger/logs/firehose.log/
├── p0/                           # Partition 0 (additional: p1, p2, ...)
│   └── {segment_id}.log          # Monotonically increasing IDs
└── offsets/
    └── {reader_name}.p{partition}/  # Offsetlog directory (Firehose instance)
        └── p0/
            └── {segment_id}.log     # JSONL commits: {"seg":N,"off":M,"ts":T,"state":{...}}
```

**Public API:**
```php
// Each Firehose instance handles ONE partition
use Newspack_Event_Logger\Firehose;

$firehose = new Firehose($base_dir, $partition, $segment_size, $num_segments);

// Static helper for URL-based partitioning
Firehose::hash_to_partition($str, $num_partitions)  // String (typically URL) → partition index

// Writing
$firehose->write($line)                           // Atomic append (+ newline + index if with_index set)
$firehose->write_raw($data)                       // Write pre-formatted data (no newline, no index)
$firehose->allow_large_writes()                   // Disable PIPE_BUF limit (for single-writer logs)
$firehose->with_index($callback)                  // Enable companion .idx files (see below)

// Configuration accessors
$firehose->get_partition_dir()                    // Get partition directory path
$firehose->get_partition()                        // Get partition number
$firehose->get_base_dir()                         // Get base directory path
$firehose->get_segment_path($segment_id)          // Get full path to segment file

// Reading
$firehose->get_segments()                         // List [{id, size}, ...]
$firehose->get_current_position()                 // {segment_id, offset} of write head
$firehose->read_at($seg_id, $offset, $length)     // Read bytes at position
$firehose->reader($default_offset)                  // Create FirehoseReader ('start'|'recent'|'end')
$firehose->scan_index($callback, $newest_first)   // Iterate companion .idx files

```

**Companion Index Files:**

Firehose supports companion `.idx` files that are written alongside `.log` files. When a log segment rotates out, its companion index is automatically deleted - ensuring 1:1 correspondence.

```php
// Enable indexing with a callback that formats index entries
use Newspack_Event_Logger\Config;

$config = Config::load_config();
$segment_size = $config['segment_size'] ?? 64 * 1024 * 1024;
$num_segments = $config['num_segments'] ?? 4;

$requests = (new Firehose('/logs/requests', 0, $segment_size, $num_segments))
    ->allow_large_writes()
    ->with_index(function($line, $position) {
        $data = json_decode($line, true);
        // Format: rid(32) + url_hash(12) + timestamp(10) + duration(8) + status(3) + seg(6) + offset(10) + len(8) = 89 chars
        return str_pad($data['rid'], 32) .
            substr(md5($data['url']), 0, 12) .
            sprintf('%010d%08d%03d%06d%010d%08d',
                $data['timestamp'], $data['duration_ms'], $data['status_code'],
                $position['segment_id'], $position['offset'], $position['length']
            );
    });

// Scan index files (callback returns false to stop, null to continue)
$requests->scan_index(function($line, $segment_id) {
    $entry = parse_index_entry($line);
    // ... process entry ...
    return null; // continue
}, true); // newest first
```

This design keeps the index format entirely up to the consumer while Firehose handles:
- Writing index entries to `{segment_id}.idx` alongside `{segment_id}.log`
- Cleaning up both files together on segment rotation
- Providing `scan_index()` helper for reading

#### Firehose Reader (`includes/class-firehose-reader.php`)

Pure streaming reader for Firehose logs. No persistence — position tracking and offsetlog management live in LogReader.

**Features:**
- Handles segment rotation during read (waits for current segment to be fully read)
- Caller controls polling via `is_caught_up()` check
- `read_line()` with buffering for lines >4KB (where atomic writes not guaranteed)
- Stateless: position set via `next_offset()`, no save/restore

**Usage:**
```php
$reader = $firehose->reader('end');  // Start at end (tail mode)

while (true) {
    $fh = $reader->open();
    if ( ! $fh ) {
        usleep(100000);  // No segments yet
        continue;
    }
    while (($line = $reader->read_line()) !== null) {
        // process $line
    }
    if ($reader->is_caught_up()) {
        usleep(100000);  // 100ms sleep when caught up
    } else {
        $reader->next_segment();
    }
}

// Explicit position resume (e.g., from SSE reconnect)
$reader->next_offset([
    'segment_id' => $saved_segment,
    'offset'     => $saved_offset,
]);
```

**Public API:**
```php
$reader->next_offset($position) // Set position: 'start', 'recent', 'end', or {segment_id, offset}
$reader->get_position()         // {segment_id, offset}
$reader->get_segment_id()       // Current segment ID
$reader->open()                 // Open filehandle at current position (returns resource|null)
$reader->next_segment()         // Move to next segment (returns resource|null)
$reader->is_caught_up()         // True if on newest segment (caller decides to sleep)
$reader->update_offset()        // Sync offset from filehandle (call after manual fgets)
$reader->refresh_segments()     // Refresh list of available segments from disk
$reader->read_line()            // Read one line with buffering for large lines (>4KB)
$reader->close()                // Close filehandle
```

**next_segment() Behavior:**
- If next segment exists AND current segment fully read: opens it, sets offset to 0, returns filehandle
- If current segment has unread data or was recently written: stays on current, returns filehandle
- If no next segment: refreshes file position, returns current filehandle
- **No sleep**: caller uses `is_caught_up()` to decide when to sleep

#### Lock (`includes/class-lock.php`)

Reusable mkdir+heartbeat based locking utility. Works on macOS Docker volumes where `flock()` fails.

**Design:**
- Lock acquisition via atomic `mkdir()` (fails if already exists)
- Heartbeat file inside lock directory, touched periodically
- Stale detection: if heartbeat > 60s old, lock is considered abandoned
- Automatic stale lock cleanup on acquisition attempt

**Usage:**
```php
use Newspack_Event_Logger\Lock;

// Lock path should end in .lock.d (directory-based lock)
$lock = new Lock('/path/to/myworker.lock.d');
// Optional: custom stale timeout (default: 60 seconds)
$lock = new Lock('/path/to/myworker.lock.d', 120);

if ($lock->acquire()) {
    // Do work...
    $lock->touch();  // Call periodically to prevent stale detection
    $lock->set_shutdown_time(time() + 300);  // Optional: stop touching near shutdown
    if ($lock->should_restart()) { $lock->release(); exit; }
    $lock->release();
}

// Static helpers (work without holding lock)
Lock::request_restart($lock_dir);    // Signal worker to restart
Lock::get_started_time($lock_dir);   // Get when worker started
Lock::is_restart_pending($lock_dir); // Check if restart requested
Lock::force_release($lock_dir);      // Force remove lock
```

**Lock Directory Contents:**
- `heartbeat` - Touched periodically, contains PID
- `started` - Created on acquisition, contains start timestamp
- `restart` - Created by `request_restart()` to signal restart

### Runtime

#### Log Manager (`includes/class-log-manager.php`)

Per-request singleton that writes log entries directly to Firehose. Uses `start()`/`complete()` pairs to track timed categories with a stack. Registers shutdown handler to close orphaned timers.

**Entry Format (JSONL):**
```json
{"ts":1702300000.123,"n":1,"rid":"abc123xyz","k":"hook (start)","m":"init"}
```
Fields: `ts` (microsecond timestamp), `n` (line number), `rid` (request ID), `k` (category), `m` (message).

#### Core (`includes/class-core.php`)

WordPress integration that instruments request lifecycle.

**Tracked Hooks:**
Configurable via `log_events` config array. Hooks are bound at priority 1 (start) and PHP_INT_MAX-1 (complete).

**Plugin Timing:**
Intercepts `option_active_plugins` filter to start timing before first plugin loads, then tracks each `plugin_loaded` action.

#### InflightTracker (`includes/class-inflight-tracker.php`)

State machine for SSE streams. Tracks in-flight requests for real-time Gyroscope view by maintaining a stack of active states per request. Does NOT build profiles - that's RequestBuilder's job.

### Background Workers

Workers use a two-level class hierarchy. See [API.md](API.md) for public APIs.

#### Worker Base (`includes/class-worker-base.php`)

Abstract base class for cron-style background workers. Provides lock management, runtime tracking, and graceful restart handling. Extended by LogReader and StreamMerger.

#### Supervisor Base (`includes/class-supervisor-base.php`)

Base class for long-running supervisor processes. Provides lock management with lazy initialization, heartbeat handling, and directory cleanup utilities. Extended by Supervisor.

#### LogReader Framework (`includes/cron/class-log-reader.php`)

Unified worker framework that all background workers use. Instead of separate worker classes, LogReader provides a generic firehose consumer that dispatches to pluggable handlers.

**Handler Interface:**
```php
interface LogReaderHandler {
    // Required: Called for each line read from input log(s)
    public static function process(string $line, string $input, array &$context): void;

    // Optional: Called on startup with restored state
    public static function init(array &$context, ?array $saved_state): void;

    // Optional: Called during housekeeping (periodic flush)
    public static function flush(array &$context): void;

    // Returns state array to persist to offsetlog (pending bucket data)
    public static function save_state(array &$context): ?array;

    // Optional: Called on shutdown
    public static function cleanup(array &$context): void;
}
```

Note: `$line` is the raw log line, `$input` is the log name (e.g., 'firehose.log') for multi-input workers, and `$context` is a persistent array shared across calls.

**Worker Registration:**
Workers register via `event_logger_log_readers` filter (two-level group format):
```php
add_filter('event_logger_log_readers', function($readers) {
    $readers['firehose-workers']['request-builder'] = [
        'class'   => RequestBuilder::class,
        'inputs'  => ['firehose.log'],
        'outputs' => ['requests.log', 'errors.log'],
    ];
    return $readers;
});
```

**Multi-Handler Groups:**
Multiple handlers can share a group (and process), reading the same inputs:
```php
$readers['firehose-workers']['job-router'] = [
    'class'   => JobRouter::class,
    'inputs'  => ['firehose.log', 'jobintake.log'],
    'outputs' => ['jobs.log'],
];
```

**Registered Worker Groups:**
| Group | Handler | Inputs | Outputs |
|-------|---------|--------|---------|
| `firehose-workers` | request-builder | firehose.log | requests.log, errors.log |
| `firehose-workers` | job-router | firehose.log, jobintake.log | jobs.log |
| `request-workers` | flame-builder | requests.log | flames.log |
| `job-workers` | job-worker | jobs.log | — |
| `job-worker` | JobWorker | jobs.log | (dispatches to handlers) |

#### RequestBuilder (`includes/cron/class-request-builder.php`)

LogReader handler that processes raw firehose entries and reconstructs complete requests.

**Responsibilities:**
1. Receives raw firehose entries from LogReader (via `process()` callback)
2. Reconstructs complete requests from start/complete pairs using LRU bucket cache
3. Stores completed requests to requests.log with companion .idx files

**LRU Bucket Cache:**
- 4 buckets × 50 entries = 200 concurrent requests max per partition
- Buckets rotate when full, oldest evicted when exceeding limit
- Crash recovery via offsetlog state persistence

**Index Format (companion .idx files):**
Fixed 89-byte records: `rid(32) url_hash(12) timestamp(10) duration_ms(8) status_code(3) seg_id(6) offset(10) length(8)`

Index files are stored as `{segment_id}.idx` alongside `{segment_id}.log` in the requests directory. This ensures 1:1 correspondence - when a log segment rotates out, its index is automatically deleted. Uses Firehose's `with_index()` callback mechanism.

#### FlameBuilder (`includes/cron/class-flame-builder.php`)

LogReader handler that builds flame graph data from completed requests.

**Responsibilities:**
1. Receives completed requests from LogReader (via `process()` callback)
2. Builds flame graph data structure from entry stack (LIFO matching)
3. Stores per-request flames to flames.log with companion .idx files
4. Maintains per-URL stats in memcache via StatsStore (flame + profiles, incremental averaging)
5. Maintains partition-level stats in memcache via StatsStore (hourly stats, global leaderboard, URL index, dimensional breakdowns, category time series)
6. **Pending-bucket architecture**: All bucketed stats (hourly, dimensional, URL, category) accumulate in a pending buffer for the current 5-minute bucket. When the bucket key rotates (complete data), the pending bucket is promoted to the flush arrays — categories capped to top 50 by time, overflow rolled into "Other". Pending state persists via `save_state`/offsetlog across worker restarts. This prevents silent memcache write failures from unbounded category growth exceeding the 1MB slab limit.
7. Flushes completed-bucket stats to memcache every 5 seconds
8. **Entry Expiration**: Flame entries older than 1 hour are pruned during aggregation
9. **URL Expiration**: URLs with no requests for 1+ hour are cleaned up (hardcoded 3600s)
10. **Auto-disable**: Noisy events exceeding count threshold are automatically disabled. Events with duration above `auto_protect_time_threshold` are marked "significant" and protected from auto-disable.

**Index Format (companion .idx files):**
Fixed 68-byte records: `rid(32) url_hash(12) seg_id(6) offset(10) length(8)`

Index files are stored as `{segment_id}.idx` alongside `{segment_id}.log` in the flames directory. Uses Firehose's `with_index()` callback mechanism.

**Flame Data Structure:**
```json
{
  "name": "request",
  "value": 234.5,
  "children": [
    {
      "name": "init",
      "value": 45.2,
      "children": [...]
    }
  ]
}
```

#### JobWorker (`includes/cron/class-job-worker.php`)

LogReader handler that dispatches jobs to registered handlers.

**Responsibilities:**
1. Reads jobs from jobs.log via FirehoseReader
2. Dispatches jobs to registered handlers based on `handler` field and job type
3. Local handlers registered via `event_logger_job_handlers` filter
4. Remote handlers registered via `event_logger_remote_job_handlers` filter
5. Flushes object cache every 50 jobs to prevent memory growth

**Job Format (JSONL in jobs.log):**
```json
{"type":"job","handler":"evtemplate","parameters":{"template":"Tools/UpdateTopic.html","parameters":"topic=123"},"ts":1702300000.123}
```

**Handler Registration:**
```php
// Local handlers - run on the server that emitted the job
add_filter('event_logger_job_handlers', function($handlers) {
    $handlers['my_handler'] = function($parameters) {
        // Process job with $parameters array
    };
    return $handlers;
});

// Remote handlers - run only on a hub server
add_filter('event_logger_remote_job_handlers', function($handlers) {
    $handlers['whack-cdn'] = [MyClass::class, 'job_handler'];
    return $handlers;
});
```

**Job Routing:**
Jobs reach jobs.log through two paths via JobRouter:

1. **Runtime jobs** (small, <4KB): Written to firehose with `k:"job"`. JobRouter extracts and routes these to jobs.log, preserving the keyword as the job's `type`. Jobs stay on the same partition as the originating request.

2. **Large jobs** (>4KB): Written directly to jobintake.log by JobIntake class. JobRouter reads from both firehose.log and jobintake.log, routing jobs to jobs.log. This allows large payloads to bypass the 4KB firehose limit.

**Local vs Remote Jobs:**

On spoke servers, all jobs are written with `k:"job"` and dispatched to local handlers (`event_logger_job_handlers`).

On hub servers, the performance-aggregator plugin rewrites all ingested `k:"job"` entries to `k:"remote_job"` during aggregation (`event_aggregator_ingest_line` filter). This is a blanket string replace — every job from spokes becomes `remote_job` on the hub. JobWorker then dispatches `remote_job` entries to the separate `event_logger_remote_job_handlers` registry. This prevents spoke jobs from being re-executed on the hub while allowing hub-only handlers (e.g., Cloudflare CDN purges that coordinate across publications).

**Security Validation:**
- Handler name pattern: `/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/`
- Max parameters: 10MB (JobRouter and JobWorker)
- JSON decode depth limit: 64 (JobRouter), 10 (JobWorker)

#### Supervisor (`includes/cron/class-supervisor.php`)

Extends `Supervisor_Base`. Long-running process (~10 minutes, self-respawning) that monitors worker health and manages partition lifecycle.

**Responsibilities:**
1. Spawns workers when missing or crashed (stale heartbeat > 60s)
2. Handles config changes (partition count, log directory, logging enabled/disabled)
3. Cleans up stale partition directories when partition count is reduced
4. Kills workers for removed partitions via `Lock::force_release()`

**Worker Spawning:**
- Checks each worker lock directory every second
- If lock missing or heartbeat stale, spawns worker via REST API (`POST /event-logger/v1/workers/spawn`)
- Spawn requests authenticated with HMAC token (10-second window, based on `NONCE_SALT`)
- Rate limited: minimum 15 seconds between spawns of same worker type
- Workers run in FastCGI context (not CLI) for consistency with runtime environment

**Config Monitoring:**
- Refreshes config every 15 seconds (clears WP option cache)
- If logging disabled: cleans up all logs, unschedules cron, exits
- If base_directory changed: deletes old directory, exits for restart
- If num_partitions reduced: kills workers for removed partitions, then cleans up stale directories after 1 hour

**Stale Partition Cleanup:**
Partition directories beyond `num_partitions` that haven't been modified in over 1 hour are automatically removed (firehose, requests, requests-index, flames, flames-index).

### Aggregator Architecture

The aggregator plugins enable multi-server log aggregation in a hub-and-spoke model. The hub server collects logs from multiple remote servers via SSE, merges them locally, and coordinates settings across all servers.

```
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│  Remote Srv 1   │  │  Remote Srv 2   │  │  Remote Srv 3   │
│  Perf Logger    │  │  Perf Logger    │  │  Perf Logger    │
└───────┬─────────┘  └───────┬─────────┘  └───────┬─────────┘
        │ SSE                │ SSE                │ SSE
        └────────────────────┼────────────────────┘
                             ▼
           ┌────────────────────────────────────┐
           │  Aggregator Hub                    │
           │  ┌──────────────────────────────┐  │
           │  │ event-aggregator             │  │
           │  │ - ServerRegistry             │  │
           │  │ - SSE Client per server      │  │
           │  │ - StreamMerger workers       │  │
           │  │ - HealthCheck (periodic job) │  │
           │  │ - RemoteManager              │  │
           │  │ - SettingsSync (core opts)   │  │
           │  └───────────┬──────────────────┘  │
           │              ▼                     │
           │       local firehose.log           │
           │              ▼                     │
           │  ┌──────────────────────────────┐  │
           │  │ performance-aggregator       │  │
           │  │ - SettingsSync (perf opts)   │  │
           │  │ - HealthCheckExtensions      │  │
           │  └──────────────────────────────┘  │
           └────────────────────────────────────┘
```

#### ServerRegistry (`event-aggregator/includes/class-server-registry.php`)

Stores remote server configurations in WordPress option `event_logger_aggregator_servers`:

```php
[
    'prod-web-01' => [
        'url'           => 'https://prod-web-01.example.com',
        'auth_username' => 'aggregator',
        'auth_password' => 'application_password',
        'enabled'       => true,
        'logs'          => ['firehose.log'],
    ],
    'prod-web-02' => [...],
]
```

**Public API:**
```php
use Newspack_Event_Aggregator\ServerRegistry;

$registry = ServerRegistry::get_instance();

// Server CRUD
$registry->add($id, $config);       // Add new server (config array with url, auth_username, auth_password, enabled, logs)
$registry->update($id, $config);    // Partial update existing server
$registry->remove($id);             // Remove server

// Reading
$registry->get($id);                // Get single server config or null
$registry->get_all();               // Get all servers
$registry->get_enabled();           // Get only enabled servers

// Utilities
$registry->reset_cache();           // Clear cached servers (for testing)
ServerRegistry::is_valid_id($id);   // Static: validate server ID format
```

Note: Enable/disable is done via `update($id, ['enabled' => true/false])`, not dedicated methods.

#### RemoteManager (`event-aggregator/includes/class-remote-manager.php`)

Job handler that fans out actions to remote servers. Registered as `remote_manager` handler.

**Built-in Actions:**
- `sync_setting` - Sync option value to all remotes (with staleness check)
- `health_check` - Run health check on all servers, then sync all settings

**Staleness Threshold:**
Jobs include a `queued_at` timestamp. Jobs older than 300 seconds are skipped as stale. This prevents duplicate syncs from piling up during high-frequency option updates.

**Extension Point:**
Plugins extend via `event_aggregator_remote_actions` filter:
```php
add_filter('event_aggregator_remote_actions', function($handlers) {
    $handlers['my_action'] = [MyClass::class, 'handle_action'];
    return $handlers;
});
```

**Static Helpers (for plugins):**
```php
RemoteManager::health_check();         // Run health check on all servers + collect discovery data
RemoteManager::post_to_server($server, $endpoint, $body);
RemoteManager::get_from_server($server, $endpoint);
RemoteManager::sync_setting($option, $value, $endpoint, $servers = null);  // Sync to all or specific servers
RemoteManager::sync_all_settings();    // Sync all registered settings
```

#### StreamMerger (`event-aggregator/includes/cron/class-stream-merger.php`)

Standalone worker (extends WorkerBase) that multiplexes SSE connections from all configured servers. One worker per partition.

**Key Features:**
- Non-blocking reads from all SSE streams (cURL multi)
- Single offsetlog per partition stores all server positions atomically
- Writes merged events to local firehose
- Reconnects with exponential backoff

#### Health Check (Supervisor-based, no dedicated file)

Health checks run inside the Supervisor process via `event_logger_supervisor_periodic` hook (every 300 seconds). No standalone worker file exists.

**Flow:**
1. Supervisor fires `event_logger_supervisor_periodic` action
2. Aggregator plugin hooks this and queues a job with `action` = `health_check`
3. JobWorker dispatches to `RemoteManager::handle_job()`
4. `RemoteManager::health_check()` serially checks each enabled server's `/wp-json/event-logger/v1/discovery` endpoint
5. Collects discovery data and fires `event_aggregator_health_check_discovery` action
6. Calls `sync_all_settings()` to ensure all servers have current settings

**Why Job-Based:**
- "Islands of serialization in a sea of concurrency" — one job does the full sweep
- Staleness check (300s) prevents duplicate health checks from piling up
- Full settings sync after discovery handles staleness drops and new servers

**Extension Point:**
Plugins process discovery data via action:
```php
add_action('event_aggregator_health_check_discovery', function($all_discovery) {
    foreach ($all_discovery as $server_id => $data) {
        // Process $data['registered_hooks'], $data['custom_events'], etc.
    }
});
```

#### SettingsSync

Settings sync is split between two plugins, each owning their respective options:

**event-aggregator/includes/class-settings-sync.php** (core options):
- `event_logger_num_partitions` → synced as-is
- `event_logger_remote_num_segments` → synced as `event_logger_num_segments`
- `event_logger_remote_segment_size` → synced as `event_logger_segment_size`
- Endpoint: `/wp-json/event-logger/v1/settings`

**performance-aggregator/includes/class-settings-sync.php** (tuning options):
- `event_logger_log_urls` - URL patterns to include
- `event_logger_skip_urls` - URL patterns to exclude
- `event_logger_log_events` - Hook checkboxes
- `event_logger_custom_events` - Custom event list
- `event_logger_auto_disable_threshold` - Auto-tune threshold
- `event_logger_auto_protect_time_threshold` - Significant event threshold
- `event_logger_significant_events` - Protected events
- Endpoint: `/wp-json/perf-logger/v1/settings`

**Job-Based Sync:**
Both SettingsSync classes hook `update_option` and `add_option`, queuing jobs with:
- `k` = option name (for job serialization - one sync per option at a time)
- `queued_at` timestamp (jobs older than 300s are skipped as stale)

**Periodic Full Sync:**
Both classes register their settings via `event_aggregator_synced_settings` filter. After health check discovery, `RemoteManager::sync_all_settings()` syncs all registered settings to ensure consistency (handles staleness drops and new servers).

#### HealthCheckExtensions (`performance-aggregator/includes/class-health-check-extensions.php`)

Handles `event_aggregator_health_check_discovery` action:
- Merges discovered `registered_hooks` into local settings (new hooks added as unchecked)
- Merges discovered `custom_events` into local settings

### Supporting Components

#### Hook Categorizer (`includes/class-hook-categorizer.php`)

Pattern-based hook categorization system that auto-categorizes WordPress hooks using regex patterns.

**Design:**
- Base configuration stored in `hook_categories.json` with `_colors` and `_patterns` maps
- User customizations stored in `event_logger_hook_customizations` option (merged with base)
- Supports explicit overrides (hook_name → category) for individual hooks
- Merged configuration (base + customizations) cached per-request for performance

**hook_categories.json Structure:**
```json
{
  "_colors": {
    "Lifecycle": "#607D8B",
    "Query & Posts": "#ef5350",
    "Content Rendering": "#FF9800",
    ...
  },
  "_patterns": {
    "Lifecycle": ["^plugins_loaded$", "^init$", "^wp$", ...],
    "Query & Posts": ["^posts_", "^pre_get_posts$", ...],
    "Content Rendering": ["^the_content", "^the_title", ...],
    ...
  }
}
```

**Public API:**
```php
use Newspack_Event_Logger\HookCategorizer;

// Categorize hooks
HookCategorizer::categorize('pre_get_posts')  // Returns "Query & Posts"
HookCategorizer::categorize_many(['init', 'wp', 'shutdown'])

// Get categories and colors
HookCategorizer::get_categories()     // [category => color, ...]
HookCategorizer::get_color('Lifecycle')

// Live $wp_filter inspection
HookCategorizer::get_registered_hooks()              // All hook names
HookCategorizer::get_registered_hooks_by_category()  // {category => [hooks...]}

// Config layers (base patterns, user overrides, merged result)
HookCategorizer::get_base_config()            // Built-in category patterns + colors
HookCategorizer::get_user_customizations()    // User overrides from WP option
HookCategorizer::get_merged_config()          // Base + user merged
HookCategorizer::clear_cache()                // Clear merged config cache
```

#### Stats Store (`includes/class-stats-store.php`)

Memcache-based storage for performance stats. Replaces filesystem JSON with direct memcache access.

**Key Naming:**
- `evlog:p{n}:hourly` - Hourly time series (5-minute buckets despite the name)
- `evlog:p{n}:leaderboard` - Global leaderboard
- `evlog:p{n}:leaderboard:{server}` - Per-server leaderboard
- `evlog:p{n}:urls:{bucket}` - URL index (5-minute buckets, Y-m-d-H-MM format)
- `evlog:p{n}:url:{hash}` - Per-URL flame + profiles
- `evlog:p{n}:url_dim:{hash}` - Per-URL dimensional stats
- `evlog:p{n}:dim:{dimension}` - Dimensional stats (global)
- `evlog:p{n}:dim:{dimension}:{server}` - Per-server dimensional stats
- `evlog:p{n}:categories` - Category time series (global)
- `evlog:p{n}:categories:{server}` - Per-server category time series
- `evlog:p{n}:url_categories:{hash}` - Per-URL category time series

**TTLs:** Driven by `max_lifespan` config (default 24h, floor 1h). Partition stats (hourly, leaderboard, dimensional, categories): `max_lifespan`. URL index: `max_lifespan`. Per-URL stats: `max_lifespan / 24` (minimum 1h).

**Category Capping:** Categories capped at `MAX_CAT_VALUES` (50) per bucket. Dimensional values capped at `MAX_DIM_VALUES` (20) per bucket, `MAX_URL_DIM_VALUES` (10) for per-URL. Overflow rolled into "Other".

#### Job Intake (`includes/class-job-intake.php`)

Interface for import and other processes to queue large jobs that exceed the 4KB firehose limit.

**Design:**
- Import and other processes (WP-CLI, admin actions, cron) can write directly to `jobintake.log`
- Uses `allow_large_writes()` since process is locked and single writer to its partition
- JobRouter reads from jobintake.log and routes jobs to jobs.log
- All jobs (small runtime + large imports/etc) processed by same JobWorker for unified CPU capping

**Usage:**
```php
use Newspack_Event_Logger\JobIntake;

// Queue a single large job
$intake = new JobIntake();
$intake->write_job('import_content', [
    'post_type' => 'post',
    'data' => $large_import_data,  // Can exceed 4KB
]);

// Pin all writes to a specific partition
$intake->partition(2);
$intake->write_job('import_event', $data);

// Or use key-based consistent partitioning
$intake->write_job('import_event', $data, 'event_123');

// Or use static helper (blocks up to 5 minutes with retry)
JobIntake::queue('import_content', $parameters);

// Batch write multiple jobs
$intake->write_jobs([
    ['handler' => 'import_posts', 'parameters' => $posts_data],
    ['handler' => 'import_media', 'parameters' => $media_data],
]);
```

**Data Flow:**
```
Import Process ──► jobintake.log ──► JobRouter ──► jobs.log ──► JobWorker
                   (large writes)    (reads both)   (unified)    (rate limited)
```

### API & UI

#### REST API

Endpoints are listed in the [Overview diagram](#overview). Controllers extend `PerformanceControllerBase` which provides config loading, Stats_Store initialization, and Firehose access for reading logs.

#### Admin (`includes/admin/class-admin.php`)

WordPress admin integration:
- Menu pages for Performance Dashboard and Gyroscope
- Settings page under Settings → Event Logger
- Script/style enqueuing for React admin UI

#### Admin UI (React)

Built with React and @wordpress/components. Pages match the menu structure:
- **Performance Dashboard**: URL stats, flame graphs, request traces, hourly charts
- **Gyroscope**: Real-time in-flight requests via SSE with color-coded state badges
- **Request Log**: Real-time completed requests via SSE with filtering
- **Worker Status**: Background worker monitoring, segment visualization
- **Settings**: Hook selector with live $wp_filter inspection, URL pattern filters

## Data Flow

### Write Path (Per Request)

```
1. Core instantiated immediately on plugin load
2. Hooks registered for lifecycle events
3. Each hook fires → Log_Manager::start() / ::complete()
4. Log entries written to Firehose partition (hash of URL)
5. Shutdown → Log_Manager::finish() closes orphaned entries
```

### Read Path (Background)

```
1. Supervisor runs continuously, spawns workers via REST API when missing/crashed
2. LogReader loads registered handlers via event_logger_log_readers filter
3. LogReader reads firehose.log → dispatches to RequestBuilder handler → requests.log
4. LogReader reads requests.log → dispatches to FlameBuilder handler → flames.log + memcache stats
5. LogReader reads firehose.log + jobintake.log → dispatches to JobRouter handler → jobs.log
6. LogReader reads jobs.log → dispatches to JobWorker handler → registered handlers
7. All workers use mkdir+heartbeat locking (one per partition)
8. Supervisor cleans up stale partition directories when partition count reduced
```

### Real-time Path (SSE)

**Slot-Based Rate Limiting:**
- Max 10 concurrent SSE connections per user:IP pair
- Each connection occupies one "slot" in memcache with 10-second TTL
- Browser must POST `/firehose/heartbeat` every 5-10s to keep slots alive
- If heartbeat stops, slot expires and connection drops
- SSE loop checks slot validity every 5 seconds

**SSE Events:**
- `'connected'` - Sent on connection with slot number
- `'config'` - Sent immediately with `num_partitions` and refresh interval
- `'inflight'` - Periodic digest of active requests (Gyroscope only)
- `'complete_batch'` - Completed requests from all partitions
- `'timeout'` - Sent when 1-hour MAX_RUNTIME exceeded

```
Gyroscope (/firehose/gyroscope):
1. Browser connects, acquires slot via memcache atomic add()
2. Controller creates InflightTracker + FirehoseReaders per partition
3. Loop: read entries from ALL partitions (multiplexed) → InflightTracker::process()
4. Every 100ms: emit 'inflight' + 'complete_batch' events
5. Every 5s: verify slot still valid (browser heartbeat keeps it alive)
6. 1-hour timeout → send 'timeout' event → client reconnects

Request Log (/firehose/requests):
1. Browser connects, acquires slot
2. Controller creates FirehoseReaders for requests.log per partition
3. Loop: read entries from ALL partitions (multiplexed) → emit 'complete_batch' events
4. Every 5s: verify slot still valid
5. 1-hour timeout → client reconnects
```

## Directory Structure

```
/tmp/event-logger/                     # base_directory
├── logs/                              # Segmented log files
│   ├── firehose.log/                  # Main event stream (raw JSONL from PHP-FPM workers)
│   │   └── p0/                        # Partition 0 (additional partitions if num_partitions > 1)
│   │       └── {segment_id}.log
│   │
│   ├── requests.log/                  # Completed request JSON + companion index
│   │   └── p0/
│   │       ├── {segment_id}.log       # Request JSON data
│   │       └── {segment_id}.idx       # Companion index (1:1 with .log)
│   │
│   ├── flames.log/                    # Flame graph JSON + companion index
│   │   └── p0/
│   │       ├── {segment_id}.log       # Flame JSON data
│   │       └── {segment_id}.idx       # Companion index (1:1 with .log)
│   │
│   ├── errors.log/                    # Error/warning entries (forwarded by RequestBuilder)
│   │   └── p0/
│   │       └── {segment_id}.log       # Entries with error/warning keywords
│   │
│   ├── jobintake.log/                 # Large jobs (written by JobIntake)
│   │   └── p0/
│   │       └── {segment_id}.log       # Job JSON data (can be >4KB)
│   │
│   └── jobs.log/                      # Async job queue (from firehose + job-intake)
│       └── p0/
│           └── {segment_id}.log       # Job JSON data
│
├── offsets/                           # Centralized offset tracking
│   ├── firehose-workers.p0/           # Per-group offsetlog (unified for all handlers)
│   │   └── p0/
│   │       └── {segment_id}.log       # JSONL: {"positions":{...},"ts":T,"state":{...}}
│   ├── request-workers.p0/
│   │   └── p0/
│   │       └── {segment_id}.log
│   ├── job-workers.p0/
│   │   └── p0/
│   │       └── {segment_id}.log
│   └── stream-merger.p0/
│       └── p0/
│           └── {segment_id}.log
│
├── locks/                             # Worker lock directories
│   ├── firehose-workers.p0.lock.d/    # Lock for firehose-workers group
│   │   ├── heartbeat
│   │   └── started
│   ├── request-workers.p0.lock.d/
│   │   ├── heartbeat
│   │   └── started
│   ├── job-workers.p0.lock.d/
│   │   ├── heartbeat
│   │   └── started
│   └── supervisor.lock                # Supervisor lock file
```

**Note:** Stats (hourly, leaderboard, per-URL) are stored in memcache via Stats_Store, not filesystem.

## Configuration

**Option Schema Filters:**

Plugins register their options via filter hooks. This allows each plugin to own its settings:

```php
// Core options (autoloaded, loaded on every request)
add_filter('event_logger_option_schema_core', function($schema) {
    return array_merge($schema, [
        'my_option' => 'array_strings',  // type: bool, int, float, path, array_strings, memcache_servers
    ]);
});

// Extended options (not autoloaded, loaded only in 'full' mode for workers/admin)
add_filter('event_logger_option_schema_extended', function($schema) {
    return array_merge($schema, [
        'my_admin_option' => 'int',
    ]);
});
```

**WordPress Options** (stored as individual `event_logger_{key}` options):

| Option | Type | Plugin | Mode | Description |
|--------|------|--------|------|-------------|
| `enable_logging` | bool | event-logger | core | Enable/disable logging |
| `base_directory` | path | event-logger | core | Base directory (default: `/tmp/event-logger`) |
| `num_partitions` | int | event-logger | core | Number of partitions (default: 1) |
| `num_segments` | int | event-logger | core | Segments retained per partition (default: 4) |
| `segment_size` | int | event-logger | core | Max segment size before rotation (default: 64MB) |
| `memcache_servers` | memcache_servers | event-logger | extended | Stats storage servers (default: `['127.0.0.1:11211']`) |
| `log_urls` | array_strings | performance-logger | core | URL patterns to include (empty = all) |
| `skip_urls` | array_strings | performance-logger | core | URL patterns to exclude |
| `log_events` | array_strings | performance-logger | core | Hook event names to log |
| `custom_events` | array_strings | performance-logger | core | Custom event names to log (name → color) |
| `remote_num_segments` | int | event-aggregator | core | Segments for remote firehose (synced as `num_segments`) |
| `remote_segment_size` | int | event-aggregator | core | Segment size for remote firehose (synced as `segment_size`) |
| `hook_customizations` | array | performance-logger | extended | User customizations for hook categories/colors |
| `aggregator_servers` | array | event-aggregator | extended | Remote server registry (ServerRegistry storage) |
| `auto_disable_threshold` | int | performance-workers | extended | Count threshold for auto-disabling noisy events |
| `auto_protect_time_threshold` | float | performance-workers | extended | Time threshold for protecting significant events (ms) |
| `significant_events` | array_strings | performance-workers | extended | Events protected from auto-disable |
| `max_lifespan` | int | performance-workers | extended | Stats retention window in seconds (default: 86400) |

**Key Constants:**

| Constant | Value | Description |
|----------|-------|-------------|
| `MAX_LINE_SIZE` | 4KB | PIPE_BUF limit for atomic writes |
| `STALE_TIMEOUT` | 60s | Lock staleness threshold |
| `MAX_RUNTIME_SECONDS` | 10 min | Worker runtime (self-respawn via spawn endpoint) |
| `MAX_CAT_VALUES` | 50 | Category cap per bucket |
| `MAX_DIM_VALUES` | 20 | Dimensional value cap per bucket |

## File Layout

```
newspack-event-logger-plugins/           # Monorepo root
├── package.json                         # Unified npm build
├── composer.json                        # Shared dev dependencies
├── phpcs.xml.dist                       # PHPCS config for all plugins
│
├── newspack-event-logger/               # Core infrastructure
│   ├── newspack-event-logger.php
│   ├── event-logger-config.php          # Default configuration
│   ├── uninstall.php                    # Cleanup on uninstall
│   ├── hook_categories.json             # Hook categorization patterns
│   └── includes/
│       ├── class-config.php
│       ├── class-firehose.php
│       ├── class-firehose-reader.php
│       ├── class-lock.php
│       ├── class-lru-cache.php
│       ├── class-memcached.php
│       ├── class-supervisor-base.php
│       ├── class-worker-base.php
│       ├── admin/class-admin.php
│       ├── cli/class-worker-command.php
│       ├── cron/
│       │   ├── class-log-reader.php
│       │   └── class-supervisor.php
│       └── rest-api/
│           ├── class-discovery-controller.php
│           ├── class-firehose-controller.php
│           ├── class-firehose-stream-controller.php
│           ├── class-settings-controller.php
│           ├── class-spawn-controller.php
│           └── class-sse-controller-base.php
│
├── newspack-event-dashboards/           # Admin UI (Workers, Raw Logs)
│   ├── newspack-event-dashboards.php
│   ├── includes/
│   │   ├── admin/class-admin.php
│   │   └── rest-api/
│   │       ├── class-rawlogs-controller.php
│   │       └── class-workers-controller.php
│   └── src/                             # React components
│
├── newspack-event-jobs/                 # Job queue system
│   ├── newspack-event-jobs.php
│   └── includes/
│       ├── class-job-intake.php
│       └── cron/
│           ├── class-job-router.php
│           └── class-job-worker.php
│
├── newspack-event-aggregator/           # Multi-server aggregation
│   ├── newspack-event-aggregator.php
│   ├── includes/
│   │   ├── class-server-registry.php
│   │   ├── class-sse-client.php
│   │   ├── class-remote-manager.php
│   │   ├── class-settings-sync.php          # Core options sync
│   │   ├── admin/class-admin.php
│   │   ├── cron/
│   │   │   └── class-stream-merger.php
│   │   └── rest-api/
│   │       ├── class-status-controller.php
│   │       └── class-servers-controller.php
│   └── src/                                 # React components
│       ├── index.js
│       ├── settings.js
│       ├── AggregatorStatus.js
│       └── AggregatorStatusPage.js
│
├── newspack-performance-aggregator/     # Hub-mode coordination
│   ├── newspack-performance-aggregator.php
│   └── includes/
│       ├── class-settings-sync.php
│       └── class-health-check-extensions.php
│
├── newspack-performance-logger/         # Request instrumentation
│   ├── newspack-performance-logger.php
│   ├── hook_categories.json             # Hook categorization patterns
│   └── includes/
│       ├── class-core.php
│       ├── class-log-manager.php
│       ├── class-hook-categorizer.php
│       ├── cli/class-reqgrep-command.php
│       └── rest-api/
│           ├── class-dashboard-controller.php   # Dashboard UI endpoints
│           ├── class-hooks-controller.php       # Hook categories endpoints
│           └── class-settings-controller.php    # Settings sync endpoint
│
├── newspack-performance-workers/        # Background workers & auto-tuning
│   ├── newspack-performance-workers.php
│   └── includes/
│       ├── class-stats-store.php
│       ├── admin/class-admin.php
│       └── cron/
│           ├── class-request-builder.php
│           └── class-flame-builder.php
│
├── newspack-performance-dashboards/     # Performance analytics UI
│   ├── newspack-performance-dashboards.php
│   ├── includes/
│   │   ├── admin/class-admin.php
│   │   └── rest-api/
│   │       ├── class-performance-controller-base.php
│   │       ├── class-performance-controller.php
│   │       ├── class-overview-controller.php
│   │       ├── class-urls-controller.php
│   │       └── class-requests-controller.php
│   └── src/                             # React dashboard
│
├── newspack-performance-gyroscope/      # Real-time monitoring
│   ├── newspack-performance-gyroscope.php
│   ├── includes/
│   │   ├── class-inflight-tracker.php
│   │   ├── admin/class-admin.php
│   │   └── rest-api/class-gyroscope-controller.php
│   └── src/                             # React UI
│
└── newspack-performance-request-log/    # Request stream
    ├── newspack-performance-request-log.php
    ├── includes/
    │   ├── admin/class-admin.php
    │   └── rest-api/class-requests-controller.php
    └── src/                             # React UI
```

## CLI Commands

Event Logger provides WP-CLI commands for log analysis:

### reqgrep - Filter Firehose Logs

```bash
# Search all partitions for URL pattern
wp eventlog reqgrep /calendar

# Follow mode - tail all partitions continuously
wp eventlog reqgrep --follow

# Follow with pattern filter
wp eventlog reqgrep pyrobase --follow

# Output raw JSON instead of formatted
wp eventlog reqgrep /calendar --raw

# Show incomplete requests
wp eventlog reqgrep pattern --incomplete
```

**Options:**
- `<pattern>` - Search pattern (request ID, URL, or content)
- `--follow` - Follow mode (like tail -f)
- `--raw` - Output raw JSON instead of formatted
- `--incomplete` - Show incomplete requests
- `--bucket-size=<n>` - History buffer size (default: 250)
- `--num-buckets=<n>` - Number of history buckets (default: 10)
- `--path=<path>` - Override firehose directory

**Stdin/Pipe Mode:**
```bash
# Read from stdin instead of firehose files
cat logfile.log | wp eventlog reqgrep pattern
```

### worker - Manage Background Workers

```bash
# List all workers and their status
wp eventlog worker list

# List available worker types
wp eventlog worker types

# Run request-builder for partition 0
wp eventlog worker run request-builder --partition=0

# Run stream-merger for partition 1 (standalone worker)
wp eventlog worker run stream-merger --partition=1

# Restart all workers on partition 0
wp eventlog worker restart all --partition=0

# Restart flame-builder on all partitions
wp eventlog worker restart flame-builder --all-partitions
```

**Subcommands:**
- `list` - List all workers (reader-based and standalone) with status, uptime, behind bytes
- `types` - List available worker types (reader-based and standalone)
- `run <type>` - Run a worker
- `restart <type>` - Request worker restart

**Options for `run`:**
- `--partition=<n>` - Partition number (0-based)
- `--quiet` - Suppress output (used by supervisor)

**Options for `restart`:**
- `--partition=<n>` - Partition number (0-based)
- `--all-partitions` - Apply to all partitions

Workers are normally spawned automatically by the Supervisor. This command is primarily for debugging or manual operation.

## Key Design Principles

1. **Modular Plugin Architecture**: Core provides infrastructure; features are optional plugins activated independently.

2. **No Database Writes at Runtime**: All logging goes to filesystem. DB only used for config.

3. **Partition for Parallelism**: URL-based partitioning allows independent processing.

4. **Segmented for Bounded Storage**: Old segments auto-deleted; no unbounded growth.

5. **Segment ID Addressing**: Positions are `segment_id:offset` - no gaps, no overlaps, no ambiguity.

6. **Fixed-Size Index for Speed**: Sequential scan of small index beats JSON parsing.

7. **Companion Index Files**: Index stored as `.idx` files alongside `.log` files. When a log segment rotates out, its index is automatically deleted - ensuring 1:1 correspondence and preventing stale index entries.

8. **Incremental Aggregation**: Stats computed incrementally, not re-scanned.

9. **mkdir+Heartbeat Locking**: Atomic lock acquisition that works on all filesystems.

10. **Crash Recovery via Offsetlog**: Offsetlog (itself a Firehose) stores position + optional state for reader resume. Managed by LogReader (constants `OFFSETLOG_SEGMENT_SIZE`, `OFFSETLOG_NUM_SEGMENTS`) and StreamMerger, not FirehoseReader.

11. **Worker Hierarchy**: Base classes (`Worker_Base`, `Supervisor_Base`) provide reusable lock management, heartbeat, and runtime tracking. Concrete workers extend these with application logic.
