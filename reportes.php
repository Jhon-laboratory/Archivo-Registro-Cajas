<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario']) || !isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// Configuración de conexión SQL Server
$host = 'Jorgeserver.database.windows.net';
$dbname = 'DPL';
$username = 'Jmmc';
$password = 'ChaosSoldier01';

// Variables
$ultimas_modificaciones = [];
$resultados_busqueda = [];
$total_resultados = 0;
$mensaje = '';
$error = '';
$busqueda_lpn = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$busqueda_fecha = isset($_GET['fecha']) ? trim($_GET['fecha']) : '';
$sede_usuario = isset($_SESSION['tienda']) ? $_SESSION['tienda'] : '';

// Conectar a la base de datos
try {
    $connectionInfo = array(
        "Database" => $dbname,
        "UID" => $username,
        "PWD" => $password,
        "CharacterSet" => "UTF-8"
    );
    
    $conn = sqlsrv_connect($host, $connectionInfo);
    
    if ($conn === false) {
        $error = "Error de conexión a la base de datos.";
        error_log("Error SQL Server: " . print_r(sqlsrv_errors(), true));
    } else {
        // Obtener las 10 últimas modificaciones (todas las sedes o solo la del usuario)
        $sql_ultimas = "SELECT TOP 10 
                               LPN, 
                               Accion, 
                               Usuario, 
                               Sede, 
                               CantidadCajas,
                               Ubicacion,
                               FechaHora
                        FROM DPL.pruebas.Historico 
                        WHERE 1=1";
        
        $params_ultimas = array();
        
        // Si el usuario no es administrador, filtrar por su sede
        if ($sede_usuario && $sede_usuario !== 'ADMIN') {
            $sql_ultimas .= " AND Sede = ?";
            $params_ultimas[] = $sede_usuario;
        }
        
        $sql_ultimas .= " ORDER BY FechaHora DESC";
        
        $stmt_ultimas = sqlsrv_prepare($conn, $sql_ultimas, $params_ultimas);
        
        if ($stmt_ultimas && sqlsrv_execute($stmt_ultimas)) {
            while ($row = sqlsrv_fetch_array($stmt_ultimas, SQLSRV_FETCH_ASSOC)) {
                // Formatear fecha
                if ($row['FechaHora'] instanceof DateTime) {
                    $row['FechaHora'] = $row['FechaHora']->format('d/m/Y H:i:s');
                }
                $ultimas_modificaciones[] = $row;
            }
        }
        
        // Si hay búsqueda, buscar en el histórico
        if (!empty($busqueda_lpn) || !empty($busqueda_fecha)) {
            $sql_busqueda = "SELECT 
                                    LPN, 
                                    Accion, 
                                    Usuario, 
                                    Sede, 
                                    CantidadCajas,
                                    Ubicacion,
                                    FechaHora
                             FROM DPL.pruebas.Historico 
                             WHERE 1=1";
            
            $params_busqueda = array();
            
            // Filtro por LPN
            if (!empty($busqueda_lpn)) {
                $sql_busqueda .= " AND LPN LIKE ?";
                $params_busqueda[] = '%' . $busqueda_lpn . '%';
            }
            
            // Filtro por fecha (buscar por día)
            if (!empty($busqueda_fecha)) {
                try {
                    $fecha_busqueda = DateTime::createFromFormat('Y-m-d', $busqueda_fecha);
                    if ($fecha_busqueda) {
                        $fecha_inicio = $fecha_busqueda->format('Y-m-d') . ' 00:00:00';
                        $fecha_fin = $fecha_busqueda->format('Y-m-d') . ' 23:59:59';
                        
                        $sql_busqueda .= " AND FechaHora >= ? AND FechaHora <= ?";
                        $params_busqueda[] = $fecha_inicio;
                        $params_busqueda[] = $fecha_fin;
                    }
                } catch (Exception $e) {
                    // Si la fecha no tiene formato válido, ignorar
                }
            }
            
            // Filtrar por sede si no es administrador
            if ($sede_usuario && $sede_usuario !== 'ADMIN') {
                $sql_busqueda .= " AND Sede = ?";
                $params_busqueda[] = $sede_usuario;
            }
            
            $sql_busqueda .= " ORDER BY FechaHora DESC";
            
            $stmt_busqueda = sqlsrv_prepare($conn, $sql_busqueda, $params_busqueda);
            
            if ($stmt_busqueda && sqlsrv_execute($stmt_busqueda)) {
                while ($row = sqlsrv_fetch_array($stmt_busqueda, SQLSRV_FETCH_ASSOC)) {
                    // Formatear fecha
                    if ($row['FechaHora'] instanceof DateTime) {
                        $row['FechaHora'] = $row['FechaHora']->format('d/m/Y H:i:s');
                    }
                    $resultados_busqueda[] = $row;
                }
                $total_resultados = count($resultados_busqueda);
            }
        }
        
        sqlsrv_close($conn);
    }
} catch (Exception $e) {
    $error = "Excepción: " . $e->getMessage();
}

// Mostrar mensaje si viene por parámetro
if (isset($_GET['msg'])) {
    $mensaje = urldecode($_GET['msg']);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reportes - RANSA</title>

    <!-- CSS del template EXACTAMENTE IGUAL QUE ransa_main.php -->
    <link href="vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="vendors/nprogress/nprogress.css" rel="stylesheet">
    <link href="vendors/iCheck/skins/flat/green.css" rel="stylesheet">
    <link href="vendors/select2/dist/css/select2.min.css" rel="stylesheet">
    <link href="vendors/bootstrap-progressbar/css/bootstrap-progressbar-3.3.4.min.css" rel="stylesheet">
    <link href="vendors/datatables.net-bs/css/dataTables.bootstrap.min.css" rel="stylesheet">
    <link href="build/css/custom.min.css" rel="stylesheet">

    <!-- CSS ESPECÍFICO SOLO PARA EL CONTENIDO (NO toca el menú) -->
    <style>
        /* Fondo específico para esta página */
        body.nav-md {
            background: linear-gradient(rgba(255, 255, 255, 0.97), rgba(255, 255, 255, 0.97)), 
                        url('img/imglogin.jpg') center/cover no-repeat fixed;
            min-height: 100vh;
        }
        
        /* Estilos para el contenido específico de reportes */
        .welcome-section {
            background: linear-gradient(135deg, rgba(0, 154, 63, 1), rgba(0, 154, 63, 0.8));
            color: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 15px;
            box-shadow: 0 4px 12px rgba(0, 154, 63, 0.2);
        }
        
        .welcome-title {
            font-weight: 700;
            font-size: 1.4rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .user-info-card {
            background: rgba(255, 255, 255, 0.95);
            color: #333;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 11px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Panel de búsqueda */
        .search-panel {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 15px;
            border: 1px solid #e8e8e8;
        }
        
        .search-title {
            color: #333;
            font-weight: 600;
            margin-bottom: 12px;
            font-size: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 8px;
            border-bottom: 1px solid #eee;
        }
        
        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .form-control {
            font-size: 12px;
            padding: 8px 12px;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        
        .form-control:focus {
            border-color: #009a3f;
            box-shadow: 0 0 0 2px rgba(0, 154, 63, 0.1);
        }
        
        .btn-reporte {
            background: linear-gradient(135deg, #009a3f, #00782f);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.2s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-reporte:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0, 154, 63, 0.3);
        }
        
        .btn-secondary-reporte {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .btn-secondary-reporte:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }
        
        /* Tablas de resultados */
        .results-container {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            margin-bottom: 15px;
            overflow-x: auto;
            border: 1px solid #e8e8e8;
        }
        
        .section-title {
            color: #333;
            font-weight: 600;
            margin-bottom: 12px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid #eee;
        }
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        
        .results-table th {
            background: linear-gradient(135deg, rgba(0, 154, 63, 0.9), rgba(0, 154, 63, 0.7));
            color: white;
            padding: 10px 8px;
            text-align: left;
            font-weight: 600;
            border: none;
            position: sticky;
            top: 0;
            white-space: nowrap;
        }
        
        .results-table td {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        
        .results-table tr:hover {
            background-color: #f9f9f9;
        }
        
        /* Badges */
        .badge-count {
            background: #009a3f;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 11px;
            text-align: center;
            display: inline-block;
        }
        
        .badge-sede {
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 10px;
            text-align: center;
            display: inline-block;
        }
        
        .badge-lpz {
            background: #28a745;
            color: white;
        }
        
        .badge-cbba {
            background: #dc3545;
            color: white;
        }
        
        .badge-scz {
            background: #ffc107;
            color: #212529;
        }
        
        /* Alertas */
        .alert-reporte {
            border-radius: 6px;
            border: none;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-size: 11px;
        }
        
        .alert-success-reporte {
            background: rgba(0, 154, 63, 0.1);
            color: #0d4620;
            border-left: 3px solid #009a3f;
        }
        
        .alert-info-reporte {
            background: rgba(23, 162, 184, 0.1);
            color: #0c5460;
            border-left: 3px solid #17a2b8;
        }
        
        .alert-warning-reporte {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
            border-left: 3px solid #ffc107;
        }
        
        .alert-danger-reporte {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border-left: 3px solid #dc3545;
        }
        
        /* Instrucciones */
        .instructions {
            background: #f0f8ff;
            border-left: 3px solid #009a3f;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 11px;
            margin-top: 10px;
        }
        
        .instructions h5 {
            color: #009a3f;
            margin-bottom: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .instructions ul {
            padding-left: 15px;
            margin-bottom: 0;
        }
        
        .instructions li {
            margin-bottom: 2px;
            line-height: 1.3;
        }
        
        /* Info badge */
        .info-badge {
            background: #e9f7ef;
            color: #009a3f;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            border: 1px solid #b8e6c9;
        }
        
        /* Footer específico */
        .footer-reporte {
            margin-top: 15px;
            padding: 12px;
            background: rgba(0, 154, 63, 0.05);
            border-radius: 6px;
            font-size: 10px;
            border-top: 1px solid #e0e0e0;
        }
        
        /* Responsive ESPECÍFICO para la tabla (NO afecta el menú) */
        @media (max-width: 768px) {
            .welcome-title {
                font-size: 1.2rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .search-form {
                grid-template-columns: 1fr;
            }
            
            .results-table {
                font-size: 11px;
                min-width: 700px; /* Para scroll horizontal en móviles */
            }
            
            .results-table th,
            .results-table td {
                padding: 6px 4px;
            }
            
            .btn-reporte, .btn-secondary-reporte {
                width: 100%;
                justify-content: center;
                margin-bottom: 5px;
            }
            
            .results-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        }
    </style>
</head>

<body class="nav-md">
    <div class="container body">
        <div class="main_container">
            <!-- SIDEBAR EXACTAMENTE IGUAL QUE ransa_main.php -->
            <div class="col-md-3 left_col">
                <div class="left_col scroll-view">
                    <div class="navbar nav_title" style="border: 0;">
                        <a href="ransa_main.php" class="site_title">
                            <img src="img/logo.png" alt="RANSA Logo" style="height: 32px;">
                            <span style="font-size: 12px; margin-left: 4px;">Reportes</span>
                        </a>
                    </div>
                    <div class="clearfix"></div>

                    <!-- Información del usuario -->
                    <div class="profile clearfix">
                        <div class="profile_info">
                            <span>Bienvenido,</span>
                            <h2><?php echo $_SESSION['usuario'] ?? 'Usuario'; ?></h2>
                            <span><?php echo $_SESSION['correo'] ?? ''; ?></span>
                        </div>
                    </div>

                    <br />

                    <!-- MENU EXACTAMENTE IGUAL -->
                    <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">
                        <div class="menu_section">
                            <h3>Navegación</h3>
                            <ul class="nav side-menu">
                                <li>
                                    <a href="ransa_main.php"><i class="fa fa-line-chart"></i> Dashboard</a>
                                </li>
                                <li>
                                    <a href="ingreso.php"><i class="fa fa-archive"></i> Ingreso</a>
                                </li>
                                <li>
                                    <a href="translado.php"><i class="fa fa-refresh"></i> Traslado</a>
                                </li>
                                <li class="active">
                                    <a href="reportes.php"><i class="fa fa-bar-chart"></i> Reportes</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- FOOTER EXACTAMENTE IGUAL -->
                    <div class="sidebar-footer hidden-small">
                        <a title="Actualizar" data-toggle="tooltip" data-placement="top" onclick="location.reload()">
                            <span class="glyphicon glyphicon-refresh"></span>
                        </a>
                        <a title="Salir" data-toggle="tooltip" data-placement="top" onclick="cerrarSesion()">
                            <span class="glyphicon glyphicon-off"></span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- NAVBAR SUPERIOR EXACTAMENTE IGUAL -->
            <div class="top_nav">
                <div class="nav_menu">
                    <div class="nav toggle">
                        <a id="menu_toggle"><i class="fa fa-bars"></i></a>
                    </div>
                    <div class="nav navbar-nav navbar-right">
                        <span style="color: white; padding: 15px; font-weight: 600;">
                            <i class="fa fa-user-circle"></i> 
                            <?php echo $_SESSION['usuario'] ?? 'Usuario'; ?>
                            <small style="opacity: 0.8; margin-left: 10px;">
                                <i class="fa fa-map-marker"></i> 
                                <?php echo $_SESSION['tienda'] ?? 'N/A'; ?>
                            </small>
                        </span>
                    </div>
                </div>
            </div>

            <!-- CONTENIDO PRINCIPAL CON CLASES DEL TEMPLATE -->
            <div class="right_col" role="main">
                
                
                <div class="clearfix"></div>
                
                <div class="row">
                    <div class="col-md-12 col-sm-12">
                        <div class="x_panel">
                            
                            <div class="x_content">
                                

                                <!-- Mensajes -->
                                <?php if (!empty($mensaje)): ?>
                                    <div class="alert-reporte alert-success-reporte">
                                        <i class="fa fa-check-circle"></i> <?php echo $mensaje; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($error)): ?>
                                    <div class="alert-reporte alert-danger-reporte">
                                        <i class="fa fa-exclamation-circle"></i> <?php echo $error; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Panel de Búsqueda -->
                                <div class="search-panel">
                                    <div class="search-title">
                                        <span><i class="fa fa-search"></i> Búsqueda de Registros</span>
                                        <?php if (!empty($busqueda_lpn) || !empty($busqueda_fecha)): ?>
                                            <span class="info-badge">
                                                <i class="fa fa-filter"></i> Búsqueda activa
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <form method="GET" id="searchForm">
                                        <div class="search-form">
                                            <div>
                                                <label>Buscar por LPN:</label>
                                                <input type="text" 
                                                       name="busqueda" 
                                                       id="inputBusqueda"
                                                       class="form-control" 
                                                       placeholder="Ej: PALLET-001"
                                                       value="<?php echo htmlspecialchars($busqueda_lpn); ?>"
                                                       autocomplete="off">
                                            </div>
                                            
                                            <div>
                                                <label>Buscar por Fecha:</label>
                                                <input type="date" 
                                                       name="fecha" 
                                                       id="inputFecha"
                                                       class="form-control" 
                                                       value="<?php echo htmlspecialchars($busqueda_fecha); ?>">
                                                <small style="display: block; color: #666; margin-top: 4px; font-size: 10px;">
                                                    Formato: AAAA-MM-DD
                                                </small>
                                            </div>
                                            
                                            <div style="display: flex; gap: 10px; align-items: center;">
                                                <button type="submit" class="btn-reporte">
                                                    <i class="fa fa-search"></i> Buscar
                                                </button>
                                                <?php if (!empty($busqueda_lpn) || !empty($busqueda_fecha)): ?>
                                                    <a href="reportes.php" class="btn-secondary-reporte">
                                                        <i class="fa fa-times"></i> Limpiar
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                                <!-- Resultados de Búsqueda (si hay búsqueda) -->
                                <?php if (!empty($busqueda_lpn) || !empty($busqueda_fecha)): ?>
                                    <div class="results-container">
                                        <div class="section-title">
                                            <i class="fa fa-search"></i> Resultados de Búsqueda
                                            <span style="margin-left: auto; font-size: 11px; color: #666;">
                                                <?php echo $total_resultados; ?> registro(s) encontrado(s)
                                            </span>
                                        </div>
                                        
                                        <?php if ($total_resultados > 0): ?>
                                            <table class="results-table">
                                                <thead>
                                                    <tr>
                                                        <th width="12%">Fecha/Hora</th>
                                                        <th width="12%">LPN</th>
                                                        <th width="8%"># Cajas</th>
                                                        <th width="12%">Ubicación</th>
                                                        <th width="12%">Usuario</th>
                                                        <th width="12%">Sede</th>
                                                        <th width="32%">Acción Realizada</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($resultados_busqueda as $registro): ?>
                                                        <tr>
                                                            <td>
                                                                <small style="color: #666;">
                                                                    <?php echo htmlspecialchars($registro['FechaHora']); ?>
                                                                </small>
                                                            </td>
                                                            <td>
                                                                <strong style="color: #009a3f;">
                                                                    <?php echo htmlspecialchars($registro['LPN']); ?>
                                                                </strong>
                                                            </td>
                                                            <td style="text-align: center;">
                                                                <span class="badge-count">
                                                                    <?php echo $registro['CantidadCajas']; ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <code style="background: #f8f9fa; padding: 2px 6px; border-radius: 3px;">
                                                                    <?php echo htmlspecialchars($registro['Ubicacion']); ?>
                                                                </code>
                                                            </td>
                                                            <td>
                                                                <small>
                                                                    <i class="fa fa-user"></i> 
                                                                    <?php echo htmlspecialchars($registro['Usuario']); ?>
                                                                </small>
                                                            </td>
                                                            <td style="text-align: center;">
                                                                <span class="badge-sede badge-<?php echo strtolower($registro['Sede']); ?>">
                                                                    <?php echo htmlspecialchars($registro['Sede']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <small style="color: #555;">
                                                                    <?php echo htmlspecialchars($registro['Accion']); ?>
                                                                </small>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                            
                                            <!-- Información de la búsqueda -->
                                            <div class="alert-reporte alert-info-reporte" style="margin-top: 15px;">
                                                <i class="fa fa-info-circle"></i> 
                                                <strong>Búsqueda realizada:</strong>
                                                <?php 
                                                    $filtros = [];
                                                    if (!empty($busqueda_lpn)) $filtros[] = "<strong>LPN:</strong> '$busqueda_lpn'";
                                                    if (!empty($busqueda_fecha)) $filtros[] = "<strong>Fecha:</strong> '$busqueda_fecha'";
                                                    echo implode(' | ', $filtros);
                                                ?>
                                            </div>
                                            
                                        <?php else: ?>
                                            <div class="alert-reporte alert-warning-reporte">
                                                <i class="fa fa-exclamation-triangle"></i> 
                                                No se encontraron registros con los criterios de búsqueda.
                                                <?php if ($sede_usuario !== 'ADMIN'): ?>
                                                    <br><small>Mostrando solo registros de su sede: <?php echo $sede_usuario; ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Últimas 10 Modificaciones -->
                                <div class="results-container">
                                    <div class="section-title">
                                        <i class="fa fa-clock-o"></i> Últimas 10 Modificaciones
                                        <span style="margin-left: auto; font-size: 11px; color: #666;">
                                            <?php echo count($ultimas_modificaciones); ?> registro(s)
                                            <?php if ($sede_usuario !== 'ADMIN'): ?>
                                                <span style="color: #009a3f; margin-left: 5px;">
                                                    <i class="fa fa-filter"></i> Sede: <?php echo $sede_usuario; ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($ultimas_modificaciones)): ?>
                                        <table class="results-table">
                                            <thead>
                                                <tr>
                                                    <th width="12%">Fecha/Hora</th>
                                                    <th width="12%">LPN</th>
                                                    <th width="8%"># Cajas</th>
                                                    <th width="12%">Ubicación</th>
                                                    <th width="12%">Usuario</th>
                                                    <th width="12%">Sede</th>
                                                    <th width="32%">Acción Realizada</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($ultimas_modificaciones as $registro): ?>
                                                    <tr>
                                                        <td>
                                                            <small style="color: #666;">
                                                                <?php echo htmlspecialchars($registro['FechaHora']); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <strong style="color: #009a3f;">
                                                                <?php echo htmlspecialchars($registro['LPN']); ?>
                                                            </strong>
                                                        </td>
                                                        <td style="text-align: center;">
                                                            <span class="badge-count">
                                                                <?php echo $registro['CantidadCajas']; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <code style="background: #f8f9fa; padding: 2px 6px; border-radius: 3px;">
                                                                <?php echo htmlspecialchars($registro['Ubicacion']); ?>
                                                            </code>
                                                        </td>
                                                        <td>
                                                            <small>
                                                                <i class="fa fa-user"></i> 
                                                                <?php echo htmlspecialchars($registro['Usuario']); ?>
                                                            </small>
                                                        </td>
                                                        <td style="text-align: center;">
                                                            <span class="badge-sede badge-<?php echo strtolower($registro['Sede']); ?>">
                                                                <?php echo htmlspecialchars($registro['Sede']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <small style="color: #555;">
                                                                <?php echo htmlspecialchars($registro['Accion']); ?>
                                                            </small>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        
                                        <!-- Información adicional -->
                                        <div class="alert-reporte alert-info-reporte" style="margin-top: 15px;">
                                            <i class="fa fa-info-circle"></i> 
                                            <strong>Información:</strong> Mostrando las últimas 10 modificaciones del sistema.
                                            <?php if ($sede_usuario !== 'ADMIN'): ?>
                                                <span style="margin-left: 10px;">
                                                    <i class="fa fa-shield"></i> Vista restringida a su sede.
                                                </span>
                                            <?php else: ?>
                                                <span style="margin-left: 10px;">
                                                    <i class="fa fa-shield"></i> Vista de administrador (todas las sedes).
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                    <?php else: ?>
                                        <div class="alert-reporte alert-warning-reporte">
                                            <i class="fa fa-exclamation-triangle"></i> 
                                            No hay registros de modificaciones recientes.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER CON CLASES ESPECÍFICAS -->
            <footer class="footer-reporte">
                <div class="pull-right">
                    <i class="fa fa-calendar"></i> <?php echo date('d/m/Y H:i:s'); ?> | 
                    Sistema de Reportes RANSA v1.0 | 
                    <?php echo $sede_usuario === 'ADMIN' ? 'Vista Administrador' : 'Sede: ' . $sede_usuario; ?>
                </div>
                <div class="clearfix"></div>
            </footer>
        </div>
    </div>

    <!-- SCRIPTS EXACTAMENTE IGUALES QUE ransa_main.php -->
    <script src="vendors/jquery/dist/jquery.min.js"></script>
    <script src="vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="vendors/fastclick/lib/fastclick.js"></script>
    <script src="vendors/nprogress/nprogress.js"></script>
    <script src="build/js/custom.min.js"></script>

    <script>
        // Función para cerrar sesión
        function cerrarSesion() {
            if (confirm('¿Está seguro de que desea cerrar sesión?')) {
                window.location.href = 'logout.php';
            }
        }

        // Toggle del menú
        document.getElementById('menu_toggle').addEventListener('click', function() {
            const leftCol = document.querySelector('.left_col');
            leftCol.classList.toggle('menu-open');
        });

        // Auto-focus en el campo de búsqueda al cargar
        document.addEventListener('DOMContentLoaded', function() {
            const inputBusqueda = document.getElementById('inputBusqueda');
            if (inputBusqueda) {
                setTimeout(() => {
                    inputBusqueda.focus();
                    // Si ya tiene valor, seleccionarlo
                    if (inputBusqueda.value) {
                        inputBusqueda.select();
                    }
                }, 100);
            }
            
            // Configurar fecha mínima/máxima para el input de fecha
            const inputFecha = document.getElementById('inputFecha');
            if (inputFecha) {
                const hoy = new Date().toISOString().split('T')[0];
                // Permitir fechas hasta hoy
                inputFecha.max = hoy;
                
                // Establecer placeholder
                if (!inputFecha.value) {
                    inputFecha.placeholder = 'Ej: <?php echo date("Y-m-d"); ?>';
                }
            }
        });

        // Validación del formulario de búsqueda
        document.getElementById('searchForm').addEventListener('submit', function(e) {
            const inputBusqueda = document.getElementById('inputBusqueda');
            const inputFecha = document.getElementById('inputFecha');
            
            // Verificar que al menos un campo tenga valor
            if (!inputBusqueda.value.trim() && !inputFecha.value) {
                alert('Por favor, ingrese al menos un criterio de búsqueda (LPN o Fecha).');
                e.preventDefault();
                inputBusqueda.focus();
                return false;
            }
            
            // Validar formato de fecha si se ingresó
            if (inputFecha.value) {
                const fechaRegex = /^\d{4}-\d{2}-\d{2}$/;
                if (!fechaRegex.test(inputFecha.value)) {
                    alert('Por favor, ingrese la fecha en formato AAAA-MM-DD (ej: <?php echo date("Y-m-d"); ?>).');
                    e.preventDefault();
                    inputFecha.focus();
                    inputFecha.select();
                    return false;
                }
            }
            
            // Mostrar mensaje de búsqueda en proceso
            const btnSubmit = this.querySelector('button[type="submit"]');
            const textoOriginal = btnSubmit.innerHTML;
            btnSubmit.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Buscando...';
            btnSubmit.disabled = true;
            
            // Restaurar botón después de 5 segundos (por si hay error)
            setTimeout(() => {
                btnSubmit.innerHTML = textoOriginal;
                btnSubmit.disabled = false;
            }, 5000);
        });

        // Atajos de teclado
        document.addEventListener('keydown', function(event) {
            // Ctrl+F para buscar
            if (event.ctrlKey && event.key === 'f') {
                event.preventDefault();
                const inputBusqueda = document.getElementById('inputBusqueda');
                if (inputBusqueda) {
                    inputBusqueda.focus();
                    inputBusqueda.select();
                }
            }
            
            // Ctrl+L para limpiar búsqueda
            if (event.ctrlKey && event.key === 'l') {
                event.preventDefault();
                if (window.location.search) {
                    window.location.href = 'reportes.php';
                }
            }
            
            // Enter en campo de búsqueda para enviar
            if (event.key === 'Enter' && event.target.id === 'inputBusqueda') {
                event.preventDefault();
                document.getElementById('searchForm').submit();
            }
            
            // Esc para limpiar campo activo
            if (event.key === 'Escape') {
                const elementoActivo = document.activeElement;
                if (elementoActivo.tagName === 'INPUT') {
                    if (elementoActivo.id === 'inputBusqueda' || elementoActivo.id === 'inputFecha') {
                        elementoActivo.value = '';
                    }
                }
            }
        });

        // Función para exportar resultados (opcional - para futura implementación)
        function exportarResultados() {
            const resultados = <?php echo json_encode($resultados_busqueda ?: $ultimas_modificaciones); ?>;
            if (resultados.length === 0) {
                alert('No hay datos para exportar.');
                return;
            }
            
            let csvContent = "data:text/csv;charset=utf-8,";
            
            // Encabezados
            csvContent += "Fecha/Hora,LPN,Cantidad Cajas,Ubicación,Usuario,Sede,Acción\n";
            
            // Datos
            resultados.forEach(function(row) {
                const fecha = row.FechaHora || '';
                const lpn = row.LPN || '';
                const cantidad = row.CantidadCajas || '';
                const ubicacion = row.Ubicacion || '';
                const usuario = row.Usuario || '';
                const sede = row.Sede || '';
                const accion = (row.Accion || '').replace(/"/g, '""');
                
                csvContent += `"${fecha}","${lpn}","${cantidad}","${ubicacion}","${usuario}","${sede}","${accion}"\n`;
            });
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            
            // Nombre del archivo
            const fechaActual = new Date().toISOString().split('T')[0];
            let nombreArchivo = `reporte_ransa_${fechaActual}`;
            if (<?php echo !empty($busqueda_lpn) ? 'true' : 'false'; ?>) {
                nombreArchivo += `_lpn_<?php echo $busqueda_lpn; ?>`;
            }
            nombreArchivo += ".csv";
            
            link.setAttribute("download", nombreArchivo);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Añadir botón de exportación si hay resultados
        document.addEventListener('DOMContentLoaded', function() {
            const totalRegistros = <?php echo $total_resultados ?: count($ultimas_modificaciones); ?>;
            if (totalRegistros > 0) {
                const sectionTitle = document.querySelector('.section-title');
                if (sectionTitle) {
                    const exportButton = document.createElement('button');
                    exportButton.className = 'btn-secondary-reporte';
                    exportButton.style.marginLeft = 'auto';
                    exportButton.style.fontSize = '10px';
                    exportButton.innerHTML = '<i class="fa fa-download"></i> Exportar CSV';
                    exportButton.onclick = exportarResultados;
                    sectionTitle.appendChild(exportButton);
                }
            }
        });

        // Validación en tiempo real para campos de búsqueda
        const inputBusqueda = document.getElementById('inputBusqueda');
        const inputFecha = document.getElementById('inputFecha');
        
        if (inputBusqueda) {
            inputBusqueda.addEventListener('input', function() {
                const valor = this.value.trim();
                if (valor) {
                    this.style.borderColor = '#009a3f';
                    this.style.boxShadow = '0 0 0 2px rgba(0, 154, 63, 0.2)';
                } else {
                    this.style.borderColor = '#ddd';
                    this.style.boxShadow = 'none';
                }
            });
        }
        
        if (inputFecha) {
            inputFecha.addEventListener('change', function() {
                if (this.value) {
                    this.style.borderColor = '#009a3f';
                    this.style.boxShadow = '0 0 0 2px rgba(0, 154, 63, 0.2)';
                } else {
                    this.style.borderColor = '#ddd';
                    this.style.boxShadow = 'none';
                }
            });
        }

        // Auto-submit al cambiar fecha (opcional)
        if (inputFecha) {
            inputFecha.addEventListener('change', function() {
                if (this.value && !inputBusqueda.value.trim()) {
                    document.getElementById('searchForm').submit();
                }
            });
        }

        // Función para recargar automáticamente cada 2 minutos
        setTimeout(function() {
            if (!document.querySelector('.alert-reporte.alert-success-reporte')) {
                location.reload();
            }
        }, 120000); // 120000 ms = 2 minutos
    </script>
</body>
</html>