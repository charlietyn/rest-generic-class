# Seguridad

## Allowlist de relaciones

El servicio aplica allowlist de relaciones mediante `const RELATIONS`. Cuando `filtering.strict_relations` está habilitado (default), cualquier relación fuera de la allowlist genera un error.

## Límites de filtrado

El motor de filtrado limita la profundidad de `oper` y el total de condiciones con `filtering.max_depth` y `filtering.max_conditions` para proteger la base de datos de consultas abusivas.

## Allowlist de operadores

Solo se aceptan los operadores de la [lista del filtro](../03-usage/00-basic-usage.md#reglas-de-la-consulta-v310); cualquier otro es un 400.

## Validación de columnas

Con `filtering.strict_column_validation` (por defecto `true`) cada columna de `oper`, `attr`/`eq`, `select` y `orderby` se valida contra el listado de la tabla antes de ejecutar nada: una columna desconocida, de otra tabla o una expresión es un 400 que no contiene SQL.

## Columnas ocultas

Lo que el modelo oculta (`$hidden`, timestamps incluidos) tampoco es consultable: un `like` sobre un valor que la respuesta oculta lo reconstruye carácter a carácter (`password|like|$2y$%`). Es un 400 en `oper`, `attr`/`eq` y `orderby`, también a través de relaciones y en los endpoints de relación, y se omite de `select`. La respuesta de `create` se serializa como la de `update`, sin ocultos y con casts. `filtering.allow_hidden_columns=true` restaura el comportamiento anterior a 3.1.

## Permisos

Si usas la integración de Spatie, asegúrate de que el middleware de autorización se ejecute después de resolver tenant/guard. El middleware verifica permisos usando el cache de Spatie, lo que evita consultas repetidas a la DB.

## Uso de variables de entorno

Las variables de entorno solo se referencian en el archivo de configuración, por lo que el paquete es compatible con las buenas prácticas de cacheo de config de Laravel.

[Volver al índice de documentación](../index.md)
