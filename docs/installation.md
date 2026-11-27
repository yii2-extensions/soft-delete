# Installation guide

## Requirements

- PHP 8.3 or later.
- Composer.
- Yii 2.0.55 or a later 2.0 patch, or Yii 22.0.

## Install from Composer

Yii 2.0 uses Bower asset packages, and Composer reads repositories only from the application's own `composer.json`. If
the application does not already configure a source for them, add Asset Packagist before installing:

```bash
composer config repositories.asset-packagist composer https://asset-packagist.org
```

Install the package from your application directory:

```bash
composer require yii2-extensions/soft-delete:^0.1
```

To use Yii 22 while it is a development branch, allow that version in the application root:

```bash
composer require yii2-extensions/soft-delete:^0.1 yiisoft/yii2:22.0.x-dev
```

## Install from a local checkout

To try unreleased changes, register a local checkout as a Composer path repository:

```bash
composer config repositories.soft-delete path /path/to/soft-delete
composer require yii2-extensions/soft-delete:dev-main
```

[Back to documentation](../README.md#documentation)
