# Medical Center -> vendingm (conexion directa)

La integracion no usa proveedor, URL, token ni API intermedia. Medical Center y
vending-admin-clientes comparten SQL Server; el PHP 5.2 escribe la receta en el
esquema `vendingm` y la app PHP 8/Android la consume desde ahi.

## Instalacion

Ejecutar una sola vez (es idempotente):

```text
scripts/sql_integracion_medicalcenter.sql
```

Luego asignar en `vendingm.maquinas.id_sucursal_clinica` el ID de
`cat_SucursalClinica.id_SucursalClinica`. Debe existir exactamente una maquina
principal por sucursal; los modulos esclavos se relacionan con
`id_maquina_padre`.

## Flujo

1. `medicalcenter2_v2/ajaxVendingNuevo.php` toma la sucursal de la sesion.
2. `medicalcenter2_v2/clases/VendingMedicalCenter.php` busca la maquina asignada.
3. Cada medicamento se cruza primero por `id_producto_almacen_origen`; la primera
   vez se localiza por UPC y se guarda ese ID legado para cruces posteriores.
4. Al generar el QR se crea una receta `origen = medicalcenter`, se asignan los
   carriles y las cantidades quedan reservadas. Las consultas de disponibilidad
   restan esas reservas, por lo que no se puede prometer el mismo stock dos veces.
5. Android confirma la entrega y solo entonces `SalidaRegistrador` descuenta
   `vendingm.carriles`, los lotes FEFO y registra `movimientos_salida`.

El QR contiene el folio numerico de diez digitos (`codigo_receta`). Reimprimir la
misma receta es idempotente y no genera otra reserva. Una receta pendiente dura
48 horas; después deja de apartar existencia y debe reimprimirse para renovarla.

## Aislamiento

El puente exige una sesion `id_sucursalVending` o `id_cabinaMedica`. Las
sucursales sin maquina asignada reciben un error y continúan imprimiendo su receta
normal sin QR, por lo que no se altera la operacion tradicional.
