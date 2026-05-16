# CLAUDE.md — `arts/component-runtime-wp` (PHP)

WordPress integration layer for `@arts/component-runtime` — auto-discovers
components in rendered HTML, emits the Vite manifest blob + bootstrap
`<script type="module">` + per-component CSS at the head anchors.

## Onboarding gotchas

### `composer install` before `composer test`

The test suite autoloads `WP_HTML_Tag_Processor` from
`wp-core/wp-includes/html-api/` — populated by `roots/wordpress` as a
dev dependency, with `extra.wordpress-install-dir = "wp-core"` routing
the install there. `wp-core/` is gitignored. On a fresh checkout:

```
cd packages/component-runtime-wp
composer install
composer test
```

Without the `composer install` step, the suite fails at class
resolution on the first WP-HTML-API reference.

### Effective PHP floor for tests is 8.4

The package's runtime requirement is `php >= 8.0` (composer.json). But
PHPUnit 9.6's transitive dep `doctrine/instantiator 2.x` requires
PHP ^8.4, so running `composer test` against a PHP 8.0–8.3 toolchain
will fail at `composer install` with an unresolvable dependency tree.

Either run tests under PHP 8.4+ or pin `doctrine/instantiator: ^1.5`
in `require-dev` (1.5.x supports PHP 7.1+ and is API-compatible with
PHPUnit 9.6).

## Test harness

- **Bootstrap**: `tests/php/bootstrap.php` defines `ABSPATH` +
  `WP_CONTENT_DIR` with a `getmypid()` suffix so parallel test
  processes don't share scratch dirs.
- **Base class**: `tests/php/AbstractTestCase` wires Brain Monkey
  setUp/tearDown via the `MockeryPHPUnitIntegration` trait.
- **WP function stubs**: per-test via `Brain\Monkey\Functions\when(...)` —
  no global stub layer. Each cell declares what it needs.
- **Static state**: classes with private static state
  (`ComponentScanner::$components`, `ManifestRegistry`'s 3 caches,
  `ComponentCssEmitter::$cached_bundle`, `ComponentDiscovery::$emitted`)
  reset via reflection in per-test setUp so cells survive random order.

## Lefthook gates

- **pre-commit** — `composer fix; composer check` (phpcbf + phpcs +
  phpstan) on staged `*.php`
- **pre-push** — `composer check` + `composer test` (full PHPUnit
  suite, ~1 s)

## Coding standards

- WordPress-Core phpcs ruleset with selective exclusions (see `phpcs.xml`)
- phpstan level: max
- Tests in `tests/php/` are NOT linted (`phpcs.xml` scopes to `src`).
  Keep them consistent with the source's WP-style snake_case for
  readability, but don't try to satisfy WP rules in test files.

## Subtree-split CI

`packages/component-runtime-wp/` mirrors to a standalone composer-
installable repo via the master-only `subtree-split` workflow. PR
merges to master fire the mirror; the released package includes
`tests/` + `phpunit.xml` + the composer test deps so downstream
consumers can run the suite against their PHP toolchain.
