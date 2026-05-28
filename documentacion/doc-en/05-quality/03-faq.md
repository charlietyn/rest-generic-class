# FAQ

## Does this package register routes for me?
No. You register your routes in your Laravel application and wire them to your controllers that extend `RestController`.

## Can I use MongoDB?
A `BaseModelMongo` class is included for MongoDB usage through `mongodb/laravel`. You are responsible for wiring it into your app.

## Is Spatie permission required?
No. Spatie is optional. The permission models, traits, and middleware are available if you install `spatie/laravel-permission`. From 3.0.0, however, if you opt into the package's permission module, your `User` model must implement `ProvidesRoles` and your `Role` model must implement `ProvidesRolePermissions` (see the [permissions guide](../03-usage/06-permissions.md)).

## Why a `ProvidesRoles` contract and not a config key naming the relation?
Because a config key is *stringly-typed*: a typo surfaces deep in runtime. An interface is verified by PHP's class loader — native fail-fast, no reflection. The contract also accommodates non-Eloquent sources (external services, caches) without coupling the library to the ORM.

## Will my User/Role models keep working if I just upgrade to 3.0.0 without touching code?
No. Upgrading to 3.0.0 is an intentional **breaking change**. You need to add `implements ProvidesRoles` to the User (with its `provideRoles()`) and `implements ProvidesRolePermissions` to the Role. The full migration is roughly five lines across two files. See [Migrating from 2.x to 3.0.0](../03-usage/06-permissions.md#5-migrating-from-2x-to-300).

## Does the package support hierarchy trees?
Yes, when your model defines `const HIERARCHY_FIELD_ID` and you pass the `hierarchy` parameter.

[Back to documentation index](../index.md)
