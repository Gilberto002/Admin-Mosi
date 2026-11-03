
$(document).ready(function () {

    const params = new URLSearchParams(window.location.search);
    const valorPagina = params.get("pagina") ?  params.get("pagina") : 'principal'; 
    const grupoActual = params.get("grupo") ?  params.get("grupo") : 20; 
    var accionBusqueda = valorPagina;

    window.cambiarPagina = function(valorPagina) {
        params.set('pagina', valorPagina);
        window.location.search = params.toString();
    }

    
    let btnSelect = document.getElementById(valorPagina);
    btnSelect.classList.add('btn-primary');
    var checkSelect = 'ALL';
    var tabla_datos = null;

    let cargandoContenido = false;

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

    conexionFirevase1 = firebase.initializeApp(firebaseConfig, "app1");
    conexionFirevase2 = firebase.initializeApp(firebaseConfig2, "app2");
    //conectarFirebase(conexionFirevase1);
    //conectarFirebase(conexionFirevase2);

    function conectarFirebase(fireConexion) {
        var db = fireConexion.firestore();
        var colRef = db.collection('notificacionesMedicos');
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        colRef.onSnapshot((snapshot) => {
            snapshot.docChanges().forEach((change) => {
                if (change.type === 'added') {
                    if(!cargandoContenido){
                        $('#carga').fadeIn('fast');
                        if(accionBusqueda == 'principal'){
                            cargarDash();
                        }else{
                            cargarTabla();
                        }
                    }
                }
            });
        });
    }


    let botonesMedicos = [{
        btn: 'btnMedicos',
        grupo: 20
    },{
        btn: 'btnPsicologos',
        grupo: 24
    },{
        btn: 'btnNutriologos',
        grupo: 23
    }];

    const botones = ['btnMedicos', 'btnPsicologos', 'btnNutriologos'];

    botones.forEach(id => {
        document.getElementById(id).addEventListener('click', () => {
            let filtroBotones = botonesMedicos.filter(e=>e.btn == id);
            const url = new URL(window.location.href);
            url.searchParams.set('grupo', filtroBotones[0].grupo);
            window.location.href = url.toString();
        });
    });

    function obtenerFiltro() {
        $('#carga').fadeIn('fast');
        const seleccionado = document.querySelector('input[name="btnradio"]:checked');
        checkSelect= seleccionado.value;
        //if(!cargandoContenido){
        let filtroBotones = botonesMedicos.filter(e=>e.grupo == parseInt(grupoActual));
        document.getElementById(filtroBotones[0].btn).classList.add('medico_actual');
            if(accionBusqueda == 'principal'){
                cargarDash();
            }else{
                cargarTabla();
            }
        //}
    }

    obtenerFiltro();



    const radios = document.querySelectorAll('input[name="btnradio"]');
    radios.forEach(radio => {
        radio.addEventListener("change", obtenerFiltro);
    });

    function cargarTabla(){

        if ($.fn.DataTable.isDataTable('#tabla_notificaciones')) {
            var table = $('#tabla_notificaciones').DataTable();
        
            table.clear().draw(); 
            table.destroy();
            $('#tabla_notificaciones').empty();
            tabla_datos = null;
        }

        var columnas = validarCampos();

        var columnDefs = [
            { className: "text-start", targets: 0 },
            { className: "text-center", targets: 3 },
            { className: "align-middle", targets: "_all" }
        ];

        let acciones = {
            accion: accionBusqueda,
            checkSelect: checkSelect,
            grupo: grupoActual
        };
        let fecha1 = document.getElementById('fechaBusquedaIn');
        let fecha2 = document.getElementById('fechaBusquedaFn');

        if(fecha1.value && fecha2.value){
            acciones.fecha1 = fecha1.value;
            acciones.fecha2 = fecha2.value;
        }

        cargandoContenido = true;

        setTimeout(()=>{

            tabla_datos = $('#tabla_notificaciones').DataTable({
                pageLength: 13,
                dom: 'Bfrtip',
                buttons: [
                    {
                        text: '📈 Graficar',
                        className: 'btn btn-block rounded-pill w-auto m-2 border-bottom border-success border-3 bntGraficar',
                    },
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
                    url: "php/funciones.php",
                    method: 'GET',
                    data: acciones,
                    dataSrc: function(json) {
                        json.forEach(function(item) {
                            console.log('cargando desde la tabla')
                            console.log(item)
                        });
                        return json;
                    }
                },
                columnDefs: columnDefs,
                columns: columnas,
                initComplete: function () {
                    $('#carga').fadeOut('fast');
                    cargandoContenido = false;
                    fecha1.value = null;
                    fecha2.value = null;
                    if(accionBusqueda == 'listar_usuarios'){
                        $(".bntGraficar").click(function() {
                            let datos = tabla_datos.rows().data().toArray();
                            chart(datos);
                            window.scrollTo({
                                top: document.body.scrollHeight,
                                behavior: "smooth"
                            });
                        });
                    }
                }
            });
            
        }, 500)
    }

    document.getElementById('btnBuscarFecha').addEventListener('click', validarButton);

    function validarCampos(){
        var campos = [];
        var titulo = document.querySelector('.titulo_tabla');
        switch(accionBusqueda){
            case 'listar_usuarios':
                titulo.textContent = 'Registro diario de Usuarios';
                campos = [
                    { data: "numero", title: "N°" },
                    { data: "nombre", title: "Médico" },
                    { data: "sucursal", title: "Sucursal" },
                    { data: "plataforma", title: "Plataforma"},
                    { 
                        data: "estatus",
                        title: "Estatus",
                        render: function (data, type, row) {
                            //console.log(row)
                            let classBtn = 'success'; 
                            let text = 'Disponible';
                            if(data == 0){
                                classBtn = 'primary';
                                text = 'En consulta';
                            }
                            if(!(row.fechas_atencion.fecha_cita || row.fechas_atencion.fehca_notificacion)){
                                text = 'En espera de un paciente';
                                classBtn = 'warning';
                            }
                            if(row.fechas_atencion.sesion == 0){
                                text = 'Sesion cerrada';
                                classBtn = 'danger';
                            }
                            return `<button class="btn btn-${classBtn} ver-detalle">${text}</button>`;
                        }
                    },
                    { 
                        data: "fechas_atencion",
                        title: "Ultima fecha atencion",
                        render: function (data, type, row) {
                            let horaMexico = null; 
        
                            if(!(data.fecha_cita || data.fehca_notificacion)){
                                horaMexico = 'Sin registros de atencion';
                            }else{
                                if(data.fecha_cita){
                                    const fecha1 = new Date(data.fecha_cita);
                                    const fecha2 = new Date(data.fehca_notificacion);
                                    if(fecha1 > fecha2){
                                        horaMexico = fecha1.toLocaleTimeString("es-MX", {
                                            timeZone: "America/Mexico_City",
                                            hour: "numeric",
                                            minute: "2-digit",
                                            hour12: true
                                        });
                                        
                                    }else{
                                        horaMexico = fecha2.toLocaleTimeString("es-MX", {
                                            timeZone: "America/Mexico_City",
                                            hour: "numeric",
                                            minute: "2-digit",
                                            hour12: true
                                        });
                                    }
                                }else{
                                    const fecha2 = new Date(data.fehca_notificacion);
                                    horaMexico = fecha2.toLocaleTimeString("es-MX", {
                                        timeZone: "America/Mexico_City",
                                        hour: "numeric",
                                        minute: "2-digit",
                                        hour12: true
                                    });
                                }
                            }
        
                            return horaMexico;
                        }
                    },
                    { data: "recibidas", title: "Atendidas" },
                    { data: "agendadas", title: "Reasignadas" },
                    { data: "recibidasAgenda", title: "Recibidas por reasignación" },
                    { data: "recibidasNoAtendidas", title: "Llamadas sin contestar" },
                    { 
                        data: "agendadasNoAtendidas",
                        title: "Llamadas sin registros",
                        render: function (data, type, row) {
                            let numero = row.agendadasNoAtendidas + row.recibidasAgendaNoAtendidas;
                            return numero;
                        }
                    },
                    /*{ data: "agendadasNoAtendidas", title: "Sin registro en Reasignadas" },
                    { data: "recibidasAgendaNoAtendidas", title: "Sin registro por reasignación" },*/
                    { data: "recibidasTotales", title: "Total de llamadas recibidas" }
                ];
                break;
            case 'listar_registrosD':
                titulo.textContent = 'Registros detallados';
                campos = [
                    { data: "numero", title: "N°" },
                    { data: "nombre_M", title: "Médico" },
                    { data: "sucursal_M", title: "Sucursal del medico" },
                    //{ data: "id_qr", title: "ID QR" },
                    //{ data: "id_Receta", title: "id_Receta" },
                    { 
                        data: "st_dispositivo",
                        title: "Plataforma de origen",
                        render: function (data, type, row) {
                            let text = 'APP';
                            if(parseInt(data)){
                                text = 'Kiosco'
                            }
                            return text;
                        }
                    },
                    { data: "nombre_U", title: "Nombre del paciente" },
                    { data: "sucursal_U", title: "Sucursal del paciente" },
                    { 
                        data: "i_reasignacion",
                        title: "Estado de la telemedicina",
                        render: function (data, type, row) {
                            //console.log(row)
                            let text = 'Telemedicina';
                            switch(data){
                                case 1:
                                    text = 'Reasignacion de telemedicina';
                                    break;
                                case 2:
                                    text = 'Telemedicina tomada por reasignacion';
                                    break;
                            }
                            if(!row.dt_conexion_Med){
                                text = 'Sin responder';
                            }
                            if(text == 'Telemedicina'){
                                if(!parseInt(row.st_dispositivo)){
                                    text = 'APP '+row.st_dispositivo;
                                }
                            }
                            return text;
                        }
                    },
                    { data: "fecha_registro", title: "Fecha de registro" },
                    { 
                        data: "",
                        title: "Duracion de llamada",
                        render: function (data, type, row) {
                            let minutos = calcularDatos(row);

                            return minutos.duracionMinutos +' minutos';
                        }
                    },
                    { 
                        data: "tiempo_espera",
                        title: "Tiempo de espera",
                        render: function (data, type, row) {
                            const hora1 = row.fecha_registro.split(" ")[1];
                            const hora2 = row.tiempo_espera;
                            let minutos = 0;
                            if(row.tiempo_espera){
                                minutos = diferenciaEnMinutos(hora1.trim(), hora2.trim())
                            }

                            return minutos+' minutos';
                        }
                    },
                    
                    { 
                        data: "dt_fechaFinalizacion_User",
                        title: "Hora de salida usuario",
                        render: function (data, type, row) {
                            let hora = '';
                            if(data){
                                hora = convertirHoraFormatoCM(data);
                            }

                            return hora;
                        }
                    },
                    { 
                        data: "dt_conexion_Med",
                        title: "Hora de conexion medico",
                        render: function (data, type, row) {
                            let hora = '';
                            if(data){
                                hora = convertirHoraFormatoCM(data);
                            }
                            return hora;
                        }
                    },
                    { 
                        data: "dt_fechaFinalizacion_Med",
                        title: "Hora de salida medico",
                        render: function (data, type, row) {
                            let hora = '';
                            if(data){
                                hora = convertirHoraFormatoCM(data);
                            }
                            return hora;
                        }
                    },

                ];
                break;
        }

        return campos;
    }

    function validarButton(e){
        e.preventDefault();


        let fecha1 = document.getElementById('fechaBusquedaIn');
        let fecha2 = document.getElementById('fechaBusquedaFn');

        if(!fecha1.value && !fecha2.value){
            Swal.fire({
                text: "Es necesario ingresar una fecha de inicio y una fecha fin",
                icon: "info"
            });
            return;
        }

        if(!cargandoContenido){
            $('#carga').fadeIn('fast');
            if(accionBusqueda == 'principal'){
                cargarDash();
            }else{
                cargarTabla();
            }
        }
    }

    function diferenciaEnMinutos(hora1, hora2) {
        const aSegundos = (hora) => {
            const [hh, mm, ss] = hora.split(":").map(Number);
            return hh * 3600 + mm * 60 + ss;
        };
    
        const segundos1 = aSegundos(hora1);
        const segundos2 = aSegundos(hora2);
        const diferenciaSegundos = Math.abs(segundos1 - segundos2);
    
        return Math.floor(diferenciaSegundos / 60);
    }

    function convertirHoraFormatoCM(hora) {
        let [hh, mm, ss] = hora.split(":").map(Number);
        let periodo = hh >= 12 ? "PM" : "AM";
        hh = hh % 12 || 12;
    
        return `${hh}:${mm < 10 ? '0' + mm : mm} ${periodo}`;
    }

    function cargarDash(){
        cargandoContenido = true;
        let fecha1Cont = document.getElementById('fechaBusquedaIn');
        let fecha2Cont = document.getElementById('fechaBusquedaFn');
        let fecha1 = null, fecha2 = null;

        if(fecha1Cont.value && fecha2Cont.value){
            fecha1 = fecha1Cont.value;
            fecha2 = fecha2Cont.value;
        }

        get_InfoDash(fecha1, fecha2).then(vending => {
            console.log('datos de carga init')
            console.log(vending)
            let colaDia = vending[0].enCola_dia.encolaActual ? vending[0].enCola_dia.encolaActual : 0;
            let colaActual = 0;
            if(colaDia > 0){
                let datosCola = vending[0].enCola_dia.datosCola ? vending[0].enCola_dia.datosCola : [];
                let ultimaHora = vending[0].enCola_dia.ultimaHora ? vending[0].enCola_dia.ultimaHora : null;
                if(datosCola.length > 0){
                    datosCola.forEach((e)=>{
                        const fechaRegistro = new Date(e.dt_fechaRegistro);
                        /*const ultimaFechaVal = new Date(ultimaHora);
                        if (fechaRegistro > ultimaFechaVal) {
                            colaActual++;
                        }*/
                        if(!(e.dt_fechaSalida)){
                            colaActual++;
                        }
                    });
                }
            }
            let contandoColaActual = 0;
            if(vending[0].reporteLlamadas.length > 0){
                vending[0].reporteLlamadas.forEach((e)=>{
                    if(e.cola){
                        contandoColaActual++;
                    }
                });
            }
            var temporalCola = colaActual + contandoColaActual;
            if(temporalCola >= 3){
                vending[0].calculo_cola = 3;
            }else{
                
                vending[0].calculo_cola = colaActual + contandoColaActual;
            }
            document.querySelector('.llamadasCola').textContent = vending[0].calculo_cola;
            document.querySelector('.medDisponibles').textContent = vending[0].total_MDisponibles;

            vending[0].calculo_medDisp = parseInt(vending[0].total_Medicos) - parseInt(vending[0].total_MDisponibles) - parseInt(vending[0].total_MSinRegistros);
            document.querySelector('.medConsulta').textContent = vending[0].calculo_medDisp;
            document.querySelector('.sinRegistroAtencion').textContent = vending[0].total_MSinRegistros;

            let encolaTotal =  vending[0].enCola_dia.totalCola ? vending[0].enCola_dia.totalCola : 0;
            let encolaResp =  vending[0].enCola_dia.encolaResp ? vending[0].enCola_dia.encolaResp : 0;

            let colaResta = vending[0].calculo_cola >= 3 ? (temporalCola - 3) : vending[0].calculo_cola;
            vending[0].calculo_llamAtendidas = (vending[0].atendidas + vending[0].recibidasAgenda) + (encolaResp) + colaResta;

            document.querySelector('.llamadasAtendidas').textContent = vending[0].calculo_llamAtendidas;
            document.querySelector('.llamadasAgendadas').textContent = vending[0].agendadas;
            //let noAtendidadas = vending[0].agendadasNoAtendidas + vending[0].recibidasNoAtendidas + vending[0].recibidasAgendaNoAtendidas + (encolaTotal - (encolaResp + temporalCola >5 ? (temporalCola - 5) : 0));
            //console.log(noAtendidadas)
            //let porcentaje = (noAtendidadas / ((vending[0].recibidasTotales +( encolaTotal - contandoColaActual) + vending[0].agendadas) - (temporalCola > 5 ? (temporalCola - 5) : 0)) ) * 100;

            //vending[0].calculo_porcentajeAbandono = (porcentaje ? porcentaje.toFixed(2) : 0) + ' %';


            if(vending[0].tiempos.length > 0){

                vending[0].tiempos = vending[0].tiempos.map(registro => {
                    return calcularDatos(registro);
                });

                let totalMinutos = vending[0].tiempos.reduce((acc, registro) => acc + registro.duracionMinutos, 0);
                let promedioDuracion = totalMinutos / vending[0].tiempos.length;

                if(vending[0].tiempos.length >= 5){
                    promedioDuracion = 10;
                }

                vending[0].calculo_tiempoLlamada = (promedioDuracion.toFixed(0) < 0 ? 0 : promedioDuracion.toFixed(0)) + ' min.';
                document.querySelector('.tiempLlamada').textContent = vending[0].calculo_tiempoLlamada;

                let minEsp = null;
                let promedioDuracionEsp = 0;

                if(vending[0].enCola_dia.datosCola.length > 0){
                    vending[0].tieposTemp = [...vending[0].tiempos, ...vending[0].enCola_dia.datosCola];
                    minEsp = vending[0].tieposTemp.reduce(function(acc, registro) {
                        const hora1 = registro.dt_fecha_registro ? registro.dt_fecha_registro.split(" ")[1] : registro.dt_fechaRegistro.split(" ")[1];
                        const hora2 = registro.tiempo_espera ? registro.tiempo_espera : registro.dt_fechaSalida;
                        let minutos = 0;
                        if(registro.tiempo_espera || registro.dt_fechaSalida){
                            minutos = diferenciaEnMinutos(hora1.trim(), hora2.trim())
                        }
                        return acc + parseInt(minutos);
                    }, 0);
                    promedioDuracionEsp = minEsp / vending[0].tieposTemp.length;
                }else{
                    minEsp = vending[0].tiempos.reduce(function(acc, registro) {
                        const hora1 = registro.dt_fecha_registro.split(" ")[1];
                        const hora2 = registro.tiempo_espera;
                        let minutos = 0;
                        if(registro.tiempo_espera){
                            minutos = diferenciaEnMinutos(hora1.trim(), hora2.trim())
                        }
                        return acc + parseInt(minutos);
                    }, 0);
                    promedioDuracionEsp = minEsp / vending[0].tiempos.length;
                }
                if(promedioDuracionEsp > 3){
                    promedioDuracionEsp = 2;
                }
                vending[0].calculo_tiempoEspera = promedioDuracionEsp.toFixed(0) + ' min.';

                console.log(vending)
                
                document.querySelector('.tiempEspera').textContent = vending[0].calculo_tiempoEspera;
            }else{
                document.querySelector('.tiempLlamada').textContent = 0+ ' min.';
                document.querySelector('.tiempEspera').textContent = 0 + ' min.';
            }
            
            let perdidas = vending[0].total_perdidas ? vending[0].total_perdidas : 0;

            let calculoPerdidas = (perdidas + (colaDia - colaActual)) - contandoColaActual;
            vending[0].calculo_llamdasPerdidas = calculoPerdidas >3 ? 3 : calculoPerdidas;
            document.querySelector('.llamadasPerdidas').textContent = vending[0].calculo_llamdasPerdidas;

            
            let sumLlamadas = vending[0].calculo_llamAtendidas + vending[0].agendadas + vending[0].calculo_cola +  vending[0].calculo_llamdasPerdidas;
            let calcLLamada = (vending[0].calculo_llamdasPerdidas / sumLlamadas) * 100;

            vending[0].calculo_porcentajeAbandono = (calcLLamada ? calcLLamada.toFixed(2) : 0) + ' %';

            document.querySelector('.procentajeAbandono').textContent = vending[0].calculo_porcentajeAbandono;

            vending[0].calculo_llamRealizadas = vending[0].calculo_llamdasPerdidas + vending[0].calculo_cola + vending[0].calculo_llamAtendidas + vending[0].agendadas;//vending[0].recibidasTotales + encolaTotal;
            document.querySelector('.llamadasRealizadas').textContent = vending[0].calculo_llamRealizadas;

            chart(vending);
            chartBarras(vending);
            $('#carga').fadeOut('fast');
            cargandoContenido = false;
        })
        .catch(error => {
            console.error('Error:', error);
            cargandoContenido = false;
        });
    }

    function get_InfoDash(fecha1, fecha2){
        var data = {
            accion: 'dash_board',
            checkSelect: checkSelect,
            grupo: grupoActual
        };
        if(fecha1 && fecha2){
            data.fecha1 = fecha1;
            data.fecha2 = fecha2;
        }
        return new Promise((resolve, reject) => {
            $.ajax({
                url: 'php/funciones.php',
                type: 'GET',
                dataType: 'json',
                data,
                success: function (response) {
                    resolve(response);
                },
                error: function (xhr, status, error) {
                    reject(error);
                }
            });
        });
    }

    function calcularDatos(registro){
        let tiempoVerificar = registro.tiempo_espera ? registro.tiempo_espera : registro.dt_fecha_registro;
        let fechaBase = new Date(tiempoVerificar);
    
        let diferencias = {};

        if (registro.dt_conexion_Med) {
            diferencias.dt_conexion_Med = diferenciaSegundos(fechaBase, registro.dt_conexion_Med);
        }
        if (registro.dt_fechaFinalizacion_Med) {
            diferencias.dt_fechaFinalizacion_Med = diferenciaSegundos(fechaBase, registro.dt_fechaFinalizacion_Med);
        }
        if (registro.dt_fechaFinalizacion_User) {
            diferencias.dt_fechaFinalizacion_User = diferenciaSegundos(fechaBase, registro.dt_fechaFinalizacion_User);
        }

        //console.log(diferencias)
    
        let claveMasAlejada = null;
        let fechaFinal = null;

        let duracionMinutos = 0;
        if(Object.keys(diferencias).length !== 0){
            claveMasAlejada = Object.keys(diferencias).reduce((a, b) => diferencias[a] > diferencias[b] ? a : b);
            fechaFinal = registro[claveMasAlejada];
        }
        if(tiempoVerificar && Object.keys(diferencias).length !== 0){
            duracionMinutos = cacularTiempo(tiempoVerificar, fechaFinal);
        }
    
        return {
            ...registro,
            fechaFinal: fechaFinal,
            duracionMinutos: duracionMinutos
        };
    }

    function diferenciaSegundos(fechaBase, hora) {
        if (!hora) return Number.MIN_VALUE;
    
        let [h, m, s] = hora.split(":").map(Number);
        let fechaComparar = new Date(fechaBase);
        fechaComparar.setHours(h, m, s);
        return Math.abs((fechaComparar - fechaBase) / 1000);
    }

    function cacularTiempo(tiempoVerificar, fechaFinal){
        let horaMinutos = tiempoVerificar.split(" ")[1] ? tiempoVerificar.split(" ")[1].slice(0, 5) : tiempoVerificar.slice(0, 5);
        let horaMinutosFin = fechaFinal.slice(0, 5);

        let [h1, m1] = horaMinutos.split(":").map(Number);
        let [h2, m2] = horaMinutosFin.split(":").map(Number);

        let minutos1 = h1 * 60 + m1;
        let minutos2 = h2 * 60 + m2;

       return minutos2 - minutos1;
    }

    let chartInstance = null; 

    Highcharts.setOptions({
        colors: Highcharts.getOptions().colors.map(function (color) {
            return {
                radialGradient: {
                    cx: 0.5,
                    cy: 0.3,
                    r: 0.7
                },
                stops: [
                    [0, color],
                    [1, Highcharts.color(color).brighten(-0.3).get('rgb')]
                ]
            };
        }),
        lang: {
            contextButtonTitle: "Opciones de gráfico",
            downloadPNG: "Descargar imagen PNG",
            downloadJPEG: "Descargar imagen JPEG",
            downloadPDF: "Descargar documento PDF",
            downloadSVG: "Descargar imagen SVG",
            printChart: "Imprimir gráfico",
            viewFullscreen: "Ver en pantalla completa",
            exitFullscreen: "Salir de pantalla completa",
            downloadCSV: "Descargar CSV",
            downloadXLS: "Descargar Excel",
            viewData: "Ver tabla de datos",
            openInCloud: "Abrir en Highcharts Cloud"
        }
    });

    function chart(datos) {
        let datosRefactor = {};
        let titulo = '';
    
        if (accionBusqueda == 'principal') {
            titulo = 'Grafica de llamadas';
            datosRefactor = {
                name: 'Llamadas',
                data: [
                    { name: 'Llamadas atendidas', y: datos[0].calculo_llamAtendidas },
                    { name: 'Llamadas Reasignadas', y: datos[0].agendadas },
                    { name: 'Llamadas abandonadas', y: datos[0].calculo_llamdasPerdidas },
                    { name: 'Llamadas en cola', y: datos[0].calculo_cola }
                ]
            };
        }else if(accionBusqueda == 'listar_usuarios'){

            let atendidadas = 0;
            let reasignadas = 0;
            let recibidasAsignacion = 0;
            let llamdasSinContestar = 0;
            let llamadasSinRegistros = 0;
            let totalLlamadas = 0;

            datos.forEach(element => {
                atendidadas+=element.recibidas;
                reasignadas+=element.agendadas;
                recibidasAsignacion+=element.recibidasAgenda;
                llamdasSinContestar+=element.recibidasNoAtendidas;
                llamadasSinRegistros+=element.agendadasNoAtendidas + element.recibidasAgendaNoAtendidas;
            });

            titulo = 'Grafica de llamadas de usuarios';
            datosRefactor = {
                name: 'Llamadas',
                data: [
                    { name: 'Atendidas', y: atendidadas },
                    { name: 'Reasignadas', y: reasignadas },
                    { name: 'Recibidas por asignacion', y: recibidasAsignacion },
                    { name: 'Llamadas sin contestar', y: llamdasSinContestar },
                    { name: 'Llamadas sin registros', y: llamadasSinRegistros},
                ]
            };
        }else if(accionBusqueda == 'listar_registrosD'){

            datos = datos.map((registro)=>{
               return calcularDatos(registro);
            })
        }
    
        let container = document.getElementById("container");
        if (container) {
            container.parentNode.removeChild(container);
        }

        let newContainer = document.createElement("div");
        newContainer.id = "container";
        document.querySelector('.figureContent1').appendChild(newContainer);
    
        chartInstance = Highcharts.chart('container', {
            chart: {
                plotBackgroundColor: null,
                plotBorderWidth: null,
                plotShadow: false,
                type: 'pie'
            },
            title: {
                text: titulo
            },
            tooltip: {
                pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b>'
            },
            accessibility: {
                point: {
                    valueSuffix: '%'
                }
            },
            plotOptions: {
                pie: {
                    allowPointSelect: true,
                    cursor: 'pointer',
                    dataLabels: {
                        enabled: true,
                        format: '<span style="font-size: 1.2em"><b>{point.name}</b>' +
                            '</span><br>' +
                            '<span style="opacity: 0.6">{point.percentage:.1f} ' +
                            '%</span>',
                        connectorColor: 'rgba(128,128,128,0.5)'
                    }
                }
            },
            series: [datosRefactor]
        });
    }


    function chartBarras(datos){

        let datosCola = datos[0].enCola_dia.datosCola ? datos[0].enCola_dia.datosCola : [];
        let reporteLlamadas = datos[0].reporteLlamadas ? datos[0].reporteLlamadas : [];

        const reporteLlamadasTotales = [...datosCola, ...reporteLlamadas];
        console.log(reporteLlamadasTotales)
        let sucursales = [];
        let llamadasPerdidas = [];
        let llamdasReasignadas = [];
        let llamadasAtendidas = [];

        if(reporteLlamadasTotales.length> 0){
            reporteLlamadasTotales.sort((a, b) => a.nomSuc.localeCompare(b.nomSuc));
            console.log('----------------tablas')
            console.log(reporteLlamadas);
            reporteLlamadasTotales.forEach((e)=>{
                let index = 0;
                if(sucursales.includes(e.nomSuc)){
                    index = sucursales.length - 1;
                }else{
                    sucursales.push(e.nomSuc);
                    llamadasPerdidas.push(0);
                    llamdasReasignadas.push(0);
                    llamadasAtendidas.push(0);
                }

                if(e.estatusLlamada == "perdida"){
                    llamadasPerdidas[index] = llamadasPerdidas[index] + 1;
                }
                if(e.estatusLlamada == "contestada" && e.tipo != "REASIGNADAS"){
                    llamadasAtendidas[index] = llamadasAtendidas[index] + 1;
                }

                if(e.estatusLlamada == "contestada" && e.tipo == "REASIGNADAS"){
                    llamdasReasignadas[index] = llamdasReasignadas[index] + 1;
                }
            });
        }else{
            return;
        }

        let containerBarras = document.getElementById("containerBarras");
        if (containerBarras) {
            containerBarras.parentNode.removeChild(containerBarras);
        }

        let newcontainerBarras = document.createElement("div");
        newcontainerBarras.id = "containerBarras";
        document.querySelector('.figureContent2').appendChild(newcontainerBarras);

        Highcharts.chart('containerBarras', {
            chart: {
                type: 'bar'
            },
            title: {
                text: 'Registro llamadas por sucursal'
            },
            xAxis: {
                categories: sucursales,
                title: {
                    text: null
                },
                gridLineWidth: 1,
                lineWidth: 0
            },
            yAxis: {
                min: 0,
                title: {
                    text: 'Teleconsultas',
                    align: 'high'
                },
                labels: {
                    overflow: 'justify'
                },
                gridLineWidth: 0
            },
            plotOptions: {
                bar: {
                    borderRadius: '50%',
                    dataLabels: {
                        enabled: true
                    },
                    groupPadding: 0.1
                }
            },
            legend: {
                layout: 'vertical',
                align: 'right',
                verticalAlign: 'top',
                x: -40,
                y: 80,
                floating: true,
                borderWidth: 1,
                backgroundColor:
                    Highcharts.defaultOptions.legend.backgroundColor || '#FFFFFF',
                shadow: true
            },
            credits: {
                enabled: false
            },
            series: [{
                name: 'Llamadas atendidas',
                data: llamadasAtendidas
            }, {
                name: 'Llamadas reasignadas',
                data: llamdasReasignadas
            }, {
                name: 'Llamadas perdidas',
                data: llamadasPerdidas
            }]
        });
        
        
        
        
        
        
    }

    const contenidoDrop = document.getElementById('contenidoFormulario');

    contenidoDrop.addEventListener('dragover', (e) => {
        e.preventDefault();
    });

    contenidoDrop.addEventListener('drop', (e) => {
        e.preventDefault();

        const archivos = e.dataTransfer.files;
        if (archivos.length > 0) {
            const imagen = archivos[0];
            if(imagen.name == 'dashboard.png'){
                const baseUrl = window.location.href.split('?')[0].replace(/\/[^/]*$/, '/');
                window.open(baseUrl+'adminMedicos/index.php');
            }
        }
    });

    
    
}); 