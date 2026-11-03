


$(document).ready(function () {

    cargarListas('traerSucursales', 'selectSucursal', 'st_vendingMachine', null);
    cargarListas('traerMedicos', 'selectMedico', 'id_medico', null);
    cargarListas('traerSucursales', 'filtroSucursal', 'st_vendingMachine', null);
    cargarListas('traerSucursales', 'editSucursal', 'id_SucursalClinica', null);
    cargarListas('traerMedicos', 'editMedico', 'id_medico', null);

    let validandoContenido = false;
    document.getElementById('tipoBusquedaPaciente').addEventListener('change', function () {

        let contenedor = document.getElementById('contenedorNombrePaciente');
        let inputBusqueda = document.getElementById('txtBusquedaPaciente');

        if (this.value === 'nombre') {
            contenedor.classList.remove('d-none');
            inputBusqueda.disabled = true;
            validandoContenido = true;
        } else {
            contenedor.classList.add('d-none');
            inputBusqueda.disabled = false;
        }

        if (validandoContenido) {
            validandoContenido = false;
            limpiarBusquedaPaciente();
        }
    });

    document.querySelector('.btnLimpiarBusqueda').addEventListener('click', () => {
        limpiarBusquedaPaciente();
    })

    let tablaUsuariosSybi = $('#tablaUsuariosSybi').DataTable({
        responsive: true,
        pageLength: 10,
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json'
        },
        columnDefs: [
            {
                targets: 3,
                orderable: false,
                searchable: false,
                className: 'text-center'
            }
        ]
    });

    function mostrarResultadosBusqueda(data) {

        document
            .getElementById('contenedorTablaBusqueda')
            .classList.remove('d-none');

        tablaUsuariosSybi.clear();

        let id_mosi = $('#selectSucursal').val();
        let id_medico = $('#selectMedico').val();

        data.forEach(function (item) {

            let usuarioActivo = item.usuarioActivo == 1 ? 'Activado' : 'Desactivado';
            let tarjetaActiva = item.tarjetaActiva == 1 ? 'Activado' : 'Desactivado';
            tablaUsuariosSybi.row.add([
                item.id,
                item.tarjeta,
                item.nombreCompleto,
                item.parentesco,
                usuarioActivo,
                tarjetaActiva,
                `
            <button 
                class="btn btn-dark" 
                 onclick="window.open(
                    'https://www.sybiml.com/telemedicina/?idMachine=${id_mosi}&id_usuario=${item.id}&pruebasEmulador=true&id_medicoEmulado=${id_medico}',
                    '_blank'
                )"
            >
                Emular Videollamada
            </button>
            `
            ]);


        });

        tablaUsuariosSybi.draw();
    }

    let buscandoPAciente = false;
    document.getElementById('buscarPacienteSiby').addEventListener('click', async () => {
        if (buscandoPAciente) {
            return;
        }

        let tipo = document.getElementById('tipoBusquedaPaciente').value;
        let nombre = document.getElementById('nombrePaciente').value.trim();
        let apellidoP = document.getElementById('apellidoPaternoPaciente').value.trim();
        let apellidoM = document.getElementById('apellidoMaternoPaciente').value.trim();
        let id = document.getElementById('txtBusquedaPaciente').value.trim();
        let id_mosi = document.getElementById('selectSucursal').value.trim();



        console.log(tipo, nombre, apellidoP, apellidoM, id);

        try {
            buscandoPAciente = true;

            let dataArray = {
                accion: 'buscarUsuarioSybi',
                metodo: 'GET'
            };

            let datos = {
                tipo, nombre, apellidoP, apellidoM, id, id_mosi
            }

            if (datos) {
                dataArray.datos = datos;
            }

            const data = await consumirAPI(dataArray);

            mostrarResultadosBusqueda(data);

        } catch (error) {

            console.error(error);

        } finally {

            buscandoPAciente = false;

        }
    })

    function limpiarBusquedaPaciente() {

        document.getElementById('txtBusquedaPaciente').value = '';
        document.getElementById('nombrePaciente').value = '';
        document.getElementById('apellidoPaternoPaciente').value = '';
        document.getElementById('apellidoMaternoPaciente').value = '';

        if (tablaUsuariosSybi) {
            tablaUsuariosSybi.clear().draw();
        }

        document
            .getElementById('contenedorTablaBusqueda')
            .classList.add('d-none');

    }

    $('.select2').select2({
        width: '100%',
        placeholder: "Seleccionar...",
        allowClear: true
    });

    $('#selectSucursal').on('change', function () {
        limpiarBusquedaPaciente();
        cargarListas('traerPacientes', 'selectPaciente', 'id_UsuarioWeb', { id_mosi: $(this).val() })
    });


    $('#emuVideo').on('click', async function () {
        let id_mosi = $('#selectSucursal').val();
        let id_medico = $('#selectMedico').val();
        let id_usuario = $('#selectPaciente').val();

        if (id_medico && id_mosi && id_usuario) {
            window.open(`https://www.sybiml.com/telemedicina/?idMachine=${id_mosi}&id_usuario=${id_usuario}&pruebasEmulador=true&id_medicoEmulado=${id_medico}`, '_blank');

            await consumirAPI({
                accion: 'registrarLogVideollamada',
                metodo: "POST",
                datos: {
                    id_mosi,
                    id_usuario,
                    id_medico,
                }
            });
        }
    });

    $('#btnBuscarRegistros').on('click', function () {
        cargarTabla();
    });

    const modalEditar = new bootstrap.Modal(document.getElementById('modalEditar'));

    $('#modalEditar').on('hidden.bs.modal', function () {

        $('#editSucursal').val(null).trigger('change');
        $('#editMedico').val(null).trigger('change');
        $('#editFecha').val('');
        $('#id_receta').val('');
    });

    $('#tablaReporte').on('click', '.btnEditar', function () {

        const id = $(this).data('id');
        const fecha = $(this).data('fecha');
        const sucursal = $(this).data('sucursal');
        $('#id_receta').val(id);
        $('#editFecha').val(fecha);
        $('#editSucursal').val(sucursal).trigger('change');
        modalEditar.show();
    });


    $('#modalEditar').on('shown.bs.modal', function () {

        $('#editSucursal').select2({
            dropdownParent: $('#modalEditar'),
            width: '100%'
        });

        $('#editMedico').select2({
            dropdownParent: $('#modalEditar'),
            width: '100%'
        });

    });

    $('#btnCancelarApartado').on('click', function () {
        let tabla = $('#tablaReporte').DataTable();

        var datat = tabla.rows().data();

        var data = null;

        for (var i = 0; i < datat.length; i++) {
            if (datat[i].id_Receta == $('#id_receta').val()) {
                data = datat[i];
                break;
            }
        }
        cancelarApartado(data.id_qrCode);

    });

    $('#btnGuardarCambio').on('click', function () {
        let tabla = $('#tablaReporte').DataTable();

        var datat = tabla.rows().data();

        var datosAntes = null;

        for (var i = 0; i < datat.length; i++) {
            if (datat[i].id_Receta == $('#id_receta').val()) {
                datosAntes = datat[i];
                break;
            }
        }

        const data = {
            id_receta: $('#id_receta').val(),
            sucursal: $('#editSucursal').val(),
            medico: $('#editMedico').val(),
            fecha: $('#editFecha').val(),
            datosAntes
        };

        if (!data.sucursal || !data.medico || !data.fecha) {
            Swal.fire('Error', 'Completa todos los campos', 'warning');
            return;
        }

        Swal.fire({
            title: '¿Confirmar cambio?',
            text: 'Se modificará la receta',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, guardar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {

            if (result.isConfirmed) {
                actualizarRecetas(data, 'editarFechaConsulta');
                cancelarApartado(data.datosAntes.id_qrCode);
            }
        });
    });

});

let tabla = null;

function cargarTabla() {

    let fechaInicio = $('#fechaInicio').val();
    let fechaFin = $('#fechaFin').val();
    let sucursal = $('#filtroSucursal').val();

    if (!fechaInicio || !fechaFin || !sucursal) {
        return;
    }

    if ($.fn.DataTable.isDataTable('#tablaReporte')) {
        tabla.ajax.reload(null, false);
    } else {
        tabla = $('#tablaReporte').DataTable({
            processing: true,
            ajax: {
                url: 'php/funciones.php',
                type: 'GET',
                data: function (d) {
                    d.accion = 'traerTabla';
                    d.fechaInicio = $('#fechaInicio').val();
                    d.fechaFin = $('#fechaFin').val();
                    d.id_mosi = $('#filtroSucursal').val();
                },
                dataSrc: ''
            },
            columns: [
                { data: 'folio' },
                { data: 'nomUs' },
                { data: 'nomSuc' },
                { data: 'nomMed' },
                { data: 'fecha' },
                { data: 'id_Receta' },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: function (data, type, row) {

                        let url = window.location.origin + `/system/medicalcenter/ImprimeRecetaVending.php?receta=${row.id_Receta}&ideventocita=${row.id_EventoCita}&idevento=${row.id_Receta}&idusuarioweb=${row.id_UsuarioWeb}&tipoRec=subrogado&id_sucursal=${row.id_Sucursal}&id_grupo=${row.i_group}&id_notificacion=${row.id_notificacion}&imprimeVending=true`;
                        return `
                            <a href="${url}" 
                            target="_blank" 
                            class="btn btn-sm btn-primary">
                                Ver Receta
                            </a>
                        `;
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: function (data, type, row) {

                        return `
                            <button class="btn btn-sm btn-warning btnEditar"
                                data-id="${row.id_Receta}"
                                data-fecha="${row.fecha}""
                                data-sucursal="${row.id_Sucursal}">
                                Editar
                            </button>
                        `;
                    }
                }
            ],
            orderCellsTop: true,
            fixedHeader: true
        });

        $('#tablaReporte thead').on('keyup change', '.column-search', function () {

            let index = $(this).closest('th').index();

            tabla
                .column(index)
                .search(this.value)
                .draw();
        });

        $('#tablaReporte thead').on('click', 'input', function (e) {
            e.stopPropagation();
        });
    }
}

async function cargarListas(accion, idSelect, campoIDSelc, datos) {

    showLoader();

    let dataArray = {
        accion
    };

    if (datos) {
        dataArray.datos = datos;
    }

    const data = await consumirAPI(dataArray);

    hideLoader();

    actualizarSelect(idSelect, data, campoIDSelc);
}

async function actualizarRecetas(datos, accion) {

    showLoader();

    const data = await consumirAPI({
        accion,
        metodo: "POST",
        datos
    });

    hideLoader();

    $('#modalEditar').modal('hide');

    cargarTabla();
}

function showLoader() {
    document.getElementById('globalLoader').classList.remove('d-none');
}

function hideLoader() {
    document.getElementById('globalLoader').classList.add('d-none');
}

function actualizarSelect(id, data, campoId) {
    const select = $('#' + id);

    select.empty();

    select.append('<option value="">Selecciona una opcion</option>');

    data.forEach(s => {
        if (id == 'selectPaciente') {
            select.append(`<option value="${s[campoId]}">${s.folio} - ${s.st_nombre}</option>`);
        } else {
            select.append(`<option value="${s[campoId]}">${s.st_nombre}</option>`);
        }
    });

    // IMPORTANTE: refrescar Select2
    select.trigger('change');
}