# Agregaciones por parámetros

La biblioteca admite `count`, `sum`, `avg`, `min` y `max` en los endpoints de listado. Utiliza los métodos nativos de Laravel, incluidos `withAggregate()` y los métodos terminales del constructor Eloquent. No añade SQL raw para construir las agregaciones ni acepta expresiones SQL del cliente.

## Autorizar columnas en el modelo

Los modelos deben implementar el contrato opcional `HasRestAggregates`. Funciona con `BaseModel` y con modelos Eloquent externos; no se requiere cambiar la clase base.

```php
use Ronu\RestGenericClass\Core\Contracts\HasRestAggregates;
use Ronu\RestGenericClass\Core\Models\BaseModel;

class Order extends BaseModel implements HasRestAggregates
{
    public const RELATIONS = ['customer', 'lines'];

    public function getRestAggregateColumns(): array
    {
        return [
            '*' => ['count'],
            'total' => ['count', 'sum', 'avg', 'min', 'max'],
        ];
    }

    // Definir customer() y lines() como relaciones Eloquent habituales.
}
```

Sin el contrato, el modelo no permite agregaciones. `$fillable` y `$hidden` no constituyen una autorización para calcular métricas. Para agregar una relación, su nombre debe estar permitido por `HasRestRelations`/`RELATIONS` en el padre, y la columna y función deben estar autorizadas por el modelo relacionado. La autorización se comprueba antes de consultar la caché.

La declaración de relaciones es obligatoria para las métricas incluso con `filtering.strict_relations=false`: la autodetección histórica no concede permisos de agregación. Una relación no declarada se rechaza con HTTP 400.

## Métricas globales: `aggregate`

Estos parámetros calculan las métricas sobre todos los pedidos pagados que puede leer la consulta:

```json
{
  "oper": {"and": ["status|=|paid"]},
  "aggregate": [
    {"function": "count", "column": "*", "as": "cantidad"},
    {"function": "sum", "column": "total", "as": "importe"},
    {"function": "avg", "column": "total", "as": "promedio"}
  ]
}
```

Ejemplo HTTP con codificación de parámetros a cargo de curl:

```sh
curl --get 'https://example.test/api/orders' \
  --data-urlencode 'oper={"and":["status|=|paid"]}' \
  --data-urlencode 'aggregate=[{"function":"count","column":"*","as":"cantidad"},{"function":"sum","column":"total","as":"importe"}]'
```

También se admiten arrays de parámetros en PHP:

```php
$result = $orderService->list_all([
    'attr' => ['status' => 'paid'],
    'aggregate' => [
        ['function' => 'count', 'column' => '*', 'as' => 'cantidad'],
        ['function' => 'sum', 'column' => 'total', 'as' => 'importe'],
    ],
]);
// ['data' => ['cantidad' => 2, 'importe' => '40.0000']]
```

Con `list_all($params, false)` se devuelve directamente el mapa de métricas. `process_query()` conserva su función de construir un `Builder`: rechaza `aggregate`, que debe ejecutarse a través del listado.

Se reutilizan `attr`/`eq`, `oper` y los filtros por relaciones anidadas. Por ejemplo, un `oper` sobre `orders.lines` filtra los clientes antes de contarlos. Los filtros no multiplican las filas del padre mediante joins añadidos por esta funcionalidad.

No se pueden combinar métricas globales con `with_aggregates`, paginación, carga de relaciones, ordenación, jerarquías ni selección de campos. Se tolera el `select: ["*"]` que añade por defecto el controlador.

## Métricas de cada relación: `with_aggregates`

Para devolver clientes con métricas sobre sus pedidos:

```json
{
  "select": ["id", "name"],
  "with_aggregates": [
    {
      "relation": "orders",
      "function": "count",
      "column": "*",
      "as": "pedidos_pagados",
      "oper": {"and": ["status|=|paid"]}
    },
    {
      "relation": "orders",
      "function": "sum",
      "column": "total",
      "as": "importe_pagado",
      "attr": {"status": "paid"}
    }
  ],
  "orderby": [{"pedidos_pagados": "desc"}, {"id": "asc"}],
  "pagination": {"pageSize": 20, "page": 1}
}
```

Las métricas se añaden como atributos de cada cliente, sin cargar los pedidos y sin una consulta por cliente. La selección se aplica antes de las subconsultas; los alias pueden utilizarse en `orderby`.

Las llamadas PHP directas a `process_query()` y `process_all()` también admiten `with_aggregates` con `eq`/`attr` como arrays o strings JSON. Ambos filtros se combinan; `attr` tiene prioridad cuando repiten un campo, igual que en `list_all()`.

El `oper` principal determina qué clientes aparecen. El `attr`/`oper` de una métrica determina qué pedidos participan en ella. `_nested` conserva su significado para las relaciones cargadas y no copia filtros implícitamente a las métricas. Un cliente sin pedidos coincidentes permanece en el listado salvo que un filtro principal lo excluya.

Se pueden solicitar varias métricas de la misma relación con filtros diferentes. Cada alias debe ser único, de hasta 63 caracteres, y no colisionar con columnas, accessors, relaciones, casts ni alias de `select`. Los alias explícitamente solicitados se hacen visibles en la respuesta, manteniendo oculta cualquier otra columna que el modelo oculte.

La paginación normal cuenta registros principales. La paginación por cursor admite ordenación por columnas originales; no admite ordenar por alias de métricas. Incluir un orden estable y su clave de desempate, como en las consultas habituales con cursor.

## Endpoints que listan los hijos de un padre

Los endpoints gestionados por `RelationReadCoordinator` también admiten ambos parámetros. Conservan la consulta de la relación resuelta para ese padre, sus restricciones de pivote y sus controles de lectura.

Estos endpoints mantienen su sintaxis histórica de filtros con espacios:

```json
{
  "oper": {"and": ["status = paid"]},
  "aggregate": [
    {"function": "sum", "column": "total", "as": "importe"}
  ]
}
```

En sus `with_aggregates`, los filtros propios utilizan también espacios. El listado principal de `BaseService` utiliza `campo|operador|valor`. No intercambiar ambas sintaxis. Los endpoints de relaciones admiten sus listas históricas `and`/`or`; no añaden un nuevo lenguaje de rutas anidadas a ese parser.

## Tipos, nulos y semántica de Laravel

| Función | Tipo público | Sin valores |
| --- | --- | --- |
| `count(*)` | Entero; cuenta filas/asociaciones | `0` |
| `count(columna)` | Entero; cuenta valores no nulos | `0` |
| `sum` | String numérico | `"0"` |
| `avg` | String numérico o `null`; excluye valores nulos | `null` |
| `min` / `max` | Escalar del driver o `null` | `null` |

No se fija una escala decimal ni se convierte a `float` un decimal exacto devuelto como string por el driver. SQLite y las columnas flotantes pueden producir valores aproximados; serializarlos como strings no recupera precisión perdida por la base de datos.

La ordenación ocurre en SQL, antes de normalizar la respuesta. Una suma relacional sin filas es `NULL` en la subconsulta y se devuelve como `"0"`. Su posición al ordenar sigue las reglas nativas del motor: PostgreSQL y MySQL/SQLite no colocan `NULL` igual. No se añade una expresión raw `COALESCE` ni se reordena una página en PHP.

Las métricas conservan la semántica de las relaciones Eloquent declaradas. Por ejemplo, un `hasOne` agregado cuenta las filas que cumplen sus restricciones, no necesariamente una única fila; utilizar claves únicas o las restricciones apropiadas del modelo cuando sean necesarias. `belongsToMany` cuenta asociaciones, sin deduplicación implícita.

Se conservan scopes del modelo consultado, soft delete y condiciones de las relaciones. En relaciones `*Through`, Laravel aplica su tratamiento nativo de borrado lógico del intermediario; no propaga automáticamente todos los scopes personalizados de ese intermediario. Las restricciones adicionales de tenant/acceso deben formar parte de la relación declarada por la aplicación. Esta funcionalidad no elimina scopes ni modifica esas relaciones.

## Coste, límites y caché

- N métricas globales ejecutan N consultas de agregación sobre clones del constructor filtrado. No se hidratan modelos para calcularlas. La validación de columnas puede realizar consultas adicionales de metadatos.
- Las métricas relacionales forman parte del SELECT mediante subconsultas Eloquent. La paginación y las relaciones cargadas explícitamente conservan sus consultas adicionales habituales.
- Varias métricas globales pueden observar escrituras concurrentes entre consultas. El aislamiento depende de la conexión y de la transacción que gestione el consumidor; no se promete una instantánea automática.
- `rest-generic-class.aggregations.max_metrics` limita las métricas por solicitud; su valor inicial es 10. Los filtros principales y de todas las métricas comparten los límites de profundidad y cantidad de condiciones.
- Las métricas globales locales utilizan la caché existente y su versionado al escribir mediante `BaseService`.
- Las métricas que dependen de relaciones, incluidos los filtros relacionales del modo global, omiten la caché. Los coordinadores actuales de mutaciones/pivotes no garantizan invalidación en todas sus rutas. Esta decisión evita devolver totales obsoletos tras attach/detach/sync, cambios en hijos o restauraciones.
- Las escrituras externas al servicio requieren la invalidación que gestione la aplicación, como en los listados existentes.

## Validación y límites de esta entrega

Los errores de especificación devuelven HTTP 400. Se rechazan funciones no permitidas, columnas no autorizadas o inexistentes, alias inválidos, JSON incorrecto y opciones incompatibles. `column` y `as` son identificadores simples; `*` solo se admite para `count`.

Se admiten relaciones declaradas `hasMany`, `hasOne`, `belongsTo`, `belongsToMany`, `hasManyThrough`, `hasOneThrough` y relaciones polimórficas de destino concreto, como `morphMany`. No se admiten relaciones entre conexiones distintas, `morphTo` ni rutas arbitrarias como `orders.lines` en `with_aggregates.relation`. Para un recorrido profundo compatible, declarar una relación Laravel `hasManyThrough` y utilizar su nombre.

`distinct`, `groupby`, `having`, SQL libre, funciones de ventana y expresiones aritméticas quedan fuera del contrato. Las agregaciones se rechazan en detalle, jerarquías y exportaciones. Las solicitudes sin parámetros nuevos conservan su comportamiento anterior.

## Pruebas

```sh
php vendor/bin/phpunit --filter AggregationTest
```

La misma suite puede ejecutarse contra una base dedicada configurando `RGC_AGGREGATE_DRIVER` (`mysql` o `pgsql`), `RGC_AGGREGATE_HOST`, `RGC_AGGREGATE_PORT`, `RGC_AGGREGATE_DATABASE`, `RGC_AGGREGATE_USER` y `RGC_AGGREGATE_PASSWORD`. El nombre de la base debe terminar en `_test`. La suite crea y elimina sus tablas `agg_*`; usar exclusivamente una base de pruebas dedicada.

El workflow de CI añade ambos motores para Laravel 12 y 13, además de las pruebas SQLite de la matriz PHP existente.
