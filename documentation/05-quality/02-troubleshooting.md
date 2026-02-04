# Troubleshooting

## "Relation 'x' is not allowed"

**Cause**: The relation is not listed in `const RELATIONS` and `filtering.strict_relations` is enabled.

**Fix**: Add the relation to `RELATIONS` on the model or disable strict mode in config (not recommended).

---

## "Maximum nesting depth" / "Maximum conditions" errors

**Cause**: `oper` exceeded `filtering.max_depth` or `filtering.max_conditions`.

**Fix**: Reduce filter complexity or increase limits in `config/rest-generic-class.php`.

---

## "Invalid hierarchy mode" or hierarchy not supported

**Cause**: Invalid `hierarchy.filter_mode` or missing `HIERARCHY_FIELD_ID` on the model.

**Fix**: Use a valid mode (`match_only`, `with_ancestors`, `with_descendants`, `full_branch`, `root_filter`) and define the hierarchy field in the model.

---

## Export methods fail

**Cause**: `exportExcel()` or `exportPdf()` are called without installing optional packages.

**Fix**: Install `maatwebsite/excel` and/or `barryvdh/laravel-dompdf`.

---

## Spatie authorization fails unexpectedly

**Cause**: Permission cache not refreshed or tenant/guard mismatch.

**Fix**: Clear Spatie permission cache and ensure guard/team ID is set before authorization.

[Back to documentation index](../index.md)
