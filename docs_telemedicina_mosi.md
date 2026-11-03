# Modulo de telemedicina / MOSI

El modulo permite registrar equipos de telemedicina (MOSI), organizarlos en
grupos y vincular cada MOSI con una o varias maquinas vending. No guarda datos
de pacientes, consultas ni recetas; solo inventario tecnico, estado operativo y
relaciones entre equipos.

## Apartado MOSI y tablero de operacion

La navegacion separa MOSI de **Maquinas** y contiene tres vistas:

- **Operacion y llamadas** (`mosi_operacion.php`): seleccion de MOSI, rango de
  fechas, indicadores, tendencia diaria, actividad por profesional y llamadas
  recientes.
- **Modulos MOSI** (`telemedicina.php`): inventario, conectividad, telemetria y
  vinculacion con vendings.
- **Personal y horarios** (`personal_clinico.php`): asignaciones, permisos y
  horario semanal del personal clinico.
- **Colaboradores SYBI** (`mosi_colaboradores.php`): reemplazo integrado de
  `adminMedicos/index.php?grupo=20`. Consulta directamente los grupos 20
  (medicos), 23 (nutriologos) y 24 (psicologos), muestra el estado en llamada o
  consulta y permite activar/desactivar permisos, abrir/cerrar la recepcion,
  cambiar APP/Kiosco, agregar o quitar MOSI (`st_vendings`) y editar el horario
  semanal laboral/comida. Si una notificacion o consulta queda atascada, la
  accion **Liberar atencion** restablece ambos estados activos del dia. Esta
  vista es exclusiva para administradores.

La API administrativa `api/mosi_colaboradores.php` requiere acceso SQL Server
de lectura y escritura mediante `TELEMEDICINA_DB_*`. No utiliza el endpoint web
publico de SYBI para guardar cambios: todas las escrituras se validan en el
servidor, requieren sesion administrativa y proteccion CSRF.

`api/mosi_operacion.php` consulta el inventario local y agrega datos operativos
de `tbl_notificaciones` mediante `LegacyConnection`. La tabla se descubre por
nombre para admitir tanto el esquema `fernandoruiz` como `dbo`. El alcance se
limita por el personal asignado y, cuando existen, por los identificadores de
vending/dispositivo o sucursal vinculados a la MOSI. Si estos identificadores
no existen, la respuesta marca la precision como `personal` y la interfaz
advierte que las cifras incluyen toda la actividad del personal asignado.

Por privacidad, el tablero no devuelve nombre, expediente ni identificadores
del paciente. El detalle solo contiene fecha, profesional, origen, resultado y
tiempos operativos. El rango maximo permitido es de 366 dias y el detalle se
limita a las 80 llamadas mas recientes.

## Puesta en marcha

1. Abre `telemedicina.php`. La aplicacion crea las tablas de forma defensiva.
2. De manera alternativa, ejecuta `scripts/sql_v8_telemedicina.sql`,
   `scripts/sql_v9_mosi_app.sql` y `scripts/sql_v10_mosi_eventos.sql` una vez.
3. Crea grupos, registra las MOSI y selecciona sus vendings relacionadas.
4. Genera desde la ficha de cada MOSI su codigo de activacion individual.

## Personal clinico

`personal_clinico.php` muestra medicos, psicologos y nutriologos con sus MOSI y
vendings asignadas. Un administrador puede registrar personal, asignar modulos
y crear una cuenta de acceso. Los roles clinicos entran directamente a este
tablero y solo pueden consultar los equipos que tengan asignados.

El boton **Sincronizar sistema anterior** importa
`fernandoruiz.cta_medicos` (con compatibilidad para
`fernandoruiz.cat_Medicos`), `dbo.tbl_permisosVending` y
`fernandoruiz.cat_SucursalClinica`. Los grupos heredados se traducen
asi: 20 medicos, 23 nutriologos y 24 psicologos. Si esas tablas estan en otra
base SQL Server, configura las variables `TELEMEDICINA_DB_*` de `.env.example`.

Desde la ficha de cada profesional, **Permisos y horarios** permite administrar
la plataforma y las vendings de `dbo.tbl_permisosVending`, asi como los turnos
laborales y de comida por dia en `fernandoruiz.tbl_HorariosMedicos`. La
disponibilidad usa `fernandoruiz.tbl_notificaciones` y
`fernandoruiz.tbl_EvCitasUsuariosWeb` para distinguir profesionales en consulta.

## Latido de conectividad

Cada MOSI debe enviar un `POST` al menos cada 5 minutos a
`/api/mosi_telemetria.php` con estos headers:

```http
Authorization: Bearer {TOKEN_INDIVIDUAL_MLMOSI}
Content-Type: application/json
```

Cuerpo de ejemplo:

```json
{
  "numero_serie": "MOSI-0001",
  "telemetria": {
    "tipo_conexion": "Ethernet",
    "ip_local": "192.168.1.20",
    "version_software": "2.4.0",
    "internet_disponible": true,
    "wifi_conectado": false,
    "ethernet_conectado": true,
    "nombre_red": "CLINICA-LAN",
    "pendientes_sincronizacion": 2,
    "errores_recientes": 1,
    "ultima_medicion": "2026-10-13T11:30:00-06:00",
    "actualizacion_disponible": true,
    "version_disponible": "2.5.0",
    "id_cliente": "18",
    "cliente": "Programa Norte",
    "id_sucursal": "42",
    "sucursal": "Monterrey Centro",
    "sensores": [
      {"nombre": "bascula", "conectado": true},
      {"nombre": "oximetro", "conectado": false, "estado": "sin respuesta"}
    ],
    "eventos": [
      {"nivel": "error", "codigo": "SENSOR_TIMEOUT", "mensaje": "El oximetro no respondio"}
    ]
  }
}
```

La cabina tambien puede identificarse mediante `device_id` o `id_cabina`. Se
aceptan aliases como `network_type`, `app_version`, `pending_sync`,
`recent_errors`, `sensors`, `update_available`, `client_id` y `branch_id`.

La pantalla muestra Internet, Wi-Fi, Ethernet, SSID, sensores, pendientes,
errores, version disponible, cliente y sucursal. Los eventos conservan solo
nivel, codigo y mensaje. El servidor descarta valores clinicos incluidos dentro
de `sensores` para evitar que resultados de pacientes terminen en telemetria.

Una MOSI se muestra **En linea** si su ultimo latido tiene menos de 10 minutos.
Despues aparece **Sin conexion**. Si nunca ha reportado, aparece **Nunca
conectada**.

## Datos clinicos y API de pacientes

Las rutas existentes de `Usuario_mosi` para buscar pacientes y consultar
mediciones no se mezclan con este endpoint. Antes de mostrarlas en el panel se
debe colocar la credencial exclusivamente en el servidor, definir permisos por
cliente/sucursal y crear endpoints administrativos paginados. Fotografias y
archivos de ECG deben servirse por rutas protegidas independientes; nunca deben
viajar en el latido de telemetria.
