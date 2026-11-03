# API Android Vending

> El flujo nuevo de alta, ubicacion, sucursales y carriles fisicos esta
> documentado en [`docs_android_activacion_v3.md`](docs_android_activacion_v3.md).
> Para activacion, Android debe usar `numero_serie`; `id_maquina` queda como
> identificador interno y compatibilidad con operaciones existentes.

Base URL:

```text
https://www.sybiml.com/php8/adminVending/public/api/
```

Header obligatorio:

```http
Authorization: Bearer {ANDROID_API_TOKEN}
Content-Type: application/json
```

Mientras no existan tokens por maquina, el servidor usa `ANDROID_API_TOKEN` del
archivo `.env`. Cuando se genere un token por maquina, ese token reemplaza al
global para esa maquina.

## Consultar receta

```http
POST /api/android_receta_consultar.php
```

```json
{
  "folio": "UUID_DE_RECETA",
  "id_maquina": 12,
  "numero_serie": "ML-001"
}
```

Devuelve receta e `items[]` con `numero_carril`, `codigo_barras`,
`cantidad_solicitada`, stock del carril, lote, caducidad, `precio` e
`imagen_url` cuando el producto tenga imagen cargada.

## Carrito Android

El carrito real usa la tabla maestra existente `vendingm.productos` y stock por
carril. Las imagenes se administran en el panel web `Productos`.

Catalogo por maquina:

```http
GET /api/android_carrito_catalogo.php?id_maquina=12&numero_serie=ML-001
```

Si la app esta activada en una maestra y consulta una esclava, manda la maquina
destino en `id_maquina` y la activada en `id_maquina_auth`:

```http
GET /api/android_carrito_catalogo.php?id_maquina=13&id_maquina_auth=12&numero_serie_auth=ML-001
```

Listar maquinas disponibles:

```http
GET /api/android_carrito_maquinas.php?id_maquina=12&numero_serie=ML-001
```

Con token por maquina devuelve la unidad activada (maestra + esclavas). Con token
global y sin `id_maquina` devuelve la lista completa.

Crear receta desde carrito:

```http
POST /api/android_carrito_crear_receta.php
```

```json
{
  "id_maquina": 12,
  "numero_serie": "ML-001",
  "request_id": "uuid-unico",
  "items": [
    {
      "id_producto": 10,
      "numero_carril": 5,
      "cantidad": 2
    }
  ],
  "nombre_paciente": "",
  "medico_solicita": ""
}
```

Este endpoint crea una receta pendiente; despues la app usa el flujo actual de
`android_receta_consultar`, `android_receta_iniciar` y
`android_receta_finalizar`. El stock se descuenta al confirmar finalizacion
fisica, no al crear la receta.

## Marcar receta en proceso

```http
POST /api/android_receta_iniciar.php
```

```json
{
  "folio": "UUID_DE_RECETA",
  "id_maquina": 12,
  "numero_serie": "ML-001",
  "request_id": "uuid-unico"
}
```

Si el evento viene de cola offline, `request_id` es obligatorio y debe ser el
mismo en cada reintento.

## Finalizar receta y descontar inventario

```http
POST /api/android_receta_finalizar.php
```

```json
{
  "folio": "UUID_DE_RECETA",
  "id_maquina": 12,
  "numero_serie": "ML-001",
  "request_id": "uuid-unico",
  "items": [
    {
      "numero_carril": 5,
      "cantidad_entregada": 1
    }
  ]
}
```

El inventario se descuenta aqui, despues de que la app confirme caida fisica.
`request_id` evita descuentos duplicados si la app reintenta.

## Reportar error

```http
POST /api/android_error_surtido.php
```

```json
{
  "folio": "UUID_DE_RECETA",
  "id_maquina": 12,
  "numero_serie": "ML-001",
  "numero_carril": 5,
  "codigo_barras": "7501234567890",
  "codigo_error": "MOTOR_TIMEOUT",
  "mensaje": "No se confirmo caida",
  "request_id": "uuid-unico",
  "marcar_receta_fallida": true
}
```

## Configuracion de maquina

Este endpoint se usa en la activacion inicial de una instalacion Android.
La app captura `numero_serie`; no debe pedir `id_maquina`, correo, contraseña ni
sesión de técnico. El servidor valida que la serie esté preregistrada y devuelve
la configuración que debe guardarse localmente. La confirmación completa se
documenta en `docs_android_activacion_v3.md`.

```http
POST /api/android_maquina_config.php
```

```json
{
  "numero_serie": "ML-001"
}
```

Respuesta:

```json
{
  "ok": true,
  "data": {
    "id_maquina": 12,
    "numero_serie": "ML-001",
    "rol_maquina": 1,
    "rol": 1,
    "id_maquina_padre": null,
    "numero_carriles": 69,
    "carriles": [
      {
        "numero_carril": 1,
        "id_producto": 100,
        "cantidad_actual": 8,
        "producto_nombre": "Producto",
        "codigo_barras": "7501234567890",
        "numero_lote": "L-001",
        "fecha_caducidad": "2026-12-31"
      }
    ],
    "parametros": {
      "config_version": 2,
      "fecha_servidor": "2026-08-06T12:00:00-06:00"
    },
    "branding": {
      "activo": true,
      "logo_url": "https://www.sybiml.com/php8/adminVending/public/uploads/branding/logo_x.png",
      "video_url": "https://www.sybiml.com/php8/adminVending/public/uploads/branding/video_x.mp4",
      "colores": {
        "primario": "#2CD9BE",
        "secundario": "#121A21",
        "acento": "#E4AC4F"
      }
    }
  }
}
```

Si `branding` viene como `null`, o si alguna URL/color viene en `null`, la app
debe conservar sus valores por defecto para ese recurso. Este bloque se devuelve
durante la activacion inicial de la maquina; la app puede guardar localmente las
URLs/colores y refrescarlos cada vez que vuelva a ejecutar la activacion o
sincronizacion de configuracion.

## Telemetria

```http
POST /api/android_telemetria.php
```

```json
{
  "id_maquina": 12,
  "numero_serie": "ML-001",
  "request_id": "uuid-unico",
  "app_version": "1.0.0",
  "battery_level": 95,
  "network_type": "wifi",
  "signal_strength": -55
}
```

Si la telemetria viene de cola offline, mandar `request_id` para evitar
duplicados al reintentar.

## Estado del sistema

Endpoint para la pantalla tecnica de estado. Valida API, token e identidad de
maquina. Los contadores offline los calcula la app y los manda al servidor para
que la respuesta regrese una sola foto consolidada.

```http
POST /api/android_estado_sistema.php
```

```json
{
  "id_maquina": 12,
  "numero_serie": "ML-001",
  "app_version": "1.0.0",
  "recetas_pendientes_offline": 0,
  "recetas_iniciadas_pendientes_envio": 0,
  "finalizaciones_pendientes_envio": 0,
  "dispensaciones_pendientes_envio": 0,
  "errores_pendientes_envio": 0,
  "telemetria_pendiente_envio": 0
}
```

Respuesta:

```json
{
  "ok": true,
  "data": {
    "maquina": {
      "id_maquina": 12,
      "numero_serie": "ML-001",
      "rol_maquina": 1,
      "rol": 1,
      "rol_nombre": "principal",
      "numero_carriles": 69
    },
    "conexion": {
      "api_conectada": true,
      "token_valido": true,
      "token_maquina_configurado": false,
      "token_revocado": false,
      "ultima_sincronizacion": "2026-08-06 12:30:00",
      "server_time": "2026-08-06T12:31:00-06:00"
    },
    "operacion": {
      "recetas_pendientes_offline": 0,
      "recetas_iniciadas_pendientes_envio": 0,
      "finalizaciones_pendientes_envio": 0,
      "dispensaciones_pendientes_envio": 0,
      "errores_pendientes_envio": 0,
      "telemetria_pendiente_envio": 0,
      "ultimo_error": null,
      "app_version": "1.0.0",
      "ultima_telemetria": null
    }
  }
}
```

## Cola offline Android

La app debe guardar localmente cada evento antes de ejecutar la accion fisica o
antes de intentar enviarlo. Cada evento necesita un `request_id` estable,
generado una sola vez y reutilizado en todos los reintentos.

Eventos que debe guardar la app:

| Tipo local | Endpoint al sincronizar | Payload minimo |
| --- | --- | --- |
| `receta_iniciada` | `android_receta_iniciar.php` | `folio`, `id_maquina`, `numero_serie`, `request_id` |
| `receta_finalizada` | `android_receta_finalizar.php` | `folio`, `id_maquina`, `numero_serie`, `request_id`, `items[]` |
| `dispensacion` | `android_dispensacion.php` | `id_maquina`, `numero_serie`, `request_id`, `numero_carril` o `codigo_barras`, `cantidad` |
| `error_surtido` | `android_error_surtido.php` | `id_maquina`, `numero_serie`, `request_id`, `codigo_error`, `mensaje` |
| `telemetria` | `android_telemetria.php` | `id_maquina`, `numero_serie`, `request_id`, `app_version` |

Reglas de sincronizacion:

1. Enviar primero `receta_iniciada`.
2. Enviar despues `receta_finalizada` o `error_surtido`, segun el resultado real.
3. Enviar `dispensacion` solo para dispensaciones independientes de receta.
4. Enviar `telemetria` con menor prioridad; si falla, permanece en cola.
5. Borrar de la cola local solo cuando el servidor responda `ok: true`.
6. Si el servidor responde `meta.duplicado: true`, tratarlo como exito y borrar
   el evento local.
7. Si responde `401`, `403`, `404` o `422`, no reintentar en bucle; mostrarlo en
   estado tecnico porque requiere correccion de configuracion o payload.

## Administrar tokens por maquina

Requiere sesion web de administrador.

Listar:

```http
GET /api/android_tokens_maquina.php
```

Crear token nuevo y revocar los anteriores:

```http
POST /api/android_tokens_maquina.php
```

```json
{
  "accion": "crear",
  "id_maquina": 12
}
```

Revocar token activo:

```json
{
  "accion": "revocar",
  "id_maquina": 12
}
```

## Limpiar activacion Android

Para preparar maquinas que se van a activar desde la app nueva, no se deben
borrar registros de `vendingm.maquinas`, carriles, inventario ni recetas. Si
existen tokens Android previos y la app usara el token global durante esta
etapa, limpia solo `vendingm.api_tokens_maquina`.

Vista previa por maquina:

```bash
php scripts/limpiar_activacion_android.php --id-maquina=5
```

Confirmar limpieza por maquina:

```bash
php scripts/limpiar_activacion_android.php --id-maquina=5 --confirmar
```

Vista previa para todas:

```bash
php scripts/limpiar_activacion_android.php --todas
```

Confirmar limpieza para todas:

```bash
php scripts/limpiar_activacion_android.php --todas --confirmar
```
