# 🤝 Contributing

Thanks for considering a contribution! Bug reports, docs fixes, and features are welcome.

## Ground rules

- Keep the public API small, explicit, and well‑typed.
- Prefer clear names (`attach()` over `add()`).
- Docs should link to concepts pages and include runnable snippets where possible.

## Getting started (code)

Prereqs: PHP (per `composer.json`) + extensions **sqlite3**, **pdo_sqlite**, **pdo_mysql**, **msgpack**; Composer; SQLite; Node 20+ for docs. (MySQL is optional—only needed for a few integration tests.)

Verify extensions:
- `php -m | grep -E 'sqlite|pdo|msgpack'`
- MessagePack (PECL): `pecl install msgpack` then enable with `extension=msgpack` in your php.ini.

```bash
git clone https://github.com/edvin/pillar.git
cd pillar
composer install
```

Run tests
```bash
composer test
```

Run tests with coverage, we aim for 100%:
```bash
composer test:coverage
```

### MySQL-only tests (opt-in)

A small subset of tests exercise MySQL-specific behavior. They are **skipped by default**.
To run them:

```bash
TEST_WITH_MYSQL=1 composer test
```

Make sure a MySQL server is available and your local PHP/Laravel testbench has a working MySQL connection for the suite.

Spin up MySQL quickly via Docker (optional):

```bash
docker run --name pillar-mysql -e MYSQL_DATABASE=pillar -e MYSQL_USER=pillar -e MYSQL_PASSWORD=secret -e MYSQL_ROOT_PASSWORD=root -p 3306:3306 -d mysql:8.0
```

## Docs development (VitePress)

```bash
npm install
npm run docs:dev
```

When changing docs:
- Place pages under `docs/…` and use absolute links from the docs root (e.g. `/concepts/aggregate-sessions`).
- Significant changes should be mentioned in the README
- Keep code fences language‑tagged (`php`, `bash`, `mermaid`).

## Commit / PR

- Branch prefixes: `feat/…`, `fix/…`, `docs/…`
- Describe **why** the change is needed; include before/after where applicable.
- Add tests for behavior changes.

## Security

Please **do not** open public issues for security problems.  
Use GitHub **Private Vulnerability Reporting** (Security → Advisories) to contact the maintainer.

## License

By contributing, you agree your contributions are licensed under the project’s MIT license.