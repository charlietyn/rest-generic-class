# FAQ

## Does this package register routes for me?
No. You register your routes in your Laravel application and wire them to your controllers that extend `RestController`.

## Can I use MongoDB?
A `BaseModelMongo` class is included for MongoDB usage through `mongodb/laravel`. You are responsible for wiring it into your app.

## Is Spatie permission required?
No. Spatie is optional. The permission models, traits, and middleware are available if you install `spatie/laravel-permission`.

## Does the package support hierarchy trees?
Yes, when your model defines `const HIERARCHY_FIELD_ID` and you pass the `hierarchy` parameter.

[Back to documentation index](../index.md)
