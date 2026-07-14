# Repository Rules

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
