# Troubleshooting

## Errores de relación no permitida
**Síntoma:** `Relation 'x' is not allowed...`
**Causa probable:** `RELATIONS` no incluye la relación solicitada y `strict_relations` está habilitado.
**Solución:** agrega la relación en `RELATIONS` o desactiva `strict_relations` (no recomendado).

## Límite de condiciones o profundidad
**Síntoma:** `Maximum nesting depth (N) exceeded` o `Maximum conditions (N) exceeded`.
**Solución:** reduce el número de filtros o ajusta `filtering.max_depth`/`filtering.max_conditions`.

## Exportaciones fallan
**Síntoma:** errores al ejecutar `exportExcel()`.
**Causa probable:** `ModelExport` no está definido en el paquete.
**Solución:** define un export compatible o implementa tu propio flujo de exportación.

**Síntoma:** errores en `exportPdf()`.
**Causa probable:** falta `barryvdh/laravel-dompdf` o la vista `pdf`.
**Solución:** instala el paquete y crea la vista.

## Errores de DB no claros
**Síntoma:** errores SQL poco legibles.
**Solución:** revisa logs en `storage/logs/rest-generic-class.log` (o en el canal configurado) para contexto extendido.

## Evidence
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::{getRelationsForModel,applyOperTree}
  - Notes: errores de relación y límites de filtros.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::{exportExcel,exportPdf}
  - Notes: dependencias para exportaciones.
- File: src/Core/Providers/RestGenericClassServiceProvider.php
  - Symbol: RestGenericClassServiceProvider::boot()
  - Notes: canal de logging `rest-generic-class`.
- File: src/Core/Helpers/DatabaseErrorParser.php
  - Symbol: DatabaseErrorParser::parse()
  - Notes: parseo de errores SQL para mensajes legibles.
