# Migración a Laravel 13 explicada para juniors

Esta guía explica qué se cambió en **Rest Generic Class**, qué significa cada
cambio y qué parte fue exigida por Laravel 13, qué parte responde a seguridad y
qué parte es mantenimiento propio del paquete.

> Resultado: la biblioteca no dejó de soportar Laravel 12. Ahora soporta las dos
> ramas: Laravel `12.61.1+` con PHP `8.2+` y Laravel `13.12+` con PHP `8.3+`.
> La API pública del paquete no cambió.

## 1. El mapa mental antes de empezar

En esta migración participan cinco piezas diferentes:

| Pieza | Para qué sirve |
| --- | --- |
| PHP | Ejecuta el código. Laravel 13 necesita PHP 8.3 o superior. |
| Laravel | Es el framework dentro del cual funciona la biblioteca. |
| Composer | Decide qué versiones compatibles instala y crea el autoload. |
| PHPUnit | Ejecuta las pruebas automáticas. Solo es una dependencia de desarrollo. |
| Orchestra Testbench | Arranca una aplicación Laravel mínima dentro de los tests de un paquete. |

Una **aplicación** Laravel es el proyecto final del usuario. Esta
**biblioteca** es una dependencia que se instala dentro de esa aplicación. Por
eso no se migró como si fuese una aplicación nueva: no tiene que copiar el
esqueleto de Laravel 13, sino declarar compatibilidad, revisar las APIs de
framework que consume y probarse dentro de Laravel real.

### Dependencias de ejecución y dependencias de desarrollo

- `require` contiene lo necesario cuando una aplicación utiliza el paquete.
- `require-dev` contiene herramientas para desarrollar y probar el paquete.
- PHPUnit y Testbench no se instalan como requisitos obligatorios de producción
  cuando otra aplicación instala la biblioteca con `--no-dev`.

## 2. Resumen exacto de los cambios

| Área | Antes | Ahora | Motivo |
| --- | --- | --- | --- |
| PHP | `^8.0` | `^8.2` | Laravel 12 ya necesita PHP 8.2 y Laravel 13 necesita PHP 8.3. |
| Laravel | `^12.51` | `^12.61.1 || ^13.12.0` | Soportar Laravel 12 y 13 desde versiones parcheadas. |
| Componentes Illuminate | Siete requisitos directos, además de Framework | Solo `laravel/framework` | Framework ya reemplaza esos subpaquetes; declararlos dos veces era redundante. |
| Tipo Composer | `vcs` | `library` | Es una biblioteca instalable en `vendor`, no un tipo con instalador especial. |
| Versión en `composer.json` | `2.3.3` escrita a mano | Campo eliminado | Composer/Packagist obtiene la versión del tag Git. |
| PHPUnit | `^9.6` | `^11.5.50 || ^12.5.12` | Probar con runtimes actuales y compatibles con la matriz. |
| Testbench | No existía | `^10.9 || ^11.0` | Probar el service provider dentro de Laravel 12 y 13 reales. |
| Esquema PHPUnit | 9.6 | 12.5 | Validar el archivo XML con el formato actual. |
| Caché | `Cache::remember()` almacenaba cualquier resultado | Lectura/escritura explícita con comprobación de objetos | Respetar el nuevo valor seguro de Laravel 13. |
| Namespace de caché | `rgc:v1` | `rgc:v2` | No reutilizar datos generados con la política anterior. |
| CI | Sin matriz Laravel 13 | Laravel 12/13 y PHP 8.2–8.5 | Detectar regresiones en cada combinación soportada. |
| Documentación | Centraba los requisitos en Laravel 12 | Matriz, instalación, caché y pruebas 12/13 | Evitar que el código diga una cosa y la guía otra. |

## 3. Cómo leer las versiones de Composer

La restricción principal es:

```json
"php": "^8.2",
"laravel/framework": "^12.61.1 || ^13.12.0"
```

El símbolo `^` permite actualizaciones compatibles dentro de la misma versión
mayor:

- `^12.61.1` significa desde `12.61.1` hasta antes de `13.0.0`.
- `^13.12.0` significa desde `13.12.0` hasta antes de `14.0.0`.
- `||` significa **o**. Composer puede elegir la rama 12 o la rama 13.

El paquete declara PHP `^8.2` porque todavía admite Laravel 12 sobre PHP 8.2.
Esto no permite ejecutar Laravel 13 en PHP 8.2: el propio
`laravel/framework:^13` exige PHP 8.3, así que Composer combina ambas reglas y
rechaza esa pareja imposible.

Ejemplos:

| PHP | Laravel solicitado | Resultado |
| --- | --- | --- |
| 8.2 | 12.61.1 | Compatible |
| 8.2 | 13.12.0 | Incompatible: Laravel 13 exige PHP 8.3 |
| 8.3 | 12.61.1 | Compatible |
| 8.3 | 13.12.0 | Compatible |

### ¿Por qué no se dejó `php: ^8.0`?

Porque esa declaración prometía combinaciones que Composer no podía resolver
con Laravel 12. La restricción raíz de la biblioteca debe comunicar el mínimo
real de la matriz soportada, que ahora es PHP 8.2.

## 4. Qué cambió Laravel 13 y por qué

### 4.1 PHP 8.3 es el nuevo mínimo

La guía oficial de Laravel 13 exige PHP 8.3. Eso es un requisito del framework,
no una decisión de esta biblioteca.

Laravel publica una versión mayor aproximadamente cada año y reserva las
versiones mayores para cambios que pueden romper compatibilidad. Su política
también intenta que esas rupturas sean pequeñas. Laravel no da en la guía de
actualización una explicación única y literal de por qué elevó PHP a 8.3. Es
razonable inferir que acompaña el ciclo de soporte de PHP y permite mantener el
framework sobre un runtime moderno, pero esa última frase es una explicación
técnica, no una cita oficial.

Consecuencia práctica: primero hay que actualizar el PHP de la aplicación; no
basta con cambiar `composer.json`.

### 4.2 Laravel endureció la deserialización de la caché

Este es el cambio de runtime que sí afectaba directamente al paquete.
Aplicaciones nuevas de Laravel 13 configuran:

```php
'serializable_classes' => false,
```

Laravel explica que el objetivo es reducir ataques basados en cadenas de
objetos, conocidos como *deserialization gadget-chain attacks*, incluso en un
escenario donde la `APP_KEY` haya quedado expuesta.

#### ¿Qué significa serializar?

Un store como Redis, base de datos o archivo necesita convertir un objeto PHP
en datos que pueda guardar. Esa conversión se llama **serialización**. Cuando se
lee la entrada, PHP intenta reconstruir el objeto; eso se llama
**deserialización**.

Reconstruir objetos no es equivalente a leer un JSON simple. Las clases pueden
tener comportamiento especial durante la deserialización. Si un atacante logra
controlar el contenido serializado y hay clases aprovechables en el proyecto,
podría encadenar efectos no deseados. Por eso Laravel 13 prefiere no reconstruir
objetos por defecto.

Además, cuando PHP no tiene permiso o capacidad para reconstruir una clase,
puede producir un objeto `__PHP_Incomplete_Class`. Devolver eso en lugar de un
`LengthAwarePaginator`, un `CursorPaginator` o un modelo rompería el contrato
del servicio de una forma difícil de diagnosticar.

#### ¿Por qué esta biblioteca estaba afectada?

Los resultados REST no siempre son arrays. La paginación de Laravel devuelve
objetos que contienen colecciones y modelos Eloquent. El código anterior usaba:

```php
return $store->remember($key, $ttl, $callback);
```

`remember` busca la clave y, si no existe, ejecuta el callback y guarda su
resultado. Ese atajo no permitía decidir después de ejecutar el callback si el
valor era un array sencillo o un grafo de objetos.

#### Nuevo flujo de caché

Ahora `CacheCoordinator` hace lo siguiente:

```text
construir clave
    ↓
¿existe la entrada?
    ├─ sí → leerla
    │       ├─ es compatible → devolverla
    │       └─ no es compatible → olvidarla y recalcular
    └─ no → calcular el resultado
                ├─ se puede almacenar → guardar con TTL
                └─ contiene objetos prohibidos → devolver sin guardar
```

La comprobación es recursiva. No solo detecta un objeto en la raíz; también
detecta objetos dentro de arrays anidados:

```php
[
    'data' => [
        (object) ['id' => 1], // también provoca el bypass seguro
    ],
]
```

Con `cache.serializable_classes=false`:

- arrays, strings, números, booleanos y `null` pueden seguir guardándose;
- un objeto o un array que contenga objetos se devuelve correctamente, pero no
  se persiste;
- una entrada antigua con objetos se elimina al detectarse;
- el endpoint sigue funcionando, aunque esa consulta concreta pierda el ahorro
  de caché.

Esta decisión prioriza corrección y seguridad. No se convierte el paginador a
array automáticamente porque eso cambiaría el tipo público que recibe el código
consumidor.

#### ¿Por qué no pusimos `serializable_classes=true`?

Porque sería desactivar globalmente la protección que Laravel 13 añadió. Una
biblioteca no debe reducir silenciosamente la seguridad de toda la aplicación
host.

Si la aplicación necesita cachear objetos y conoce exactamente sus clases,
puede crear una lista limitada en su propio `config/cache.php`:

```php
'serializable_classes' => [
    Illuminate\Pagination\LengthAwarePaginator::class,
    Illuminate\Pagination\CursorPaginator::class,
    Illuminate\Database\Eloquent\Collection::class,
    App\Models\Product::class,
    App\Models\Category::class,
],
```

La decisión queda así en manos de la aplicación, que es la única que conoce sus
modelos y relaciones. Hay que incluir todas las clases que puedan formar parte
del resultado, no solo el paginador exterior.

### 4.3 Las claves pasaron de `rgc:v1` a `rgc:v2`

El prefijo es un namespace lógico. Por ejemplo, dos claves iguales salvo por su
versión se consideran entradas totalmente distintas:

```text
rgc:v1:...
rgc:v2:...
```

No es una versión de Laravel ni una versión publicada del paquete. Es la versión
del formato y de la política de nuestras claves. Se elevó porque una entrada
`v1` podía contener objetos guardados antes del nuevo control.

Las entradas `v1` no se borran de forma destructiva durante la instalación:
quedan sin uso hasta que venza su TTL. Esto evita reutilizar datos incompatibles
y también evita que la biblioteca vacíe una caché compartida de la aplicación.

### 4.4 Otros cambios de Laravel 13 revisados

La guía oficial contiene más rupturas. Se buscó cada API afectada en `src`,
tests, rutas, configuración y ejemplos. No encontrar una API significa que no
se debe modificar código por precaución: un refactor innecesario también puede
introducir errores.

| Cambio de Laravel 13 | Qué significa | Resultado en este paquete |
| --- | --- | --- |
| Nuevo método `touch` en contratos de caché | Los stores personalizados deben implementar el contrato actualizado. | No implementamos un store; consumimos Laravel Cache. |
| Ajuste de `Container::call` con parámetros nullable | Cambia cómo el contenedor resuelve ciertos callbacks. | No se usa `Container::call`. |
| `upsert` no admite `uniqueBy` vacío | Una escritura masiva debe indicar su clave única. | No existen llamadas `upsert`. |
| Cambios en `DELETE` con `JOIN`, `ORDER` o `LIMIT` | Algunas combinaciones SQL cambian, especialmente en MySQL. | Los deletes del paquete no construyen esa combinación. |
| Modelos instanciados durante `boot` | Laravel 13 es más estricto con inicialización/boot de modelos. | El provider no instancia modelos durante su arranque. |
| Cambios en pivots polimórficos | Puede afectar clases `MorphPivot` personalizadas. | No hay un `MorphPivot` personalizado. |
| Serialización de colecciones Eloquent en colas | Afecta payloads de jobs y restauración de modelos. | El paquete no encola esas colecciones; caché se trató aparte. |
| Firmas del cliente HTTP | Afecta subclases o implementaciones propias de response. | No extendemos responses del cliente HTTP. |
| Eventos de colas/notificaciones | Algunos listeners pueden necesitar adaptarse. | No se escuchan esos eventos. |
| Precedencia de rutas por dominio | Puede cambiar qué ruta coincide primero. | Las rutas opcionales del paquete no declaran dominios. |
| Configuración de sesión | Cambia serialización/config de la sesión. | El paquete no publica ni modifica la sesión. |
| Cambios del scheduler | Afectan el momento de ejecución de tareas. | No se registran tareas programadas. |
| Renombre relacionado con protección CSRF | Código que referencia el nombre anterior debe actualizarse. | No hay referencias a esos aliases. |
| Helpers globales `array_first` y `array_last` | Pueden colisionar con helpers definidos por una app. | El paquete no los define ni usa. |
| Retirada de vistas Bootstrap 3 de paginación | Nombres internos antiguos dejan de existir. | No se referencian esas vistas. |

La matriz técnica completa está en el
[plan y auditoría de migración](../../../docs/laravel-13-migration-plan.md).

## 5. Por qué los mínimos son 12.61.1 y 13.12.0

La guía general permitiría declarar Laravel 13 desde `^13.0`, pero durante la
prueba de versiones mínimas se ejecutó `composer audit`. El auditor detectó una
vulnerabilidad en versiones anteriores de las ramas 12 y 13 relacionada con la
interpretación de la ruta en URLs firmadas temporales.

Una URL firmada contiene una firma que permite a Laravel comprobar que ciertos
datos no fueron modificados. En las versiones afectadas, una ambigüedad de ruta
podía permitir que una firma válida se interpretase para un recurso distinto o
que se eludiese la expiración en determinados flujos. Los parches oficiales
están en Laravel `12.61.1` y `13.12.0`.

Por eso los mínimos no son arbitrarios:

- `12.61.1` es el primer mínimo de Laravel 12 que esta migración certifica sin
  ese aviso;
- `13.12.0` es el primer mínimo de Laravel 13 que esta migración certifica sin
  ese aviso;
- CI vuelve a ejecutar `composer audit` para que una vulnerabilidad conocida no
  pase desapercibida.

Esto no significa que Laravel 13.0 no pudiera ejecutar parte del código. Significa
que el paquete no promete soporte para una combinación con una vulnerabilidad
conocida y ya corregida.

## 6. Limpieza del `composer.json`

### Se quitaron los requisitos Illuminate duplicados

Antes se exigían `illuminate/database`, `illuminate/http`, `illuminate/mail`,
`illuminate/pagination`, `illuminate/support` e `illuminate/validation`, además
de `laravel/framework`.

El paquete completo `laravel/framework` declara que reemplaza esos componentes.
Mantener ambos grupos obliga a Composer a resolver restricciones duplicadas y
hace más fácil que sus versiones se desalineen. Como esta biblioteca ya depende
del framework completo, una sola restricción expresa mejor la realidad.

Esto es limpieza de metadata propia, no una ruptura exigida por Laravel 13.

### `type: library` en lugar de `type: vcs`

Composer usa `type` para decidir si hace falta una lógica especial de
instalación. `library` es el tipo normal que se copia dentro de `vendor`.
`vcs` describe una clase de repositorio en otras partes de Composer, pero no era
el tipo correcto para este paquete.

Este cambio tampoco modifica clases PHP ni la forma de usar la biblioteca.

### Se eliminó `version: 2.3.3`

En un paquete publicado desde Git, Composer y Packagist pueden obtener la versión
del tag, por ejemplo `v2.3.3`. Escribirla además dentro de `composer.json` crea
dos fuentes de verdad: alguien podría crear el tag `v2.4.0` y olvidar actualizar
el campo interno. La documentación oficial de Composer recomienda omitirlo en
este caso.

### Por qué la biblioteca no publica `composer.lock`

Una aplicación sí suele versionar su lock porque necesita instalaciones
repetibles. Una biblioteca debe probar los rangos que declara y dejar que la
aplicación consumidora resuelva su combinación final. El lock local se usó para
las pruebas, pero no forma parte del paquete publicado.

## 7. Qué se cambió en las pruebas

### PHPUnit 11 y 12

PHPUnit 9 era demasiado antiguo para representar bien la nueva matriz. Se
admiten dos generaciones porque Laravel 12 y Laravel 13 se verifican con sus
herramientas compatibles. También se actualizó el esquema XML de
`phpunit.xml.dist`; ese esquema valida nombres de opciones y estructura, no
cambia el código productivo.

### Orchestra Testbench

Un test unitario puede instanciar una clase aislada y aun así no detectar que el
service provider falla al arrancar Laravel. Testbench crea una aplicación mínima
y permite probar el paquete como lo verá una aplicación real.

La correspondencia oficial es:

| Laravel | Testbench |
| --- | --- |
| 12.x | 10.x |
| 13.x | 11.x |

La nueva prueba de integración comprueba que:

- Laravel puede descubrir y arrancar el service provider;
- la configuración del paquete se fusiona;
- los singletons públicos se registran y resuelven desde el contenedor.

### Casos nuevos de caché

Se añadieron pruebas para demostrar que:

1. un objeto anidado no se almacena cuando Laravel deshabilita la
   deserialización de objetos;
2. el callback vuelve a ejecutarse porque no hubo persistencia;
3. una allowlist explícita permite el camino de caché;
4. si después se vuelve a `false`, una entrada de objeto ya existente se olvida
   y se recalcula.

### Matriz de integración continua

El workflow ejecuta los extremos y combinaciones relevantes:

| PHP | Laravel | Testbench | PHPUnit | Propósito |
| --- | --- | --- | --- | --- |
| 8.2 | 12.61.1 | 10.x | 11.x | Mínimo seguro soportado |
| 8.3 | última 12.x | 10.x | 11/12 | Regresión de Laravel 12 |
| 8.3 | 13.12.0 | 11.x | 12.x | Mínimo seguro de Laravel 13 |
| 8.4 | última 13.x | 11.x | 12.x | Runtime moderno |
| 8.5 | última 13.x | 11.x | 12.x | Runtime más reciente de la matriz |

Cada fila valida Composer, resuelve las dependencias, ejecuta la auditoría de
seguridad y corre la suite. Una sola instalación local no demostraría todo ese
rango.

## 8. Qué no cambió

La migración conserva el contrato público:

- no cambia las URLs ni registra rutas nuevas;
- no cambia el formato intencional de las respuestas REST;
- no cambia los métodos públicos CRUD;
- no cambia el sistema de filtros, relaciones, jerarquías o permisos;
- no añade migraciones de base de datos;
- no obliga a publicar de nuevo la configuración del paquete;
- no convierte paginadores a arrays;
- no elimina el soporte de Laravel 12.

La mayor parte del código no necesitó refactor porque la auditoría confirmó que
no usaba las APIs rotas de Laravel 13. **Migrar bien no significa cambiar todos
los archivos; significa encontrar los puntos afectados, modificar solo esos
puntos y demostrar compatibilidad con pruebas.**

## 9. Cómo actualizar una aplicación consumidora

### Paso 1: crea una rama y comprueba el estado actual

```bash
git switch -c chore/upgrade-laravel-13
php -v
composer show laravel/framework
composer audit
```

### Paso 2: instala PHP 8.3 o superior

Laravel 13 no se resolverá con PHP 8.2. Confirma también que el PHP usado por la
terminal sea el correcto; puede ser distinto del PHP configurado en Apache,
Nginx o el IDE.

### Paso 3: actualiza Laravel y la biblioteca juntos

```bash
composer require laravel/framework:^13.12 ronu/rest-generic-class --with-all-dependencies
```

`--with-all-dependencies` permite que Composer actualice dependencias
relacionadas que, de otro modo, podrían quedar bloqueadas por el lock anterior.

### Paso 4: limpia la configuración compilada

```bash
php artisan optimize:clear
```

Esto evita que Laravel siga usando una configuración compilada antes del cambio.

### Paso 5: revisa la política de caché

Si `REST_CACHE_ENABLED=false`, no hace falta una allowlist para esta biblioteca.
Si la caché está activa:

1. deja `cache.serializable_classes=false` si no necesitas cachear paginadores;
2. mide el rendimiento antes de abrir excepciones;
3. si realmente necesitas objetos cacheados, enumera únicamente las clases
   necesarias;
4. vuelve a ejecutar `php artisan optimize:clear`.

No copies de nuevo la configuración publicada con `--force` sin comparar antes:
podrías sobrescribir valores propios de tu aplicación.

### Paso 6: ejecuta las pruebas de la aplicación

```bash
composer audit
php artisan test
```

Prueba especialmente endpoints con paginación, relaciones, permisos, filtros y
caché. La suite del paquete demuestra su contrato, pero no puede conocer las
personalizaciones de cada aplicación.

## 10. Preguntas frecuentes

### ¿Laravel 13 obligó a reescribir toda la biblioteca?

No. Obligó a elevar PHP, revisar dependencias y auditar cambios incompatibles.
Solo la nueva política de objetos serializados tocó un camino real del código.

### ¿Por qué soportar Laravel 12 y 13 a la vez?

Porque las aplicaciones no migran todas el mismo día. El constraint con `||`
permite una transición gradual sin mantener dos copias de la biblioteca.

### ¿Que un paginador no se guarde en caché es un error?

No. Con la política segura es un *cache miss* deliberado. La respuesta se calcula
y devuelve con su tipo correcto; solo se evita persistirla.

### ¿La caché de arrays sigue funcionando?

Sí. El bloqueo se aplica cuando el valor contiene al menos un objeto.

### ¿Debo activar una allowlist siempre?

No. Solo si la aplicación necesita persistir objetos PHP y acepta mantener una
lista completa y controlada de clases.

### ¿Puedo usar `serializable_classes=true`?

Laravel lo permite como decisión explícita de la aplicación, pero abre la
deserialización a cualquier clase. Esta guía recomienda una allowlist mínima.

### ¿Tengo que borrar toda la caché al desplegar?

No por obligación del paquete. `rgc:v2` deja de consultar las claves `v1`. Puedes
purgarlas operativamente si necesitas recuperar espacio, teniendo cuidado si el
store es compartido.

### ¿Por qué no se cambia `cache.serializable_classes` desde el service provider?

Porque pertenece a la política de seguridad global de la aplicación host. El
paquete la respeta, pero no debe sobrescribirla.

## 11. Glosario corto

| Término | Explicación sencilla |
| --- | --- |
| API pública | Clases, métodos y formatos que otra aplicación puede usar. |
| Breaking change | Cambio que puede hacer que código anterior deje de funcionar. |
| Constraint | Regla de versiones que Composer debe satisfacer. |
| Dependency solver | Parte de Composer que encuentra una combinación compatible. |
| Allowlist | Lista explícita de elementos permitidos; todo lo demás queda fuera. |
| Serialización | Conversión de un valor PHP a datos almacenables. |
| Deserialización | Reconstrucción del valor PHP desde esos datos. |
| TTL | Tiempo durante el cual una entrada permanece válida en caché. |
| Namespace de caché | Prefijo que separa familias o versiones de claves. |
| Service provider | Clase que registra y arranca servicios de un paquete en Laravel. |
| Test de integración | Prueba de varias piezas trabajando juntas, no de una sola clase aislada. |
| CI | Automatización que ejecuta validaciones y tests en cada cambio. |

## 12. Fuentes primarias

- [Guía oficial de actualización a Laravel 13](https://laravel.com/docs/13.x/upgrade)
- [Notas de versión y política de soporte de Laravel](https://laravel.com/docs/13.x/releases)
- [Desarrollo de paquetes en Laravel](https://laravel.com/docs/13.x/packages)
- [Advisory de URLs firmadas y versiones corregidas](https://github.com/laravel/framework/security/advisories/GHSA-crmm-hgp2-wgrp)
- [Esquema oficial de `composer.json`](https://getcomposer.org/doc/04-schema.md)
- [Versiones y constraints de Composer](https://getcomposer.org/doc/articles/versions.md)
- [Compatibilidad oficial de Orchestra Testbench](https://github.com/orchestral/testbench#version-compatibility)

**Siguiente:** [Inicio rápido](02-quickstart.md)

[Volver al índice de documentación](../index.md)
