# EvolvePHP

EvolvePHP is a lightweight, component-based PHP framework created to support structured web application development without the overhead of a large framework.

It provides routing, reusable application components, model and view foundations, session handling, logging, exception management, configuration, and PHPUnit testing support.

EvolvePHP was developed from the ground up and has been used to build [Africa Global Export Market](https://africaglobalexportmarket.com), a live export marketplace serving more than 5,000 users.

## Why EvolvePHP?

EvolvePHP was created to explore and implement the core architectural concerns behind modern PHP frameworks:

- Application routing
- Component-based organisation
- Models and views
- Dependency loading
- Configuration management
- Session handling
- Logging and error reporting
- Exception handling
- Reusable helpers
- Automated testing
- PSR-4 autoloading with Composer

The project demonstrates framework design, backend architecture, and the process of building reusable application infrastructure from first principles.

## Requirements

- PHP 7.1 or later
- Composer
- Apache with `mod_rewrite`, or another compatible web server
- A supported relational database when using database-backed components

## Installation

Clone the repository:

```bash
git clone https://github.com/josiahking/evolvephp.git
cd evolvephp
```

Install Composer dependencies:

```bash
composer install
```

Review and update the application configuration files inside:

```text
configs/
```

Start a local PHP development server:

```bash
php -S localhost:8800
```

Then open:

```text
http://localhost:8800
```

> EvolvePHP was originally designed for Apache-based deployment. Depending on your local environment, you may need to configure the document root or URL rewriting before running the application.

## Project Structure

```text
evolvephp/
├── components/      # Application components and feature modules
├── configs/         # Framework and application configuration
├── core/            # Core framework classes
├── helpers/         # Reusable helper classes and functions
├── logs/            # Application log files
├── public/          # Public assets and web-accessible files
├── tasks/           # Application and maintenance tasks
├── tests/           # Automated tests
├── index.php        # Application entry point
├── route.php        # Route resolution and dispatch
└── composer.json    # Dependencies and autoloading configuration
```

## Core Components

The `core` directory includes foundational classes for:

- Application behaviour
- Model operations
- View rendering
- Session management
- Class and component loading
- Logging
- Exception handling
- Error handling

Application functionality is organised into reusable modules inside the `components` directory.

## Routing

Incoming requests are processed through `route.php`.

The router:

1. Loads the required application configuration.
2. Determines the current environment.
3. Sanitises the requested route.
4. Resolves the matching component or default controller.
5. Dispatches the requested method.
6. Sends unmatched routes to the error controller.

## Autoloading

EvolvePHP uses Composer PSR-4 autoloading.

```json
{
  "autoload": {
    "psr-4": {
      "EvolvePhpCore\\": "core/",
      "EvolvePhpComponent\\": "components/",
      "EvolvePhpHelper\\": "helpers/"
    }
  }
}
```

After adding or moving framework classes, regenerate the Composer autoloader:

```bash
composer dump-autoload
```

## Testing

Run the PHPUnit test suite with:

```bash
composer test
```

Or:

```bash
vendor/bin/phpunit
```

## Production Use

EvolvePHP has been used as the foundation of Africa Global Export Market, a live business-to-business export platform serving more than 5,000 users.

This repository also demonstrates:

- Custom framework development
- Object-oriented PHP
- Backend architecture
- Modular application design
- Production system ownership
- Maintenance of applications built without a mainstream framework

## Project Status

EvolvePHP is an independently maintained framework and portfolio project.

It represents an earlier framework-design effort and may require modernisation before being adopted for a new production application. For new commercial projects, review the PHP version, dependencies, security requirements, and test coverage before use.

The current `master` line represents EvolvePHP 1 and is preserved for historical and maintenance purposes. EvolvePHP 2 is a separate redesign, not an in-place refactor of the legacy runtime. New production applications should not begin with EvolvePHP 1 without a detailed security and compatibility review. See the [legacy overview](docs/history/evolvephp-1-overview.md), [known risks and limitations](docs/history/known-risks-and-limitations.md), [support policy](SUPPORT.md), and [security policy](SECURITY.md).

## Roadmap

Potential improvements include:

- PHP 8.2+ support
- Improved routing syntax
- Dependency injection
- Environment-variable configuration
- Database migrations
- Request and response abstractions
- Middleware support
- Expanded automated tests
- Continuous integration
- Improved documentation and examples

## Composer Metadata

Recommended `composer.json` description:

```json
{
  "description": "A lightweight component-based PHP framework for building structured web applications."
}
```

Also correct any misspelled keyword such as:

```text
elvovephp framework
```

to:

```text
evolvephp framework
```

## Author

**Josiah Gerald**

Senior Backend Engineer specialising in PHP, Laravel, REST APIs, payment integrations, and production business platforms.

- GitHub: [github.com/josiahking](https://github.com/josiahking)
- LinkedIn: [linkedin.com/in/josiah-g-0919763b](https://www.linkedin.com/in/josiah-g-0919763b/)

## License

EvolvePHP is available under the BSD 3-Clause License.
