# CLAUDE.md — `arts/component-runtime-wp` (PHP)

WordPress integration layer for `@arts/component-runtime` — auto-discovers
components in rendered HTML, emits the Vite manifest blob + bootstrap
`<script type="module">` + per-component CSS at the head anchors.

## Mental model

One output-buffer pass over the finished page. `Plugin::instance()` (called
by the product) registers `ComponentDiscovery`, which opens
`ob_start( ComponentDiscovery::process )` at `template_redirect` priority 0.
At flush, `process()`:

1. `ComponentScanner::scan` walks the HTML with `WP_HTML_Tag_Processor` and
   collects every `data-arts-component-name` value.
2. **EARLY anchor** (after `<meta charset>`): `ComponentCssEmitter` styles +
   `PreloadEmitter` modulepreload links.
3. **LATE anchor** (before `</head>`): CSS-coverage blob + `BootstrapEmitter`
   (manifest blob, then the bootstrap module tag).

Scoping differs per emitter: CSS and modulepreloads cover only the components
scanned this request; the manifest blob is always the full set from the merged
manifests, so AJAX navigation, editor drops and static templates resolve from
one emission.

Every manager is a static-only class; state, where any, lives in per-request
statics. `Plugin` declares no managers / config / strings,
`ManagersContainer` is an empty typing shim, and `Plugin` also boots the
`arts/gsap-loader` and `arts/text-splitter` plugins. `ManifestRegistry` is the
hub (the CSS, preload and bootstrap emitters read merged manifests through it);
`ComponentLayoutResolver` is the only place that knows manifest-key layouts
(`src/components/<Name>/<Name>.ts(x)`, else flat `src/components/<Name>.ts(x)`).

## WordPress filters

These share the `arts_runtime/` prefix with the JS `HookBus` namespace but are
plain WordPress filters.

- `arts_runtime/auto_discover` — bool, default `true`; `false` disables the
  whole pipeline.
- `arts_runtime/manifest_paths` — `string[]` of Vite `.vite/manifest.json`
  paths, default `[]`. Products MUST register their build's path; the package
  ships none.
- `arts_runtime/dev_manifest` — `Name => { script, style? }` for components
  the Vite dev server currently serves, default `[]`.
- `arts_runtime/bootstrap_entry` — manifest key of the bootstrap module,
  default `src/bootstrap.ts`.
- `arts_runtime/component_css/mode` — `'inline'` (default) or `'link'`; any
  other value falls back to inline.
- `arts_runtime/component_css/should_skip` — per-component CSS skip; args are
  the default `false`, the merged manifest entry, the component name.

## Frozen identifiers (PHP ↔ JS bridge)

Changing one side without the other breaks the runtime silently.

- `arts-cr-manifest` (`BootstrapEmitter::MANIFEST_BLOB_ID`),
  `arts-cr-css-coverage` (`ComponentCssEmitter::COVERAGE_BLOB_ID`) and the
  `arts-cr` link-id prefix mirror `MANIFEST_BLOB_ID` / `COVERAGE_BLOB_ID` /
  `LINK_ID_PREFIX` in `packages/component-runtime/src/shared/constants/BOOTSTRAP.ts`.
  The scanned attribute is `COMPONENT_ATTR` in that folder's `ATTRIBUTES.ts`.
- Link-mode ids `arts-cr-<chunk basename without ext>-css` match what
  `ComponentCssPlugin.injectLink` synthesises, so server and runtime links
  dedup by id. Dev SASS links `arts-cr-<Name>-inline` are the id looked up by
  `packages/vite-plugin/src/internals/hmr-snippet.ts`.
- Inline CSS is `<style id="arts-cr-component-css" data-noptimize="1">` (PHP-only
  id). The Autoptimize exclude token is `arts-runtime`.

## Gotchas

- **Registration runs in `Plugin::instance()`'s constructor, not on
  `plugins_loaded`.** `Plugin` overrides none of `add_filters` / `add_actions`
  / `do_run`, so its `plugins_loaded` run action does nothing.
  `ComponentDiscovery::register()` reads `is_admin()`, `WP_CLI`,
  `wp_doing_cron()` and `arts_runtime/auto_discover` once, at that call, and
  `CachePluginCompat::register()` checks `LSCWP_V` then too: a subscriber
  attached after the first `instance()` call is too late. Feeds and robots.txt
  are skipped later, inside the `template_redirect` callback.
- **Consumer contract**: any script reading `window.__artsManifest__` must be
  `defer`, `async`, `type="module"` or footer-printed. The late payload is
  spliced into the buffer after `wp_head` has already printed.
- **Silent no-ops**: with no `</head>` the late payload is dropped, yet
  `$emitted` still flips. With no `<meta charset>` in the first 2 KB (or only
  inside an HTML comment) the early payload falls back to `</head>` too.
  `process()` leaves `PHP_OUTPUT_HANDLER_CLEAN` phases and empty buffers alone.
- **`$emitted` is the double-pass guard.** `CachePluginCompat` routes
  `litespeed_buffer_after` (priority 999, only when `LSCWP_V` is defined) back
  through `ComponentDiscovery::process`; once the first pass has injected, the
  second returns the buffer untouched. New cache-plugin shims go in
  `CachePluginCompat`, not in the discovery flow.
- **Bootstrap tag is hand-built with `wp_get_script_tag`**, not
  `wp_enqueue_script_module`. Do not "modernise" it; the `BootstrapEmitter`
  docblock holds the reasoning.
- **Manifest location decides asset URLs.** Each merged entry is stamped with
  `_arts_base_url`: the dir containing `.vite/manifest.json`, mapped through
  `content_url()` (with a `realpath` fallback for symlinked plugin dirs). A
  manifest outside `WP_CONTENT_DIR` gets `''`, its assets resolve to bare
  relative paths, and the only signal is an `error_log` prefixed
  `[arts-runtime]` under `WP_DEBUG`. Each entry resolves `file` / `css` against
  its OWN base, so builds coexist; later `manifest_paths` override earlier
  keys (shallow merge).
- **Asset lists are the full `imports[]` closure.** `collect_entry_css_urls` /
  `collect_entry_js_urls` walk transitive imports, not just the entry's own
  `css[]` / `file`; the blob's per-entry `css` is that flattened list.
- **Inline mode drops what it cannot read.** CSS URLs that do not invert to a
  file under `content_url()` (`WpContentPathInverter`) are skipped, and a
  component left with none is not reported as covered. Link mode dedups by URL
  and touches no filesystem.
- **Dev-served components are special-cased.** For a name in
  `dev_manifest`: the blob entry becomes `{ file: <dev script> }` with prod
  `css` removed, `ComponentCssEmitter` skips the prod CSS, and, when `style` is
  given, a `<link>` to the dev SASS entry is printed at the early anchor. The
  docblocks on `apply_dev_overrides` and `resolve_component_css_urls` explain
  why stale prod CSS must stay out.
- **Component-name charset `[A-Za-z0-9_@/-]+` is duplicated** in
  `ComponentScanner::is_valid_component_name` and the `ComponentLayoutResolver`
  regex. Change both together.
- **Output is spliced verbatim.** `JsonBlobEmitter` encodes with
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES`; inline CSS is never
  HTML-escaped, only `</style>` is neutralised.

## Dependencies

`composer.json`: `php >=8.0`; `arts/base`, `arts/gsap-loader`,
`arts/text-splitter`, `arts/utilities` — all `@dev` via `path` repositories
with absolute, symlinked dev-machine paths. `roots/wordpress` is dev-only and
exists for the HTML API classes; at runtime `ComponentScanner` relies on
core's `WP_HTML_Tag_Processor` with no polyfill.

## Commands (run from this package dir)

`composer test` (PHPUnit), `composer check` (phpcs then phpstan), `composer fix`
(phpcbf). Lefthook pre-push runs `check` + `test` with this directory as root;
pre-commit is in the root CLAUDE.md.

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

Without the `composer install` step there is no `vendor/` for the bootstrap's
`require_once` of `vendor/autoload.php`, and no `wp-core/` HTML API classes.

### Dev dependencies are resolved for PHP 8.4+

The package's runtime requirement is `php >= 8.0` (composer.json), but
`composer.lock` pins dev-dependency `doctrine/instantiator` to a 2.x release
that declares `php ^8.4`. PHPUnit 9.6 itself accepts `^1.5.0 || ^2`
(`vendor/phpunit/phpunit/composer.json`), so the floor comes from the lock, not
from PHPUnit; for PHP 8.0–8.3 pin `doctrine/instantiator: ^1.5` in
`require-dev` and update. An already-installed `vendor/` runs the suite under
both 8.3 and 8.5.

## Test harness

- **Bootstrap**: `tests/php/bootstrap.php` defines `ABSPATH` +
  `WP_CONTENT_DIR` with a `getmypid()` suffix so parallel test
  processes don't share scratch dirs. No WordPress boot, no DB.
- **Base class**: `tests/php/AbstractTestCase` wires Brain Monkey
  setUp/tearDown via the `MockeryPHPUnitIntegration` trait. WP function stubs
  are per-test via `Brain\Monkey\Functions\when(...)` — no global stub layer.
- **Strict, random order**: `phpunit.xml` sets `executionOrder="random"`,
  `failOnRisky`, `failOnWarning` and `beStrictAboutOutputDuringTests`; stray
  output or order-dependence fails the run.
- **Static state**: classes with private static state
  (`ComponentScanner::$components`, every `ManifestRegistry` cache,
  `ComponentCssEmitter::$cached_bundle`, `ComponentDiscovery::$emitted`)
  reset via reflection in per-test setUp so cells survive random order. Each
  test file keeps its own reset list, so a new static cache must be added to
  every file that reaches that class.
- **Constants leak across cells**: `LSCWP_V` may only be defined in a
  `@runInSeparateProcess` cell (`CachePluginCompatTest`); otherwise every later
  `register()` would also add the LiteSpeed filter.

## Coding standards

- WordPress-Core phpcs ruleset with selective exclusions (see `phpcs.xml`)
- phpstan level: max
- Source stays PHP 8.0-compatible: `phpcs.xml` `testVersion` is `8.0-` and
  `phpstan.neon` `phpVersion` is `80000`.
- Tests in `tests/php/` are NOT linted (`phpcs.xml` scopes to `src`).
  Keep them consistent with the source's WP-style snake_case for
  readability, but don't try to satisfy WP rules in test files.

## Packaging

`.gitattributes` marks `tests/`, `phpunit.xml`, `phpstan.neon`, `phpcs.xml`
and dotfiles `export-ignore` to keep them out of the composer dist. Tests and lint config DO reach the subtree-split mirror (the split action does not apply `export-ignore`), so the suite is runnable from a clone of the mirror, not from `vendor/`; they are stripped only from the composer dist (`git archive`).
