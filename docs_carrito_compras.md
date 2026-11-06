# Modulo de carrito de compras

El carrito queda preparado pero apagado por defecto. Para activarlo, agrega o
cambia esta variable en `.env`:

```env
FEATURE_CARRITO_COMPRAS=true
```

Al activarse aparece el enlace `Carrito` en el menu lateral y queda disponible
`public/carrito.php`. La API del modulo vive en `public/api/carrito.php` y usa la
sesion web del usuario.

## Endpoints

- `GET api/carrito.php`: devuelve el estado actual del carrito.
- `POST api/carrito.php?accion=seleccionar_maquina`: define maquina y limpia los items.
- `POST api/carrito.php?accion=agregar`: agrega producto por `numero_carril`.
- `POST api/carrito.php?accion=actualizar`: cambia cantidad o quita con `cantidad = 0`.
- `POST api/carrito.php?accion=quitar`: elimina un carril.
- `POST api/carrito.php?accion=limpiar`: vacia el carrito.
- `POST api/carrito.php?accion=crear_receta`: convierte el carrito en receta.

## Payloads base

Seleccionar maquina:

```json
{
  "id_maquina": 12
}
```

Agregar producto:

```json
{
  "id_maquina": 12,
  "numero_carril": 5,
  "cantidad": 1
}
```

Actualizar cantidad:

```json
{
  "numero_carril": 5,
  "cantidad": 2
}
```

Crear receta:

```json
{
  "nombre_paciente": "Opcional",
  "medico_solicita": "Opcional"
}
```

Mientras `FEATURE_CARRITO_COMPRAS` no sea `true`, la pantalla y la API responden
como modulo desactivado y el menu no muestra la opcion.

## Endpoints Android para carrito real

Todos usan `Authorization: Bearer {token}`. Si la app usa token por maquina,
manda tambien `id_maquina` y/o `numero_serie` en query o payload.

Catalogo por maquina:

```http
GET /api/android_carrito_catalogo.php?id_maquina=12&numero_serie=ML-001
```

Si la app esta activada en una maestra pero pide catalogo de una esclava, usar:

```http
GET /api/android_carrito_catalogo.php?id_maquina=13&id_maquina_auth=12&numero_serie_auth=ML-001
```

Respuesta:

```json
{
  "ok": true,
  "data": {
    "maquina": {
      "id_maquina": 12,
      "numero_serie": "ML-001",
      "nombre": "Maquina principal"
    },
    "productos": [
      {
        "id_producto": 10,
        "numero_carril": 5,
        "nombre": "Paracetamol",
        "descripcion": "500 mg - caja",
        "precio": 25.5,
        "stock": 8,
        "imagen_url": "https://..."
      }
    ],
    "reglas_stock": {
      "fuente": "carril",
      "reserva_pedidos_pendientes": false,
      "descuento": "al_finalizar_surtido"
    }
  }
}
```

Maquinas disponibles:

```http
GET /api/android_carrito_maquinas.php?id_maquina=12&numero_serie=ML-001
```

Con token por maquina devuelve la unidad activada: maestra y sus esclavas. Con
token global y sin `id_maquina` devuelve la lista real completa.

Crear receta desde carrito:

```http
POST /api/android_carrito_crear_receta.php
```

```json
{
  "id_maquina": 12,
  "numero_serie": "ML-001",
  "id_maquina_auth": 12,
  "numero_serie_auth": "ML-001",
  "request_id": "uuid-opcional-idempotente",
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

`id_maquina` es la maquina destino que tiene el carril. `id_maquina_auth` y
`numero_serie_auth` son opcionales; se usan cuando el token pertenece a la
maquina activada pero el pedido se genera para una esclava de la misma unidad.

El flujo real queda como receta: este endpoint crea `vendingm.recetas` y
`vendingm.detalle_receta`; despues la app usa el flujo actual de surtido de
receta (`android_receta_consultar`, `android_receta_iniciar`,
`android_receta_finalizar`). El descuento de stock sigue ocurriendo al finalizar
cada giro de motor, sin esperar confirmacion de caida y nunca al crear la receta.

## Imagenes y productos

La tabla maestra existente es `vendingm.productos`. El sistema agrega estas
columnas automaticamente cuando se usa la administracion o los endpoints:

- `descripcion`
- `precio`
- `imagen_path`
- `activo`

La administracion esta en `public/productos.php` y permite agregar, editar,
desactivar y cargar imagen PNG/JPG/WEBP. La API Android recibe `imagen_url`; si
no hay imagen, llega `null`.
