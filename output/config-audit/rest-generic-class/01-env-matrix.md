# ENV Matrix

| Key | Declared in (files) | Used in (files/callsites) | Suggested action | Risk | Notes |
| --- | --- | --- | --- | --- | --- |
| LOG_LEVEL | config/rest-generic-class.php; docs/Documentation.md; docs/Documentation.es.md; documentation/ronu/rest-generic-class/docs/02-configuration.md; documentation/ronu/rest-generic-class/docs/09-reference/env-vars.md | config/rest-generic-class.php (`env()`); src/Core/Providers/RestGenericClassServiceProvider.php (fallback) | KEEP | Low | Evitar `env()` en runtime; preferir `config()` en ServiceProvider. |
| LOG_QUERY | documentation/ronu/rest-generic-class/docs/02-configuration.md; documentation/ronu/rest-generic-class/docs/09-reference/env-vars.md | src/Core/Controllers/RestController.php (`env()`) | KEEP + CONFIG | Med | Mover a `config()` para compatibilidad con config cache. |
| REST_VALIDATE_COLUMNS | config/rest-generic-class.php; documentation/ronu/rest-generic-class/docs/02-configuration.md; documentation/ronu/rest-generic-class/docs/09-reference/env-vars.md | config/rest-generic-class.php (`env()`) | INVESTIGATE | Med | Config declarada pero no leída en runtime del paquete. |
| REST_STRICT_COLUMNS | config/rest-generic-class.php; documentation/ronu/rest-generic-class/docs/02-configuration.md; documentation/ronu/rest-generic-class/docs/09-reference/env-vars.md | config/rest-generic-class.php (`env()`) | INVESTIGATE | Med | Config declarada pero no leída en runtime del paquete. |
| REST_MAX_DEPTH | docs/Documentation.md; docs/Documentation.es.md | (none) | REMOVE or IMPLEMENT | Low | Documentada pero no existe en config actual. |
| REST_MAX_CONDITIONS | docs/Documentation.md; docs/Documentation.es.md | (none) | REMOVE or IMPLEMENT | Low | Documentada pero no existe en config actual. |
| REST_STRICT_RELATIONS | docs/Documentation.md; docs/Documentation.es.md | (none) | REMOVE or IMPLEMENT | Low | Documentada pero no existe en config actual. |
