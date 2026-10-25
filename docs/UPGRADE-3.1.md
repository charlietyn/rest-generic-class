# Upgrading to rest-generic-class 3.1 / Actualizar a rest-generic-class 3.1

## English

3.1 is a minor release: it only fixes defects (12 `KI-*` measured by the apps-generator contract
suite). No class, method or route is removed. Some requests that used to
answer a 200 (or a 500) now answer a 400; each of those has a switch that restores the 3.0 behaviour
while clients are adjusted. Publish nothing: the defaults apply without a published config, and an
existing `config/rest-generic-class.php` keeps working (add the new keys only to turn a fix off).

### Responses that change

| Request | 3.0 | 3.1 | Switch to restore 3.0 |
| --- | --- | --- | --- |
| `POST` create | the raw attributes: hidden ones (password hash, tokens, timestamps) included, no casts | the serialised model, like `update` | — (not a contract) |
| `oper`/`attr`/`eq`/`orderby` on a hidden column (`$hidden`, timestamps included) | 200, filtered — an oracle | 400 `Column 'password' of User cannot be used to filter.` / `… sort.` | `filtering.allow_hidden_columns=true` (`REST_ALLOW_HIDDEN_COLUMNS`) |
| hidden column in `select` | selected, then hidden on output | dropped from the query | idem |
| unknown column in `oper`/`attr`/`eq`/`select`/`orderby`, `other_table.col`, an expression | 500 with the SQL (sometimes a 200 on SQLite) | 400 `Column 'nope' does not exist on products.` | `filtering.strict_column_validation=false` (`REST_STRICT_COLUMNS`) |
| `orderby` `{"joined_table.col": …}` (literal pass-through for manual JOINs) | passed to SQL | 400 (another table) | idem |
| invalid JSON in `oper`/`attr`/`eq`/`orderby`/`pagination` | 200 **unfiltered** (or a 500) | 400 `The oper parameter is not valid JSON.` | `filtering.reject_invalid_json=false` (`REST_REJECT_INVALID_JSON`) |
| `regexp` / `not regexp` | verbatim: 500 on PostgreSQL | `~` / `!~` on PostgreSQL, `REGEXP` on MySQL/MariaDB, 400 on SQLite/SQL Server | `filtering.translate_regexp=false` (`REST_TRANSLATE_REGEXP`), e.g. SQLite with a user-defined `REGEXP` |
| `exists` / `not exists` | 500 (TypeError) | 400 `The exists value is not a valid operator.` | — (use a relation filter) |
| more than `max_depth` nested `and`/`or` groups | 200 | 400 `Maximum nesting depth (5) exceeded.` | raise `filtering.max_depth` |
| filter through a self relation (`roles?oper={"father":…}`) | compared the OUTER row: wrong set | compares the related row | — |
| `DELETE delete-by-id` `{"ids": [...]}` / `{"<model>": [...]}` | 500 | deletes them (a bare list keeps working) | — |
| `eq` and `attr` together | 500 | merged, `attr` wins a repeated key | — |
| `_nested=true` with `relations` | 500 (TypeError) for every relation | the eager-loaded relation is filtered too | — |
| `hierarchy.filter_mode` + `select` | added ancestors/descendants were full rows | every node has the selected columns, plus the primary key and the hierarchy field | — |

### What to check

1. A client that filtered or ordered by a column the model hides (often `created_at`/`updated_at`):
   expose the column (remove it from `$hidden`) or set `allow_hidden_columns=true`.
2. A client that sent a column that does not exist, or ordered by a manually joined table: fix the
   request, or set `strict_column_validation=false`.
3. Nothing else needs a change: the other fixes turn a 500 or a wrong result into the right one.

## Español

3.1 es una versión menor: solo corrige defectos (12 `KI-*` medidos por la suite de contrato de
apps-generator). No se elimina ninguna clase, método ni ruta. Algunas peticiones que
respondían 200 (o 500) responden ahora 400; cada una tiene un interruptor que restaura el comportamiento
de 3.0 mientras se ajustan los clientes. No hay que publicar nada: los valores por defecto se aplican sin
config publicada, y un `config/rest-generic-class.php` existente sigue funcionando (añade las claves
nuevas solo para desactivar un arreglo).

| Interruptor | Por defecto | `false`/`true` restaura 3.0 |
| --- | --- | --- |
| `filtering.allow_hidden_columns` (`REST_ALLOW_HIDDEN_COLUMNS`) | `false` | `true`: las columnas ocultas vuelven a ser filtrables y ordenables |
| `filtering.strict_column_validation` (`REST_STRICT_COLUMNS`) | `true` | `false`: una columna desconocida llega a la base de datos |
| `filtering.reject_invalid_json` (`REST_REJECT_INVALID_JSON`) | `true` | `false`: el JSON inválido se ignora |
| `filtering.translate_regexp` (`REST_TRANSLATE_REGEXP`) | `true` | `false`: `regexp` se envía tal cual |

Qué revisar: los clientes que filtraban u ordenaban por una columna oculta (a menudo
`created_at`/`updated_at`) o por una columna inexistente o de una tabla unida a mano. La tabla de arriba
(en inglés) detalla cada respuesta que cambia.
