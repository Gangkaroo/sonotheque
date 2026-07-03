# Repository Rules

## PHP

- All PHP code must comply with PSR-12. Run `vendor/bin/pint --test` from
  `backend` before considering PHP work complete; use `vendor/bin/pint` to
  apply formatting.
- All autoloaded PHP classes must comply with PSR-4. Namespace and directory
  structure must follow the mappings in `backend/composer.json`, and class file
  names must match their class names exactly.
- Production classes under `backend/app` use the `App\` namespace. Tests under
  `backend/tests` use the `Tests\` namespace. Database factories and seeders
  follow their declared `Database\Factories\` and `Database\Seeders\`
  mappings.
- Run `composer check:autoload` after adding, moving, renaming, or changing the
  namespace of a PHP class.
