$(document).ready(function () {
    const params = new URLSearchParams(window.location.search);
    const grupoPerteneciente = params.get("grupo") ? params.get("grupo") : 20;

    const baseUrl = window.location.href.split('?')[0].replace(/\/[^/]*$/, '/');
    const backgrounds = [
        `url("${baseUrl}imgs/slider3.jpg")`,
        `url("${baseUrl}imgs/slider2.jpg")`,
        `url("${baseUrl}imgs/slider1.jpg")`,
    ];

    window.addEventListener('scroll', () => {
      const scrollY = window.scrollY;
      const totalScroll = document.body.scrollHeight - window.innerHeight;
      const scrollRatio = scrollY / totalScroll;

      const index = Math.floor(scrollRatio * backgrounds.length);
      const bg = backgrounds[index] || backgrounds[backgrounds.length - 1];

      document.body.style.backgroundImage = bg;
    });

    document.body.style.backgroundImage = backgrounds[0];

    var cargandoContenido = false;
    var tabla_datos = null;
    var actualizandoSucAtencion = false;
    var desactivarMedicos = false;

    const firebaseConfig = {
        apiKey: "AIzaSyAD9zWa5wENRev4HbMB4XpVM4a27a67M6c",
        authDomain: "notificacionessybi.firebaseapp.com",
        projectId: "notificacionessybi",
        storageBucket: "notificacionessybi.appspot.com",
        messagingSenderId: "684568327784",
        appId: "1:684568327784:web:c24f62c251f1587c356ddd"
    };

    const firebaseConfig2 = {
        apiKey: "AIzaSyAB1XkCnHD-F6JnHD0AJvXHi9fqCSfWglo",
        authDomain: "notificaciones-push-84106.firebaseapp.com",
        projectId: "notificaciones-push-84106",
        storageBucket: "notificaciones-push-84106.firebasestorage.app",
        messagingSenderId: "23492088701",
        appId: "1:23492088701:web:ee06d31c735404e5f03214",
        measurementId: "G-HL0797VLMM"
    }

    var conexionFirevase1 = firebase.initializeApp(firebaseConfig, "app1");
    var conexionFirevase2 = firebase.initializeApp(firebaseConfig2, "app2");

    var sucursalesList = [];
    
    const opcionesGrupos = [
    {
        clase: 'medico',
        texto: 'Médicos',
        imagen: 'https://cdn-icons-png.flaticon.com/512/3774/3774299.png',
        grupo: 20
    },
    {
        clase: 'psicologo',
        texto: 'Psicólogos',
        imagen: 'https://www.arqhys.com/general/wp-content/uploads/2011/05/El-logo-de-Psicolog%C3%ADa.png',
        grupo: 24
    },
    {
        clase: 'nutriologo',
        texto: 'Nutriólogos',
        imagen: 'https://cdn-icons-png.flaticon.com/512/1046/1046843.png',
        grupo: 23
    }
    ];

    const container = document.createElement('div');
    container.className = 'container_new';

    opcionesGrupos.forEach(op => {
        const card = document.createElement('div');
        card.className = `card_new id_${op.grupo}`;
        card.onclick = () => {
            const url = new URL(window.location.href);
            url.searchParams.set('grupo', op.grupo);
            window.location.href = url.toString();
        }

        const img = document.createElement('img');
        img.src = op.imagen;
        img.alt = op.texto;

        const span = document.createElement('span');
        span.textContent = op.texto;

        card.appendChild(img);
        card.appendChild(span);
        container.appendChild(card);
    });

    document.querySelector('.options_groups').appendChild(container);

    var infoActual = null;

    document.querySelector('.id_'+grupoPerteneciente).classList.add('select_group');
    infoActual = opcionesGrupos.filter((e)=>e.grupo == grupoPerteneciente);
    document.querySelector('.title_name').textContent = 'Administrador de '+infoActual[0].texto;
    buscarSucursales().then((response)=>{
        sucursalesList = response;
        cargarTabla();
    });

    function cargarTabla(){

        cargandoContenido = true;

        if ($.fn.DataTable.isDataTable('#tabla_medicos')) {
            tabla_datos.destroy();
            tabla_datos = null;
            $('#tabla_medicos').remove();
        }

        var columnDefs = [
            { className: "text-start", targets: 0 },
            { className: "text-center", targets: 3 },
            { className: "align-middle", targets: "_all" }
        ];

        $('.table-responsive').html(`
            <div class="row mb-3">
                <div class="col-md-6 mb-2">
                    <button id="btnDesactivarTodos" class="btn btn-danger">
                        Desactivar todos los ${infoActual[0].texto}
                    </button>
                </div>
                <div class="col-md-6">
                    <input type="text" id="buscadorSucursal" class="form-control" placeholder="Buscar por sucursal de atención...">
                </div>
            </div>
            <table id="tabla_medicos" class="table table-sm" style="width:100%">
                    <thead>
                        <tr>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>`);
        
        //setTimeout(()=>{

            tabla_datos = $('#tabla_medicos').DataTable({
                pageLength: 13,
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'copyHtml5',
                        text: '📋 Copiar',
                        className: 'bntImprimirTable'
                    },
                    {
                        extend: 'excelHtml5',
                        text: '📊 Excel',
                        className: 'bntImprimirTable'
                    },
                    {
                        extend: 'pdfHtml5',
                        text: '📄 PDF',
                        className: 'bntImprimirTable'
                    },
                    {
                        extend: 'print',
                        text: '🖨 Imprimir',
                        className: 'bntImprimirTable'
                    }
                ],
                responsive: true,
                language: {
                    "lengthMenu": "Mostrar _MENU_ registros",
                    "zeroRecords": "No se encontraron resultados",
                    "info": "Mostrando _START_ a _END_ de _TOTAL_ registros",
                    "infoEmpty": "Mostrando 0 a 0 de 0 registros",
                    "sSearch": "🔎",
                    "oPaginate": {
                        "sFirst": "Primero",
                        "sLast": "Último",
                        "sNext": "Sig.",
                        "sPrevious": "Ant."
                    }
                },
                order: [],
                ajax: {
                    url: "funciones.php",
                    method: 'GET',
                    data: {
                        accion: 'medicos',
                        grupo: grupoPerteneciente
                    },
                    dataSrc: ''
                },
                columnDefs: columnDefs,
                columns: [
                    { data: "id", title: "ID" },
                    { data: "nombre", title: "Médico" },
                    { data: "sucursal", title: "Sucursal" },
                    {
                        data: "",
                        title: "Horario",
                        render: function (data, type, row) {
                            // Función para convertir a formato 12h
                            function formato12h(hora) {
                                if (!hora) return "No disponible";
                                const [h, m] = hora.split(":");
                                let hh = parseInt(h, 10);
                                const ampm = hh >= 12 ? "PM" : "AM";
                                hh = hh % 12 || 12;
                                return `${hh}:${m} ${ampm}`;
                            }

                            // Validaciones de datos
                            const laboralInicio = row.horaLaboral?.inicio || null;
                            const laboralFin = row.horaLaboral?.fin || null;
                            const comidaInicio = row.horaComida?.inicio || null;
                            const comidaFin = row.horaComida?.fin || null;

                            if (!laboralInicio && !laboralFin && !comidaInicio && !comidaFin) {
                                return `<div class="text-danger text-center">Cerrado</div>`;
                            }

                            return `
                                <div style="text-align:center; width: 120px; font-size: 13px;">
                                    <div><strong>Laboral:</strong></div>
                                    <div>${formato12h(laboralInicio)} -</div>
                                    <div>${formato12h(laboralFin)}</div>
                                    <div style="margin-top:5px;"><strong>Comida:</strong></div>
                                    <div>${formato12h(comidaInicio)} -</div>
                                    <div>${formato12h(comidaFin)}</div>
                                </div>
                            `;
                        }
                    },
                    { 
                        data: "estatusSesion",
                        title: "Estatus de sesion",
                        render: function (data, type, row) {
                            let text = `<span class="text-danger">Cerrado</span>`;
                            if(data == 1){
                                text = `<span class="text-success">Activo</span>`;
                            }
                            return text;
                        }
                    },
                    { 
                        data: "estatusActivo",
                        title: "Estatus de permisos",
                        render: function (data, type, row) {
                            let text = `<span class="text-warning">Desactivado</span>`;
                            if(data == 1){
                                text = `<span class="text-success">Activado</span>`;
                            }
                            return text;
                        }
                    },
                    { 
                        data: "dispositvo",
                        title: "Dispositivos de atención",
                        render: function (data, type, row) {
                            if (row.estatusActivo != 1) {
                                return `<i class="fas fa-lock text-muted" title="Permisos desactivados"></i>`;
                            }
                            return data;
                        }
                    },
                    {
                        data: null,
                        title: "Sucursales de atencion",
                        orderable: false,
                        width: "250px",
                        render: function (data, type, row) {
                            if (row.estatusActivo != 1) {
                                return `<i class="fas fa-lock text-muted" title="Permisos desactivados"></i>`;
                            }

                            if (row.dispositvo == 'APP') {
                                return 'Todas las sucursales';
                            } else {
                                const numeros = row.st_vendings ? row.st_vendings.split(",").map(Number) : [];
                                let options = '';
                                sucursalesList.forEach((e) => {
                                    let seleccion = numeros.includes(parseInt(e.st_vendingMachine)) ? 'selected' : '';
                                    options += `<option value="${e.st_vendingMachine}" ${seleccion}>${e.st_Nombre}</option>`;
                                });
                                return `
                                    <div class="select-wrapper">
                                        <select class="sucList w-100" name="states[]" multiple="multiple">
                                            ${options}
                                        </select>
                                        <button class="btn btn-sm btn-primary mt-2 btn-actualizar" style="display: none;">Actualizar</button>
                                    </div>
                                `;
                            }
                        }
                    },
                    { 
                        data: "estatusNot",
                        title: "Disponibilidad de notificacion",
                        render: function (data, type, row) {
                            if (row.estatusActivo != 1) {
                                return `<i class="fas fa-lock text-muted" title="Permisos desactivados"></i>`;
                            }

                            return data == 1 
                                ? `<span class="text-warning">Ocupado</span>` 
                                : `<span class="text-success">Libre</span>`;
                        }
                    },
                    { 
                        data: "estatusCons",
                        title: "Disponibilidad de consultas",
                        render: function (data, type, row) {
                            if (row.estatusActivo != 1) {
                                return `<i class="fas fa-lock text-muted" title="Permisos desactivados"></i>`;
                            }

                            return data == 1 
                                ? `<span class="text-warning">En consulta</span>` 
                                : `<span class="text-success">Disponible</span>`;
                        }
                    },
                    { 
                        data: "",
                        title: "Vaciar notificacion",
                        render: function (data, type, row) {
                            let btn = '';
                            if(row.estatusActivo == 1){
                                btn = `<button class="btn btn-primary btn-vaciarNotificacion data-id="${row.id}"">Vaciar Notificacion</button>`;
                            }
                            return btn;
                        }
                    },
                    { 
                        data: "",
                        title: "Vaciar consulta",
                        render: function (data, type, row) {
                            let btn = '';
                            if(row.estatusActivo == 1){
                                btn = `<button class="btn btn-primary btn-vaciarConsulta data-id="${row.id}"">Vaciar Consulta</button>`;
                            }
                            return btn;
                        }
                    },
                    { 
                        data: "",
                        title: "Cambiar tipo permisos",
                        render: function (data, type, row) {
                            let btn = '';
                            if(row.estatusActivo == 1){
                                btn = `<button class="btn btn-primary btn-cambiarPermisos data-id="${row.id}"">Cambiar permisos</button>`;
                            }

                            return btn;
                        }
                    },
                    
                    { 
                        data: "",
                        title: "Deshabilitar permisos",
                        render: function (data, type, row) {
                            let text = 'Activar';
                            let classBtn = 'success';
                            if(row.estatusActivo == 1){
                                text = 'Desactivar';
                                classBtn = 'danger';
                            }
                            return `<button class="btn btn-${classBtn} btn-deshabilitar data-id="${row.id}"">${text}</button>`;
                        }
                    },
                ],
                initComplete: function () {
                },
                drawCallback: function () {
                    $('.sucList').select2({
                        width: '100%'
                    });

                    $('.sucList').off('select2:select select2:unselect').on('select2:select select2:unselect', function () {
                        const contenedor = $(this).closest('.select-wrapper');
                        contenedor.find('.btn-actualizar').show();
                    });

                    $('.btn-actualizar').off('click').on('click', function () {
                        const $btn = $(this);
                        const row = tabla_datos.row($btn.closest('tr')); // ✅ Obtiene la fila como objeto de DataTables
                        const data = row.data();
                        const valoresSeleccionados = $(this).siblings('select').val();
                        const stringValores = valoresSeleccionados.join(',');
                        if(actualizandoSucAtencion){
                            return;
                        }
                        $('#carga').fadeIn('fast');
                        actualizandoSucAtencion = true;
                        actualizarSucursalesAtencion(stringValores, data.id).then((response)=>{
                            actualizandoSucAtencion = false;
                            $(this).hide();
                            cargarTabla();
                        });
                    });

                    let filtroSucursalActivo = false;

                    function aplicarFiltroSucursal() {
                        if (!filtroSucursalActivo) {
                            $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
                                const input = $('#buscadorSucursal').val().toLowerCase();
                                const row = $('#tabla_medicos').DataTable().row(dataIndex).node();

                                const textoSucursal = $(row).find('select option:selected').text().toLowerCase();

                                return textoSucursal.includes(input);
                            });
                            filtroSucursalActivo = true;
                        }
                    }

                    function debounce(func, delay) {
                        let timer;
                        return function () {
                            clearTimeout(timer);
                            timer = setTimeout(func, delay);
                        };
                    }

                    $('#buscadorSucursal').on('keyup', debounce(() => {
                        aplicarFiltroSucursal();
                        $('#tabla_medicos').DataTable().draw();
                    }, 300));


                    $('#btnDesactivarTodos').on('click', function () {
                        if (desactivarMedicos && !(grupoPerteneciente)) return;

                        Swal.fire({
                            title: '¿Estás seguro?',
                            text: "Esto desactivará a todos los médicos.",
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Sí, desactivar',
                            cancelButtonText: 'Cancelar',
                            reverseButtons: true
                        }).then((result) => {
                            if (result.isConfirmed) {
                                $('#carga').fadeIn('fast');
                                desactivarMedicos = true;

                                desactivarTodosMedicos().then((response) => {
                                    desactivarMedicos = false;
                                    cargarTabla();

                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Médicos desactivados',
                                        showConfirmButton: false,
                                        timer: 1500
                                    });
                                }).catch(() => {
                                    desactivarMedicos = false;
                                    $('#carga').fadeOut('fast');
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Ocurrió un error',
                                        text: 'No se pudieron desactivar los médicos.'
                                    });
                                });
                            }
                        });
                    });
                }
            });

            $('#tabla_medicos').on('xhr.dt', function (e, settings, json, xhr) {
                $('#carga').fadeOut('fast');
                cargandoContenido = false;
                agregarEventosBotones();
            });
            
        //}, 500);
    
    }

    function desactivarTodosMedicos(){
        return new Promise((resolve, reject) => {
            $.ajax({
                url: 'funciones.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    accion: 'desactivarTodosMedicos',
                    grupo: grupoPerteneciente
                },
                success: function (response) {
                    resolve(response);
                },
                error: function (xhr, status, error) {
                    reject(error);
                }
            });
        });
    }

    function actualizarSucursalesAtencion(ids, id_medico){
        return new Promise((resolve, reject) => {
            $.ajax({
                url: 'funciones.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    accion: 'insertarSucursales',
                    ids,
                    id_medico
                },
                success: function (response) {
                    resolve(response);
                },
                error: function (xhr, status, error) {
                    reject(error);
                }
            });
        });
    }

    function agregarEventosBotones() {
        let accionBoton = false;
        $(document).off('click', '.btn-vaciarNotificacion');
        $(document).on('click', '.btn-vaciarNotificacion', function () {
            if(accionBoton || cargandoContenido){
                return;
            }
            var rowData = $('#tabla_medicos').DataTable().row($(this).parents('tr')).data();
            accionBoton = true;
            $('#carga').fadeIn('fast');
            vaciarRegistros(rowData.id, 'vaciarNotificacion', null).then(response => {
                accionBoton = false;
                eliminarColeccion(conexionFirevase1, rowData.id);
                eliminarColeccion(conexionFirevase2, rowData.id);
                cargarTabla();
            });
        });
    
        $(document).off('click', '.btn-vaciarConsulta');
        $(document).on('click', '.btn-vaciarConsulta', function () {
            if(accionBoton || cargandoContenido){
                return;
            }
            var rowData = $('#tabla_medicos').DataTable().row($(this).parents('tr')).data();
            accionBoton = true;
            $('#carga').fadeIn('fast');
            vaciarRegistros(rowData.id, 'vaciarConsulta', null).then(response => {
                accionBoton = false;
                cargarTabla();
            });
        });
    
        $(document).off('click', '.btn-cambiarPermisos');
        $(document).on('click', '.btn-cambiarPermisos', function () {
            if(accionBoton || cargandoContenido){
                return;
            }
            accionBoton = true;
            var rowData = $('#tabla_medicos').DataTable().row($(this).parents('tr')).data();
            Swal.fire({
                title: 'seleccione un dispositivo',
                input: 'radio',
                inputOptions: {
                  ALL: 'ALL',
                  APP: 'APP',
                  Kiosco: 'Kiosco'
                },
                inputValidator: (value) => {
                  if (!value) {
                    return 'Debes seleccionar una opción';
                  }
                },
                showCancelButton: true,
                confirmButtonText: 'Continuar'
              }).then((result) => {
                accionBoton = false;
                if (result.isConfirmed) {
                    $('#carga').fadeIn('fast');
                    vaciarRegistros(rowData.id, 'cambiarPermisos', result.value).then(response => {
                        accionBoton = false;
                        cargarTabla();
                    });
                }else{
                    accionBoton = false;
                }
              });
              
              
        });
    
        $(document).off('click', '.btn-deshabilitar');
        $(document).on('click', '.btn-deshabilitar', function () {
            if(accionBoton || cargandoContenido){
                return;
            }
            var rowData = $('#tabla_medicos').DataTable().row($(this).parents('tr')).data();
            console.log(rowData)
            accionBoton = true;
            $('#carga').fadeIn('fast');
            vaciarRegistros(rowData.id, 'deshabilitar', null).then(response => {
                accionBoton = false;
                cargarTabla();
            });
        });
    }

    function buscarSucursales(){
        return new Promise((resolve, reject) => {
            $.ajax({
                url: 'funciones.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    accion: 'sucursalesVending'
                },
                success: function (response) {
                    resolve(response);
                },
                error: function (xhr, status, error) {
                    reject(error);
                }
            });
        });
    }

    function vaciarRegistros(id_medico, accion, dispositivo){
        return new Promise((resolve, reject) => {
            $.ajax({
                url: 'funciones.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    accion: accion,
                    id_medico: id_medico,
                    dispositivo: dispositivo
                },
                success: function (response) {
                    resolve(response);
                },
                error: function (xhr, status, error) {
                    reject(error);
                }
            });
        });
    }

    async function eliminarColeccion(firebaseApp, id_medico) {
        try {
            const db = firebaseApp.firestore();
            const colRef = db.collection('NotificacionesSybi');
            const snapshot = await colRef.where('id_medico', '==', id_medico).get();
    
            if (snapshot.empty) {
                console.log("No hay documentos");
            } else {
                const batch = db.batch();
                snapshot.docs.forEach(doc => {
                    batch.delete(doc.ref);
                });
                await batch.commit();
                console.log("Registros eliminados correctamente.");
            }
            db.terminate().then(() => {
                console.log("Conexión con Firestore cerrada.");
            });
        } catch (error) {
            console.error("Error al eliminar los registros:", error);
        }
    }
    
}); 