# Integracion de la app MOSI

La app deja de usar una llave global. Cada cabina se activa una vez y recibe un token propio `MLMOSI_...`, que debe guardarse en Android Keystore/EncryptedSharedPreferences. El servidor solo conserva su SHA-256.

## Flujo inicial

1. Dar de alta la cabina en **MOSI > Inventario** (`telemedicina.php`).
2. Abrir su ficha y pulsar **Generar activacion**. El codigo vence en 24 horas y funciona una sola vez.
3. La app llama `POST /api/mosi_activar.php`:

```json
{
  "codigo_activacion": "MOSI-ACT-123.secreto",
  "numero_serie": "MOSI-001",
  "device_id": "identificador-estable",
  "android_id": "android-id",
  "package_name": "com.sybiml.mosi",
  "certificado_sha256": "64-caracteres-hex"
}
```

La respuesta entrega `data.token` una sola vez y las rutas/intervalo de sincronizacion. Una reactivacion revoca el token anterior al completarse.

## Autenticacion normal

Enviar en todas las llamadas:

```http
Authorization: Bearer MLMOSI_TOKEN
Content-Type: application/json
```

El `numero_serie` del cuerpo debe coincidir con la cabina asociada al token.

## Telemetria tecnica

`POST /api/mosi_telemetria.php`, recomendado cada 300 segundos y al ocurrir un error importante:

```json
{
  "request_id": "uuid-unico",
  "numero_serie": "MOSI-001",
  "device_id": "identificador-estable",
  "telemetria": {
    "version_code": 42,
    "version_name": "2.6.0",
    "tipo_conexion": "wifi",
    "internet_disponible": true,
    "wifi_conectado": true,
    "ethernet_conectado": false,
    "nombre_red": "Clinica",
    "pendientes_sincronizacion": 3,
    "errores_recientes": 0,
    "ultima_sincronizacion": "2026-10-30T12:00:00-06:00",
    "device_owner": true,
    "sensores": [{"nombre":"bascula","conectado":true,"estado":"ok"}]
  }
}
```

`request_id` hace el envío idempotente. El servidor conserva el historial técnico y actualiza el último estado. No enviar pacientes, nombres, teléfonos, fotografías, mediciones clínicas ni resultados.

## Eventos de telemedicina

`POST /api/mosi_telemedicina_evento.php` registra el ciclo técnico de la consulta
con el mismo token individual. Los eventos permitidos son `inicio`, `fin`,
`cancelacion` y `error`.

```json
{
  "request_id": "uuid-unico",
  "numero_serie": "MOSI-001",
  "sesion_id": "uuid-de-la-sesion-local",
  "evento": "inicio",
  "version_code": 42,
  "version_name": "2.6.0"
}
```

`request_id` evita duplicados. `sesion_id` permite relacionar inicio y fin sin
usar identificadores de pacientes. No enviar `id_usuario_web`, nombres ni datos
clinicos en este endpoint.

## Actualizaciones MOSI

- `POST /api/mosi_actualizacion_consultar.php`: cuerpo con `numero_serie`, `version_code_actual`, `version_name_actual` y `request_id`.
- Descargar `data.actualizacion.apk_url` usando el mismo header `Authorization`.
- Validar el SHA-256 antes de instalar.
- `POST /api/mosi_actualizacion_evento.php`: informar `descarga`, `instalacion` o `error`, incluyendo `id_actualizacion`, versión y `request_id`.

Los APK se publican en **Actualizaciones app > MOSI**. Este canal es independiente del canal de vending/clientes.

## Cambios que debe hacer Mosy.SYBI

- Pantalla de activacion que solicite codigo y serie.
- Sustituir la API key global por el token individual.
- Worker periódico con reintentos y `request_id` persistente.
- Consumir el canal de actualizaciones del servidor en lugar de GitHub Releases.
- Guardar secretos con Android Keystore y retirar llaves/tokens de código, logs y respaldos.
- No enviar la base `quiosco.db` ni información clínica por estos endpoints.
