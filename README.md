# Medical Life - Panel de administracion de vending

Sistema web en PHP para administrar la red de maquinas expendedoras de Medical Life: stock por carril, productos, recetas, reportes, usuarios, mantenimiento, distribucion y comunicacion con la app Android.

## Stack

- PHP 8.2+
- SQL Server 2017+
- PDO con driver `pdo_sqlsrv`
- Microsoft ODBC Driver 17 u 18 para SQL Server
- Composer para instalar dependencias PHP cuando se trabaje localmente o cuando el servidor no tenga `vendor/`
- Frontend con PHP, HTML, CSS y JavaScript nativo

Dependencias declaradas en `composer.json`:

- `phpoffice/phpspreadsheet`: lectura de Excel
- `box/spout`: soporte adicional para archivos tabulares
- `vlucas/phpdotenv`: carga de variables de entorno en scripts que usan Composer

La aplicacion tambien trae autoload y lector `.env` manual en `config/`, por lo que varias pantallas pueden arrancar sin Composer si ya estan todos los archivos necesarios. Para importar Excel, usa Composer o sube la carpeta `vendor/` ya generada.

## Requisitos del servidor

El servidor debe tener:

- PHP 8.2 o superior
- Extensiones PHP habilitadas:
  - `pdo`
  - `sqlsrv`
  - `pdo_sqlsrv`
  - `fileinfo`
  - `mbstring`
- Microsoft ODBC Driver 17 u 18 instalado en el sistema operativo
- Permisos de escritura en:
  - `logs/`
  - `public/uploads/`
- Acceso de red desde PHP hacia SQL Server

Para validar el servidor, abre `public/diagnostico.php` desde el navegador. Despues de usarlo en produccion, elimina ese archivo o bloquea su acceso.

## Configuracion inicial

1. Copia `.env.example` como `.env`.
2. Llena los datos reales de SQL Server y tokens.
3. Instala dependencias si vas a usar importacion Excel o scripts CLI:

```bash
composer install
```

Variables principales:

```env
DB_HOST=127.0.0.1
DB_PORT=1433
DB_NAME=nombre_base
DB_USER=usuario
DB_PASS=contrasena
DB_INSTANCE=
DB_TRUST_SERVER_CERTIFICATE=true

APP_ENV=development
APP_TIMEZONE=America/Mexico_City

ANDROID_API_TOKEN=cambia-este-token
ANDROID_JSON_MAX_BYTES=1048576

FEATURE_CARRITO_COMPRAS=false

GOOGLE_MAPS_API_KEY=
DISTRIBUCION_ORIGEN_NOMBRE=
DISTRIBUCION_ORIGEN_LAT=
DISTRIBUCION_ORIGEN_LNG=
```

Notas:

- `APP_ENV=development` muestra errores con mas detalle. En produccion usa `APP_ENV=production`.
- `ANDROID_API_TOKEN` es el token global para la app Android. Tambien existen tokens por maquina desde el panel/API.
- `FEATURE_CARRITO_COMPRAS=true` activa la pantalla y API web del carrito.
- `GOOGLE_MAPS_API_KEY` es opcional. Si esta vacio, distribucion calcula rutas con coordenadas locales.
- No compartas el `.env` real. Esta ignorado por Git.

## Ejecucion local

Desde la raiz del proyecto:

```bash
composer install
php -S localhost:8000 -t public
```

Abre:

```text
http://localhost:8000
```

El `index.php` redirige a `login.php` si no hay sesion o a `dashboard.php` si el usuario ya esta autenticado.

## Despliegue

El document root del sitio debe apuntar a:

```text
public/
```

Esto evita exponer `.env`, `config/`, `src/`, `scripts/` y `logs/`.

Si el hosting solo permite FTP:

1. Genera `vendor/` localmente con `composer install`.
2. Sube el proyecto completo, incluyendo `vendor/`.
3. Sube un `.env` con valores de produccion.
4. Verifica permisos de `logs/` y `public/uploads/`.
5. Abre `/diagnostico.php` para confirmar extensiones y conexion.
6. Elimina o bloquea `diagnostico.php` al terminar.

## Estructura del proyecto

```text
config/                 Arranque, autoload manual y carga de .env
src/                    Clases de dominio y servicios
src/Api/                Utilidades para API Android
src/Auth/               Sesion, login, permisos y alcance por maquina
src/Database/           Conexion PDO a SQL Server
src/import/             Importacion de planimetria y stock desde Excel
public/                 Document root y pantallas web
public/api/             Endpoints AJAX, administrativos y Android
public/assets/          CSS, JS, imagenes y vendor frontend
public/uploads/         Archivos subidos, por ejemplo imagenes de productos
scripts/                Scripts CLI de mantenimiento/importacion
logs/                   Logs locales, no deben ser publicos
templates/              Vistas o fragmentos reutilizables
```

## Modulos principales

- Dashboard: resumen de salud de la red, alertas, productos con movimiento y maquinas que requieren atencion.
- Maquinas: alta, edicion, baja logica, agrupacion maestra/esclavas y carga de stock desde Excel.
- MOSI / operacion: apartado independiente con seleccion de modulo, llamadas atendidas y perdidas, tendencia diaria, tiempos y rendimiento por profesional (`mosi_operacion.php`).
- Colaboradores SYBI: tabla operativa de medicos, psicologos y nutriologos con estado en vivo, activacion y asignacion multiple de MOSI (`mosi_colaboradores.php`).
- Modulos MOSI: alta y agrupacion de equipos de consulta, vinculacion con vendings y monitoreo de conectividad (`telemedicina.php`).
- Personal clinico: medicos, psicologos y nutriologos, asignacion a MOSI, sincronizacion de Mosy.SYBI, permisos, horarios y acceso limitado por profesional.
- Stock maquinas: consulta de stock por maquina/carril.
- Productos: catalogo, categorias, imagenes y datos comerciales.
- Recetas: creacion, seguimiento, entrega y estados.
- Carrito: modulo opcional controlado por `FEATURE_CARRITO_COMPRAS`.
- Planeacion: operaciones de planeacion sobre maquinas y abastecimiento.
- Distribucion: viajes, prioridad, estados y ruta sugerida.
- Reportes: exportacion/consulta operativa.
- Usuarios: administracion de usuarios y permisos por maquina.
- Mantenimiento: tipos, eventos y operacion de mantenimiento.

## Autenticacion y permisos

La app web usa sesiones PHP.

- Usuarios normales deben iniciar sesion y solo ven maquinas asignadas.
- Usuarios `admin` pueden crear/editar maquinas, usuarios, productos, tokens Android y operaciones administrativas.
- Los endpoints web bajo `public/api/` validan sesion con `Auth::requireLogin()` o `Auth::requireAdmin()`.
- Las peticiones inseguras de API web usan proteccion CSRF cuando aplica.

## API Android

Los endpoints Android viven en:

```text
public/api/android_*.php
```

Usan JSON y, después de la activación, autenticación por header:

```http
Authorization: Bearer {ANDROID_API_TOKEN}
Content-Type: application/json
```

La activación inicial en `android_maquina_config.php`, la búsqueda de sucursal
y la ubicación sugerida no requieren login técnico. Validan el
`numero_serie` previamente registrado; al confirmar generan el token permanente
de la máquina. La misma serie puede reactivarse sin autorización manual; cada
reactivación revoca el token anterior. Consulta `docs_android_activacion_v3.md`
para el flujo completo.

Endpoints relevantes:

- `POST /api/android_maquina_config.php`
- `POST /api/android_estado_sistema.php`
- `POST /api/android_receta_consultar.php`
- `POST /api/android_receta_iniciar.php`
- `POST /api/android_receta_finalizar.php`
- `POST /api/android_dispensacion.php`
- `POST /api/android_error_surtido.php`
- `POST /api/android_telemetria.php`
- `POST /api/mosi_activar.php` (activacion con codigo temporal)
- `POST /api/mosi_telemetria.php` (latido con token individual `MLMOSI_...`)
- `POST /api/mosi_actualizacion_consultar.php` (canal de actualizaciones MOSI)
- `GET /api/android_carrito_catalogo.php`
- `GET /api/android_carrito_maquinas.php`
- `POST /api/android_carrito_crear_receta.php`

Documentacion detallada:

- `docs_android_api.md`
- `docs_mosi_app_api.md`
- `docs_carrito_compras.md`

## Importacion de stock desde Excel

La forma recomendada es desde la pantalla `Maquinas`:

1. Entrar al panel web.
2. Ir a `Maquinas`.
3. Elegir `Cargar stock (Excel)`.
4. Descargar la plantilla vacia y capturar una fila por producto, lote y carril.
5. Usar `Cabina = 1` para la maquina principal y `Cabina = 2` para la esclava.
6. Seleccionar la maquina principal.
7. Subir archivo `.xlsx` o `.xls`.

La plantilla usa este orden de columnas: `Código de barras`, `Clave`,
`Descripción`, `Nombre comercial`, `Sustancia activa`, `Ubicación`, `Cabina`,
`Exhibición`, `Grupo`, `Uso terapéutico`, `Lote`, `Fecha de caducidad`, `Stock`.
Se requiere `Código de barras` o `Clave`; también son obligatorias
`Descripción`, `Ubicación` y `Stock`.

La importacion procesa:

- catalogo de productos
- asignacion de carriles
- lotes
- cantidades
- caducidades
- registros de reabastecimiento

Tambien existe un script CLI:

```bash
php scripts/importar_planimetria.php ruta/al/archivo.xlsx NUMERO_SERIE usuario
```

Antes de usar scripts CLI, confirma que `vendor/` existe y que el `.env` se esta cargando correctamente.

## Base de datos

El proyecto espera tablas bajo el esquema:

```text
vendingm
```

Ejemplos de tablas usadas por el codigo:

- `vendingm.usuarios`
- `vendingm.usuarios_maquinas`
- `vendingm.maquinas`
- `vendingm.productos`
- `vendingm.categorias_producto`
- `vendingm.estatus_receta`
- `vendingm.api_tokens_maquina`
- `vendingm.api_eventos_android`
- `vendingm.distribucion_viajes`
- `vendingm.telemedicina_grupos`
- `vendingm.telemedicina_mosi`
- `vendingm.telemedicina_mosi_maquinas`
- `vendingm.telemedicina_personal`
- `vendingm.telemedicina_personal_mosi`

Algunas tablas auxiliares de Android y distribucion se crean automaticamente si no existen. Aun asi, la base principal de negocio debe existir previamente.

## Troubleshooting

- Error de conexion SQL Server: revisa `DB_HOST`, `DB_PORT`, `DB_INSTANCE`, `DB_NAME`, `DB_USER`, `DB_PASS`, el ODBC Driver y las extensiones `sqlsrv`/`pdo_sqlsrv`.
- Pantalla en blanco: pon `APP_ENV=development` temporalmente y revisa `logs/db_errors.log`.
- Error al importar Excel: ejecuta `composer install` y confirma permisos en `public/uploads/`.
- No aparecen opciones de carrito: activa `FEATURE_CARRITO_COMPRAS=true`.
- Distribucion no usa rutas reales: configura `GOOGLE_MAPS_API_KEY`, origen y coordenadas.
- Android responde token invalido: revisa `ANDROID_API_TOKEN` o los tokens por maquina.
- Acceso directo a carpetas internas: corrige el document root para que apunte a `public/`.

## Archivos que no se deben compartir con credenciales reales

- `.env`
- `logs/*.log`
- contenido de `public/uploads/` si tiene datos sensibles
- respaldos SQL o exports con datos reales

Usa `.env.example` para compartir la plantilla de configuracion.
