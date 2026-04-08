# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- JobRouter: write jobs to jobs.log immediately instead of batching until housekeeping flush, reducing job routing latency from ~15s to sub-millisecond

## [2.4.10] - 2026-04-05

### Security

- HookCategorizer: ReDoS protection via scoped `pcre.backtrack_limit` (10000) on user-controlled regex patterns
- HookCategorizer: type validation on deserialized option patterns before `preg_match()`
- Event-logger bootstrap: `is_subclass_of(WP_REST_Controller)` check on filter-provided controller classes
- SupervisorBase: path containment check in `delete_directory_recursive()` — rejects paths outside configured base directory
- Lock: `Config::ensure_path()` validation in `force_release()` to prevent destructive ops on arbitrary paths
- SSE controller base: strip `\r`, `\n`, `\0` from custom header names/values to prevent HTTP response splitting
- JobRouter: handler name validation on jobintake passthrough entries against `HANDLER_NAME_PATTERN`
- LogManager: added `_URL` and `DSN` to sensitive environment variable blocklist
- Memcached: atomic `touch()` for SSE slot TTL refresh, eliminating TOCTOU race in get-then-set
- Admin: hardcode `manage_options` capability on menu pages, preventing filter-based capability downgrade
- RemoteManager: restrict outbound endpoint prefixes to `event-logger/` and `perf-logger/`

### Fixed

- DashboardController: `sanitize_string_array()` converted `false` to `true` — now preserves boolean value
- RequestBuilder: cap string interning cache at 1000 entries to prevent unbounded memory growth

## [2.4.9] - 2026-04-03

### Fixed

- Health check job silently dead — `'k' => 'discovery'` in data array overrode the `'job'` category via PHP `+` left-side precedence, making the entry unroutable by JobRouter; removed the override so the category argument takes effect
- LogManager: protect `k`, `n`, `rid` fields from caller override — `$entry = [n, rid, k] + $data + [ts]` ensures routing/identity fields can't be accidentally stomped while preserving intentional `ts` override for profiler
- RemoteManager: `log_status()` argument mismatch — error messages passed as `$lag` (int) instead of `$message` (string), silently discarding all sync error diagnostics
- StreamMerger: `output->write()` failure silently advanced position — now checks return value and skips position advance on write failure, preventing permanent data loss
- StreamMerger: heartbeat POST missing `redirection => 0` and `limit_response_size` — could leak Basic Auth credentials via redirect or consume unbounded memory
- StreamMerger: `restore_offsets` lost positions after offsetlog segment rotation — falls back to previous segment when newest is empty
- SSEClient: unbounded `current_event['data']` growth — added `MAX_EVENT_SIZE` (10MB) check to prevent OOM from malicious servers sending unlimited short `data:` lines
- SSEClient: position values from remote JSON not cast to `(int)` — added `(int)` casts with `max(0, ...)` clamping; also deduplicated heartbeat/entry position extraction
- SSEClient: reservoir sampling used `count` instead of `timed_count` as denominator, under-representing recent samples
- WorkersController: `read_permissions_check` and `restart_permissions_check` bypassed `allowed_users` whitelist — used raw `current_user_can('manage_options')` instead of `Admin::current_user_allowed()`
- HealthCheckExtensions: unbounded option growth across health check cycles — `merge_hooks` and `merge_events` now cap at `MAX_EVENTS` (10000) total accumulated entries
- HealthCheckExtensions: replaced fragile manual `remove_action`/`add_action` with `SettingsSync::suppress_sync()` for fan-out prevention
- HealthCheckExtensions: removed dead `$lookup` assignments overwritten by `array_flip`
- Performance-aggregator ingest filter: `wp_json_encode` returning `false` silently dropped job entries — now falls back to original `$line`
- Performance-aggregator ingest filter: duration merge in persist path biased toward old data — now shuffles before slicing for fair sampling
- Event-logger SettingsController: `max_lifespan=0` rejected by blanket `< 1` int validation — now uses per-option minimum (0 for max_lifespan, 1 for others)
- ServerRegistry: config-file servers could have URL/credentials overridden via `update()` — now restricts config-file servers to `enabled` toggle only
- ServerRegistry: config-file servers missing `logs` key — `get_all()` now normalizes all entries with required-key defaults
- FlameBuilder: URL index buckets could exceed memcache 1MB value limit — reduced per-bucket duration sample from 1000 to 100, added 500-URL cap per bucket
- Profiler: dead `get_declared_classes()`/`get_included_files()` initialization — replaced with `0` (values overwritten before first use)
- Profiler: first plugin timing inflated by WordPress bootstrap overhead — removed premature `get_option('active_plugins')` so baseline initializes right before the plugin loading loop

### Security

- ServerRegistry: `auth_password` now encrypted at rest via `sodium_crypto_secretbox` with key derived from `wp_salt('auth')` — transparent migration from legacy plaintext values
- SettingsSync (performance-aggregator): corrected docblock to describe actual re-entrancy scenario (hub self-sync, not spoke re-queue)

### Changed

- Version bump script now updates `_VERSION` constants in all plugin main files, not just the core plugin

### Security (previous)

- HealthCheckExtensions: sanitize remote hook/event names with `sanitize_text_field()` before writing to options — prevents null bytes, control characters, and HTML injection from compromised spoke servers
- HealthCheckExtensions: cap discovered hooks/events at 10,000 entries to prevent unbounded option growth
- HealthCheckExtensions: unhook both `update_option` and `add_option` SettingsSync handlers to prevent fan-out loops on fresh installs
- HealthCheckExtensions: fix data loss when `event_logger_log_events` arrives in associative format — dual-format extraction handles both `['hook' => true]` and `['hook']` storage
- HealthCheckExtensions: `try/finally` around SettingsSync unhook/rehook for exception safety
- StreamMerger: fix undefined behavior from closing cURL handle before removing from multi handle — save handle reference before `check_stale()` to ensure correct `curl_multi_remove_handle` ordering
- StreamMerger: guard `wp_json_encode` failure in `commit_all()` to prevent writing `false` to offsetlog, which caused duplicate event processing on worker restart
- StreamMerger: validate remote entry structure (`k` field must be a string) before writing to local firehose
- StreamMerger: check `curl_multi_add_handle` return value to prevent phantom handle-to-server map entries
- StreamMerger: add `json_decode` depth limit (16) on heartbeat response parsing
- SSEClient: disable `CURLOPT_FOLLOWLOCATION` and restrict `CURLOPT_PROTOCOLS` to HTTPS (with HTTP fallback when `verify_ssl` is disabled) — prevents SSRF via redirect and protocol downgrade attacks
- SSEClient: add 10MB buffer size limit to prevent memory exhaustion from malicious servers sending data without newlines
- SSEClient: fix SSE spec violation — dispatch events on blank line (not on `data:` field), accumulate multi-line `data:` with newline concatenation
- SSEClient: fix `curl_init()` TypeError on PHP 8.0+ by assigning to local variable before typed property
- SSEClient: preserve current position on missing heartbeat fields instead of resetting to 0
- RemoteManager: add `redirection => 0` and `limit_response_size => 1MB` on both `post_to_server` and `get_from_server` to prevent credential leakage via redirect and memory exhaustion from oversized responses
- RemoteManager: log `sync_setting` failures with `log_status()` instead of silently discarding errors
- RemoteManager: validate `$endpoint` from job parameters starts with `/wp-json/` before URL concatenation
- RemoteManager: validate discovery response schema — return only expected fields (`registered_hooks`, `custom_events`, `lag`) instead of raw remote JSON
- RemoteManager: add `json_decode` depth limit (16) on discovery response
- ServerRegistry: strip control characters and enforce 256-char max on `auth_password` — prevents null bytes and CRLF from corrupting Basic Auth headers
- ServerRegistry: fix cache/DB desync — only update in-memory cache after `update_option` succeeds, force reload on failure
- ServerRegistry: enforce `MAX_SERVERS` (100) on `add()` to prevent unbounded `wp_options` growth
- ServerRegistry: track config-file vs WP-option server provenance — write operations only persist WP-managed servers, preventing config-file contamination of the option
- ServerRegistry: `remove()` rejects config-file servers instead of silently "succeeding" while the server reappears on next load
- ServersController: whitelist discovery response fields instead of proxying raw remote JSON verbatim
- ServersController: return 502 on non-JSON responses instead of 200 "connected" with `null` data
- ServersController: tighten route regex from `[a-zA-Z0-9_-]+` to `{1,64}` to match `ServerRegistry::is_valid_id()`
- ServersController: add `redirection => 0`, `limit_response_size => 1MB`, and `json_decode` depth limit on `test_connection`
- StatusController: use `rest_authorization_required_code()` instead of hardcoded 403
- Config: update stale `aggregator_servers` sanitizer from `auth_token` to `auth_username`/`auth_password` — config-file server credentials were silently dropped
- Performance-aggregator bootstrap: replace raw `preg_replace` on JSON text with `json_decode`/check/modify/re-encode — prevents corrupting payload data containing `"k":"job"` as a string value
- Performance-aggregator bootstrap: simplify activation/deactivation hooks to direct callables
- Performance-aggregator SettingsSync: add re-entrancy guard (`$syncing` flag) to prevent sync loops on spokes receiving settings with same option names as hub
- Performance-aggregator SettingsSync: use `load_config('full')` for default resolution — fixes silent sync failure for `auto_disable_threshold`, `auto_protect_time_threshold`, and `significant_events` which have no config-file defaults
- Performance-logger SettingsController: allow `int_value >= 0` (was `>= 1`) — `auto_disable_threshold=0` means "disabled" and is valid
- Performance-logger SettingsController: suppress SettingsSync around `update_option` with `try/finally` to prevent sync loops
- Performance-dashboards Admin: narrow hook suffix check from `str_contains('newspack-event-logger')` to exact page match — prevents `eventLoggerDashboards` JS global collision on sibling pages
- Event-aggregator Admin: remove dead nonce field, duplicate AJAX handler; migrate test button to REST endpoint; add `esc_url_raw()` on localized URLs; fix asset loading to `require` + early return
- Event-aggregator Admin: change `register_setting` type from `integer` to `string` for settings that legitimately store empty string (meaning "use default")
- Event-aggregator bootstrap: add Event Logger core dependency check with admin notice
- Performance-request-log bootstrap: add Event Logger core dependency check with admin notice
- RequestsController: truncate `url` (2000 chars) and `user_agent` (500 chars) to match sibling controller patterns
- InflightTracker: add `?? 0` fallback on `$entry['status_code']` for consistency with all other field accesses
- InflightTracker: extract timeout magic number to `STALE_TIMEOUT` constant; fix non-Yoda `strpos` condition

### Added

- "Average Time per Event" chart — third category chart showing ms per event (time / count) for each profile category
- Category time series charts — "Time by Category" and "Events by Category" overlaid area charts on overview and per-URL views, showing profile category breakdowns over time in 5-minute buckets

### Changed

- Response Times chart moved after category charts in URL detail view (just above flame graph)
- Category charts show rates instead of per-request averages — Time by Category shows seconds/second, Events by Category shows events/second
- Removed "total" series from category charts — individual categories are more informative without a total envelope
- Removed x-axis padding from aggregate and category charts so they align temporally
- Collapsible layers in request log — all start/complete pairs folded by default, Fold All / Unfold All controls, Cmd/Ctrl+Click for recursive unfold, color swatch column for pair highlighting, duration/memory stats in message column
- Log entries search — search input in request detail view matches keywords and messages, Enter navigates to first match with lazy unfolding, n/N/p for next/previous, Escape clears and restores fold state, `/` focuses the search input
- Status code legend on Response Times scatter chart
- `/` keyboard shortcut focuses the URL search input on the overview page

### Fixed

- Performance aggregator SettingsSync resolves empty/false option values to config defaults before queuing sync jobs — prevents silent 400 errors on spoke endpoints when array or numeric options are cleared
- LogManager `message()` now allows callers to override `ts` — the `$data + defaults` union order was inverted, silently discarding timestamp overrides from the profiler; plugin load events now appear at their actual load time instead of clustering at flush time
- FlameBuilder pending-bucket architecture — all bucketed stats (hourly, dimensional, URL, category) now accumulate in a pending buffer for the current 5-minute bucket, promoted to memcache only when the bucket completes; prevents silent memcache write failures from unbounded category growth exceeding the 1MB slab limit, and ensures accurate top-N rankings with complete bucket data; pending state persists across worker restarts via offsetlog
- Category time series capped at 50 categories per bucket (overflow rolled into "Other"), matching the dimensional cap pattern
- Renamed `$hour_key` to `$bucket_key` throughout FlameBuilder (buckets are 5-minute, not hourly)
- LogManager closes orphaned hooks before logging memory/resources in `finish()`
- Hook categories: 17 new plugin patterns (AutomateWoo, BuddyPress, Complianz, Divi, Formidable Forms, Freemius, OneSignal, Password Protected, Query Monitor, Swift Performance, Ultimate Member, WP Activity Log, WP Rocket, WPML, Web Stories, AI/Agents) and extended 12 existing categories with ~80 new WP core hooks
- Significant events automatically added to `log_events` — hooks in `significant_events` that aren't already in `log_events` are instrumented automatically, so adding a significant event is all you need
- `Memcached::get_multi()` — batch multiple keys in a single round-trip (uses `\Memcached::getMulti()`, falls back to serial for legacy `\Memcache`)
- `errors.log` — RequestBuilder forwards error and warning entries to a dedicated firehose, browsable in the raw log viewer
- Raw log viewer starts with ~1MB of recent entries instead of an empty screen
- "Errors Only" filter on URL overview table — filters to URLs with timed-out or fatal requests
- Fatal error detection in `LogManager::finish()` — calls `error_get_last()` during shutdown and tags `process (complete)` with `fatal_error`, `fatal_file`, `fatal_line`, `fatal_plugin`, and `error_status='F'`
- Request index v4 format — 1-byte error status at position 96 (`F` fatal, `T` timeout, `-` normal), backwards-compatible with v3
- 1-hour orphan timeout in RequestBuilder — stuck requests are force-written to `requests.log` with `error_status='T'` instead of silently evicted
- REST API `error_status` filter — URL detail endpoint returns `error_status` and accepts `?error_status=F,T` to filter to error requests
- Dashboard "Errors only" toggle — filters request list to fatal/timed-out requests with red `F` and amber `T` indicators
- Per-callback profiling for significant events — hooks in `significant_events` get each callback wrapped with timing (e.g. `do_blocks @9 (complete): 800ms` nested inside `the_content hook`)
- Closure identification via ReflectionFunction — closures now show `{closure}:filename.php:42` instead of `{closure}`
- `log_memory` config option — appends `peak_mb` to every `complete()` log entry for tracking memory growth
- `flush_every_line` config option — flushes write buffer after every log line (survives OOM kills)
- Admin UI "Debugging" section with checkboxes for both debugging options
- REST config endpoints and settings sync for new options
- Configurable `hook_start_priority` (default -10000) to capture callbacks at all priorities
- Salt-based cache invalidation for StatsStore — "Clear Memcache Stats" rotates a salt to orphan all keys instantly, including per-URL stats that were previously skipped
- Use mu-profiler's boot `hrtime` for process timer — request duration now includes plugin loading time on first request; subsequent requests after `reset()` use current time
- JobWorker: `begin_job_context()` / `end_job_context()` for per-job request isolation
- RemoteManager: Use `begin_job_context()` for per-job request isolation

### Fixed

- `LogReader::publish_positions()` called on every 1ms loop pass (~1000 memcache writes/sec) — now rate-limited to heartbeat cadence (~10s)
- Supervisor double-touched heartbeat every iteration (explicit `lock->touch()` redundant with `should_restart()` internal touch) — removed redundant call
- Partition reduction missed standalone worker locks — now force-releases both log reader and standalone worker locks for retired partitions
- Missing `stream_set_timeout` on rawlogs and errors SSE controllers — a hung `fgets()` blocked all partitions; added 1-second timeout and `timed_out` check matching gyroscope/requests pattern
- FlameBuilder `$slb['count']` init guard fired inside per-category loop instead of per-request — removed bogus guard; the real increment at the request level handles initialization correctly
- `PHP_INT_MAX` sentinel persisted to raw memcache entries for `min_ms` — replaced with 0 initialization and first-value-wins merge logic; removed post-hoc fixup in `get_merged_url_index`
- `break 3` in `get_merged_url_index` silently dropped all URLs from later partitions when cap reached — changed to `break 2` so partition loop continues accumulating stats for already-seen URLs
- Aggregator `update_item` never requested supervisor restart — stale server config persisted for up to 10 minutes; now calls `Supervisor::request_restart()` matching create/delete pattern
- `spl_object_id()` used as curl handle key in StreamMerger — unsafe after handle reuse; replaced with `(int) $handle` which is stable for the handle's lifetime
- `curl_multi_exec` called only once per poll — must loop while `CURLM_CALL_MULTI_PERFORM` returned; added do/while loop
- `LogManager::finish()` reset `started`/`tracked` flags allowing re-initialization by subsequent shutdown handlers — added `$finished` guard preventing re-entry via `ensure_started()`/`ensure_tracked()`
- `$_SERVER['UNIQUE_ID']` mutation leaked across `suspend()`/`resume()` contexts — child context overwrote parent's UNIQUE_ID; now saved on suspend and restored on resume
- `num_partitions=0` passed REST settings sanitization — causes divide-by-zero in Firehose; changed lower bound from 0 to 1 in both settings controllers
- JS `WorkerStatus` animation `setTimeout` callbacks not cancellable on unmount — added ref-tracked timers cleared on each fetch and on component unmount
- Version constants frozen at `1.0.0` across all 9 satellite plugins while headers showed `2.4.7` — broke asset cache busting; updated all constants to match
- Custom events leaking into `log_events` on aggregation hub — discovery endpoint now filters `custom_events` names out of `registered_hooks`, and `merge_hooks()` skips any hook that exists in `custom_events`
- Custom event names (pyrobase, gyrobase, etc.) appearing in hook selector — significant events auto-add now skips names in `custom_events` to avoid calling `add_filter()` on non-hook names
- `Firehose::read_at()` treated empty reads as failures — `$data ?: null` returns null for empty string (valid zero-length read), making it indistinguishable from file-not-found; now uses strict `false === $data` check
- `JobIntake::queue()` spun for 300 seconds on permanent write failures — retry loop couldn't distinguish lock contention (transient) from disk-full or size-exceeded (permanent); now checks if lock was acquired and bails immediately on non-lock failures
- JS dashboard crash when `window.eventLoggerDashboards` not set — unguarded property access on the global threw TypeError crashing the entire React tree; added null-guard with error state fallback
- Missing `use Newspack_Event_Logger\Memcached` import in event-logger and performance-dashboards admin classes — bare `Memcached::DEFAULT_SERVERS` resolved to wrong namespace, causing latent fatal error when `memcache_servers` config key is absent
- SSE heartbeats never fired — `FirehoseReader::is_caught_up()` always returned false for SSE controllers because they use `fgets()` directly (which never sets `at_eof`), and `next_segment()` unconditionally cleared `at_eof` when staying on the same segment. Added `mark_eof()` method for `fgets()` callers, removed the unconditional `at_eof=false` from `next_segment()`, and added heartbeats to the gyroscope controller
- Staleness indicator showed `-1s` when events arrived between timer ticks — clamped to 0
- Job requests (health_check, etc.) logged 0ms duration — `finish()` hardcoded `duration_ms: 0` for tracked-but-not-started contexts; now always computes real duration from the process timer stack
- Supervisor requests orphaned after first job dispatch — `begin_job_context()` called `reset()` which destroyed the parent LogManager; now uses `suspend()`/`resume()` to preserve parent context on a stack, giving supervisors and job workers a continuous trace across all job executions
- Job context durations showed full PHP uptime instead of actual job time — the `$boot_time_used` static flag failed to prevent reuse of the mu-profiler boot hrtime across job contexts; replaced with consume-and-unset in the constructor so only the first LogManager instance gets boot time
- Worker requests (spawn, health_check) polluted avg response time chart — dimensional stats (status code breakdowns) were not gated on `$has_timing`, and hourly count included workers inflating the denominator; also detect worker type from explicit `worker_type` log entry since env var is set after initial logging
- Sensitive query params (client_secret, apiKey, sig, key) visible in logged URLs — apply URL_REDACT_PATTERN to message `m` field
- Worker status pipeline ordering broken — workers controller read `output` (singular) but registrations use `outputs` (plural array)
- Worker restart on salt rotation — `flush_all()` called `Supervisor::request_restart()` (restarts supervisor, not workers); `updated_option` hook skipped restart when reader not in registry; also hook `added_option` for first-time salt creation
- Fix 0ms request duration when `ensure_tracked()` runs before `ensure_started()` — mu-profiler calling `message()` during plugin loading set `tracked=true` early, causing `ensure_started()` to skip setting `started=true`; `finish()` then fell through to the tracked-only 0ms branch
- Block root processes in `ensure_tracked()` — root running as root could create firehose files with wrong ownership, breaking www-data workers
- LruCache `get()` and `delete()` used `isset()` which returns false for stored `null` values — replaced with `array_key_exists()`
- Test infrastructure: `apply_filters` stub now passes extra arguments to callbacks; `add_action`/`do_action` now route through the filter system instead of being no-ops
- Default `wp eventlog worker run` partition to 0 — no longer requires explicit `--partition=0` flag
- Discovery endpoint returned color hex values as event names — for associative `custom_events` entries like `'pyrobase' => '#FF7600'`, the color string matched first and was added as an event name, doubling the option on each discovery cycle
- Discovery event merge auto-selected discovered events — now writes to `event_logger_discovered_events` so they appear as available in the selector modal but unchecked
- Remote `max_lifespan` sync silently rejected — `event_logger_max_lifespan` was missing from the spoke settings controller whitelist
- Remote settings sanitizers converted empty input to 0 instead of preserving config file defaults — "use default" reset button saved 0, overriding the file default
- Settings sync sent empty string to spokes when hub used file default — spoke ignored it and fell back to its own (different) default; now resolves the hub file default before syncing
- Timed-out requests (error_status=T) excluded from timing stats — synthetic durations no longer skew avg, percentiles, flame data, or profile breakdown
- `flush_buffer()` null guard — prevents fatal when LogManager is used in root/CLI context without firehose initialization
- T/F error indicators moved to status cell in request list (was a separate column)
- Request detail view shows method, status, and error label for timed-out/fatal requests; displays message when no entries available
- RequestBuilder `save_state()` preserves entries and profiles across worker restarts — timed-out requests retain trace data
- Worker status: ALL RUN/DEAD badges and restart controls aligned to right edge

### Changed

- Magic numbers replaced with named constants: SSE heartbeat interval (4 controllers), supervisor stale partition age, RequestBuilder payload scan length
- `MAX_PARTITIONS` mismatch: performance controller base reduced from 64 to 16 to match StatsStore
- `MAX_JSON_DEPTH` mismatch: JobWorker raised from 10 to 64 to match JobRouter (deep payloads no longer silently dropped)
- Redaction marker unified to `[REDACTED]` (was `***` in `redact_url()`, `[REDACTED]` in `message()`)
- `RemoteManager::STALE_THRESHOLD` raised from 300s to 600s — was equal to health check interval, causing race-to-drop under cron latency
- `StatsStore::get_url_time_series()` and `get_merged_url_index()` batch all partition keys per bucket via `get_multi()` — reduces memcache round-trips from `buckets x partitions` (up to 4,608) to `buckets` (up to 288) per call
- RequestBuilder: replace `sweep_orphans` with time-based LRU bucket rotation (3 buckets × 100 requests, rotated every 200s). Active requests are promoted on access and survive; stale requests are evicted with `error_status=T` when their bucket expires
- LruCache: add `with_timed_rotation()` for time-based eviction with callback, and `rotate_if_due()` for caller-driven periodic rotation
- Extract `SSEControllerBase::stream_log()` — shared SSE polling loop with resume, batching, heartbeats; rawlogs, errors, and requests controllers now provide only config + line transformer callback (~460 lines removed)
- Removed dead code: `raw_queue` in JobRouter, `maybe_migrate_options()` empty stub in Config, `messages_read` field in RequestBuilder, `wpApiSettings` legacy localization in performance-dashboards
- Admin dependency notices: `class="error"` → `class="notice notice-error"` (deprecated since WP 4.2)
- `house_is_dirty` in LogReader: `float` → `bool` (was used as bool/int/true)
- Removed dead `isPaused` guards from SSE event handlers in ErrorLog, RawLogs, RequestStream (pausing disconnects, so handler never fires while paused)
- Log reader registration: `output` (string) renamed to `outputs` (array) to match `inputs`
- Stats/graphs: retention window and memcache TTLs now driven by `max_lifespan` config instead of hardcoded 24 hours
  - StatsStore TTL constants replaced with `ttl()` / `ttl_url_stats()` methods derived from retention
  - Bucket count computed from retention (was hardcoded 288)
  - FlameBuilder and REST API cutoffs use `StatsStore::get_retention()`
  - JS chart reads `retentionSeconds` from localized config
- Replace per-hook closure pairs with `hook_start`/`hook_complete` methods using `current_filter()`
- Switch duration timing from `microtime(true)` to `hrtime(true)` — monotonic nanosecond clock unaffected by NTP adjustments
- Remove per-hook callback list logging — redundant now that per-callback wrapping shows each callback individually
- Callback labels shortened to `{name} @{priority}` — hook context is visible as the parent in flame graphs
- Pretty-print non-scalar filter values in hook start messages (JSON_PRETTY_PRINT)
- Raise MAX_LOG_LINES from 4,000 to 50,000 and MAX_ENTRIES_PER_REQUEST from 5,000 to 20,000
- Truncate hook start `m` values to 1024 bytes (was unbounded, full HTML content)
- Callback wrapping now runs every invocation to catch late-registered callbacks (wrapper_ids prevents double-wrapping)

### Performance

- Intern keyword strings in RequestBuilder — identical strings from json_decode share one zval (~1.6MB savings per in-flight request)
- Intern category, entry name, and dimension value strings in FlameBuilder (200-400KB/min savings)
- Compact RequestBuilder entry storage — truncate `m` to 1024 bytes, only store `duration_ms` when present
- 00-newspack-profiler: derive wall-clock timestamps from hrtime deltas instead of extra microtime() syscalls

### Fixed

- Fix firehose logs starting at line 5 instead of line 1 — Firehose constructor re-entered LogManager before `request_id` was set
- Fix callback wrapper inflating `accepted_args` from 0 to 1, passing unexpected arguments to callbacks and triggering `wp_json_encode` circular reference crashes during core upgrades
- Fix callback wrapper not completing timing on crash — add `try/finally`
- Fix per-callback categories inflating total profiled time above 100% — skip in FlameBuilder `$req_time` accumulation, JS `profiledTime` calculation, and profile summary bar
- Fix nested hooks double-counting time — walk up stack past callback entries to subtract from the nearest non-callback ancestor
- Include callback categories in aggregate profile display but exclude from totals, auto-disable, and significant event detection
- Fix profile summary bar not matching flame graph — remove 0.5% minimum threshold for bar segments
- PHP 8.2 compatibility: use `spl_object_id()` instead of dynamic properties on Closure objects

## [2.4.6] - 2026-02-23

### Added

- Add peak memory tracking to performance dashboard — overview stats show Avg Peak Memory, URL table bars reflect selected metric (volume/response time/memory), URL detail request table includes sortable Mem column
- Add `memory` metric option to aggregate time charts — line chart shows average peak memory over time with MB axis labels
- Add `m` (sum_peak_mb) field to dimensional stats — FlameBuilder now accumulates peak memory per dimension (status, method, server) in global, per-server, and per-URL buckets; StatsStore merges across partitions; enables memory breakdowns in charts
- Add `peak_mb` field to request index (v2 format) — backward-compatible 6-char extension at position 89, parsed when present
- Add `peak_mb` to RequestBuilder state callbacks — extracts memory peak from firehose `memory` entries
- Track `sum_peak_mb` and `max_peak_mb` in StatsStore and FlameBuilder hourly aggregation
- Add `avg_peak_mb` and `max_peak_mb` to URL stats REST response
- Emit real duration for line-limited requests in LogManager — requests that hit MAX_LOG_LINES now compute actual duration from the process timer instead of reporting 0ms

### Fixed

- Fix infinite loop during WordPress core update — `wp_json_encode()` on `Core_Upgrader` objects with circular references caused unbounded recursion in the log events hook. Use `json_encode()` with depth limit instead, and store the encoded string rather than the raw object.
- Fix blank lines in `jobs.log` — `JobRouter::process_jobintake_entry()` passed raw lines from `read_line()` (which include trailing `\n`) to `Firehose::write()` (which appends another `\n`), producing double newlines after every jobintake entry
- Fix RequestBuilder not writing to `requests.log` — LogManager deferred request ID generation until the first buffer flush (`init_firehose()` called lazily from `flush_buffer()`), so all early lifecycle entries (`process (start)`, `request`, `environment_v2`) were written with empty `rid`. RequestBuilder silently dropped every entry with no rid, meaning no request was ever initialized and no completions were matched. Move `init_firehose()` to run eagerly in `ensure_tracked()` / `ensure_started()` so the request ID exists before any messages are buffered.
- Fix workers silently dying when job handler code calls `exit()` or `die()` — PHP's `exit()` bypasses `finally` blocks entirely, so the lock was never released and the heartbeat stopped being touched. Supervisor had to wait for the full 900s stale timeout before respawning. The shutdown handler now detects exit() (via a `$shutdown_handled` flag set in the finally block), logs the termination, and releases the lock immediately.
- Fix settings sync returning 400s on spokes — `SettingsController::sanitize_array()` rejected arrays with >1000 entries, but `log_events` legitimately exceeds 1000 from hook discovery. Raised limit to 5000.
- Fix `HealthCheckExtensions::merge_events()` corrupting `custom_events` — used `array_merge` + `array_unique` on mixed associative/indexed arrays, dropping duplicate boolean values. Rewrote to use keyed-insert pattern matching `merge_hooks()`.
- Fix `HealthCheckExtensions` using raw `get_option()` instead of `Config::load_config()` — bypassed config file overrides and `array_strings` sanitization.
- Remove `ignore_user_abort()` from `WorkerBase::execute()` — not the root cause of worker deaths
- Fix "Invalid parameter(s): sort" error on Performance Dashboard — `avg_peak_mb` was missing from URL endpoint's sort validation whitelist, so clicking the Mem column header returned a 400 error
- Fix URL table bar chart always showing response time — bars now reflect the selected metric (request volume, response time, or memory)
- Fix auto-tuning not disabling noisy hooks — `performance_workers_disable_hooks` handler used `unset($existing[$hook])` to remove by key, but `event_logger_log_events` is a flat indexed array (keys are 0,1,2... not hook names), so the unset had no effect. Rewrote to filter by value using `array_filter`. Also standardized all writers to produce flat indexed arrays: `DashboardController::configure_hooks` and `update_config` array_assoc type were saving associative format, `HealthCheckExtensions::merge_hooks` was using `isset()` key lookup and inserting `$hook => false` entries
- Fix URL detail request table bar chart ignoring metric — bars always showed duration regardless of metric dropdown selection
- Fix memory metric chart showing only "Total" instead of dimensional breakdowns — `AggregateTimeChart` was forcing `breakdownData` to null when memory was selected because dimensional stats lacked memory data; now uses `m/c` from breakdown buckets
- Fix stats grid layout — changed from CSS Grid to Flexbox with `space-between` so stats spread evenly across full width
- Fix URL table Mem column values overflowing into adjacent columns — widened timing and memory column grid tracks

## [2.4.5] - 2026-02-22

### Fixed

- Add `ignore_user_abort(true)` to `WorkerBase::execute()` — root cause of silent worker deaths. Workers spawned via fire-and-forget POST (0.01s timeout) were silently killed by PHP when it detected the client disconnect on the next output operation
- Fix `worker list` CLI and REST API using hardcoded 30-second heartbeat threshold instead of actual `stale_timeout` from reader/worker config — job-workers (900s stale timeout) were incorrectly shown as "dead" while actively processing long-running handlers

### Added

- Log reason when workers exit via `Lock::should_restart()` — restart requested, heartbeat file gone, or lock stolen now all produce `error_log` entries with lock path and PID details
- Add `register_shutdown_function` to `WorkerBase::execute()` to catch fatal errors (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR) that bypass try/catch, log them with file/line, and release the lock immediately for faster supervisor respawn
- Periodic database connection health check in `WorkerBase::should_restart()` — pings `$wpdb` every 30 seconds, restarts worker after 3 consecutive failures (90 seconds) to prevent silent job processing failures from stale MySQL connections
- Log when workers exit due to max runtime reached

## [2.4.4] - 2026-02-22

### Added

- Add `AGENTS.md` with project knowledge, commands, architecture decisions, and common pitfalls for coding agents

### Fixed

- Route SettingsSync jobs through JobIntake (`jobintake.log`) instead of LogManager (`firehose.log`) — settings like `log_events` (array of 50+ hook names) can exceed the 4KB PIPE_BUF atomic write limit, causing silent truncation and lost sync jobs
- Fix undefined `$handler_name` variable in JobIntake size-limit error message (should be `$handler`)

## [2.4.3] - 2026-02-20

### Fixed

- Discovery endpoint returned integer indices instead of hook names when `log_events` was in sequential format (`array_keys()` on `[0 => 'hookA']` returns `[0]`, not `['hookA']`)
- Core hook registration skipped non-string values — `false` entries (from merge_hooks "unchecked" state) were passed to `add_filter()`, registering dead closures on `$wp_filter[0]`
- Auto-tuning `disable_hooks` handler corrupted `log_events` format: `array_filter()` + `array_values()` converted associative `['hookA' => 'hookA']` to sequential `[0 => 'hookA']`, breaking `isset()` lookups in merge_hooks and discovery. Changed to `unset()` to match `disable_custom_events`
- Add re-entrancy guard to `flush_buffer()` — lazy firehose init (2.4.2) could recurse when `init_firehose()` triggered hooks (e.g. `updated_option`, `deleted_option`) that fired instrumented closures, which called `message()` → `flush_buffer()` again with firehose still null, causing infinite recursion OOM

## [2.4.2] - 2026-02-19

### Changed

- Eliminate per-request overhead for filtered URLs: move URL filter check before request ID generation and firehose initialization
- Lazy firehose + request ID: defer filesystem ops and ID generation to first `flush_buffer()` write via `init_firehose()`; cascade `HTTP_X_A8C_REQUEST_ID` → `UNIQUE_ID` → `generate_request_id()` + publish to `$_SERVER`
- Extract `generate_request_id()` as public static for direct callers (nuclear-gyrobase cron, pyrobase evtemplate)
- Cache `Config::get_logs_directory()` result so `ensure_path()` chain runs once per request
- Consolidate `ensure_path()` + `validate_path()` into single method; move null byte check before filesystem operations

### Fixed

- Restore `is_dir()` guard before `mkdir()` in `ensure_path()` — it's not that PHP is... uniquely inefficient here, but `mkdir(recursive=true)` is genuinely more expensive than `is_dir()`, and caused OOM on memory-constrained sites
- Cache `get_locks_directory()` and `get_offsets_directory()` results (were calling `ensure_path()` on every invocation)

## [2.4.1] - 2026-02-18

### Added

- SpawnController sets `EVENT_LOGGER_WORKER_TYPE` and `EVENT_LOGGER_WORKER_PARTITION` in `$_SERVER` before worker execution, making worker identity available in environment logging

### Changed

- Move LogReader housekeeping after caught-up check so flush happens after processing, not before
- Simplify JobRouter: write jobintake lines directly to jobs_log instead of buffering in raw_queue
- Consolidate size guards: rename `MAX_JOB_PARAMS_SIZE` / `MAX_PARAMS_SIZE` to `MAX_JOB_SIZE`, check once at entry

## [2.4.0] - 2026-02-14

### Changed

- Refactor LogReader to multi-handler group architecture with unified offsetlog per group
- Refactor FirehoseReader to pure stateless reader — offsetlog persistence moved to LogReader
- Split directory structure into `/logs`, `/locks`, `/offsets` with granular Config accessors
- Simplify WorkerBase: consolidate housekeeping methods into `should_restart()`
- Add `'type' => 'job'` to all job messages for consistent dispatch routing
- Update WorkerStatus UI to group workers by handler with pipeline visualization
- Fix RawLogs canvas flash — snapshot buffer to prevent SSE mutation between draw frames
- Update ARCHITECTURE.md and API.md documentation

### Removed

- Remove `class-health-check.php` (dead code — health checks already run via Supervisor)
- Remove `class-hook-categorizer.php`
- Remove FirehoseReader offset methods: `restore()`, `commit()`, `get_offset()`, `get_filehandle()`
- Remove `RemoteManager::get_enabled_servers()`, `get_max_servers()`; `SSEClient::get_server_id()`
- Remove `Memcached::reset()`, `get_extension()`, `incr()`, `decr()`, `count_sse_slots()`
- Remove unused SCSS variables and base.scss imports

## [2.3.14] - 2026-02-14

### Changed

- Supervisor: Skip spawning log reader workers when input log doesn't exist or worker is caught up
  - Checks input partition directory existence (cheap `is_dir`)
  - Compares committed offset against newest segment every 15s per worker
  - Caches results between checks; defaults to spawning on unknown state
- Firehose: Make `segment_size` and `num_segments` constructor params optional, defaulting to config
  - Removes redundant config-loading boilerplate from ~19 call sites
  - Callers with custom sizes (offsetlog firehoses) still pass explicit values

## [2.3.13] - 2026-02-13

### Changed

- Performance Dashboard: Compress synthetic time-gap rows in log entries table (2026-02-13)
  - Escalating intervals: 10ms×10, then 100ms×10, then 1s×10, then 10s×10, etc.
  - A 3-minute gap that produced ~18,000 rows now produces ~40 rows
  - Log Entries count now shows only real entries, excluding synthetic placeholders

## [2.3.12] - 2026-02-12

### Added

- JobWorker: Add `remote_job` handler support via `event_logger_remote_job_handlers` filter
  - Separate handler registry for jobs that run on a hub server (e.g., Cloudflare CDN purges)
  - `dispatch_job()` routes by job type: `job` → local handlers, `remote_job` → remote handlers

### Changed

- LruCache: Rename `clear()` to `flush()` for consistency with cache API conventions
- SupervisorBase: Simplify `delete_directory_recursive()` symlink handling
  - Combine `is_dir` + `is_link` checks into single condition per item
  - Remove redundant per-item symlink skip (directory-level check is sufficient)
- JobRouter: Accept both `"k":"job"` and `"k":"remote_job"` keywords
  - Passes job type through validation into queued job entries
- LogReader: Remove unused `$partition` parameter from handler `process()` interface (2026-02-12)
  - Handler signature is now `process($line, $input, &$context)` (was 4 params)
  - Updated all handlers: JobRouter, JobWorker, FlameBuilder, RequestBuilder
- LogReader: Inline `process_line()` into main loop for fewer method calls (2026-02-12)
- LogReader: Cache handler class reference outside loop (2026-02-12)
- FlameBuilder: Update `clear()` → `flush()` call to match renamed LruCache method

### Fixed

- FirehoseReader: Only set `current_segment` after successful file open (2026-02-12)
  - Prevents stale segment state when `open_segment()` fails
- FirehoseReader: Remove stale TOCTOU comments from `open_segment()` callers (2026-02-12)

## [2.3.11] - 2026-02-07

### Documentation

- ARCHITECTURE.md: Comprehensive review and correction (2026-02-07)
  - Fixed dependency graph (added event-jobs dependency for event-aggregator)
  - Fixed LogReader handler signature (4 params: `$line`, `$partition`, `$segment_id`, `$offset`)
  - Fixed RequestBuilder LRU capacity (200, not 10,000)
  - Fixed JobWorker security limits (10MB params, depth 10/64)
  - Fixed ServerRegistry API (`add()` takes config array, no `enable()`/`disable()` methods)
  - Fixed SettingsSync options (added `log_urls`, `skip_urls`)
  - Fixed StatsStore keys and TTLs (URL index TTL is 24h, not 1h)
  - Fixed File Layout (corrected paths for locks, offsetlogs, CLI)
  - Removed non-existent `FirehoseReader::get_saved_state()` method
  - Added missing Firehose `get_segment_path()` method
  - Added missing Lock `set_shutdown_time()` method and `.lock.d` convention
  - Added missing JobIntake `partition()` method
  - Added CLI stdin/pipe mode documentation
- API.md: Comprehensive review and correction (2026-02-07)
  - Fixed `/firehose/stream` params (added `aggregator` boolean)
  - Moved hook endpoints (`registered-hooks`, `hook-categories`) to correct plugin (performance-logger)
  - Removed non-existent `/servers/{id}/enable` and `/servers/{id}/disable` endpoints
  - Added `/servers/{id}/test` endpoint
  - Fixed `ServerRegistry::add()` signature (config array, not separate params)
  - Fixed `WorkerBase::init_worker()` signature (4th param `$stale_timeout`)
  - Added `WorkerBase::execute()` method
  - Added `Lock` optional constructor param and `set_shutdown_time()` method
  - Added `Firehose::write_raw()`, `get_segments(true)`, accessor methods
  - Fixed `LogManager` convenience methods (optional `$data` param)
  - Removed non-existent `FirehoseReader::get_saved_state()` method

## [2.3.10] - 2026-02-02

### Added

- Event Aggregator: `event_aggregator_ingest_line` filter in StreamMerger (2026-02-02)
  - Fires on each JSON line before writing to the hub firehose
  - Return null to drop the line; handlers should be fast (no I/O)
  - Parameters: `$line` (JSON string), `$server_id`, `$partition`
- Performance Aggregator: Rewrite `"k":"job"` → `"k":"remote_job"` during aggregation (2026-02-02)
  - Uses `event_aggregator_ingest_line` with fast `strpos()` match
  - Jobs are already routed to `jobs.log` by JobRouter on each spoke; rewriting
    prevents re-routing on the hub while preserving entries for diagnostics

### Changed

- Performance Logger: Include non-string hook arguments in log entries (2026-02-02)
  - Scalars (int, bool, float) are now included directly
  - Arrays/objects included if JSON-encoded size is ≤ 1 KB, omitted otherwise

### Fixed

- Performance Workers: Handle non-string `m` values in RequestBuilder and FlameBuilder (2026-02-02)
  - Hook args can now be arrays; coerce to empty string for stack/flame labels
  - Array data is still preserved in stored request entries

## [2.3.9] - 2026-02-01

### Changed

- Performance Dashboard: Server filter now applies page-wide (2026-02-01)
  - Lifted server dropdown state from OverviewSection to PerformanceDashboard
  - Summary stats (Total Requests, Avg Response, Req/s) recompute from per-server dimensional buckets
  - Unique URLs shows "---" when filtered (can't compute per-server)
  - Global Time Breakdown hidden when a server is selected (no per-server flame data)
  - Breakdown chart and URL table continue working as before
- LRU cache tuning (2026-02-01)
  - LruCache defaults: 250×5 buckets (was 1000×10)
  - FlameBuilder stats cache: 1000×5 buckets (was 500×10)
  - RequestBuilder request cache: 50×4 buckets / 200 capacity (was 50×3 / 150)

## [2.3.8] - 2026-01-31

### Changed

- Lock: Stop touching heartbeat when remaining runtime < stale_timeout (2026-01-31)
  - Heartbeat ages naturally so it expires right at shutdown
  - Supervisor can reclaim the lock immediately instead of waiting stale_timeout after stop
  - Especially important for JobWorker (900s timeout) -- previously looked alive 15 min after death

## [2.3.7] - 2026-01-31

### Fixed

- Raw Logs: Fix scroll jumping when new entries arrive while scrolled down (2026-01-31)
  - Update spacer height imperatively before adjusting scrollTop to prevent browser clamping
  - Guard onScroll handler against programmatic scroll adjustments

## [2.3.6] - 2026-01-31

### Added

- Per-reader configurable stale_timeout for lock heartbeats (2026-01-31)
  - Threaded through WorkerBase, LogReader, Supervisor, and Workers dashboard
  - JobWorker set to 900s (15 min) — job handlers like imports can block for minutes
  - All other readers default to Lock::STALE_TIMEOUT (60s) — no behavioral change
  - Workers dashboard uses per-reader timeout for "running" status display

## [2.3.5] - 2026-01-31

### Fixed

- Performance Dashboard: Req/s calculation uses complete 5-minute buckets (2026-01-31)
  - Was using the most recent (in-progress) bucket divided by 3600, giving near-zero values
  - Now sums up to 12 complete buckets (last hour), skipping the current in-progress one
  - Divisor scales to actual bucket count so startup periods are accurate too

### Changed

- Performance Dashboard: Auto-refresh breakdown chart data every 5 minutes (2026-01-31)
  - Overview and URL detail 24-hour charts now poll for updated dimensional data
  - Keeps stacked bar/line charts current without requiring manual filter change

## [2.3.4] - 2026-01-30

### Changed

- State efficiency and OOM prevention (2026-01-30)
  - LogManager: Hard cutoff at 5,000 lines — disables logging while keeping lifecycle tracking
  - RequestBuilder: Entry cap lowered to 5,000 (aligned with LogManager), truncated flag added
  - RequestBuilder: Completed requests written immediately to requests.log (queue eliminated)
  - RequestBuilder: save_state() strips entries and profiles from in-flight requests (small offsetlog)
  - RequestBuilder: LruCache right-sized from 1,000 to 200 max in-flight requests
  - RequestBuilder: Drops job entries with n > 4000 to preserve bandwidth for lifecycle signals
  - JobRouter: strpos pre-filter skips json_decode on ~99% of firehose lines
  - JobRouter: jobintake.log uses regex handler extraction + raw passthrough (no decode/re-encode)

## [2.3.3] - 2026-01-30

### Added

- Performance Dashboard: Per-URL dimensional breakdowns in URL detail view (2026-01-30)
  - Metric and Breakdown dropdown controls (same as overview chart) now appear in URL detail modal
  - Per-URL dimensional stats accumulated in FlameBuilder and stored in memcache (all 7 dimensions per URL)
  - New `?breakdown=` REST param on URL detail endpoint returns per-URL dimensional time series
  - `fetchUrlBreakdown()` API hook for frontend to request per-URL breakdown data
  - MAX_URL_DIM_VALUES = 10 (tighter cap than global 20) keeps per-URL data compact
  - Single memcache key per URL per partition (~30MB worst case for 500 active URLs)

## [2.3.2] - 2026-01-30

### Changed

- Consolidate shared hooks and utilities across plugins (2026-01-30)
  - Canonical sources in `src/shared/hooks/` and `src/shared/utils/`
  - `sync-shared.sh` copies to each plugin that needs them (run via `npm run sync`)
  - Sync runs automatically as part of `npm run build`
  - Unified `useFirehoseConnection` on `eventLoggerDashboards` global (fixes nonce issues)
  - Gyroscope and Request Log admin now localize `eventLoggerDashboards` (was `wpApiSettings`)
- Performance Dashboard: Flatten `src/performance/` directory to `src/` (2026-01-30)
  - Components, hooks, and utils moved up one level for consistency with other plugins
  - Deleted unused `usePageVisibility.js` copy from perf-dashboards

## [2.3.1] - 2026-01-30

### Changed

- Performance Dashboard: Status codes as a regular dimension (2026-01-30)
  - Status codes (2xx/3xx/4xx/5xx) tracked as dimensional stats like method/country/ua/etc
  - Status breakdown now works with per-server filtering (same as all other breakdowns)
  - Removed special-case status code rendering path in favor of unified dimensional pipeline
  - Status colors (green/blue/orange/red) preserved via STATUS_COLORS map
  - Pruned dead `count_2xx/3xx/4xx/5xx` from hourly aggregate stats (now served by dimensional data)
- Performance Dashboard: Per-server dimensional breakdowns in hub mode (2026-01-30)
  - Breakdowns (status, method, country, from, UA, JA4) now filter to selected server's data
  - Per-server dimensional stats stored in memcache (hub mode only, gated on remote servers)
  - "Server" breakdown hidden when a server is already selected (redundant)

## [2.3.0] - 2026-01-30

### Added

- Performance Dashboard: Dropdown-controlled time series charts (2026-01-30)
  - Metric dropdown: Request Volume, Avg Response Time, Cumulative Response Time
  - Breakdown dropdown: Status Codes, Method, Server, Country, From, User Agent, JA4 Hash
  - Dimensional time-series storage in memcache (per dimension, 5-min buckets, 24h TTL)
  - Top-20 capping per dimension with "Other" rollup to prevent unbounded growth
  - Server filter dropdown auto-detected in hub mode (2+ servers)
  - Stacked bars for volume/cumulative, line chart for avg response time
  - Human-readable cumulative time formatting (ms, s, Ks)
- Request Builder: Extract SERVER_NAME, GEOIP_COUNTRY_CODE, HTTP_FROM, HTTP_X_JA4_HASH from env_v2 logs
- Stats Store: Dimensional get/set/merge methods mirroring hourly stats pattern
- Overview REST API: `?breakdown=` query parameter for dimensional data

## [2.2.7] - 2026-01-30

### Changed

- Performance Dashboard: Server-side URL search, sort, and pagination (2026-01-30)
  - Search filter sent to server so matching URLs beyond the top 100 are visible
  - Sort column sent to server so limit applies after sorting by the selected field
  - Added `offset` param for pagination with Prev/Next controls
  - Response format: `{ data, total, limit, offset }` for pagination metadata
  - Added `url`, `min_ms`, `max_ms` to allowed sort fields

## [2.2.6] - 2026-01-30

### Changed

- Supervisor: Add `event_logger_supervisor_periodic` action hook (2026-01-30)
  - Fires every config-check interval (~15s) inside the supervisor main loop
  - Lets plugins run lightweight periodic tasks without dedicating a worker
- Supervisor: Reduce max runtime from 3599s to 3535s (2026-01-30)
  - Restarts 65 seconds ahead of other 1-hour jobs to avoid overlap
- Event Aggregator: Gate stream-merger on enabled remote servers (2026-01-30)
  - Workers no longer start when no remote servers are configured
  - Avoids idle CPU burn from stream-merger polling empty server list
- Event Aggregator: Inline health check into supervisor periodic hook (2026-01-30)
  - Runs every 300s inside the supervisor process instead of a dedicated worker
  - Queues health_check job via LogManager (sub-millisecond, no lock needed)
  - Removed standalone health-check worker registration and lock cleanup
- SSE: Fix flush padding to use proper SSE comment termination (2026-01-30)
  - Padding comment now ends with double newline per SSE spec

## [2.2.5] - 2026-01-28

### Added

- Event Aggregator: Settings page styles for card-style forms (2026-01-29)
  - Added light theme tokens to base.scss ($light-text, $light-border, $focus-color)
  - New settings.scss with .event-logger-settings-wrap wrapper styles
  - Styles h2 headers, form-table cards, rounded inputs, submit button
  - Enqueues on Event Logger settings page independently of performance-logger
- Performance Logger: Lightweight tracking for skip_urls requests (2026-01-29)
  - message() now auto-enables tracking so requests appear in dashboard
  - Skip_urls requests get 0ms duration (filtered from stats, visible for review)
  - Allows reviewing errors/warnings from workers without polluting timing stats
- Event Aggregator: Dashboard shows both server and client heartbeats (2026-01-29)
  - Server HB: SSE heartbeat timestamp received from remote server
  - Client HB: HTTP heartbeat response time with RTT latency
  - Configurable refresh interval dropdown (1s, 2s, 5s, 10s) with localStorage persistence

### Fixed

- Event Dashboards: Raw logs no longer jumps when new entries arrive (2026-01-29)
  - Set offset/scroll position BEFORE updating filteredLinesRef
  - Sync scrollTopRef immediately when adjusting scrollTop
- Event Aggregator: Respect `enable_workers` config option (2026-01-29)
  - stream-merger and health-check workers no longer start on non-hub nodes
  - SettingsSync no longer queues sync jobs on non-hub nodes (prevents feedback loop)
- Performance Aggregator: SettingsSync respects `enable_workers` config (2026-01-29)
  - Remote nodes receive settings but don't fan-out, preventing feedback loops
- Event Logger: SSE streams now flush through nginx/fastcgi/TLS buffers (2026-01-29)
  - Sends 4KB padding before sleeping to push buffered data to client
  - Only flushes when going idle, not per-event (reduces bandwidth)
  - Fixes aggregator connections stuck in "connecting" on buffered proxies
- Event Logger: FirehoseReader no longer busy-loops when caught up (2026-01-29)
  - Added at_eof flag set by fread() result, used by is_caught_up() instead of filesize comparison
  - Fixes SSE heartbeats not being sent due to filesize() cache mismatch with fread()
  - Offset calculation now uses ftell() - buffer_length for self-healing accuracy
- Performance Logger: LogManager::message() now works from worker contexts (2026-01-28)
  - Workers run in spawn endpoint context which is in skip_urls
  - Changed message() to check firehose initialization instead of started flag
  - Allows job queuing (health_check, sync_setting) while still skipping request logging
  - start()/complete() still respect skip_urls for request lifecycle logging
- Event Dashboards: Restart button now works for standalone workers (2026-01-28)
  - validate_restart_type() now checks Supervisor::get_standalone_workers()
  - restart_workers() handles standalone worker lock paths (single vs partitioned)
  - Fixes "Invalid parameter(s): type" error for health-check, stream-merger, supervisor
- Performance Logger: HooksController moved from dashboards to logger plugin (2026-01-28)
  - Fixes 404 error on `/event-logger/v1/performance/registered-hooks` when dashboards not active
  - Hook selector modal in settings now works without requiring performance-dashboards plugin
- Event Aggregator: HealthCheck now flushes LogManager buffer after queuing jobs (2026-01-28)
  - LogManager buffers writes up to 4KB for atomic writes
  - Long-running workers never triggered buffer flush, jobs sat in buffer indefinitely
  - Added flush_buffer() call to ensure discovery jobs are written immediately

### Changed

- Performance Workers: FlameBuilder filters 0ms duration from stats (2026-01-29)
  - Tracked-only requests still appear in dashboard but don't pollute timing stats
  - Flames are stored for viewing request details
- Event Logger: Firehose skips rotation lock for single-writer scenarios (2026-01-28)
  - allow_large_writes() now also sets skip_rotation_lock flag
  - Eliminates mkdir/rmdir syscall overhead for offsetlogs and job queues
  - Rotation logic extracted to do_rotate() for code clarity
- Performance Logger: LogManager::flush_buffer() is now public (2026-01-28)
  - Allows workers to explicitly flush buffered writes without waiting for 4KB threshold
- Event Logger: Lock class handles orphaned lock directories (2026-01-28)
  - Detects lock dirs without heartbeat file (crash during creation)
  - Waits 1 second before treating as stale to avoid race conditions
  - Separates logic for missing heartbeat vs stale heartbeat
- Event Jobs: Increased MAX_JOB_PARAMS_SIZE from 64KB to 10MB (2026-01-28)
  - Allows larger job payloads for bulk operations
- Event Aggregator: Aggregator Status shows HTTP response code (2026-01-28)
  - SSEClient captures HTTP code on data received and connection completion
  - StreamMerger preserves error/response when starting new connection attempts
  - UI shows "HTTP {code}" before error message, green badge for 200 OK
  - Label changes to "Attempt" when not connected, RTT moved left of time-since

## [2.2.4] - 2026-01-27

### Changed

- Config consistency: Always use Config::load_config() instead of get_option() directly (2026-01-27)
  - RemoteManager::sync_all_settings() uses Config for option values
  - FlameBuilder::refresh_significant_events() uses Config::reset() + Config::load_config()
  - DiscoveryController::get_discovery() uses Config for log_events and custom_events
  - Only read-modify-write patterns (auto-tuning) and admin UI use get_option() directly
- Performance Aggregator: Added log_urls and skip_urls to synced options (2026-01-27)
- Performance Logger: SettingsController accepts log_urls and skip_urls (2026-01-27)
- Settings Sync Architecture Cleanup (2026-01-27)
  - Clean separation of options between event-logger (core) and performance-logger (tuning)
  - Event Logger owns: `num_partitions`, `num_segments`, `segment_size`
  - Performance Logger owns: `log_events`, `custom_events`, `auto_disable_threshold`, `auto_protect_time_threshold`, `significant_events`
- Aggregator: Job-based settings sync with serialization keys (2026-01-27)
  - Settings changes queue jobs with `k` = option name for proper serialization
  - Jobs include `queued_at` timestamp; stale jobs (>300s) are skipped
  - Both event-aggregator and performance-aggregator register settings via `event_aggregator_synced_settings` filter
- Aggregator: Health check now job-based with periodic full sync (2026-01-27)
  - HealthCheck worker queues a single `discovery` job instead of direct HTTP calls
  - RemoteManager::health_check() runs serially, collects discovery data, fires action
  - After discovery, syncs ALL registered settings to ensure consistency
  - Handles staleness drops and new servers joining the pool
- Performance Logger: Renamed RemoteApiController to DashboardController (2026-01-27)
  - Better reflects purpose: dashboard UI endpoints, not aggregator-related
  - Provides `/hooks/available`, `/hooks/configure`, `/config` endpoints

### Added

- Event Aggregator: New SettingsSync class (2026-01-27)
  - Hooks `update_option` and `add_option` for core options
  - Maps `remote_num_segments` → `num_segments`, `remote_segment_size` → `segment_size`
  - Queues sync jobs to `/wp-json/event-logger/v1/settings`
- Performance Logger: New SettingsController (2026-01-27)
  - REST endpoint at `/perf-logger/v1/settings` for performance options
  - Accepts `log_events`, `custom_events`, `auto_disable_threshold`, etc.
- RemoteManager: `sync_all_settings()` method (2026-01-27)
  - Uses `event_aggregator_synced_settings` filter for extensibility
  - Called after discovery to sync all settings to all servers

### Removed

- Performance Aggregator: Deleted RemoteActions class (2026-01-27)
  - Redundant now that SettingsSync handles option fan-out
- Performance Logger: Deleted `/tuning/hooks` and `/tuning/custom-events` endpoints (2026-01-27)
  - Replaced by proper SettingsController endpoint

## [2.2.3] - 2026-01-27

### Changed

- Aggregator: Dashboard now shows per-partition status (2026-01-27)
  - Status keyed by server_id AND partition (`aggregator_status:{id}:p{n}`)
  - Each partition displays independently with connection status, heartbeat, RTT
  - Horizontal flex layout shows all partitions at a glance
- Aggregator: Refactored to true curl_multi multiplexing (2026-01-27)
  - StreamMerger now uses a single shared curl_multi handle for ALL connections
  - Uses curl_multi_select() to efficiently wait on all connections at once
  - Eliminates sequential polling overhead - data from any server is processed immediately
  - SSEClient no longer manages its own multi handle (cleaner separation of concerns)
- Event Logger: Reduced aggregator heartbeat interval from 60s to 15s (2026-01-27)
  - Prevents proxy/CDN idle connection timeouts (which are often 60s)
  - SSE connections now stay alive through Cloudflare, nginx, etc.
  - Stale connection detection reduced from 120s to 45s to match

### Fixed

- Aggregator: Heartbeat is now purely informational (2026-01-27)
  - Client no longer closes connection based on heartbeat response
  - Server terminates connection when slot expires; client detects via SSE
  - Heartbeat status displayed for user info only (success/error/slot_expired)
  - Process events before heartbeat check to initialize timing correctly
- Aggregator: Health-check single-instance worker now uses correct lock path (2026-01-27)
  - Single-instance workers use `{name}.lock.d`, not `{name}.p0.lock.d`
  - Was showing as "dead" in worker list due to lock path mismatch
- Aggregator: Reset slot on connection close to prevent stale heartbeats (2026-01-27)
  - When connection dies and reconnects, old slot value was preserved
  - Caused heartbeats to touch wrong slot (slot=2 instead of slot=0)
  - Slot is now reset to null on close, skipping heartbeats until new connected event
- Event Logger: Reinitialize stale memcache connection in SSE streams (2026-01-27)
  - Long-running SSE streams can have their memcache connection go stale
  - Slot checks failed even though slot existed (heartbeats touched it via fresh connections)
  - Added Memcached::reset() to allow reconnection when needed

## [2.2.2] - 2026-01-27

### Fixed

- Aggregator: Improved SSE connection reliability and status reporting (2026-01-27)
  - Reduced connect timeout from 10s to 5s for faster failure detection
  - Added explicit "disconnected" and "backoff" connection states
  - SSE Controller now more aggressively disables output buffering
  - Added Apache mod_deflate bypass and Content-Encoding: none header
  - Error messages now include cURL errors, HTTP codes, and stale connection details
  - Status dashboard shows retry backoff countdown
- Event Logger: SSE output buffering improvements (2026-01-27)
  - Added implicit_flush ini setting
  - Added ob_flush() before flush() for more reliable streaming
  - Better compatibility with reverse proxies and CDNs

## [2.2.1] - 2026-01-26

### Changed

- Performance Logger: Settings UI moved from Dashboards to Logger plugin (2026-01-26)
  - TagInputField, HookSelectorModal, CustomEventSelectorModal components now in Logger
  - Settings page works without Dashboards/Workers plugins active
  - Dashboards now only loads scripts on dashboard page, not settings

### Added

- Event Logger: Worker CLI now uses subcommands (2026-01-26)
  - `wp eventlog worker list` - list all workers including standalone
  - `wp eventlog worker types` - list available worker types
  - `wp eventlog worker run <type> --partition=N` - run a worker
  - `wp eventlog worker restart <type> --partition=N` - restart workers
  - Standalone workers (stream-merger, health-check) now included in list/types output
- Event Logger: SSE slot TTL configurable per connection type (2026-01-26)
  - `SLOT_TTL_BROWSER = 10` seconds for browser connections
  - `SLOT_TTL_AGGREGATOR = 120` seconds for aggregator connections
  - Heartbeat endpoint accepts `aggregator` param to select TTL

### Fixed

- Performance Logger: Added missing `/tuning/hooks` and `/tuning/custom-events` endpoints (2026-01-26)
  - Aggregator was calling these endpoints but they didn't exist (404)
  - Removed dead `/tuning/disable` endpoint
- Aggregator: SSE connections no longer timeout prematurely (2026-01-26)
  - Increased client HEARTBEAT_TIMEOUT from 30s to 120s
  - Server sends heartbeats every 60s; client now waits long enough
  - First heartbeat delayed until 60s after connect (slot already has fresh TTL)

## [2.2.0] - 2026-01-25

### Added

- Aggregator: Real-time status dashboard (2026-01-25)
  - New admin page under Event Logger → Aggregator
  - Shows connection status, heartbeat RTT, and errors per server
  - Auto-refreshes every 5 seconds from REST API
  - Dark theme matching Gyroscope/Request Log dashboards
- Aggregator: HTTP heartbeat verification for SSE slots (2026-01-25)
  - StreamMerger sends POST to `/firehose/heartbeat` every 60s
  - Verifies slot ownership, forces reconnect on slot expiry
  - Status stored in memcache for dashboard display
- Aggregator: StatusController REST endpoint (2026-01-25)
  - GET `/event-aggregator/v1/status` returns all server statuses
  - Reads connection and heartbeat status from memcache
- Event Logger: SSE endpoint accepts `aggregator` parameter for longer heartbeat interval (2026-01-25)
  - Aggregator connections use 60s SSE heartbeat (vs 5s for browsers)
  - Aggregators verify slot ownership via HTTP POST instead of SSE heartbeat
- Performance Workers: `enable_workers` config option (2026-01-25)
  - Set to `false` in event-logger-config.php to disable workers on non-hub nodes
  - Prevents redundant requests.log and flames.log generation on spoke servers
  - Defaults to `true` for backwards compatibility with standalone deployments
- Performance Logger: Request log now includes full URL with server name (2026-01-25)
  - Logs `GET https://server.name/path` instead of `GET /path`
  - Helps identify source server in aggregated logs
- Aggregator: `aggregator_verify_ssl` config option to allow self-signed certificates (2026-01-25)
  - Set to `false` in event-logger-config.php for development with self-signed certs
- Aggregator: WordPress Application Password authentication (2026-01-25)
  - Replaced `auth_token` with `auth_username` and `auth_password`
  - Uses HTTP Basic Auth for WordPress Application Passwords
  - Create app passwords at Users → Profile → Application Passwords

### Changed

- Monorepo: Consolidated all lock files into `{$log_directory}/locks/` directory (2026-01-25)
  - Supervisor, StreamMerger, HealthCheck, LogReader, Firehose rotation, JobIntake locks
  - Easier to inspect and clean up lock state
- Monorepo: Renamed main plugin files for consistency (2026-01-25)
  - All plugins now use `newspack-{plugin-name}.php` naming pattern
  - e.g., `event-logger.php` → `newspack-event-logger.php`
- Event Logger: Heartbeat endpoint simplified to single slot (2026-01-25)
  - Changed from `slots: [N]` array to `slot: N` integer
  - Response now includes `success`, `slot`, `error`, `timestamp`
- Event Logger: Aggregators now acquire and maintain SSE slots (2026-01-25)
  - Reverted skip_slots logic; aggregators use slots like browsers
  - Slot ownership verified via HTTP heartbeat every 60s

### Fixed

- Event Logger: Supervisor scheduling no longer thrashes shared database (2026-01-25)
  - Check for registered readers at runtime when hook fires
  - Prevents schedule/unschedule race between nodes with different configs

- Event Logger: SSE entry events now include position for accurate resume (2026-01-25)
  - Previously only heartbeat events included position, causing duplicates on reconnect
  - Removed unused `event_logger_firehose_stream_entry` filter
- Event Logger: Custom SSE headers now set before output starts (2026-01-25)
  - Fixed "headers already sent" warning for X-Server-Id header
  - Added `$custom_headers` parameter to `start_sse_stream()`
- Aggregator: SSEClient null handle check in read_event loop (2026-01-25)
  - Added null check for `$this->mh` before `curl_multi_info_read()` call
  - Break out of loop after `close()` to avoid re-checking null handle
- Aggregator: SSEClient now uses per-instance curl_multi handles (2026-01-25)
  - Fixed stale handles causing duplicate requests and short-lived connections
  - Properly cleans up handles on close() instead of leaving orphaned entries
- Aggregator: SSEClient now sends `aggregator=1` parameter to skip slot management (2026-01-25)
  - Prevents 10-second slot TTL from closing long-running aggregator connections
- Dashboards: Fixed namespace imports for RequestBuilder and FlameBuilder (2026-01-25)
  - Classes are in `Newspack_Performance_Workers\Cron`, not `Newspack_Performance_Dashboards\Cron`
- Aggregator: All HTTP request points now use Application Passwords and SSL verify config (2026-01-25)
  - HealthCheck worker: Uses Basic Auth and respects `aggregator_verify_ssl`
  - RemoteManager: Updated `post_to_server` and `get_from_server` methods
  - SSEClient: Accepts auth credentials and `verify_ssl` in constructor
  - StreamMerger: Passes `verify_ssl` config to SSEClient instantiation
- Admin: URL validation now provides specific error messages instead of generic "A valid URL was not provided" (2026-01-25)
  - Shows "URL is required", "URL must be a string", "URL must use HTTPS", or "Invalid URL format"
- Admin: Segment size input now uses MB units for consistency with remote segment size field (2026-01-25)
  - Previously required bytes input (e.g., 67108864), now accepts MB (e.g., 64)
  - JavaScript converts MB to bytes for storage
- Aggregator: Test connection now works with private/internal IPs (2026-01-25)
  - Changed from `wp_safe_remote_get` to `wp_remote_get` to allow 192.168.x.x, 10.x.x.x, etc.

### Changed

- Refactored aggregator plugin architecture (2026-01-25)
  - Moved RemoteManager from performance-aggregator to event-aggregator
  - Moved HealthCheck worker from performance-aggregator to event-aggregator
  - event-aggregator now owns all base hub functionality (servers, settings sync, health check)
  - performance-aggregator extends via filters instead of duplicating functionality
  - Added `event_aggregator_remote_actions` filter for custom remote actions
  - Added `event_aggregator_health_check_discovery` action for processing discovery data
  - Created RemoteActions class for performance-specific actions (disable_hooks, disable_custom_events)
  - Created HealthCheckExtensions class for hook/event merging from discovery
  - Removed duplicate Admin class from performance-aggregator (settings now in event-aggregator)

## [2.1.0] - 2026-01-25

### Added

- newspack-performance-aggregator: New plugin for hub-mode performance management (2026-01-25)
  - SettingsSync hooks into option updates for fan-out to remotes
  - RemoteManager job handler for settings sync, health checks, auto-tune fan-out
  - HealthCheck worker discovers hooks/events from remotes and merges locally
  - Hub mode action handlers (priority 20) queue fan-out after local updates
  - Remote-specific segment settings (num_segments, segment_size for remotes)
- newspack-event-aggregator: New plugin for multi-server log aggregation (2026-01-25)
  - ServerRegistry manages remote server configs (URL, auth token, enabled logs)
  - SSEClient with cURL-based streaming, exponential backoff, and resume support
  - StreamMerger worker multiplexes SSE connections from all servers per partition
  - Single offsetlog per partition stores all server positions atomically
  - Admin UI for adding/removing/testing remote servers
  - REST API for server CRUD operations
- SSE endpoint: Added `segment_id` parameter to `/firehose/stream` for precise resume (2026-01-25)
  - Combine with existing `offset` parameter to resume from exact segment:offset position
  - Enables aggregator clients to reconnect without missing or duplicating entries
- Supervisor: Added `event_logger_standalone_workers` filter for non-LogReader workers (2026-01-25)
  - Enables plugins to register workers that extend WorkerBase directly
  - Supports partitioned (one per partition) or single-instance workers
- REST API: Added `/event-logger/v1/settings` endpoint for remote config updates (2026-01-25)
  - POST endpoint with whitelist approach for allowed options
  - Supports hub-to-remote settings synchronization
- REST API: Added `/event-logger/v1/discovery` endpoint for hook/event discovery (2026-01-25)
  - Returns registered_hooks, custom_events, and lag metrics
  - Enables aggregator hub to discover remote server capabilities
- newspack-performance-workers: New plugin extracted from performance-dashboards (2026-01-25)
  - Contains RequestBuilder, FlameBuilder, StatsStore worker classes
  - Contains auto-tuning admin settings (Auto-Tune thresholds, Significant Events)
  - Registers log readers (request-builder, flame-builder) and log count filter
  - Fires `performance_workers_*` actions for auto-tune handlers
- LruCache: New shared utility class for bucket-based LRU caching (2026-01-24)
  - Extracted from RequestBuilder for reuse across workers
  - Prevents unbounded memory growth with automatic eviction

### Changed

- **Plugin renames** for consistency (2026-01-25)
  - `newspack-event-logger-dashboards` → `newspack-event-dashboards`
  - `newspack-event-logger-jobs` → `newspack-event-jobs`
  - Updated namespaces: `Newspack_Event_Dashboards`, `Newspack_Event_Jobs`
- Settings: Reorganized to correct plugin ownership (2026-01-25)
  - Moved log_urls, skip_urls, log_events, custom_events fields to performance-logger
  - Moved memcache_servers field to event-logger (used by SSE and stats)
  - Dashboard-only options (auto_tune, significant_events) stay in performance-dashboards
  - Created separate "Dashboard Settings" section in settings page
- Config: Split load_config() into core/full modes to reduce autoloaded options (2026-01-25)
  - Core mode (default): loads only options needed for request logging
  - Full mode: loads all options for workers and admin
  - Extended options (significant_events, memcache_servers, auto-tune thresholds) now have autoload=false
- Config: Option schemas now use filter hooks for plugin extensibility (2026-01-25)
  - `event_logger_option_schema_core` - plugins register core options (autoloaded)
  - `event_logger_option_schema_extended` - plugins register extended options (workers/admin only)
  - Each plugin owns its options: performance-logger (log_urls, skip_urls, log_events, custom_events), performance-workers (auto_disable_threshold, auto_protect_time_threshold, significant_events)
- FlameBuilder: Use LruCache for stats_accumulators to prevent memory exhaustion (2026-01-24)
- FlameBuilder: Removed MAX_UNIQUE_URLS_PER_HOUR limit (5-second flush already bounds url_stats) (2026-01-24)
- FlameBuilder: Renamed MAX_REQUESTS_PER_URL to EMA_SAMPLE_LIMIT for clarity (2026-01-24)
- RequestBuilder: Refactored to use shared LruCache utility (2026-01-24)
- LogManager: Sort environment keys before logging for consistent output (2026-01-24)
- FlameBuilder: Auto-protect now triggers on average time per call instead of any single call (2026-01-24)
- FlameBuilder: Use regular segment size for flames.log instead of half (2026-01-24)
- FlameBuilder: Renamed `apply_auto_disable()` to `apply_auto_tune()` (2026-01-24)
- Admin: Renamed settings field label from "Auto-Disable" to "Auto-Tune" (2026-01-24)

### Fixed

- FirehoseReader: Fixed `restore()` returning without value when return type is `?array` (2026-01-25)
- LogReader: Re-throw exceptions from handler init() so workers exit on initialization failure (2026-01-25)
  - Previously exceptions were swallowed, leading to flush()/save_state() on incomplete context
- LruCache: Added to Composer autoloader (was missing) (2026-01-25)
- FlameBuilder: Fixed auto-tune not re-disabling hooks after they're re-enabled (2026-01-24)
  - Added `wp_cache_delete('alloptions', 'options')` before firing auto-tune actions
  - Long-running FlameBuilder process was using stale cached option values

### Changed

- JobWorker: Increased MAX_PARAMS_SIZE from 64KB to 10MB (2026-01-23)
  - Supports larger job parameters for import handlers
- JobIntake: Added MAX_JOB_SIZE (10MB) limit with validation (2026-01-23)
  - Rejects oversized jobs before writing to firehose
  - Logs error message identifying the handler

## [2.0.0] - 2026-01-22

### Changed

- **Monorepo restructure**: Split into separate plugins for modularity
  - `newspack-event-logger` - Core infrastructure (Firehose, Lock, Config, Memcached)
  - `newspack-event-dashboards` - Admin UI for raw logs and worker status
  - `newspack-event-jobs` - Background job queue system
  - `newspack-event-aggregator` - Multi-server log aggregation via SSE
  - `newspack-performance-logger` - Request lifecycle logging
  - `newspack-performance-workers` - Background workers (RequestBuilder, FlameBuilder)
  - `newspack-performance-dashboards` - Performance analytics and flame graphs
  - `newspack-performance-gyroscope` - Real-time in-flight request monitoring
  - `newspack-performance-request-log` - Completed request stream viewer
  - `newspack-performance-aggregator` - Hub-mode settings sync and coordination
- Unified build system with npm and Composer at monorepo level
- Plugins can be activated independently based on needs
- **LogReader framework**: Unified worker architecture with pluggable handlers
  - All workers now use `LogReader` base with handler interface (`init`, `process`, `flush`, `save_state`, `cleanup`)
  - Workers registered via `event_logger_log_readers` filter
  - Multi-input support (JobRouter reads from both firehose.log and jobintake.log)
- **Worker spawning**: Changed from WP-CLI to REST API with HMAC authentication
  - Spawn requests use 10-second window HMAC tokens based on `NONCE_SALT`
  - Rate limited: 15 seconds minimum between spawns of same worker type
- **Renamed classes**: LogAggregator → RequestBuilder, consistent naming throughout
- **JobRouter**: New dedicated handler for job extraction and routing to jobs.log
- **Plugin timing**: Simplified to record each plugin after load instead of pre-computing indices
- **Dashboards structure**: Flattened src/ directory (moved WorkerStatus to top level with RawLogs)

### Documentation

- ARCHITECTURE.md: Complete rewrite for monorepo structure
  - Added Plugin Structure section with dependency graph
  - Documented LogReader framework and handler interface
  - Updated worker spawn mechanism (REST API + HMAC)
  - Added SSE slot-based rate limiting documentation
  - Added missing Firehose methods (`write_raw`, getters)
  - Fixed `memcache_server` → `memcache_servers` (array)
  - Fixed URL index TTL (1h, not 24h)
  - Updated File Layout with all 7 plugins and missing files
- API.md: Added REST Endpoints by Plugin section
- README.md: Rewritten as monorepo overview

## [1.0.8] - 2026-01-17

### Added

- WorkerStatus: ETA indicator showing estimated time for workers to catch up when behind

### Changed

- PerformanceDashboard: fix React hooks exhaustive-deps lint violations using ref pattern for api object
- TagInputField: remove unnecessary eslint-disable for @wordpress/icons import
- FlameGraph: add explanatory comment for jsx-a11y disable (D3 handles interactivity)

## [1.0.7] - 2026-01-15

### Documentation

- API.md: fix WorkerBase example (`run(): void`, correct method names)
- API.md: fix class name typo (FirehoseReader not Firehose_Reader)
- API.md: fix method names (`should_restart()` not `should_exit()`)
- ARCHITECTURE.md: fix SSE timeout (1 hour, not 5 minutes)
- ARCHITECTURE.md: remove non-existent REQUEST_TIMEOUT constant
- ARCHITECTURE.md: add missing class-memcached.php to file layout
- ARCHITECTURE.md: add missing class-worker-command.php to file layout
- ARCHITECTURE.md: add `wp eventlog worker` CLI command documentation
- README.md: fix plugin filename in releasing section

### Added

- LogAggregator: persist in-flight request state to offsetlog for recovery after restart
- Memcached: new class for direct Memcached/Memcache access (supports both extensions)
- Firehose: slot-based SSE rate limiting using atomic `add()` (no TOCTOU races)
- Firehose: browser heartbeat endpoint (`POST /firehose/heartbeat`) for SSE lifecycle management

### Changed

- Firehose: SSE endpoints now multiplex all partitions into a single stream (N connections → 1)
- Request Stream: removed partition column (no longer relevant with multiplexed SSE)
- Workers: heartbeat touched at time-based intervals (every 10s) instead of job-count intervals
- Lock: stale timeout now configurable per-lock (default still 60s)
- SupervisorBase: supports custom stale timeout for long-running jobs
- LogAggregator: heartbeat call moved to `do_housekeeping()` for consistency
- StatsStore: refactored to use shared Memcached class
- Firehose: SSE connections use fixed slots with TTL instead of list-based tracking
- Firehose: SSE loop checks slot validity (read-only) instead of touching it
- Firehose: browser must POST heartbeat every 5s to keep slot alive (10s TTL)
- Gyroscope: SSE batches at fixed 100ms interval, display refresh is independent
- Firehose: SSE max runtime increased from 5 minutes to 1 hour

### Fixed

- Workers: prevent stale lock detection during long-running jobs by adding `maybe_touch_heartbeat()`
- Firehose: SSE connection counting now reliable (slots auto-expire, no shutdown handler needed)
- Firehose: SSE connections now exit when browser tab closes (heartbeat stops → slot expires)
- Firehose: SSE endpoints now initialize memcached (was missing, causing slot checks to no-op)
- Gyroscope: changing refresh interval no longer resets tracked requests
- Core: plugin timing now triggered reliably, hook events include metadata for flame graphs

## [1.0.6] - 2026-01-12

### Added

- Settings: "Clear Memcache Stats" button to flush hourly stats, leaderboards, and URL indexes

### Changed

- Config: `memcache_server` (string) replaced with `memcache_servers` (array) to support multiple servers

## [1.0.5] - 2026-01-12

### Fixed

- Hooks with backslashes (e.g., `Yoast\WP\SEO\...`) now save correctly instead of clearing to empty
- FirehoseReader line buffer limit increased from 1MB to 10MB for large aggregated requests
- Reduce max stack/recursion depth from 100 to 50 to fit within json_decode depth limit (64)
- Plugin timing: prevent duplicate start events when `option_active_plugins` filter runs multiple times

### Changed

- Hook selector modal "Select All" and "Clear" buttons now operate on filtered search results
- Button labels update to show match count when searching (e.g., "Select Matches (47)")
- Add spawn endpoint to skip_urls

## [1.0.4] - 2026-01-08

### Added

- Settings page card-style form tables with white background, borders, and rounded corners
- Split settings into "Logging Settings" and "Storage Settings" sections
- Reset buttons for all settings fields (clear to use config defaults)
- Number fields show default values as gray placeholders when empty
- Skeleton loading states and empty state styling in base.scss

### Fixed

- Oval tag remove button caused by WordPress Button component min-width
- Hook selector modal too narrow for long hook names (increased to 1100px)
- Inconsistent reset button styling across field types
- Auto-disable row layout with reset button on right edge
- Number field sanitize callbacks converting empty to 0 instead of preserving empty for config fallback
- JobWorker not restarting when settings change (storage, log_events, custom_events)
- Firehose scandir warning causing php-error class on all WP admin pages

### Changed

- Move inline React styles to CSS classes in settings.scss
- Reset buttons now clear fields (empty = use config default) instead of setting default value
- FlameBuilder now refreshes significant_events from DB periodically (1s when idle, 15s when busy) instead of requiring restart

## [1.0.3] - 2026-01-08

### Added

- JobIntake: New class for queuing large import jobs (>4KB) that bypass firehose limits
- JobIntake: Opens all partition firehoses on init, round-robin at write_job() time
- JobIntake: Optional partition() method for pinning to specific partition
- JobIntake: Optional key parameter for consistent partition routing
- JobIntake: Blocking wait with 5-minute timeout when lock held
- JobIntake: Internal locking (default enabled) for single-writer guarantee
- JobIntake: Fail fast on invalid handler names before retry loop
- JobIntake: Throttle heartbeat touch to once per second
- LogAggregator: Read from jobintake.log and route to jobs.log
- LogAggregator: Persist in-flight request state to offsetlog for recovery
- LogAggregator: Enable allow_large_writes() on jobs.log (single writer)
- JobWorker: Hot path optimization (check $fh first, else open)
- JobWorker: Flush object cache every 50 jobs to prevent memory growth
- Workers dashboard: Display jobintake.log in Jobs Pipeline section

### Changed

- Lock: Rename HEARTBEAT_STALE to STALE_TIMEOUT and increase from 30s to 60s
- FlameBuilder: Hot path optimization (check $fh first, else open)
- Supervisor: Use Lock::STALE_TIMEOUT instead of internal constant
- Supervisor: Restore REST API spawn for Atomic hosting (no CLI php binary available)

### Security

- JobWorker: Add handler name pattern validation to prevent injection (2026-01-05)
- JobWorker: Add parameter type and 64KB size validation (2026-01-05)
- JobWorker: Add JSON decode depth limit (10) to prevent stack exhaustion (2026-01-05)
- JobWorker: Log exceptions with sanitized messages instead of swallowing (2026-01-05)
- JobWorker: Sanitize all logged values to prevent log injection (2026-01-05)
- LogAggregator: Validate handler name and parameters before job routing (2026-01-05)

### Fixed

- LogAggregator: Profile time calculation - remove max(0,...) that broke parent-child subtraction
- WorkersController: Remove localhost check from spawn auth (Atomic proxies through LB)
- FirehoseReader: is_caught_up() now checks offset within segment, not just segment ID
- FirehoseReader: 'recent' offset now starts from second-to-last segment
- FirehoseReader: Enable allow_large_writes() on offsetlogs (single-writer per worker)
- Firehose: Include log path and sizes in PIPE_BUF exceeded error message
- JobWorker: Track and log malformed JSON entries (2026-01-05)

## [1.0.2] - 2026-01-07

### Changed

- WorkerBase: Consolidate timeout handling in execute() method after lock acquisition
- Supervisor: Spawn workers via proc_open/WP-CLI instead of REST API
- Supervisor: Track and reap child processes to prevent zombies
- Remove spawn endpoint from skip_urls (no longer needed)

## [1.0.1] - 2026-01-05

### Fixed

- LogManager: Refuse to run as root to prevent permission issues with workers (2026-01-05)
- LogManager: Truncate oversized log entries instead of failing (2026-01-05)
- LogManager: Add null coalesce on getrusage fields for compatibility (2026-01-05)
- Firehose: Enforce minimum segment_size (1KB) to prevent infinite rotation (2026-01-05)
- Firehose: Fix hash_to_partition using explode instead of strtok (2026-01-05)
- Firehose: Handle partial writes in fwrite loop (2026-01-05)
- Firehose: Wrap index callback in try/catch to prevent write failures (2026-01-05)
- Lock: Check for write failures during acquire (2026-01-05)
- LogAggregator: Prevent negative time accumulation from out-of-order entries (2026-01-05)
- LogAggregator: Add bounds check for index format to prevent corruption (2026-01-05)
- LogAggregator: Conditional logging for partition open failures (2026-01-05)
- Supervisor: Clean up spawn tracking for removed partitions (memory leak) (2026-01-05)
- Supervisor: Use pipe delimiter for worker keys to prevent collision (2026-01-05)

### Changed

- LogManager: Add reset() method for REQUEST_URI context changes (2026-01-05)
- Firehose: Update segments_cache in-place to avoid scandir (2026-01-05)
- LogAggregator: Replace time-based expiration with LRU bucket cache (2026-01-05)
- LogAggregator: Search stack efficiently without array_reverse copy (2026-01-05)
- Supervisor: Extract MAX_SUPERVISOR_RUNTIME_S constant (2026-01-05)
- Supervisor: Clear specific option cache instead of alloptions (2026-01-05)
- Supervisor: Log spawn failures for debugging (2026-01-05)

### Security

- LogManager: Add BEARER to sensitive key substrings (2026-01-05)
- LogManager: Strip control characters from environment values (2026-01-05)
- Config: Validate config values to reject objects/closures/resources (2026-01-05)
- LogAggregator: Add length limits before regex to prevent ReDoS (2026-01-05)
- LogAggregator: Use explode instead of strtok to avoid global state (2026-01-05)
- LogAggregator: Validate X-Forwarded-For IP before using (2026-01-05)
- FlameBuilder: Add atomic locking for apply_auto_disable() (2026-01-05)
- REST API: Validate segment_id bounds in firehose controller (2026-01-05)

## [1.0.0] - 2026-01-05

Initial release of Event Logger - high-throughput WordPress request lifecycle logging with real-time streaming and flame graph visualization.

### Added

- Async job system with job handlers (2026-01-01)
- Purple color for plugin timing events (2025-12-29)
- Restrict Event Logger access to `allowed_users` config (2025-12-29)
- Duration bar chart backgrounds to URL and request tables (2025-12-28)
- Performance dashboard improvements (2025-12-27)
- Status code column to request log (2025-12-25)
- Preserve request log entries on SSE reconnect (2025-12-25)
- Request Log page with real-time SSE streaming (2025-12-24)
- Aggregate Time Chart to URL Detail View (2025-12-24)
- Hourly URL bucketing for dashboard (2025-12-24)
- WP-CLI `reqgrep` command for filtering firehose logs (2025-12-24)
- Newspack plugin conventions alignment (2025-12-23)
- `newspack_event_logger_custom_colors` filter for plugin extensibility (2025-12-22)
- Support for local config overrides (2025-12-21)
- Offset tracking with proper offsetlog (2025-12-21)
- Configurable memcache host:port (2025-12-17)
- Memcache/Memcached for stats storage (2025-12-17)
- Auto-disable threshold for noisy hooks (2025-12-17)
- Firehose read-only mode for read-only consumers (2025-12-17)
- Profile timestamp tracking for 1-hour expiration alignment (2025-12-17)
- Long-running supervisor with 1-second worker respawn (2025-12-17)
- Server-side flame graph scaling/normalization (2025-12-17)
- Dashboard refresh pause when tab is hidden (2025-12-17)
- Color-coded log rows (2025-12-17)
- Hidden sequence suffix for duplicate flame graph siblings (2025-12-16)
- Pattern-based hook auto-categorization (2025-12-16)
- Initial commit with Firehose, Gyroscope, Performance Dashboard, Flame Graphs, and Workers (2025-12-16)

### Fixed

- Rate limit sliding window bug with fixed time windows (2026-01-03)
- Input validation for localStorage and URL parameters (2026-01-01)
- Require `manage_options` capability as baseline in `current_user_allowed()` (2026-01-01)
- Comprehensive security hardening and DoS protection (2026-01-01)
- Segment rotation being undone by TOCTOU guard (2025-12-30)
- Firehose recovery after `rm -rf` and reduce I/O overhead (2025-12-30)
- Significant event detection and custom event auto-disable (2025-12-29)
- Input validation and path traversal prevention (2025-12-28)
- Ellipsize long state badges in Gyroscope view (2025-12-28)
- Scroll jump in Firefox request log (2025-12-28)
- Grid column layout for URL and request tables (2025-12-28)
- Global namespace prefix for PHP builtin functions (2025-12-28)
- Column ordering in URL table and detail view (2025-12-28)
- Extract `remote_addr` and `user_agent` for request log dashboard (2025-12-28)
- Request log scroll animation speed (2025-12-25)
- Layout shift by always showing scrollbar (Safari compat) (2025-12-25)
- Column width matching between Gyroscope and Request Log (2025-12-25)
- Stream continues past buffer limit (2025-12-24)
- Request Log stats layout and smooth animation (2025-12-24)
- Package.json and phpcs.xml.dist for flattened structure (2025-12-24)
- Admin page slugs (2025-12-23)
- Missing ABSPATH check (2025-12-22)
- Lazy load `custom_colors` for plugin filter registration timing (2025-12-22)
- "Disable logging" setting and uninstall cleanup (2025-12-20)
- Config updates sent to workers (2025-12-18)
- Default `log_events` setting (2025-12-17)
- Auto-disable breaking settings (2025-12-17)
- Admin settings refactoring (2025-12-17)
- Log entry numbering (2025-12-17)
- Segment rotation animation on worker status page (2025-12-16)

### Changed

- Centralize SCSS design tokens and fix SSE connection limit (2026-01-04)
- Inline jobs.log path for consistency with other workers (2026-01-02)
- Centralize path validation and add DoS protections (2026-01-02)
- Rename log directories with `.log` suffix (2025-12-27)
- Simplify Request Log filter to URL-only (2025-12-24)
- Flatten plugin structure (2025-12-24)
- Migrate to `Newspack_Event_Logger` namespace with Composer autoloading (2025-12-24)
- Unify Request Log and Gyroscope styling (2025-12-24)
- Admin and config classes to use short array syntax (2025-12-20)
- Rework logging for async work without full debug logging (2025-12-18)
- Move supervisor cron to its own class (2025-12-17)
- Refactor auto-disable to flame builder (2025-12-17)
- Optimize URL detail view charts for fast refresh rates (2025-12-17)
- Optimize URL detail modal performance (2025-12-17)
- Optimize client-side performance dashboard CPU usage (2025-12-16)

### Security

- Comprehensive security hardening and DoS protection (2026-01-01)
- Input validation for localStorage and URL parameters (2026-01-01)
- Require `manage_options` capability baseline (2026-01-01)
- Path traversal prevention (2025-12-28)
- Spawn endpoint nonce authentication (2025-12-17)
