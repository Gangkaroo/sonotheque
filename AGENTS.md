# Repository Rules

## Branching And Releases

- Use `development` as the integration branch for ongoing development. Start
  ordinary implementation and documentation work there, not on `master`.
- If a short-lived feature or fix branch is useful, branch it from
  `development` and merge it back into `development` when complete.
- Keep `master` release-only. When a new version is ready, validate the release
  commit on `development`, then merge or fast-forward that exact commit into
  `master` and push `master` before creating the version tag.
- Create version tags only from commits already contained in `master`. Do not
  publish a release directly from `development` or a feature branch.
- Every release must have a dated, non-empty `## X.Y.Z` section in
  `CHANGELOG.md`. This section is the canonical release-note reference and is
  published as the GitHub Release description by the release workflow.
- Keep the version in `VERSION`, `frontend/package.json`, and both root-package
  entries in `frontend/package-lock.json` synchronized for every release.

## PHP

- On this Windows workspace, never assume that `php` or `composer` from
  `PATH` uses the required runtime. `composer.bat` currently resolves PHP
  8.2, while Sonotheque requires PHP 8.5. Use these explicit commands:

  ```powershell
  $php85 = "C:\Users\Tom\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
  $composerPhar = "C:\ProgramData\ComposerSetup\bin\composer.phar"
  & $php85 artisan test
  & $php85 vendor\bin\pint --test
  ```

- Run Composer itself as `& $php85 $composerPhar <command>`. Do not use bare
  `composer test` on this machine because its `@php` scripts run under PHP
  8.2. The `composer check:autoload` wrapper can also hang after generating
  the autoloader because it recursively invokes the PATH-bound Composer.
  Run its strict underlying command directly instead:

  ```powershell
  & $php85 $composerPhar dump-autoload --optimize --strict-psr --no-scripts
  ```

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
- Run the explicit strict Composer command documented above after adding,
  moving, renaming, or changing the namespace of a PHP class.
