# Original EvolvePHP 1 Architecture

This document describes the EvolvePHP 1 architecture as it exists at audited commit `2da5da7866f65d314a0e2bf10b572004b3014d60`. It does not propose or implement the EvolvePHP 2 architecture.

## Request lifecycle

```text
Incoming request
    |
    v
index.php
    |
    v
CORS and PHP version checks
    |
    v
route.php
    |
    v
Composer and configuration
    |
    v
Environment selection
    |
    v
Route sanitisation
    |
    v
Component route file or default controller method
    |
    v
Dynamic method dispatch
    |
    v
View output or error controller
```

## Entry point

`index.php` is the application entry point. It performs request preprocessing before including `route.php`.

The file:

- Reflects `$_SERVER['HTTP_ORIGIN']` into `Access-Control-Allow-Origin` when an origin header is present.
- Sends `Access-Control-Allow-Credentials: true`.
- Handles `OPTIONS` requests by sending allowed method and requested-header CORS headers, then exits.
- Checks the current PHP version against `7.1.0`.
- Displays an inline HTML message and exits when PHP is older than 7.1, although the message says PHP 5.4.0 or newer is supported.
- Requires `route.php` after the checks.

## Composer bootstrap and configuration

`route.php` requires:

- `vendor/autoload.php`
- `configs/application.config.php`
- `configs/user.config.php`
- `configs/ini.config.php`

The configuration files define global constants for paths, URLs, assets, routing, sessions, debug mode and site settings.

`configs/application.config.php` derives `BASE_URL` from `$_SERVER['REQUEST_SCHEME']`, `$_SERVER['HTTPS']` and `$_SERVER['HTTP_HOST']`. It also defines directory constants such as `BASE_DIR`, `COMPONENTS_DIR`, `HELPERS_DIR`, `CONFIGS_DIR`, `PUBLIC_DIR`, `ASSETS_DIR` and layout/asset URL constants.

`configs/user.config.php` sets `DEBUG` to `TRUE`, defines `DEFAULT_ROUTE`, session constants and placeholder site metadata.

`configs/ini.config.php` configures error reporting, display settings, log settings, session cookie-only mode, timezone and session garbage-collection lifetime.

## Environment selection

`route.php` creates an `EvolvePhpCore\ExceptionFactory` instance and then sets `$_SERVER['APPLICATION_ENV']` to `development` when `DEBUG === TRUE`; otherwise it sets it to `production`.

This environment is stored in the global `$_SERVER` array rather than an application environment object.

## Router behaviour

`route.php` reads the route from `$_SERVER['PATH_INFO']` or `$_GET['route']`, then applies:

```php
preg_replace('/[^a-zA-Z0-9\/&=-]/', '', ...)
```

The result is trimmed, split by `/`, and processed segment by segment. For an empty route segment, the router instantiates the configured default controller class from `DEFAULT_ROUTE` and invokes the configured default method.

For a non-empty segment, the router:

- Instantiates the default controller.
- Checks the segment against a broad alphanumeric/hyphen pattern.
- Includes `components/{segment}/route.php` when that file is readable.
- Otherwise calls a same-named method on the default controller when present.
- Otherwise calls `ErrorController::pageNotFound()`.

The router returns after handling the first route segment.

## Component route files

The router supports component-level `route.php` files under `components/{component}/route.php`. The audited repository includes `components/site/define.php` and `components/error/define.php`, but no component route file was present in the inspected component folders.

Component `define.php` files define component constants such as `COMP_NAME`, `COMP_ID`, `COMP_DIR`, and, for the error component, `ERROR_404`.

## Controller dispatch and fallback

The default route is:

```php
['EvolvePhpComponent\site\controllers\SiteController', 'home']
```

`SiteController::home()` sets a `page_title` session value and calls `View::loadView('default', 'home.php', [])`.

When a route cannot be resolved, `EvolvePhpComponent\error\controllers\ErrorController::pageNotFound()` sets HTTP status code 404 and calls `View::loadView('error', '404.php')`.

Controller classes are instantiated directly with `new`; there is no dependency-injection container, request object or response object.

## Core framework classes

The current core classes include:

- `ApplicationAbstract`: base class with `sessionHandler()` and `getInstance()`.
- `Loader`: file loading and class/method factory helpers.
- `View`: layout and view inclusion based on `configs/view.config.php`.
- `Model`: PDO connection setup, table configuration, prepared-query helpers and simple query builders.
- `Session`: session initialization, fingerprinting, flash messages and CSRF helper methods.
- `ExceptionFactory`: error and exception handler registration plus HTML exception output.
- `LogFactory`: logger provider factory.
- `core/log/Apachelog4phpFileLogger.php`: default file logger using Apache log4php.
- `core/log/MonologDatabaseLogger.php`: database logger placeholder.
- `core/exception/*`: base and specific exception classes.
- `core/error/*`: base error types.

## Component structure

Components are organised under `components/{component}/` with nested `controllers/`, `models/` and `views/` directories. The audited repository includes `site` and `error` components.

The default site and error views both render the current 404-style page content.

## Helper structure

Helpers live under `helpers/` and are mapped to `EvolvePhpHelper\`.

Audited helpers include URL handling, HTML encoding/decoding, form data filtering, string formatting, cURL requests, date/time formatting and a `FormatTime.php` file that declares another `FormData` class.

Helpers directly access globals such as `$_SERVER`, `$_GET` and `$_POST`, and some helpers emit headers.

## Model and database foundations

`core/Model.php` loads `configs/database.config.php` and `configs/database-tables.config.php`. It supports MySQL, PostgreSQL, SQLite 2 and SQLite DSNs. It stores a PDO connection on the model instance and exposes protected methods for prepared inserts, selects and simple query construction.

The query builder concatenates table names, column lists, joins, where keys and extra SQL fragments. Data values are passed separately to prepared statements, but SQL identifiers and fragments are not abstracted through a grammar or query object.

## View foundations

`core/View.php` loads `configs/view.config.php`, chooses a configured layout, replaces `%layout%`, `%component%` and `%placeholder%` markers, and includes PHP files from the layout, component or base directories.

The view layer renders PHP templates directly and unsets flash messages after loading the layout.

## Session handling

`core/Session.php` starts PHP sessions using constants from `configs/user.config.php`. It fingerprints sessions using `REMOTE_ADDR` plus browser details from `whichbrowser/parser`, stores session metadata in `$_SESSION`, provides timeout helpers, generates CSRF tokens with `md5(rand())`, and renders flash messages as HTML strings.

## Logging

`LogFactory` currently registers `default_file`, backed by `Apachelog4phpFileLogger`. The logger writes to `BASE_DIR.'logs/error.log'`. `configs/ini.config.php` also sets PHP's `error_log` to `__DIR__."/logs/php-error.log"`, which resolves relative to `configs/`.

Composer also declares Monolog and a Monolog PDO handler, while `MonologDatabaseLogger.php` remains a placeholder.

## Exception and error handling

`ExceptionFactory` registers global error and exception handlers. Error handling converts PHP errors to custom exceptions. Exception handling echoes generated HTML directly, includes stack-trace information, and writes trace text through the default file logger.

## PHPUnit setup

The repository has no `phpunit.xml` or `phpunit.xml.dist` file in the audited tree. `composer.json` declares `phpunit/phpunit` version `8` in `require-dev` and defines:

```json
"test": "phpunit"
```

The `tests/` directory contained only `tests/index.html` before Phase 0 documentation-policy tests were added.

## Apache rewrite dependency

`.htaccess` enables `RewriteEngine` and rewrites non-file, non-directory, non-link requests to:

```text
index.php?route=$1
```

The README states that EvolvePHP was originally designed for Apache-based deployment and may need document-root or URL-rewrite configuration.

## Direct global and output usage

The current runtime directly uses:

- `$_SERVER`
- `$_GET`
- `$_POST`
- `$_SESSION`
- `getallheaders()`
- `header()`
- `http_response_code()`
- `session_*()` functions
- `ini_set()`
- direct `echo`, `include_once`, `require` and `exit`

These are preserved legacy behaviours and were not changed during this documentation task.
