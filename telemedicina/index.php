<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registros Cabinas medicas</title>
    <link rel="stylesheet" href="css/style.css">
    <!--link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700&display=swap" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/7.3.2/mdb.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/v/bs5/jq-3.7.0/dt-2.0.8/b-3.0.2/datatables.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script-->
    
    
    <!-- Estilos de DataTables y MDB -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700&display=swap" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/7.3.2/mdb.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/v/bs5/jq-3.7.0/dt-2.0.8/b-3.0.2/datatables.min.css" rel="stylesheet">

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Bootstrap y MDB -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.11.6/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM"
        crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/7.3.2/mdb.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/js/solid.min.js"></script>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>

    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>

    <!-- Requerido para exportar a Excel -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

    <!-- Botones para exportar -->
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

    <!-- Exportación a PDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

    <!-- Exportación a Imprimir -->
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">  
    
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <script src="https://code.highcharts.com/highcharts-3d.js"></script> 

    <script src="https://code.highcharts.com/modules/exporting.js"></script>
    <script src="https://code.highcharts.com/modules/export-data.js"></script>
    <script src="https://code.highcharts.com/modules/accessibility.js"></script>

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <script src="https://www.gstatic.com/firebasejs/9.14.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.14.0/firebase-messaging-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.14.0/firebase-firestore-compat.js"></script>


</head>

<body>
    <div id='carga'>
        <img src="imgs/ML_logo.png" alt="MDN" id="img_carga" draggable="false" />
        <div id="precarg">
            <div id="car_circulo"></div>
        </div>
    </div>
    <nav class="navbar navbar-expand-lg navbar-light bg-white topbar mb-4 static-top shadow col-11 m-auto">
        <div class="container-fluid">
            <!-- Botones alineados a la izquierda -->
            <div class="d-flex flex-wrap">
                <div class="contentImgDash">
                    <img src="imgs/dashboard.png">
                </div>

                <a class="btn btn-block rounded-pill w-auto m-2 border-bottom border-primary border-3" id="principal" onclick="cambiarPagina('principal')">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>SCOREBOARD</span>
                </a>
                <a class="btn btn-block rounded-pill w-auto m-2 border-bottom border-primary border-3" id="listar_usuarios" onclick="cambiarPagina('listar_usuarios')">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Registros diario</span>
                </a>
                <a class="btn btn-block rounded-pill w-auto m-2 border-bottom border-primary border-3" id="listar_registrosD" onclick="cambiarPagina('listar_registrosD')">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Tabla de registros</span>
                </a>
            </div>

            <!-- Botón para colapsar en dispositivos pequeños -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarButtons" aria-controls="navbarButtons" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Contenedor de los botones a la derecha -->
            <div class="collapse navbar-collapse justify-content-end" id="navbarButtons">
                <div class="btn-group m-2" role="group" aria-label="Opciones">
                    <!-- Botón All (Naranja) -->
                    <input type="radio" class="btn-check" name="btnradio" id="btnradio1" value="ALL" autocomplete="off" checked>
                    <label class="btn custom-btn all-btn" for="btnradio1">
                        <i class="fas fa-globe"></i> All
                    </label>

                    <!-- Botón APK (Verde Android) -->
                    <input type="radio" class="btn-check" name="btnradio" id="btnradio2" value="APP" autocomplete="off">
                    <label class="btn custom-btn apk-btn" for="btnradio2">
                        <i class="fab fa-android"></i> APP
                    </label>

                    <!-- Botón Kiosco (Azul) -->
                    <input type="radio" class="btn-check" name="btnradio" id="btnradio3" value="Kiosco" autocomplete="off">
                    <label class="btn custom-btn kiosco-btn" for="btnradio3">
                        <i class="fas fa-store"></i> Kiosco
                    </label>
                </div>
            </div>
        </div>
    </nav>

    <div class="container">
    
    <div class="row">
        <div class="col-12 text-center">
            <h1 class="h3 mb-4 text-gray-800 titulo_tabla" style="font-family: 'Press Start 2P', cursive; text-shadow: 3px 3px 5px rgba(0, 0, 0, 0.3); color:rgb(0, 0, 0);">SCOREBOARD</h1>
        </div>
    </div>
    
   
    <div class="row justify-content-center" id="contenidoFormulario">
        <div class="col-12 col-md-8 justify-content-end">
            <form class="form_Buscar_fecha card p-3 border-top border-bottom border-3 border-primary col-12 col-md-8">
                <div class="form-group mb-2 text-center">
                    <h4>Filtrar por fecha</h4>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <label for="fechaBusquedaIn" class="form-label">Fecha de inicio</label>
                        <input type="date" class="form-control fechaRecetaIn" name="fechaBusquedaIn" id="fechaBusquedaIn">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label for="fechaBusquedaFn" class="form-label">Fecha fin</label>
                        <input type="date" class="form-control fechaRecetaFn" name="fechaBusquedaFn" id="fechaBusquedaFn">
                    </div>
                </div>
                <div class="text-center">
                    <button class="btn btn-primary" id="btnBuscarFecha">Filtrar</button>
                </div>
            </form>
        </div>
        <div class="col-12 col-md-4 d-flex flex-column align-items-center gap-3">

            <!-- Botón médico -->
            <button class="btn btn-lg btn-outline-primary shadow rounded-pill px-5 py-3" id="btnMedicos">
                <i class="fas fa-user-md me-2"></i> Médicos
            </button>

            <!-- Base de la pirámide -->
            <div class="d-flex justify-content-between gap-3 mt-2 w-100" style="max-width: 300px;">

                <button class="btn btn-lg btn-outline-success shadow rounded-pill w-100 py-3" id="btnPsicologos">
                    <span class="me-2" style="font-size: 1.4rem;">Ψ</span> Psicólogos
                </button>

                <button class="btn btn-lg btn-outline-warning shadow rounded-pill w-100 py-3" id="btnNutriologos">
                    <i class="fas fa-apple-alt me-2"></i> Nutriólogos
                </button>

            </div>
        </div>

    </div>
</div>



    <?php switch($_GET['pagina'] ){
        case 'principal':
            include ('principal.php');
            break;
        case 'listar_usuarios':
            include ('tablaUsuarios.php');
            break;
        case 'listar_registrosD':
            include ('tablaUsuarios.php');
            break;
        default:
            include ('principal.php');
            break;
     }  ?>
    

    <!--script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.11.6/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM"
        crossorigin="anonymous"></script>
    <script type="text/javascript"
        src="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/7.3.2/mdb.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/js/solid.min.js"></script>

    

    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script-->
    <script src="js/funciones.js"></script>
</body>

</html>