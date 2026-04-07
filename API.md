# Event Logger API

Public APIs for reusable components. See [ARCHITECTURE.md](ARCHITECTURE.md) for system design.

## REST Endpoints by Plugin

### newspack-event-logger (Core)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/event-logger/v1/firehose/logs` | GET | List available log files |
| `/event-logger/v1/firehose/status` | GET | Get log status (segments, positions) |
| `/event-logger/v1/firehose/heartbeat` | POST | Keep SSE slots alive |
| `/event-logger/v1/firehose/stream` | GET | SSE stream for firehose.log (params: partition, segment_id, offset, aggregator) |
| `/event-logger/v1/settings` | POST | Update Event Logger settings (whitelist approach) |
| `/event-logger/v1/discovery` | GET | Get registered hooks, custom events, lag metrics |
| `/event-logger/v1/workers/spawn` | POST | Spawn worker (internal) |

### newspack-event-dashboards

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/event-logger/v1/firehose/rawlogs` | GET | SSE stream for any log file |
| `/event-logger/v1/performance/workers` | GET | Worker status and segments |
| `/event-logger/v1/performance/workers/restart` | POST | Request worker restart |

### newspack-performance-dashboards

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/event-logger/v1/performance/overview` | GET | Dashboard summary |
| `/event-logger/v1/performance/urls` | GET | List URLs with stats |
| `/event-logger/v1/performance/urls/{hash}` | GET | URL detail with flame data |
| `/event-logger/v1/performance/requests/search/{rid}` | GET | Search request by ID |
| `/event-logger/v1/performance/requests/{rid}` | GET | Get request trace |

### newspack-performance-gyroscope

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/event-logger/v1/firehose/gyroscope` | GET | SSE stream for in-flight requests |

### newspack-event-aggregator

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/event-aggregator/v1/servers` | GET | List configured remote servers |
| `/event-aggregator/v1/servers` | POST | Add a new remote server |
| `/event-aggregator/v1/servers/{id}` | GET | Get server details |
| `/event-aggregator/v1/servers/{id}` | PUT | Update server config (including enabled field) |
| `/event-aggregator/v1/servers/{id}` | DELETE | Remove a server |
| `/event-aggregator/v1/servers/{id}/test` | POST | Test connection to server |
| `/event-aggregator/v1/status` | GET | Get aggregator connection status (from memcache) |

### newspack-performance-aggregator

No additional REST endpoints. Extends event-aggregator via filters.

### newspack-performance-logger

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/perf-logger/v1/hooks/available` | GET | Available WordPress hooks |
| `/perf-logger/v1/hooks/configure` | POST | Configure hooks to log |
| `/perf-logger/v1/config` | GET | Get logger configuration |
| `/perf-logger/v1/config` | POST | Update logger configuration |
| `/perf-logger/v1/settings` | POST | Update performance options (for aggregator sync) |
| `/event-logger/v1/performance/registered-hooks` | GET | Hooks grouped by category |
| `/event-logger/v1/performance/hook-categories` | GET | Hook categories with colors |

### newspack-performance-request-log

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/event-logger/v1/firehose/requests` | GET | SSE stream for completed requests |

---

## PHP Classes

## Firehose

Partitioned, segmented append-only log for concurrent writes.

```php
use Newspack_Event_Logger\Firehose;

// Create firehose for a partition
$firehose = new Firehose($base_dir, $partition, $segment_size, $num_segments);

// Static helper for string-based partitioning (CRC32 mod N)
$partition = Firehose::hash_to_partition($str, $num_partitions);

// Writing
$firehose->write($json_line);              // Atomic append (under 4KB)
$firehose->write_raw($data);               // Write raw data (no newline added)
$firehose->allow_large_writes();           // Disable PIPE_BUF limit
$firehose->with_index($callback);          // Enable companion .idx files

// Reading
$firehose->get_segments();                 // List [{id, size}, ...]
$firehose->get_segments(true);             // With file paths included
$firehose->get_current_position();         // {segment_id, offset}
$firehose->read_at($seg_id, $offset, $len);
$firehose->reader($default_offset);        // Create FirehoseReader ('start'|'recent'|'end')
$firehose->scan_index($callback, $newest_first);

// Accessors
$firehose->get_base_dir();                 // Base directory path
$firehose->get_partition();                // Partition number
$firehose->get_segment_path($segment_id);  // Full path to segment file
```

## Firehose Reader

Pure streaming reader — no persistence. Position tracking and offsetlog managed by LogReader.

```php
// Create reader with default offset
$reader = $firehose->reader('end');      // Start at end (tail mode)
$reader = $firehose->reader('start');    // Start from beginning (default)
$reader = $firehose->reader('recent');   // Start at most recent segment

// Read loop
$fh = $reader->open();
while ($fh) {
    while (($line = $reader->read_line()) !== null) {
        // process $line (read_line handles large lines + updates offset)
    }
    if ($reader->is_caught_up()) {
        usleep(100000);                    // Caller controls timing
    } else {
        $reader->next_segment();
    }
}

// Resume from explicit position (e.g., SSE reconnect)
$reader->next_offset([
    'segment_id' => $saved_segment,
    'offset'     => $saved_offset,
]);
```

**Methods:**
- `next_offset($position)` - Set position: 'start', 'recent', 'end', or `{segment_id, offset}`
- `get_position()` - Returns `{segment_id, offset}`
- `get_segment_id()` - Current segment ID
- `open()` - Open filehandle at current position (returns resource|null)
- `next_segment()` - Move to next segment (returns resource|null)
- `is_caught_up()` - True if on newest segment
- `update_offset()` - Sync offset from filehandle (call after manual fgets)
- `refresh_segments()` - Refresh available segments from disk
- `read_line()` - Read with buffering for large lines
- `close()` - Close filehandle

## Worker Base

Abstract base class for background workers (cron-style batch processing).

```php
use Newspack_Event_Logger\WorkerBase;

class My_Worker extends WorkerBase {

    public function __construct(int $partition) {
        $this->init_worker('/path/to/my-worker.p' . $partition . '.lock', $partition);
    }

    public function run(): void {
        // Processing loop
        while (!$this->should_restart()) {
            // Process work here
        }
    }
}

// Usage
$worker = new My_Worker($partition);
if ($worker->acquire()) {
    $results = $worker->run();
    $worker->release();
}
```

**Methods:**
- `init_worker($lock_path, $partition, $max_runtime, $stale_timeout)` - Initialize (call from constructor)
- `acquire()` / `release()` - Lock management
- `execute()` - Main entry point: acquire lock, run(), release (override run() in subclass)
- `should_restart()` - Check if restart requested, runtime exceeded, or heartbeat due (handles all housekeeping)
- `get_lock()` - Lock accessor

## Supervisor Base

Base class for long-running supervisor processes.

```php
use Newspack_Event_Logger\SupervisorBase;

class My_Supervisor extends SupervisorBase {

    public function __construct() {
        parent::__construct(null, 600);  // Lazy lock, 10 min max
    }

    public function run(): void {
        $this->init_lock('/path/to/supervisor.lock');
        if (!$this->acquire()) {
            return;
        }

        while (!$this->should_restart()) {
            // Monitor and spawn workers
            sleep(1);
        }

        $this->release();
    }
}
```

**Methods:**
- `init_lock($lock_path)` - Initialize lock (for lazy init)
- `acquire()` / `release()` - Lock management
- `should_restart()` - Check if should exit (auto-touches heartbeat)
- `get_elapsed()` - Seconds since start
- `get_lock()` - Get lock instance

**Static Utilities:**
- `delete_directory_recursive($dir)` - Remove directory tree
- `remove_stale_directory($dir, $stale_age)` - Remove if not modified in N seconds
- `force_release($lock_path)` - Force release a lock

## Lock

Atomic mkdir + heartbeat locking (works on Docker volumes where flock fails).

```php
use Newspack_Event_Logger\Lock;

$lock = new Lock('/path/to/worker.lock');
// Or with custom stale timeout (default 60s)
$lock = new Lock('/path/to/worker.lock', 120);

if ($lock->acquire()) {
    // Do work...
    $lock->touch();              // Call periodically (prevents stale detection)
    if ($lock->should_restart()) {
        $lock->release();
        exit;
    }
    $lock->release();
}

// Set graceful shutdown time (for restart coordination)
$lock->set_shutdown_time($timestamp);

// Static helpers
Lock::request_restart($lock_dir);
Lock::force_release($lock_dir);
Lock::get_started_time($lock_dir);
Lock::is_restart_pending($lock_dir);
```

## Server Registry

Manage remote Event Logger servers for aggregation.

```php
use Newspack_Event_Aggregator\ServerRegistry;

$registry = ServerRegistry::get_instance();

// Add a new server (config array with url, auth_username, auth_password, enabled)
$registry->add('prod-web-01', [
    'url'           => 'https://prod-web-01.example.com',
    'auth_username' => 'aggregator',
    'auth_password' => 'application_password',
    'enabled'       => true,
]);

// Get server config
$server = $registry->get('prod-web-01');

// Update server (including enable/disable via enabled field)
$registry->update('prod-web-01', [
    'enabled' => false,  // Disable server
]);

// List servers
$all = $registry->get_all();       // All servers
$enabled = $registry->get_enabled(); // Only enabled

// Remove server
$registry->remove('prod-web-01');
```

## Remote Manager

Fan out actions to remote servers. Used internally by event-aggregator.

```php
use Newspack_Event_Aggregator\RemoteManager;

// Static helpers for plugins that extend aggregator
$servers = RemoteManager::get_enabled_servers();
$max = RemoteManager::get_max_servers();  // 100

// Make requests to remote servers
RemoteManager::post_to_server($server, '/wp-json/endpoint', ['key' => 'value']);
RemoteManager::get_from_server($server, '/wp-json/endpoint');

// Sync a setting to all servers
RemoteManager::sync_setting('event_logger_log_events', $value, '/wp-json/perf-logger/v1/settings');

// Sync all registered settings (called after health check discovery)
RemoteManager::sync_all_settings();
```

**Built-in Actions:**

- `sync_setting` - Sync option value to all remotes (with staleness check)
- `health_check` - Trigger health check and full settings sync

**Settings Sync Architecture:**

Settings are synced via job queue for proper serialization:

```php
// Job structure for settings sync
[
    'k' => 'event_logger_log_events',  // Option name as key (serialization)
    'm' => [
        'handler'    => 'remote_manager',
        'parameters' => [
            'action'    => 'sync_setting',
            'option'    => 'event_logger_log_events',
            'value'     => [...],
            'endpoint'  => '/wp-json/perf-logger/v1/settings',
            'queued_at' => 1706400000,  // For staleness check (300s threshold)
        ],
    ],
]
```

**Registering Synced Settings:**

Plugins register settings for periodic full sync via filter:

```php
add_filter('event_aggregator_synced_settings', function($settings) {
    $settings[] = [
        'local_option'  => 'my_plugin_setting',
        'remote_option' => 'my_plugin_setting',
        'endpoint'      => '/wp-json/my-plugin/v1/settings',
    ];
    return $settings;
});
```

**Extending with Custom Actions:**

```php
// Register custom remote action
add_filter('event_aggregator_remote_actions', function($handlers) {
    $handlers['my_action'] = function($parameters) {
        // Fan out to all enabled servers
        foreach (RemoteManager::get_enabled_servers() as $server_id => $server) {
            RemoteManager::post_to_server(
                $server,
                '/wp-json/my-plugin/v1/action',
                $parameters
            );
        }
    };
    return $handlers;
});

// Queue the action via firehose
$log_manager->message('job', [
    'm' => [
        'handler'    => 'remote_manager',
        'parameters' => [
            'action'  => 'my_action',
            'data'    => ['key' => 'value'],
        ],
    ],
]);
```

## Health Check Extensions

Process discovery data from remote servers.

```php
// Hook into health check discovery
add_action('event_aggregator_health_check_discovery', function($all_discovery) {
    // $all_discovery is map of server_id => discovery data
    foreach ($all_discovery as $server_id => $data) {
        $hooks = $data['registered_hooks'] ?? [];
        $events = $data['custom_events'] ?? [];
        $lag = $data['lag'] ?? 0;
        // Process as needed...
    }
});
```

## Config Option Schemas

Plugins can register their own configuration options via filter hooks. Options are loaded by `Config::load_config()`.

### Filter Hooks

```php
// Core options - loaded on every request (autoloaded)
add_filter('event_logger_option_schema_core', function($schema) {
    return array_merge($schema, [
        'my_option' => 'array_strings',
    ]);
});

// Extended options - loaded only in 'full' mode (workers/admin)
add_filter('event_logger_option_schema_extended', function($schema) {
    return array_merge($schema, [
        'my_admin_option' => 'int',
    ]);
});
```

### Option Types

| Type | Description |
|------|-------------|
| `bool` | Boolean value |
| `int` | Integer (validated with `is_numeric`) |
| `float` | Float (validated with `is_numeric`) |
| `path` | Filesystem path (absolute, no `..`, no null bytes) |
| `array_strings` | Array of strings (supports associative arrays) |
| `memcache_servers` | Newline-separated `host:port` list |

### Loading Config

```php
use Newspack_Event_Logger\Config;

// Core mode (default) - loads only autoloaded options
$config = Config::load_config();

// Full mode - loads all options including extended
$config = Config::load_config('full');

// Reset cache (e.g., after option update)
Config::reset();
```

---

## Custom Events

Track plugin-specific timed events with colors in flame graphs.

### Configuration

Plugins can register custom events via the `event_logger_custom_colors` filter (event name => hex color):

```php
add_filter('event_logger_custom_colors', function($colors) {
    $colors['my_plugin'] = '#9C27B0';
    $colors['query']     = '#2196F3';
    $colors['render']    = '#FF9800';
    return $colors;
});
```

Alternatively, add events directly to `event-logger-config.php` under the `custom_colors` key.

Then enable them via the admin UI (Settings → Event Logger → Custom Events → Select Events), or to enable them by default, add to `custom_events` in the config file.

### Integration Pattern

```php
class My_Plugin_Logger {
    private static $log_manager;
    private static $enabled_events = [];

    /**
     * Initialize - call early (plugins_loaded or init).
     */
    public static function initialize() {
        if ( ! class_exists( 'Newspack_Event_Logger\\LogManager' ) ) {
            return;  // Event Logger not installed
        }
        $config = \Newspack_Event_Logger\Config::load_config();
        self::$log_manager = \Newspack_Event_Logger\LogManager::instance();
        self::$enabled_events = $config['custom_events'] ?? [];
    }

    /**
     * Check if event type is enabled.
     */
    private static function is_enabled( string $keyword ): bool {
        return isset( self::$enabled_events[ $keyword ] );
    }

    /**
     * Start timed event (appears in flame graph).
     */
    public static function start( string $keyword, string $notes = '' ) {
        if ( self::is_enabled( $keyword ) && self::$log_manager ) {
            self::$log_manager->start( $keyword, [ 'm' => $notes ] );
        }
    }

    /**
     * Complete timed event (calculates duration).
     */
    public static function complete( string $keyword, string $notes = '' ) {
        if ( self::is_enabled( $keyword ) && self::$log_manager ) {
            self::$log_manager->complete( $keyword, [ 'm' => $notes ] );
        }
    }

    /**
     * Log a message (not timed).
     */
    public static function message( string $category, string $message ) {
        if ( self::$log_manager ) {
            self::$log_manager->message( $category, [ 'm' => $message ] );
        }
    }
}
```

### Usage

```php
// In your plugin bootstrap
add_action( 'plugins_loaded', [ 'My_Plugin_Logger', 'initialize' ] );

// Around expensive operations
My_Plugin_Logger::start( 'query', 'SELECT * FROM posts' );
$results = $wpdb->get_results( $sql );
My_Plugin_Logger::complete( 'query' );

// Simple messages
My_Plugin_Logger::message( 'cache', 'Hit for key xyz' );
```

### Log Manager Methods

- `instance()` - Get singleton instance
- `reset()` - Clear singleton (call before changing `REQUEST_URI` to log a new request context)
- `start($label, $data)` - Start timed event, pushes to stack
- `complete($label, $data)` - Complete timed event, pops stack, adds `duration_ms`
- `message($category, $data)` - Log raw message with `$data['m']` as content
- `info($msg, $data)` / `warning($msg, $data)` / `error($msg, $data)` - Convenience wrappers (optional $data array)

### How It Works

1. `start()` logs `"{keyword} (start)"` and pushes to timing stack
2. `complete()` logs `"{keyword} (complete)"` with `duration_ms` calculated from stack
3. Flame Builder aggregates start/complete pairs into flame graph nodes
4. Colors from `custom_events` config are applied in the UI

## Async Jobs

Queue background tasks via the firehose for deferred execution.

### Registering Job Handlers

Register local handlers via the `event_logger_job_handlers` filter:

```php
add_filter('event_logger_job_handlers', function($handlers) {
    $handlers['my_handler'] = function($parameters) {
        // Process async job on the same server that emitted it
    };
    return $handlers;
});
```

Register remote handlers (hub-only) via the `event_logger_remote_job_handlers` filter:

```php
add_filter('event_logger_remote_job_handlers', function($handlers) {
    $handlers['whack-cdn'] = [WhackCDN::class, 'job_handler'];
    return $handlers;
});
```

On hub servers, the performance-aggregator rewrites all ingested `k:"job"` to `k:"remote_job"` during aggregation. JobWorker dispatches `type:"job"` to local handlers and `type:"remote_job"` to remote handlers. This prevents spoke jobs from re-executing on the hub while enabling hub-only jobs that coordinate across publications.

### Queueing Jobs

Write job entries to the firehose with `k:"job"`:

```php
use Newspack_Event_Logger\LogManager;

$log_manager = LogManager::instance();
$log_manager->message('job', [
    'm' => [
        'handler'    => 'my_handler',
        'parameters' => ['key' => 'value'],
    ],
]);
```

The entry becomes: `{"k":"job","m":{"handler":"my_handler","parameters":{"key":"value"}},...}`

### How It Works

1. Job entries written to firehose with `k:"job"`
2. Log Aggregator routes job entries to `jobs.log`
3. Job Worker reads `jobs.log` and dispatches to registered handlers
4. Each partition has its own Job Worker instance

### Job Parameters

The `parameters` array is passed directly to your handler callback. Structure it however your handler needs:

```php
// Queueing
$log_manager->message('job', [
    'm' => [
        'handler'    => 'send_email',
        'parameters' => [
            'to'      => 'user@example.com',
            'subject' => 'Welcome',
            'body'    => 'Hello world',
        ],
    ],
]);

// Handler receives
add_filter('event_logger_job_handlers', function($handlers) {
    $handlers['send_email'] = function($params) {
        wp_mail($params['to'], $params['subject'], $params['body']);
    };
    return $handlers;
});
```

### Built-in Handlers

Pyrobase registers the `evtemplate` handler for async template execution:

```php
// Queue template execution (GET style - query string)
$log_manager->message('job', [
    'm' => [
        'handler'    => 'evtemplate',
        'parameters' => [
            'template'   => 'Tools/ProcessImport.html',
            'parameters' => 'id=123&action=process',
        ],
    ],
]);

// Or with POST style parameters (array)
$log_manager->message('job', [
    'm' => [
        'handler'    => 'evtemplate',
        'parameters' => [
            'template'   => 'Tools/ProcessImport.html',
            'parameters' => ['id' => 123, 'data' => ['foo' => 'bar']],
        ],
    ],
]);
```
