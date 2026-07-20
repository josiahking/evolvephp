# EvolvePHP

EvolvePHP is a lightweight, component-based PHP framework created to support
structured web application development without the overhead of a large framework.

It provides routing, reusable application components, model and view foundations,
session handling, logging, exception management, configuration and PHPUnit testing
support.

EvolvePHP was developed from the ground up and has been used to build
[ Africa Global Export Market ](https://africaglobalexportmarket.com),
a live export marketplace serving more than 5,000 users.

## Why EvolvePHP?

EvolvePHP was created to explore and implement the core architectural concerns
behind modern PHP frameworks:

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

The project demonstrates framework design, backend architecture and the process of
building reusable application infrastructure from first principles.

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
