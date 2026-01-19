<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario']) || !isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// Obtener sede del usuario actual
$sede_usuario = isset($_SESSION['tienda']) ? $_SESSION['tienda'] : '';

// Configuración de conexión SQL Server
$host = 'Jorgeserver.database.windows.net';
$dbname = 'DPL';
$username = 'Jmmc';
$password = 'ChaosSoldier01';

// Variables
$resultados = [];
$total_registros = 0;
$total_cajas = 0;
$mensaje = '';
$error = '';
$filtro_lpn = isset($_GET['lpn']) ? trim($_GET['lpn']) : '';
$filtro_ubicacion = isset($_GET['ubicacion']) ? trim($_GET['ubicacion']) : '';
$filtro_sede = isset($_GET['sede']) ? trim($_GET['sede']) : $sede_usuario;

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
        // Construir consulta con filtros
        $sql = "SELECT 
                    LPN,
                    Ubicacion,
                    Sede,
                    Usuario,
                    COUNT(ID_Caja) as CantidadCajas,
                    MIN(FechaHora) as FechaPrimera,
                    MAX(FechaHora) as FechaUltima
                FROM DPL.pruebas.MasterTable
                WHERE 1=1";
        
        $params = array();
        
        // Aplicar filtro por LPN
        if (!empty($filtro_lpn)) {
            $sql .= " AND LPN LIKE ?";
            $params[] = '%' . $filtro_lpn . '%';
        }
        
        // Aplicar filtro por ubicación
        if (!empty($filtro_ubicacion)) {
            $sql .= " AND Ubicacion LIKE ?";
            $params[] = '%' . $filtro_ubicacion . '%';
        }
        
        // Aplicar filtro por sede
        if (!empty($filtro_sede)) {
            $sql .= " AND Sede = ?";
            $params[] = $filtro_sede;
        }
        
        // Agrupar por LPN y otros campos
        $sql .= " GROUP BY LPN, Ubicacion, Sede, Usuario
                  ORDER BY MAX(FechaHora) DESC, LPN";
        
        // Ejecutar consulta
        $stmt = sqlsrv_prepare($conn, $sql, $params);
        
        if ($stmt && sqlsrv_execute($stmt)) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Formatear fechas
                if ($row['FechaPrimera'] instanceof DateTime) {
                    $row['FechaPrimera'] = $row['FechaPrimera']->format('d/m/Y H:i');
                }
                
                if ($row['FechaUltima'] instanceof DateTime) {
                    $row['FechaUltima'] = $row['FechaUltima']->format('d/m/Y H:i');
                }
                
                $resultados[] = $row;
                $total_cajas += $row['CantidadCajas'];
            }
            $total_registros = count($resultados);
        } else {
            $error = "Error al ejecutar la consulta.";
            if (($errors = sqlsrv_errors()) != null) {
                error_log("Error SQL: " . print_r($errors, true));
            }
        }
        
        sqlsrv_close($conn);
    }
} catch (Exception $e) {
    $error = "Excepción: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Traslados - RANSA</title>

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
        
        /* Estilos para el contenido específico de traslados */
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
            margin-bottom: 5px;
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
        
        /* Panel de filtros compacto */
        .filters-panel {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 15px;
            border: 1px solid #e8e8e8;
        }
        
        .filter-title {
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
        
        .filter-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-field {
            flex: 1;
            min-width: 180px;
        }
        
        .filter-label {
            font-size: 11px;
            font-weight: 600;
            color: #555;
            margin-bottom: 4px;
            display: block;
        }
        
        .form-control-sm {
            font-size: 12px;
            padding: 6px 10px;
            border-radius: 5px;
            border: 1px solid #ddd;
            height: 32px;
        }
        
        .form-control-sm:focus {
            border-color: #009a3f;
            box-shadow: 0 0 0 2px rgba(0, 154, 63, 0.1);
        }
        
        .btn-traslado {
            background: linear-gradient(135deg, #009a3f, #00782f);
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 5px;
            font-weight: 600;
            font-size: 11px;
            transition: all 0.2s ease;
            cursor: pointer;
            height: 32px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-traslado:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0, 154, 63, 0.3);
        }
        
        .btn-secondary-traslado {
            background: #6c757d;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 5px;
            font-weight: 600;
            font-size: 11px;
            transition: all 0.2s ease;
            cursor: pointer;
            height: 32px;
        }
        
        .btn-secondary-traslado:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }
        
        /* Tabla de resultados */
        .results-container {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            margin-bottom: 15px;
            overflow-x: auto;
            border: 1px solid #e8e8e8;
        }
        
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #eee;
        }
        
        .results-title {
            color: #333;
            font-weight: 600;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .results-stats {
            font-size: 11px;
            color: #666;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
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
        
        .badge-count {
            background: #009a3f;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 10px;
            min-width: 24px;
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
        
        .btn-details {
            background: #009a3f;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 10px;
            transition: all 0.2s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        
        .btn-details:hover {
            background: #00782f;
            transform: translateY(-1px);
            color: white;
            text-decoration: none;
        }
        
        /* Alertas */
        .alert-traslado {
            border-radius: 6px;
            border: none;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-size: 11px;
        }
        
        .alert-success-traslado {
            background: rgba(0, 154, 63, 0.1);
            color: #0d4620;
            border-left: 3px solid #009a3f;
        }
        
        .alert-info-traslado {
            background: rgba(0, 154, 63, 0.08);
            color: #0d4620;
            border-left: 3px solid #009a3f;
        }
        
        .alert-warning-traslado {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
            border-left: 3px solid #ffc107;
        }
        
        .alert-danger-traslado {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border-left: 3px solid #dc3545;
        }
        
        /* Instrucciones compactas */
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
        
        /* Info badge en header */
        .info-badge {
            background: #e9f7ef;
            color: #009a3f;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            border: 1px solid #b8e6c9;
        }
        
        /* Loading spinner para filtro automático */
        .search-loading {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
        }
        
        .search-loading.active {
            display: block;
        }
        
        .search-loading i {
            color: #009a3f;
            font-size: 12px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Campo de búsqueda con icono */
        .search-field {
            position: relative;
        }
        
        .search-field input {
            padding-right: 30px;
        }
        
        /* Footer específico */
        .footer-traslado {
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
            
            .filter-group {
                flex-direction: column;
                gap: 8px;
            }
            
            .filter-field {
                min-width: 100%;
            }
            
            .results-table {
                font-size: 11px;
                min-width: 700px; /* Para scroll horizontal en móviles */
            }
            
            .results-table th,
            .results-table td {
                padding: 6px 4px;
            }
            
            .btn-traslado, .btn-secondary-traslado {
                width: 100%;
                justify-content: center;
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
                            <span style="font-size: 12px; margin-left: 4px;">Traslados</span>
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
                                <li class="active">
                                    <a href="translado.php"><i class="fa fa-refresh"></i> Traslado</a>
                                </li>
                                <li>
                                    <a href="reportes.php"><i class="fa fa-bar-chart"></i> Vista</a>
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
                            <div class="x_title">
                                <h2><i class="fa fa-refresh"></i> Búsqueda de Pallets para Traslado</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                

                                <!-- Mensajes -->
                                <?php if (!empty($mensaje)): ?>
                                    <div class="alert-traslado alert-success-traslado">
                                        <i class="fa fa-check-circle"></i> <?php echo $mensaje; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($error)): ?>
                                    <div class="alert-traslado alert-danger-traslado">
                                        <i class="fa fa-exclamation-circle"></i> <?php echo $error; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Panel de Filtros Compacto en una sola fila -->
                                <div class="filters-panel">
                                    <div class="filter-title">
                                        <span><i class="fa fa-filter"></i> Filtros de Búsqueda</span>
                                        <?php if (!empty($filtro_lpn) || !empty($filtro_ubicacion) || !empty($filtro_sede)): ?>
                                            <span class="info-badge">
                                                <i class="fa fa-search"></i> Filtros activos
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <form method="GET" id="filtrosForm">
                                        <div class="filter-group">
                                            <div class="filter-field search-field">
                                                <label class="filter-label">Buscar por LPN:</label>
                                                <input type="text" 
                                                       name="lpn" 
                                                       id="inputLPN"
                                                       class="form-control form-control-sm" 
                                                       placeholder="Ej: PALLET-001"
                                                       value="<?php echo htmlspecialchars($filtro_lpn); ?>"
                                                       autocomplete="off">
                                                <div class="search-loading" id="loadingLPN">
                                                    <i class="fa fa-spinner"></i>
                                                </div>
                                            </div>
                                            
                                            <div class="filter-field search-field">
                                                <label class="filter-label">Buscar por Ubicación:</label>
                                                <input type="text" 
                                                       name="ubicacion" 
                                                       id="inputUbicacion"
                                                       class="form-control form-control-sm" 
                                                       placeholder="Ej: RACK-01-A"
                                                       value="<?php echo htmlspecialchars($filtro_ubicacion); ?>"
                                                       autocomplete="off">
                                                <div class="search-loading" id="loadingUbicacion">
                                                    <i class="fa fa-spinner"></i>
                                                </div>
                                            </div>
                                            
                                            <div class="filter-field">
                                                <label class="filter-label">Filtrar por Sede:</label>
                                                <select name="sede" id="selectSede" class="form-control form-control-sm">
                                                    <option value="">Todas las Sedes</option>
                                                    <option value="LPZ" <?php echo ($filtro_sede == 'LPZ') ? 'selected' : ''; ?>>LPZ - La Paz</option>
                                                    <option value="CBBA" <?php echo ($filtro_sede == 'CBBA') ? 'selected' : ''; ?>>CBBA - Cochabamba</option>
                                                    <option value="SCZ" <?php echo ($filtro_sede == 'SCZ') ? 'selected' : ''; ?>>SCZ - Santa Cruz</option>
                                                </select>
                                            </div>
                                            
                                            <div class="filter-field" style="flex: 0 0 auto; display: flex; gap: 5px;">
                                                <button type="submit" class="btn-traslado" id="btnBuscar">
                                                    <i class="fa fa-search"></i> Buscar
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                                <!-- Resultados - Tabla como vista principal -->
                                <div class="results-container">
                                    <div class="results-header">
                                        <div class="results-title">
                                            <i class="fa fa-table"></i> Resumen de Pallets
                                        </div>
                                        <div class="results-stats">
                                            <?php echo $total_registros; ?> LPNs | <?php echo $total_cajas; ?> cajas
                                            <?php if ($filtro_sede): ?>
                                                <span style="color: #009a3f; margin-left: 5px;">
                                                    <i class="fa fa-filter"></i> Sede: <?php echo $filtro_sede; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($total_registros > 0): ?>
                                        <table class="results-table" id="tablaResultados">
                                            <thead>
                                                <tr>
                                                    <th width="15%">LPN</th>
                                                    <th width="15%">Ubicación</th>
                                                    <th width="8%"># Cajas</th>
                                                    <th width="8%">Sede</th>
                                                    <th width="15%">Usuario</th>
                                                    <th width="12%">Primer Ingreso</th>
                                                    <th width="12%">Último Ingreso</th>
                                                    <th width="15%">Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($resultados as $row): ?>
                                                    <tr>
                                                        <td>
                                                            <strong style="color: #009a3f; font-size: 11px;">
                                                                <i class="fa fa-pallet"></i> <?php echo htmlspecialchars($row['LPN']); ?>
                                                            </strong>
                                                        </td>
                                                        <td>
                                                            <code style="background: #f8f9fa; padding: 2px 6px; border-radius: 3px; font-size: 11px;">
                                                                <?php echo htmlspecialchars($row['Ubicacion']); ?>
                                                            </code>
                                                        </td>
                                                        <td style="text-align: center;">
                                                            <span class="badge-count">
                                                                <?php echo $row['CantidadCajas']; ?>
                                                            </span>
                                                        </td>
                                                        <td style="text-align: center;">
                                                            <?php 
                                                                $sede_class = 'badge-sede ';
                                                                switch($row['Sede']) {
                                                                    case 'LPZ': $sede_class .= 'badge-lpz'; break;
                                                                    case 'CBBA': $sede_class .= 'badge-cbba'; break;
                                                                    case 'SCZ': $sede_class .= 'badge-scz'; break;
                                                                    default: $sede_class .= 'badge-secondary'; break;
                                                                }
                                                            ?>
                                                            <span class="<?php echo $sede_class; ?>">
                                                                <?php echo htmlspecialchars($row['Sede']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <small style="color: #666; font-size: 11px;">
                                                                <i class="fa fa-user"></i> 
                                                                <?php echo htmlspecialchars($row['Usuario']); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <small style="color: #666; font-size: 10px;">
                                                                <i class="fa fa-calendar-plus-o"></i>
                                                                <?php echo $row['FechaPrimera']; ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <small style="color: #009a3f; font-weight: 600; font-size: 10px;">
                                                                <i class="fa fa-calendar-check-o"></i>
                                                                <?php echo $row['FechaUltima']; ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <a href="detalle_lpn.php?lpn=<?php echo urlencode($row['LPN']); ?>&sede=<?php echo urlencode($row['Sede']); ?>" 
                                                               class="btn-details">
                                                                <i class="fa fa-eye"></i> Ver Detalle
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        
                                    <?php else: ?>
                                        <div class="alert-traslado alert-warning-traslado" style="margin-top: 10px;" id="sinResultados">
                                            <i class="fa fa-exclamation-triangle"></i> 
                                            No se encontraron registros con los filtros aplicados.
                                            <?php if (!empty($filtro_lpn) || !empty($filtro_ubicacion)): ?>
                                                <br><small>Intente con otros términos de búsqueda o seleccione "Todas las Sedes".</small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Información del filtro aplicado (solo si hay filtros) -->
                                <?php if (!empty($filtro_lpn) || !empty($filtro_ubicacion) || !empty($filtro_sede)): ?>
                                    <div class="alert-traslado alert-info-traslado" id="infoFiltros">
                                        <i class="fa fa-info-circle"></i> 
                                        <strong>Filtros aplicados:</strong>
                                        <?php 
                                            $filtros = [];
                                            if (!empty($filtro_lpn)) $filtros[] = "<strong>LPN:</strong> '$filtro_lpn'";
                                            if (!empty($filtro_ubicacion)) $filtros[] = "<strong>Ubicación:</strong> '$filtro_ubicacion'";
                                            if (!empty($filtro_sede)) $filtros[] = "<strong>Sede:</strong> '$filtro_sede'";
                                            echo implode(' | ', $filtros);
                                        ?>
                                        <a href="translado.php" style="margin-left: 10px; font-size: 10px;">
                                            <i class="fa fa-times"></i> Limpiar todos
                                        </a>
                                    </div>
                                <?php endif; ?>

                                <!-- Instrucciones compactas -->
                                <div class="instructions">
                                    <h5><i class="fa fa-lightbulb-o"></i> Instrucciones rápidas:</h5>
                                    <ul>
                                        <li><strong>Búsqueda automática:</strong> Filtra automáticamente al escribir en LPN o Ubicación</li>
                                        <li><strong>Cada fila</strong> representa un pallet (LPN) único</li>
                                        <li><strong># Cajas:</strong> Total de cajas en ese pallet</li>
                                        <li><strong>Sede:</strong> Se filtra automáticamente por tu sede (<?php echo $sede_usuario; ?>)</li>
                                        <li><strong>Ctrl+F:</strong> Para buscar rápidamente</li>
                                        <li><strong>Esc:</strong> Limpia el campo de búsqueda actual</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER CON CLASES ESPECÍFICAS -->
            <footer class="footer-traslado">
                <div class="pull-right">
                    <i class="fa fa-calendar"></i> <?php echo date('d/m/Y H:i:s'); ?> | 
                    Sistema de Traslados RANSA v1.0
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
        // Variables para control del filtrado automático
        let timeoutLPN = null;
        let timeoutUbicacion = null;
        let isSubmitting = false;
        const debounceDelay = 500; // 500ms de retraso

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

        // Función para limpiar filtros
        function limpiarFiltros() {
            window.location.href = 'translado.php';
        }

        // Función para mostrar loading
        function mostrarLoading(campo) {
            const loadingElement = document.getElementById(`loading${campo}`);
            if (loadingElement) {
                loadingElement.classList.add('active');
            }
        }

        // Función para ocultar loading
        function ocultarLoading(campo) {
            const loadingElement = document.getElementById(`loading${campo}`);
            if (loadingElement) {
                loadingElement.classList.remove('active');
            }
        }

        // Función para aplicar filtros automáticamente
        function aplicarFiltroAutomatico() {
            if (isSubmitting) return;
            
            isSubmitting = true;
            const btnBuscar = document.getElementById('btnBuscar');
            const textoOriginal = btnBuscar.innerHTML;
            
            btnBuscar.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Buscando...';
            btnBuscar.disabled = true;
            
            document.getElementById('filtrosForm').submit();
        }

        // Event listeners para búsqueda automática en LPN
        document.getElementById('inputLPN').addEventListener('input', function() {
            clearTimeout(timeoutLPN);
            mostrarLoading('LPN');
            
            timeoutLPN = setTimeout(function() {
                aplicarFiltroAutomatico();
            }, debounceDelay);
        });

        // Event listeners para búsqueda automática en Ubicación
        document.getElementById('inputUbicacion').addEventListener('input', function() {
            clearTimeout(timeoutUbicacion);
            mostrarLoading('Ubicacion');
            
            timeoutUbicacion = setTimeout(function() {
                aplicarFiltroAutomatico();
            }, debounceDelay);
        });

        // Auto-submit al cambiar sede
        document.getElementById('selectSede').addEventListener('change', function() {
            aplicarFiltroAutomatico();
        });

        // Limpiar campo al presionar Esc
        document.getElementById('inputLPN').addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                this.value = '';
                aplicarFiltroAutomatico();
            }
        });

        document.getElementById('inputUbicacion').addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                this.value = '';
                aplicarFiltroAutomatico();
            }
        });

        // Auto-focus en el primer campo de búsqueda
        document.addEventListener('DOMContentLoaded', function() {
            const firstInput = document.getElementById('inputLPN');
            if (firstInput) {
                firstInput.focus();
                // Seleccionar texto si ya tiene valor
                if (firstInput.value) {
                    firstInput.select();
                }
            }
        });

        // Submit al presionar Enter en cualquier campo de búsqueda
        document.querySelectorAll('#inputLPN, #inputUbicacion').forEach(input => {
            input.addEventListener('keypress', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    clearTimeout(timeoutLPN);
                    clearTimeout(timeoutUbicacion);
                    aplicarFiltroAutomatico();
                }
            });
        });

        // Atajo de teclado Ctrl+F para buscar
        document.addEventListener('keydown', function(event) {
            if (event.ctrlKey && event.key === 'f') {
                event.preventDefault();
                const searchInput = document.getElementById('inputLPN');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            // Atajo Ctrl+L para limpiar filtros
            if (event.ctrlKey && event.key === 'l') {
                event.preventDefault();
                limpiarFiltros();
            }
        });

        // Mostrar contador de caracteres en tiempo real (opcional)
        document.getElementById('inputLPN').addEventListener('input', function() {
            const valor = this.value.trim();
            if (valor.length > 0) {
                this.style.borderColor = '#009a3f';
                this.style.boxShadow = '0 0 0 2px rgba(0, 154, 63, 0.2)';
            } else {
                this.style.borderColor = '#ddd';
                this.style.boxShadow = 'none';
            }
        });

        document.getElementById('inputUbicacion').addEventListener('input', function() {
            const valor = this.value.trim();
            if (valor.length > 0) {
                this.style.borderColor = '#009a3f';
                this.style.boxShadow = '0 0 0 2px rgba(0, 154, 63, 0.2)';
            } else {
                this.style.borderColor = '#ddd';
                this.style.boxShadow = 'none';
            }
        });

        // Limpiar timeouts cuando se cierra la página
        window.addEventListener('beforeunload', function() {
            clearTimeout(timeoutLPN);
            clearTimeout(timeoutUbicacion);
        });

        // Feedback visual para mostrar que se está filtrando
        function mostrarFeedbackFiltrado() {
            const tabla = document.getElementById('tablaResultados');
            if (tabla) {
                tabla.style.opacity = '0.7';
                tabla.style.transition = 'opacity 0.2s';
                
                setTimeout(() => {
                    if (tabla) {
                        tabla.style.opacity = '1';
                    }
                }, 300);
            }
        }

        // Llamar a mostrarFeedbackFiltrado cuando se inicia el filtrado
        document.getElementById('inputLPN').addEventListener('input', mostrarFeedbackFiltrado);
        document.getElementById('inputUbicacion').addEventListener('input', mostrarFeedbackFiltrado);
        document.getElementById('selectSede').addEventListener('change', mostrarFeedbackFiltrado);
    </script>
</body>
</html>