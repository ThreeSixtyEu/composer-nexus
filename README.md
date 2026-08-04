# Nexus Composer plugin

[![Packagist Version](https://img.shields.io/packagist/v/threesixty-eu/composer-nexus)](https://packagist.org/packages/threesixty-eu/composer-nexus)
[![Packagist Downloads](https://img.shields.io/packagist/dt/threesixty-eu/composer-nexus)](https://packagist.org/packages/threesixty-eu/composer-nexus)
![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/threesixty-eu/composer-nexus)
[![License](https://img.shields.io/github/license/ThreeSixtyEu/composer-nexus)](https://github.com/ThreeSixtyEu/composer-nexus/blob/master/LICENSE)

Fast and reliable Composer package downloads using Nexus proxy: a caching proxy that does not require you to modify your projects.

## Getting Started

### Prerequisites

* PHP 7.4 or newer
* A running Nexus Composer proxy instance
* Composer 2

### Installation

Installation and configuration of the Nexus plugin is global, so you can use it for all projects that use Composer without having to add it to your project's `composer.json`.

```
composer global config allow-plugins.threesixty-eu/composer-nexus true
composer global require threesixty-eu/composer-nexus
composer nexus:enable https://nexus.your-domain.lan/repository/composer-proxy/
```

### Usage

After enabling and configuring Nexus proxy, it is automatically used for all Composer projects when running `require`, `update`, `install`, etc.

### Removal

Disable the plugin by executing:

```
composer nexus:disable
```

If you want to remove the plugin completely, execute:

```
composer global remove threesixty-eu/composer-nexus
```

## Authors

* [3sixty team](https://3sixty.eu)
* Jelle Raaijmakers - [jelle@gmta.nl](mailto:jelle@gmta.nl) / [GMTA](https://github.com/GMTA) (Original author)

## Contributing

Raise an issue or submit a pull request on [GitHub](https://github.com/ThreeSixtyEu/composer-nexus).

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

