When developing `wp-pest`, add a local path repository to the consuming project's `composer.json`. The repository URL is relative to that file:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../wp-pest",
      "options": { "symlink": true }
    }
  ],
  "require-dev": {
    "crosspeak/wp-pest": "*@dev"
  }
}
```

Then run `composer update` (or `composer require --dev crosspeak/wp-pest:@dev`). Use the relative path that reaches the local `wp-pest` checkout from the consuming project.

Run the framework unit suite with `composer test`. Run the real WordPress/SQLite
regression suite with `composer test:integration`; the first run installs a small
WordPress fixture through Composer and needs network access. The integration suite
checks database rollback through the SQLite adapter, request-state restoration,
cleanup callbacks, and dependency basenames/URLs with a symlinked plugin.

To check plugins that branch on `is_admin()` during bootstrap, run the integration
suite a second time with `WP_PEST_INTEGRATION_CONTEXT=admin composer test:integration`.
The fixture plugin records its context at load time so the suite verifies both
bootstrap behavior and scoped admin/AJAX helpers against WordPress itself.

Shared constants are emitted as self-contained PHP because browser containers do
not necessarily mount wp-pest's Composer installation. The runtime probe in
`src/Runtime/environment.php` is likewise executed both locally and through WP-CLI.
Keep it free of dependencies on framework classes. Docker Compose output must escape
PHP dollar signs as `$$` so Compose does not expand them as host variables.
