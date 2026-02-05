# Seguridad

## Allowlist de relaciones

El servicio aplica allowlist de relaciones mediante `const RELATIONS`. Cuando `filtering.strict_relations` está habilitado (default), cualquier relación fuera de la allowlist genera un error.

## Límites de filtrado

El motor de filtrado limita la profundidad de `oper` y el total de condiciones con `filtering.max_depth` y `filtering.max_conditions` para proteger la base de datos de consultas abusivas.

## Allowlist de operadores

Solo se aceptan operadores definidos en `filtering.allowed_operators`. Los operadores no soportados lanzan una excepción.

## Validación de columnas

`filtering.validate_columns` y `filtering.strict_column_validation` se usan para validar el uso de columnas antes de aplicar consultas.

## Permisos

Si usas la integración de Spatie, asegúrate de que el middleware de autorización se ejecute después de resolver tenant/guard. El middleware verifica permisos usando el cache de Spatie, lo que evita consultas repetidas a la DB.

## Uso de variables de entorno

Las variables de entorno solo se referencian en el archivo de configuración, por lo que el paquete es compatible con las buenas prácticas de cacheo de config de Laravel.

[Volver al índice de documentación](../index.md)
