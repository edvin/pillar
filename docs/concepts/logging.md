## Logging

Pillar uses a dedicated `PillarLogger` wrapper around Laravel’s logger to emit
structured, namespaced log messages from its internals (event store, commands,
projections, metrics, etc.).

### Configuration

By default, Pillar logs to Laravel’s default logging channel (`LOG_CHANNEL`):

```php
// config/pillar.php

'logging' => [
    'enabled' => true,

    // Which Laravel logging channel Pillar should use for its own logs.
    // Falls back to LOG_CHANNEL (and then "stack") by default.
    'channel' => env('PILLAR_LOG_CHANNEL', env('LOG_CHANNEL', 'stack')),

    // Minimum log level. Currently this is passed through to the underlying
    // logger via Laravel's logging configuration and is not filtered inside
    // Pillar itself.
    'level'   => env('PILLAR_LOG_LEVEL', env('LOG_LEVEL', 'info')),
],