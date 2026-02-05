# Solución de problemas

## "Relation 'x' is not allowed"

**Causa**: La relación no está en `const RELATIONS` y `filtering.strict_relations` está habilitado.

**Solución**: Agrega la relación a `RELATIONS` en el modelo o desactiva el modo estricto en config (no recomendado).

---

## Errores de "Maximum nesting depth" / "Maximum conditions"

**Causa**: `oper` excede `filtering.max_depth` o `filtering.max_conditions`.

**Solución**: Reduce la complejidad del filtro o aumenta límites en `config/rest-generic-class.php`.

---

## "Invalid hierarchy mode" o jerarquía no soportada

**Causa**: `hierarchy.filter_mode` inválido o falta `HIERARCHY_FIELD_ID` en el modelo.

**Solución**: Usa un modo válido (`match_only`, `with_ancestors`, `with_descendants`, `full_branch`, `root_filter`) y define el campo jerárquico en el modelo.

---

## Fallan los métodos de exportación

**Causa**: `exportExcel()` o `exportPdf()` se llaman sin instalar paquetes opcionales.

**Solución**: Instala `maatwebsite/excel` y/o `barryvdh/laravel-dompdf`.

---

## La autorización Spatie falla inesperadamente

**Causa**: Cache de permisos sin refrescar o desajuste de tenant/guard.

**Solución**: Limpia el cache de permisos de Spatie y asegura que el guard/team ID esté definido antes de la autorización.

[Volver al índice de documentación](../index.md)
