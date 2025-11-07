# 🤝 Contributing

Thanks for considering a contribution! Bug reports, docs fixes, and features are welcome.

## Ground rules

- Keep the public API small, explicit, and well‑typed.
- Prefer clear names (`attach()` over `add()`).
- Docs should link to concepts pages and include runnable snippets where possible.

## Getting started (code)

Prereqs: PHP (per `composer.json`), Composer, a DB (SQLite is fine), Node 20+ for docs.

```bash
git clone https://github.com/edvin/pillar.git
cd pillar
composer install
```

Run tests (pick the one the repo defines):
```bash
composer test || ./vendor/bin/pest || ./vendor/bin/phpunit
```

## Docs development (VitePress)

```bash
npm install
npm run docs:dev   # local preview
npm run docs:build # production build
```

When changing docs:
- Place pages under `docs/…` and use absolute links from the docs root (e.g. `/concepts/aggregate-sessions`).
- If you update the README, mirror the change in **Reference → Full README (archived)**.
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
