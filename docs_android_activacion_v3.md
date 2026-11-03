# Activación Android v3

## Objetivo

La activación deja de pedir `id_maquina`. La aplicación identifica el equipo por
`numero_serie`; `id_maquina` continúa existiendo únicamente como llave interna en
el servidor.

Flujo recomendado:

1. Escanear o capturar el número de serie.
2. Consultar la configuración de la máquina.
3. Capturar el nombre visible de la máquina.
4. Buscar y seleccionar una sucursal clínica activa.
5. Obtener coordenadas del dispositivo y solicitar una dirección sugerida.
6. Permitir que el usuario confirme o corrija la dirección.
7. Confirmar la activación y guardar la respuesta localmente.

La activación no solicita correo, contraseña, sesión técnica ni token `TECH_`.
El servidor autoriza el flujo cuando `numero_serie` corresponde a una máquina
previamente registrada y que no está fuera de servicio. Al confirmar, la
respuesta contiene `data.maquina` y `data.credenciales_api`; este último token
`MLVENDING_` es la credencial permanente que la máquina debe guardar localmente.

Una serie ya activada puede ejecutar nuevamente todo el flujo sin autorización
manual desde el panel. Al confirmar la reactivación, el servidor revoca el token
anterior y genera otro en `data.credenciales_api.token`; la app debe reemplazar
inmediatamente la credencial guardada.

El acceso al panel técnico posterior a la activación continúa siendo un flujo
independiente y puede conservar su propia autenticación.

## 1. Consultar máquina por serie

```http
POST /api/android_maquina_config.php
```

```json
{
  "numero_serie": "ML-001"
}
```

No enviar ni solicitar `id_maquina` en la interfaz. La respuesta puede incluirlo
para compatibilidad interna, pero Android debe guardar y mostrar `numero_serie`.

## 2. Buscar sucursal

```http
POST /api/android_sucursales.php
```

```json
{
  "numero_serie": "ML-001",
  "q": "Anahuac"
}
```

Respuesta:

```json
{
  "ok": true,
  "data": [
    {
      "id_sucursal_clinica": 25,
      "nombre": "CLINICA ANAHUAC"
    }
  ],
  "meta": {
    "solo_activas": true,
    "total": 1
  }
}
```

El backend consulta `fernandoruiz.cat_SucursalClinica` y siempre aplica
`i_Activo = 1`. La app debe esperar al menos dos caracteres y aplicar un debounce
de 300 a 400 ms antes de buscar.

## 3. Obtener ubicación sugerida

Usar primero la ubicación del dispositivo Android. La geolocalización por IP es
solo un respaldo aproximado.

```http
POST /api/android_ubicacion_sugerida.php
```

Con GPS:

```json
{
  "numero_serie": "ML-001",
  "latitud": 19.4326,
  "longitud": -99.1332
}
```

Con IP pública como respaldo:

```json
{
  "numero_serie": "ML-001",
  "ip_publica": "203.0.113.10"
}
```

Respuesta:

```json
{
  "ok": true,
  "data": {
    "disponible": true,
    "origen": "gps",
    "precision": "coordenadas_dispositivo",
    "latitud": 19.4326,
    "longitud": -99.1332,
    "direccion_sugerida": "Dirección aproximada devuelta por Google",
    "confirmacion_requerida": true
  }
}
```

La dirección debe mostrarse en un campo editable. Nunca completar la activación
sin confirmación del usuario. Una IP corporativa, VPN o red móvil puede ubicar
otra colonia o incluso otra ciudad.

## 4. Confirmar activación

```http
POST /api/android_maquina_config.php
```

```json
{
  "numero_serie": "ML-001",
  "confirmar_activacion": true,
  "nombre_maquina": "Farmacia acceso principal",
  "id_sucursal_clinica": 25,
  "direccion": "Lago Xochimilco 121, Anáhuac I Secc, Miguel Hidalgo, CDMX",
  "latitud": 19.445,
  "longitud": -99.181
}
```

El servidor valida que la sucursal siga activa y guarda:

- `nombre_maquina`: nombre operativo capturado.
- `id_sucursal_clinica`: identificador de la sucursal seleccionada.
- `nombre_ubicacion`: nombre oficial de la sucursal.
- `direccion`, `latitud`, `longitud`: ubicación confirmada.

## Carriles físicos

Los múltiplos de 10 no existen físicamente. No mostrar ni aceptar los carriles
`10`, `20`, `30`, `40`, `50` o `60`.

La API v3 devuelve:

```json
{
  "numero_carriles": 63,
  "cantidad_carriles": 63,
  "ultimo_numero_carril": 69,
  "numeros_carril": [1, 2, 3, 4, 5, 6, 7, 8, 9, 11],
  "carriles": []
}
```

Reglas para Android:

- Renderizar desde `carriles` o `numeros_carril`.
- No generar posiciones con un ciclo `1..ultimo_numero_carril`.
- Usar `clave_carril` (`id_maquina:numero_carril`) para distinguir principal y esclava.
- Principal: último número 69, 63 posiciones físicas.
- Esclava: último número 58, 53 posiciones físicas.

## Diseño de Escanear, Confirmar y Retirar

Usar un indicador de progreso de tres etapas, no tres tarjetas grandes iguales:

- `Escanear`: icono QR, color activo mientras espera lectura.
- `Confirmar`: icono de verificación, muestra medicamento, cantidad y carril.
- `Retirar`: icono de paquete abierto, enfatiza la puerta/carril que debe usar.

Cada etapa debe tener altura estable, radio máximo de 8 px y estos estados:

- Pendiente: fondo neutro, texto secundario.
- Activa: borde y barra lateral con color primario.
- Completada: icono de verificación y contraste verde.
- Error: mensaje accionable y botón para reintentar.

En móvil usar una sola columna. No cambiar el tamaño del contenedor cuando
cambie el texto o el estado. En `Confirmar`, el comando principal debe incluir
medicamento y cantidad; en `Retirar`, el número de carril debe ser el dato con
mayor jerarquía visual.

## Configuración del servidor

Se requiere:

- Preregistrar el `numero_serie` de cada máquina antes de activarla.
- `ANDROID_API_TOKEN` para las operaciones normales posteriores a la activación;
  no se solicita durante el flujo inicial descrito aquí.
- Google Geocoding API habilitada para convertir coordenadas en dirección.
- `GOOGLE_MAPS_API_KEY` autorizada para Geocoding API desde el servidor.
- `IP_GEOLOCATION_ENDPOINT=https://ipwho.is/{ip}` o un proveedor equivalente.
- Permiso `SELECT` para el usuario SQL sobre
  `fernandoruiz.cat_SucursalClinica`.
- Permiso `ALTER` temporal sobre `vendingm.maquinas` durante el primer despliegue,
  o crear manualmente las columnas `nombre_maquina` e `id_sucursal_clinica`.
