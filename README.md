# Laravel Simple Saga

[![Build Status](https://github.com/henzeb/laravel-simple-saga/workflows/tests/badge.svg)](https://github.com/henzeb/laravel-simple-saga/actions)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/henzeb/laravel-simple-saga.svg?style=flat-square)](https://packagist.org/packages/henzeb/laravel-simple-saga)
[![Total Downloads](https://img.shields.io/packagist/dt/henzeb/laravel-simple-saga.svg?style=flat-square)](https://packagist.org/packages/henzeb/laravel-simple-saga)
[![License](https://img.shields.io/packagist/l/henzeb/laravel-simple-saga)](https://packagist.org/packages/henzeb/laravel-simple-saga)

A simple saga pattern implementation for Laravel.

## Table of contents

- [Installation](#installation)
- [Documentation](#documentation)
- [Testing this package](#testing-this-package)
- [Security](#security)
- [Credits](#credits)
- [License](#license)

## Installation

You may install the package via Composer:

```bash
composer require henzeb/laravel-simple-saga
```

If you're using the `database` driver (the default), publish and run its migration:

```bash
php artisan vendor:publish --tag=saga-migrations
php artisan migrate
```

## Documentation

- [Getting Started](docs/getting-started.md)
- [Defining Workflows](docs/workflows.md)
- [Writing Steps](docs/steps.md)
- [Running Steps in Parallel](docs/parallel.md)
- [Compensation](docs/compensation.md)
- [Inspecting Sagas](docs/inspecting-sagas.md)
- [Failure Handling](docs/failure-handling.md)
- [Retrying a Saga](docs/retrying-sagas.md)
- [Events](docs/events.md)
- [Encrypting Context](docs/encryption.md)
- [Drivers](docs/drivers.md)
- [Console Commands](docs/console-commands.md)
- [Testing Workflows](docs/testing.md)

## Testing this package

```bash
composer test
```

## Security

If you discover any security-related issues, please email henzeberkheij@gmail.com instead of using the issue tracker.

## Credits

- [Henze Berkheij](https://github.com/henzeb)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
