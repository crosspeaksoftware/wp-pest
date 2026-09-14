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
