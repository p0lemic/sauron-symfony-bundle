# Sauron Symfony Bundle

Sauron is a transparent HTTP proxy and APM dashboard for API observability. This bundle instruments Symfony applications by reading the `traceparent` header the Sauron proxy injects and reporting `controller` and `db` spans to the dashboard — giving you end-to-end trace waterfalls with zero manual code changes.

## Architecture

```
Browser / API client
        │
        ▼
Sauron proxy  :8080   ──────────────────────────▶  Symfony app  :9000
  (profiler)          injects traceparent header     (your app)
        │                                                 │
        │  stores proxy spans                             │  kernel.terminate
        ▼                                                 ▼
  profiler.db  ◀─────────── shared storage ─────  Sauron dashboard  :9090
  (SQLite/PG)                                       POST /ingest/spans
```

Traffic goes through the Sauron proxy, which records latency, status codes, and injects a W3C `traceparent` header. The bundle picks up that header inside Symfony and reports child spans (controller timing, DB queries) back to the dashboard after the response is sent.

## Installation

```bash
composer require sauron/symfony-bundle
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Sauron\Bundle\SauronBundle::class => ['all' => true],
];
```

Create `config/packages/sauron.yaml`:

```yaml
sauron:
  enabled: true
  endpoint: '%env(SAURON_ENDPOINT)%'
  service_name: '%env(APP_NAME)%'
```

Add to `.env`:

```
SAURON_ENDPOINT=http://localhost:9090/ingest/spans
APP_NAME=my-symfony-app
```

That's it. Requests routed through the Sauron proxy will now appear as full trace waterfalls in the dashboard.

---

## Prerequisites

- PHP ≥ 8.1, Symfony ≥ 6.0
- Sauron binaries built from `cmd/profiler` and `cmd/dashboard` (see Step 1)
- `ext-curl` or `allow_url_fopen=1` for flushing spans
- (Optional) `doctrine/dbal` ≥ 3.0 and DoctrineBundle for automatic DB query spans

---

## Step-by-step integration

### Step 1 — Build Sauron binaries

```bash
go build -o profiler ./cmd/profiler
go build -o dashboard ./cmd/dashboard
```

### Step 2 — Start the proxy and dashboard

Point the proxy at your Symfony app and use a shared storage file:

```bash
./profiler --upstream http://localhost:9000 --port 8080 --storage-dsn profiler.db
./dashboard --listen :9090 --storage-dsn profiler.db
```

**`profiler` key flags**

| Flag | Default | Description |
|------|---------|-------------|
| `--upstream` | *(required)* | Symfony app base URL, e.g. `http://localhost:9000` |
| `--port` | `8080` | Proxy listen port |
| `--storage-driver` | `sqlite` | `sqlite` or `postgres` |
| `--storage-dsn` | `profiler.db` | File path (SQLite) or connection string (Postgres) |
| `--retention` | disabled | How long to keep records, e.g. `7d`, `24h` |
| `--no-trace-context` | false | Disable W3C TraceContext header injection |
| `--tls-skip-verify` | false | Disable TLS certificate verification for upstream |
| `--timeout` | `30s` | Upstream request timeout |
| `--config` | — | Path to YAML config file |

**`dashboard` key flags**

| Flag | Default | Description |
|------|---------|-------------|
| `--listen` | `:9090` | Dashboard listen address |
| `--storage-driver` | `sqlite` | `sqlite` or `postgres` |
| `--storage-dsn` | `profiler.db` | Must match the profiler's DSN |
| `--metrics-window` | `30m` | Aggregation window for metrics |
| `--apdex-t` | `500` | Apdex satisfaction threshold (ms) |
| `--error-rate-threshold` | disabled | Error rate % to trigger alert, e.g. `10.0` |
| `--throughput-drop-threshold` | disabled | Min RPS % of baseline before alerting, e.g. `50.0` |
| `--baseline-windows` | `5` | Past windows used for baseline |
| `--anomaly-threshold` | `3.0` | Anomaly detection multiplier |
| `--webhook-url` | — | URL to POST alert notifications to |

### Step 3 — Install the bundle

```bash
composer require sauron/symfony-bundle
```

### Step 4 — Register the bundle

Add to `config/bundles.php`:

```php
return [
    // ...
    Sauron\Bundle\SauronBundle::class => ['all' => true],
];
```

### Step 5 — Create `config/packages/sauron.yaml`

```yaml
sauron:
  enabled: true
  endpoint: '%env(SAURON_ENDPOINT)%'
  service_name: '%env(APP_NAME)%'
  instrument_doctrine: true
  timeout_ms: 2000
```

### Step 6 — Add environment variables

Add to `.env`:

```
SAURON_ENDPOINT=http://localhost:9090/ingest/spans
APP_NAME=my-symfony-app
```

### Step 7 — Verify

Send a request through the proxy and check the dashboard:

```bash
curl http://localhost:8080/api/your-endpoint
# Open http://localhost:9090 → Traces → click the trace → see waterfall
```

---

## How it works

The bundle registers a `TraceSubscriber` that hooks into the Symfony kernel event lifecycle:

| Event | Action |
|-------|--------|
| `kernel.controller` | Reads `traceparent`, generates controller span ID, starts timer |
| `kernel.response` | Closes controller span, records HTTP method, route, status code |
| `kernel.exception` | Marks the active span as `status=error` |
| `kernel.terminate` (priority −1024) | Flushes all spans in a single POST to `endpoint` |

The flush happens in `kernel.terminate`, **after** the response is sent to the client — so there is zero latency impact on your users.

The Doctrine middleware wraps the DBAL driver and records one `db` span per query, parented to the active controller span.

### Span hierarchy in the waterfall

```
HTTP proxy span (created by Sauron proxy)
└── controller  App\Controller\UserController::index   140ms
    ├── db  SELECT users                                  8ms
    ├── db  SELECT orders WHERE user_id = ?              12ms
    └── db  INSERT audit_log                              3ms
```

---

## Configuration reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | bool | `true` | Set `false` to disable all instrumentation |
| `endpoint` | string | `http://localhost:9090/ingest/spans` | Dashboard ingest URL |
| `service_name` | string | `symfony-app` | Label shown in the Traces UI |
| `instrument_doctrine` | bool | `true` | Auto-instrument Doctrine DBAL queries |
| `timeout_ms` | int | `2000` | Max milliseconds to wait when flushing spans |

---

## Doctrine instrumentation

- Requires `doctrine/dbal` ^3.0|^4.0 and DoctrineBundle to be configured
- The bundle auto-registers a `doctrine.middleware` tag — no manual wiring needed
- Each SQL query becomes a `db` span, child of the `controller` span
- SQL is normalized (literals replaced with `?`) before storage — safe to log

---

## Adding custom spans

Inject `SauronClient` and call `recordSpan()` anywhere in your application:

```php
use Sauron\Bundle\SauronClient;

class MyService
{
    public function __construct(private readonly SauronClient $client) {}

    public function doWork(): void
    {
        $start = microtime(true);

        // ... do work ...

        $this->client->recordSpan(
            name:       'my-service.doWork',
            kind:       'rpc',                        // controller|db|cache|event|view|rpc
            startTime:  new \DateTimeImmutable(),
            durationMs: (microtime(true) - $start) * 1000,
            attributes: ['key' => 'value'],
            status:     'ok',
        );
    }
}
```

---

## Disabling per environment

```yaml
# config/packages/test/sauron.yaml
sauron:
  enabled: false
```

---

## Using PostgreSQL instead of SQLite

Both binaries must point to the same database:

```bash
./profiler --storage-driver postgres \
           --storage-dsn "postgres://user:pass@localhost:5432/sauron" \
           --upstream http://localhost:9000

./dashboard --storage-driver postgres \
            --storage-dsn "postgres://user:pass@localhost:5432/sauron"
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| No spans in dashboard | `traceparent` not injected | Requests must go through the proxy, not directly to Symfony |
| `container.xml` compile error | DI bug in old bundle version | Upgrade or apply the `Reference` fix in `SauronExtension.php` |
| Doctrine spans missing | `instrument_doctrine: false` or DBAL not installed | Enable config key; `composer require doctrine/dbal` |
| Spans appear but dashboard shows nothing | Shared `--storage-dsn` mismatch | Both binaries must point to the same `profiler.db` |
| High latency impact | `timeout_ms` too large | Reduce to `500`; flush is async (happens in `kernel.terminate`, after response is sent) |
| Only controller spans, no DB spans | Missing DoctrineBundle 2.x+ | The `doctrine.middleware` tag requires DoctrineBundle ≥ 2.0 |
