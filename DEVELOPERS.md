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
