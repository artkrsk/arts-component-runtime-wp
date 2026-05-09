# `arts/component-runtime-wp`

WordPress integration layer for [`@arts/component-runtime`](../component-runtime/). Bridges PHP-side rendering with the JS-side runtime via:

- **Manifest emission** — buffered output is scanned for `data-arts-component-name` attributes; the bootstrap module + inline CSS + modulepreload links are injected after the document's `<meta charset>`.
- **Auto-discovery** — opt-out per request via `add_filter('arts_runtime/auto_discover', '__return_false')`.
- **Bundled dependencies** — bootstraps GSAPLoader and TextSplitter UMD scripts so `window.gsap`, `window.ScrollTrigger`, `window.SplitText`, and `window.TextReveal` are available before the JS bootstrap module evaluates.

## Install

Composer (path or vcs source):

```jsonc
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/artkrsk/arts-component-runtime-wp" }
  ],
  "require": {
    "arts/component-runtime-wp": "^3.0"
  }
}
```

The PSR-4 namespace is `Arts\ComponentRuntime\` rooted at `src/`.

## Layout

```
src/
├── Plugin.php                       # Entry — extends BasePlugin<ManagersContainer>
├── Containers/ManagersContainer.php # DI container for the manager set
├── Managers/                        # Per-concern managers (10 files)
│   ├── BootstrapEmitter.php
│   ├── CachePluginCompat.php
│   ├── ComponentCssEmitter.php
│   ├── ComponentDiscovery.php
│   ├── ComponentLayoutResolver.php
│   ├── ComponentScanner.php
│   ├── JsonBlobEmitter.php
│   ├── ManifestRegistry.php
│   ├── PreloadEmitter.php
│   └── WpContentPathInverter.php
└── libraries/arts-component-runtime/index.iife.js
```

## License

GPL-3.0-or-later. See [LICENSE](./LICENSE).
