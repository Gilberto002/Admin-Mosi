<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filtros de búsqueda</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <link rel="stylesheet" href="css/estilos.css">
</head>

<body>

    <div class="container mt-4">
        <div class="filter-card shadow-sm">

            <div class="d-flex align-items-center mb-3">
                <span class="filter-title">Filtros de búsqueda</span>
            </div>

            <div class="row g-3">

                <!-- Sucursal -->
                <div class="col-md-4">
                    <label class="field-label">Sucursal</label>
                    <select id="selectSucursal" class="form-select select2">
                        <option value="">Seleccionar sucursal…</option>
                        <option value="1">Norte</option>
                        <option value="2">Sur</option>
                        <option value="3">Centro</option>
                    </select>
                </div>

                <!-- Médico -->
                <div class="col-md-4">
                    <label class="field-label">Médico</label>
                    <select id="selectMedico" class="form-select select2">
                        <option value="">Seleccionar médico…</option>
                        <option value="1">Dr. Carlos Mendoza</option>
                        <option value="2">Dra. Laura Vega</option>
                    </select>
                </div>

                <!-- Paciente -->
                <div class="col-md-4">
                    <label class="field-label">Paciente</label>
                    <select id="selectPaciente" class="form-select select2">
                        <option value="">Seleccionar primero un médico…</option>
                    </select>
                </div>

            </div>
            <div class="col-md-6">
                <label class="field-label">Paciente</label>

                <div class="input-group mb-2">

                    <button
                        class="btn btn-secondary btnLimpiarBusqueda"
                        type="button">
                        Limpiar
                    </button>
                    <select class="form-select" id="tipoBusquedaPaciente" style="max-width: 160px;">
                        <option value="tarjeta">Tarjeta</option>
                        <option value="id_paciente">ID Paciente</option>
                        <option value="nombre">Nombre</option>
                    </select>

                    <input
                        type="text"
                        class="form-control"
                        placeholder="Buscar Paciente"
                        id="txtBusquedaPaciente">

                    <button
                        class="btn btn-primary"
                        type="button"
                        id="buscarPacienteSiby">
                        Buscar
                    </button>

                </div>

                <!-- Campos para búsqueda por nombre -->
                <div class="row d-none" id="contenedorNombrePaciente">

                    <div class="col-md-4">
                        <input
                            type="text"
                            class="form-control"
                            placeholder="Nombre"
                            id="nombrePaciente">
                    </div>

                    <div class="col-md-4">
                        <input
                            type="text"
                            class="form-control"
                            placeholder="Apellido Paterno"
                            id="apellidoPaternoPaciente">
                    </div>

                    <div class="col-md-4">
                        <input
                            type="text"
                            class="form-control"
                            placeholder="Apellido Materno"
                            id="apellidoMaternoPaciente">
                    </div>

                </div>
            </div>

            <div class="col-md-12 mt-3 d-none" id="contenedorTablaBusqueda">
                <table id="tablaUsuariosSybi" class="table table-striped table-bordered w-100">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Tarjeta</th>
                            <th>Nombre Completo</th>
                            <th>Parentesco</th>
                            <th>Usuario Activo</th>
                            <th>Tarjeta Activa</th>
                            <th class="text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                <button class="btn btn-dark" id="emuVideo">Emular Videollamada</button>
            </div>

        </div>

        <div class="container mt-4">

            <!-- FILTROS -->
            <div class="card p-3 mb-4 shadow-sm">
                <div class="row g-3">

                    <div class="col-md-3">
                        <label class="form-label">Fecha inicio</label>
                        <input type="date" id="fechaInicio" class="form-control">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Fecha fin</label>
                        <input type="date" id="fechaFin" class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Sucursal</label>
                        <select id="filtroSucursal" class="form-select select2">
                            <option value="">Todas</option>
                        </select>
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-dark w-100" id="btnBuscarRegistros">
                            Buscar
                        </button>
                    </div>

                </div>
            </div>

            <!-- TABLA -->
            <div class="card p-3 shadow-sm">
                <table id="tablaReporte" class="table table-striped w-100">
                    <thead>
                        <tr>
                            <th>Tarjeta</th>
                            <th>Nombre del Paciente</th>
                            <th>Nombre de la Sucursal</th>
                            <th>Nombre del Médico</th>
                            <th>Fecha de la receta</th>
                            <th>ID receta</th>
                            <th>Ver Receta</th>
                            <th>Cambiar Datos</th>
                        </tr>

                        <!-- FILTRO POR COLUMNA -->
                        <tr>
                            <th><input type="text" class="form-control form-control-sm column-search" placeholder="Buscar Folio"></th>
                            <th><input type="text" class="form-control form-control-sm column-search" placeholder="Buscar Paciente"></th>
                            <th><input type="text" class="form-control form-control-sm column-search" placeholder="Buscar Sucursal"></th>
                            <th><input type="text" class="form-control form-control-sm column-search" placeholder="Buscar Médico"></th>
                            <th><input type="text" class="form-control form-control-sm column-search" placeholder="Buscar Fecha"></th>
                            <th><input type="text" class="form-control form-control-sm column-search" placeholder="Buscar Receta"></th>
                            <th><input type="text" class="form-control form-control-sm column-search" placeholder=""></th>
                            <th><input type="text" class="form-control form-control-sm column-search" placeholder=""></th>
                        </tr>
                    </thead>
                </table>
            </div>

        </div>
    </div>
    <div class="modal fade" id="modalEditar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Editar Receta</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" id="id_receta">

                    <div class="mb-3">
                        <label>Sucursal</label>
                        <select id="editSucursal" class="form-select select2" disabled></select>
                    </div>

                    <div class="mb-3">
                        <label>Médico</label>
                        <select id="editMedico" class="form-select select2"></select>
                    </div>

                    <div class="mb-3">
                        <label>Fecha</label>
                        <input type="date" id="editFecha" class="form-control">
                    </div>

                </div>
                <div class="modal-footer">
                    <button class="btn btn-info btn-sm me-auto" id="btnCancelarApartado">
                        Cancelar apartado Vending
                    </button>
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary" id="btnGuardarCambio">Guardar</button>

                </div>

            </div>
        </div>
    </div>
    <div id="globalLoader" class="loader-overlay d-none">
        <div class="spinner-border text-light" role="status"></div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


    <script src="js/services.js?vSB=0.1"></script>
    <script src="js/main.js?vSB=0.1"></script>

</body>

</html>