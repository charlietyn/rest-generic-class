# Enabling channel authorization with the migration script

Since v2.7.1 the library ships `scripts/enable-channel-authorization.php`, the script used to migrate kwikvet to channel-scoped authorization (v2.7.0+): route-name permissions, fail-closed middleware, the effective-permission Gate, explicit globals and a catalog limited to protected routes.

```bash
php vendor/ronu/rest-generic-class/scripts/enable-channel-authorization.php --path=/path/to/project
```

It is not an artisan command and is not registered in `composer.json` `bin`: it is run once per project.

## Scope

It targets projects of the kwikvet family: `ronu/rest-generic-class` plus the generated security module. Before writing anything it checks that the project has what `--apply` needs, and a missing requirement blocks `--apply`, even with `--force`:

- the commands `permissions:sync-from-routes` (`--guard`, `--dry-run`), `roles:assign-permissions` (`--by`, `--guard`, `--prefix`, `--no-create`; `--from` only when a grant uses a file) and `permission:cache-reset`;
- the library columns on the permissions table (`name`, `guard_name`, `module`, `route`, `type`, `model`, `restrict`, `action`) and a reachable database;
- channels identified by URL prefix (`admin/...`), not by subdomain, and every protected route under one of those prefixes;
- `ronu/rest-generic-class` 2.7.0 or newer installed.

## What it inspects

It boots the project in a child process and reads the real route table:

| Value | Source |
| --- | --- |
| Channel prefixes | First URI segment of every route behind the authorization middleware (`--prefixes` overrides it). |
| Catalog markers | Middleware aliases that resolve to `SpatieAuthorize`, directly or by wrapping it. |
| Guard | Parameter of that middleware, e.g. `maybe.permission:api` (`--guard` overrides it). |
| Globals | Protected routes whose name matches `--globals` (default `*.auth.permissions,*.auth.user-profile`). |

It then reports unnamed or duplicated protected route names, simulates the new configuration (every protected route must resolve a permission), and prints the `REST_AUTHZ_*` values it will write.

## Dry run by default

Without `--apply` it only prints the plan, including the diff of the route files, and writes nothing. The exit code is `1` when the checks found problems, `2` when it aborts (for example outside a Laravel project) and `0` otherwise.

## `--apply` order

Activation is the last step, so nobody is locked out halfway:

1. `composer.json`: raise the constraint to `^2.7` if it allows older versions.
2. Route files: wrap each global route in `Route::group(['permission_global' => true], fn () => ...)`. The report lists, per file, only the route names of that file's channel.
3. `permissions:sync-from-routes` with the new configuration injected as environment variables.
4. Role grants (`--grant`).
5. `permission:cache-reset`.
6. Check that at least one role has permissions; otherwise it stops (unless `--force`).
7. Write `.env.example` and `.env` (this activates enforcement), refresh cached configuration.
8. Verify the configuration the project now reads.

At the end, or when a step fails, it prints the list of steps with `✔` (done), `✘` (the one that failed) and `·` (not reached), so you know where to resume.

## Granting roles

| Form | Grants |
| --- | --- |
| `--grant=Administrator:admin.` | Every permission whose name starts with `admin.`. |
| `--grant=Client:@client-permissions.json` | The explicit flat list in the file (`.json`, `.yml` or `.yaml`), through `roles:assign-permissions --from`. |

Use a file when resources are published in several channels: granting a whole prefix such as `client.` may give a role more than it should have. A file outside the project is copied into it for the command and removed afterwards. The roles must already exist.

```bash
php vendor/ronu/rest-generic-class/scripts/enable-channel-authorization.php --path=/path/to/project --apply \
    --grant=Administrator:admin. --grant=Client:@client-permissions.json --grant=Veterinarian:mobile.
```

## Options

| Option | Meaning |
| --- | --- |
| `--path=` | Project directory (default: current directory). |
| `--apply` | Write the changes; without it, dry run. |
| `--grant=Role:prefix` / `--grant=Role:@file` | Grant permissions to an existing role; repeatable. |
| `--globals=` | Comma-separated name patterns of the global routes. |
| `--prefixes=` | Channel prefixes, if detection is not right. |
| `--guard=` | Guard, when routes use several. |
| `--skip-sync` | Do not run the permission synchronization. |
| `--force` | Continue despite check errors or no role grants, and overwrite existing `.env` values. |
| `--php=` | PHP binary used for the child processes. |

## Manual follow-ups

The script prints them at the end: per-channel login restriction, per-channel `GET /permissions` filtering, regression tests, project documentation and the deployment order in other environments (synchronize and grant **before** changing their `.env`).

See [configuration](../02-configuration/00-configuration-reference.md) and the [release notes](../../../docs/release-notes-channel-authorization.md).
