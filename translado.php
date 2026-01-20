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
$resultados = [];
$total_registros = 0;
$total_cajas = 0;
$mensaje = '';
$error = '';

// NUEVO: Filtros separados por cliente y número de caja
$filtro_cliente = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
$filtro_num_caja = isset($_GET['num_caja']) ? trim($_GET['num_caja']) : '';
$filtro_lpn = isset($_GET['lpn']) ? trim($_GET['lpn']) : '';
$filtro_ubicacion = isset($_GET['ubicacion']) ? trim($_GET['ubicacion']) : '';

// Variable para controlar si se debe mostrar datos
$mostrar_datos = false;

// Solo consultar la base de datos si hay al menos un filtro activo
if (!empty($filtro_cliente) || !empty($filtro_num_caja) || !empty($filtro_lpn) || !empty($filtro_ubicacion)) {
    $mostrar_datos = true;
    
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
            
            // NUEVO: Aplicar filtro por código de cliente (antes de la C)
            if (!empty($filtro_cliente)) {
                // Buscar ID_Caja que empiece con el código del cliente seguido de 'C' o 'c'
                $sql .= " AND (ID_Caja LIKE ? OR ID_Caja LIKE ?)";
                $params[] = $filtro_cliente . 'C%';
                $params[] = $filtro_cliente . 'c%';
            }
            
            // NUEVO: Aplicar filtro por número de caja (después de la C)
            if (!empty($filtro_num_caja)) {
                // Buscar ID_Caja que contenga 'C' o 'c' seguido del número de caja
                $sql .= " AND (ID_Caja LIKE ? OR ID_Caja LIKE ?)";
                $params[] = '%C' . $filtro_num_caja . '%';
                $params[] = '%c' . $filtro_num_caja . '%';
            }
            
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
            min-width: 160px;
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
            border-color = #009a3f;
            box-shadow: 0 0 0 2px rgba(0, 154, 63, 0.1);
        }
        
        /* Estilos específicos para campos de cliente y número de caja */
        .caja-filters {
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex: 2;
            min-width: 300px;
        }
        
        .caja-filters .filter-field {
            flex: 1;
        }
        
        .separator-c {
            color: #dc3545;
            font-weight: bold;
            font-size: 14px;
            margin: 0 2px;
            line-height: 32px;
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
        
        /* Mensaje de bienvenida inicial */
        .welcome-message {
            background: #f0f8ff;
            border-left: 4px solid #009a3f;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .welcome-message i {
            font-size: 24px;
            color: #009a3f;
            margin-bottom: 10px;
            display: block;
        }
        
        .welcome-message h4 {
            color: #333;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .welcome-message p {
            color: #666;
            font-size: 12px;
            margin-bottom: 5px;
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
            
            .caja-filters {
                min-width: 100%;
                flex-direction: column;
                gap: 8px;
            }
            
            .separator-c {
                display: none;
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

                                <!-- Panel de Filtros Compacto -->
                                <div class="filters-panel">
                                    <div class="filter-title">
                                        <span><i class="fa fa-filter"></i> Filtros de Búsqueda</span>
                                        <?php if (!empty($filtro_cliente) || !empty($filtro_num_caja) || !empty($filtro_lpn) || !empty($filtro_ubicacion)): ?>
                                            <span class="info-badge">
                                                <i class="fa fa-search"></i> Búsqueda activa
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <form method="GET" id="filtrosForm">
                                        <div class="filter-group">
                                            <!-- Filtros separados para cliente y número de caja -->
                                            <div class="caja-filters">
                                                <div class="filter-field search-field">
                                                    <label class="filter-label">Código Cliente:</label>
                                                    <input type="text" 
                                                           name="cliente" 
                                                           id="inputCliente"
                                                           class="form-control form-control-sm" 
                                                           placeholder="Ej: 0000123"
                                                           value="<?php echo htmlspecialchars($filtro_cliente); ?>"
                                                           autocomplete="off"
                                                           maxlength="20">
                                                    <div class="search-loading" id="loadingCliente">
                                                        <i class="fa fa-spinner"></i>
                                                    </div>
                                                </div>
                                                
                                                <div class="separator-c">C</div>
                                                
                                                <div class="filter-field search-field">
                                                    <label class="filter-label">Número Caja:</label>
                                                    <input type="text" 
                                                           name="num_caja" 
                                                           id="inputNumCaja"
                                                           class="form-control form-control-sm" 
                                                           placeholder="Ej: 0000001850"
                                                           value="<?php echo htmlspecialchars($filtro_num_caja); ?>"
                                                           autocomplete="off"
                                                           maxlength="20">
                                                    <div class="search-loading" id="loadingNumCaja">
                                                        <i class="fa fa-spinner"></i>
                                                    </div>
                                                </div>
                                            </div>
                                            
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
                                            
                                            <div class="filter-field" style="flex: 0 0 auto; display: flex; gap: 5px;">
                                                <button type="submit" class="btn-traslado" id="btnBuscar">
                                                    <i class="fa fa-search"></i> Buscar
                                                </button>
                                                <button type="button" class="btn-secondary-traslado" onclick="limpiarFiltros()">
                                                    <i class="fa fa-times"></i> Limpiar
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                    
                                    <!-- Información del formato de caja -->
                                    <div style="margin-top: 8px; font-size: 10px; color: #666; background: #f8f9fa; padding: 5px 8px; border-radius: 4px; border-left: 3px solid #009a3f;">
                                        <strong><i class="fa fa-info-circle"></i> Formato de ID Caja:</strong> 
                                        <code style="background: #fff; padding: 1px 4px; border-radius: 3px; border: 1px solid #ddd; margin: 0 3px;">0000123C0000001850</code> 
                                        <span style="margin-left: 5px;">Donde <strong>0000123</strong> = Cliente, <strong>C</strong> = Separador, <strong>0000001850</strong> = N° Caja</span>
                                    </div>
                                </div>

                                <?php if ($mostrar_datos): ?>
                                    <!-- Resultados - Tabla como vista principal -->
                                    <div class="results-container">
                                        <div class="results-header">
                                            <div class="results-title">
                                                <i class="fa fa-table"></i> Resumen de Pallets
                                            </div>
                                            <div class="results-stats">
                                                <?php echo $total_registros; ?> LPNs | <?php echo $total_cajas; ?> cajas
                                                <?php if ($filtro_cliente): ?>
                                                    <span style="color: #dc3545; margin-left: 5px;">
                                                        <i class="fa fa-building"></i> Cliente: <?php echo htmlspecialchars($filtro_cliente); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($filtro_num_caja): ?>
                                                    <span style="color: #dc3545; margin-left: 5px;">
                                                        <i class="fa fa-box"></i> N° Caja: <?php echo htmlspecialchars($filtro_num_caja); ?>
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
                                                                <?php
                                                                    // Construir el ID de caja concatenado si hay filtros
                                                                    $caja_completa = '';
                                                                    if (!empty($filtro_cliente) && !empty($filtro_num_caja)) {
                                                                        $caja_completa = $filtro_cliente . 'C' . $filtro_num_caja;
                                                                    } elseif (!empty($filtro_cliente)) {
                                                                        $caja_completa = $filtro_cliente . 'C';
                                                                    } elseif (!empty($filtro_num_caja)) {
                                                                        $caja_completa = 'C' . $filtro_num_caja;
                                                                    }
                                                                ?>
                                                                
                                                                <a href="detalle_lpn.php?lpn=<?php echo urlencode($row['LPN']); ?>&sede=<?php echo urlencode($row['Sede']); ?><?php echo !empty($filtro_cliente) ? '&cliente=' . urlencode($filtro_cliente) : ''; ?><?php echo !empty($filtro_num_caja) ? '&num_caja=' . urlencode($filtro_num_caja) : ''; ?><?php echo !empty($caja_completa) ? '&caja_completa=' . urlencode($caja_completa) : ''; ?>" 
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
                                                <br><small>Intente con otros términos de búsqueda.</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Información del filtro aplicado (solo si hay filtros) -->
                                    <?php if (!empty($filtro_cliente) || !empty($filtro_num_caja) || !empty($filtro_lpn) || !empty($filtro_ubicacion)): ?>
                                        <div class="alert-traslado alert-info-traslado" id="infoFiltros">
                                            <i class="fa fa-info-circle"></i> 
                                            <strong>Filtros aplicados:</strong>
                                            <?php 
                                                $filtros = [];
                                                if (!empty($filtro_cliente)) $filtros[] = "<strong style='color:#dc3545;'>Cliente:</strong> '$filtro_cliente'";
                                                if (!empty($filtro_num_caja)) $filtros[] = "<strong style='color:#dc3545;'>N° Caja:</strong> '$filtro_num_caja'";
                                                if (!empty($filtro_lpn)) $filtros[] = "<strong>LPN:</strong> '$filtro_lpn'";
                                                if (!empty($filtro_ubicacion)) $filtros[] = "<strong>Ubicación:</strong> '$filtro_ubicacion'";
                                                echo implode(' | ', $filtros);
                                            ?>
                                            <a href="translado.php" style="margin-left: 10px; font-size: 10px;">
                                                <i class="fa fa-times"></i> Limpiar todos
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Mensaje de bienvenida cuando no hay búsqueda -->
                                    <div class="welcome-message">
                                        <i class="fa fa-search"></i>
                                        <h4>Bienvenido al Sistema de Traslados</h4>
                                        <p>Utilice los filtros de búsqueda para encontrar pallets.</p>
                                        <p>Los resultados aparecerán aquí después de realizar una búsqueda.</p>
                                        <p><small><i class="fa fa-lightbulb-o"></i> Puede buscar por cliente, número de caja, LPN o ubicación.</small></p>
                                    </div>
                                <?php endif; ?>

                                <!-- Instrucciones compactas -->
                                <div class="instructions">
                                    <h5><i class="fa fa-lightbulb-o"></i> Instrucciones rápidas:</h5>
                                    <ul>
                                        <li><strong>Cliente/N° Caja:</strong> Busca pallets por código de cliente y/o número de caja</li>
                                        <li><strong>Formato caja:</strong> 0000123<strong>C</strong>0000001850 (Cliente + C + N° Caja)</li>
                                        <li><strong>Búsqueda automática:</strong> Filtra automáticamente al escribir en cualquier campo</li>
                                        <li><strong>Cada fila</strong> representa un pallet (LPN) único</li>
                                        <li><strong># Cajas:</strong> Total de cajas en ese pallet</li>
                                        <li><strong>Ctrl+F:</strong> Para buscar rápidamente en Cliente</li>
                                        <li><strong>Ctrl+B:</strong> Para buscar rápidamente en N° Caja</li>
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
                    <i class="fa fa-calendar"></i>
                    Sistema Ransa Archivo - Bolivia 
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
        let timeoutCliente = null;
        let timeoutNumCaja = null;
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

        // Event listener para búsqueda automática en Cliente
        document.getElementById('inputCliente').addEventListener('input', function() {
            clearTimeout(timeoutCliente);
            mostrarLoading('Cliente');
            
            timeoutCliente = setTimeout(function() {
                aplicarFiltroAutomatico();
            }, debounceDelay);
        });

        // Event listener para búsqueda automática en Número de Caja
        document.getElementById('inputNumCaja').addEventListener('input', function() {
            clearTimeout(timeoutNumCaja);
            mostrarLoading('NumCaja');
            
            timeoutNumCaja = setTimeout(function() {
                aplicarFiltroAutomatico();
            }, debounceDelay);
        });

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

        // Limpiar campo al presionar Esc
        document.getElementById('inputCliente').addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                this.value = '';
                aplicarFiltroAutomatico();
            }
        });

        document.getElementById('inputNumCaja').addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                this.value = '';
                aplicarFiltroAutomatico();
            }
        });

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

        // Auto-focus en el primer campo de búsqueda (Cliente)
        document.addEventListener('DOMContentLoaded', function() {
            const firstInput = document.getElementById('inputCliente');
            if (firstInput) {
                firstInput.focus();
            }
        });

        // Submit al presionar Enter en cualquier campo de búsqueda
        document.querySelectorAll('#inputCliente, #inputNumCaja, #inputLPN, #inputUbicacion').forEach(input => {
            input.addEventListener('keypress', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    clearTimeout(timeoutCliente);
                    clearTimeout(timeoutNumCaja);
                    clearTimeout(timeoutLPN);
                    clearTimeout(timeoutUbicacion);
                    aplicarFiltroAutomatico();
                }
            });
        });

        // Atajos de teclado
        document.addEventListener('keydown', function(event) {
            // Ctrl+F para buscar en Cliente
            if (event.ctrlKey && event.key === 'f') {
                event.preventDefault();
                const searchInput = document.getElementById('inputCliente');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            // Ctrl+B para buscar en Número de Caja
            if (event.ctrlKey && event.key === 'b') {
                event.preventDefault();
                const searchInput = document.getElementById('inputNumCaja');
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
            
            // Atajo Ctrl+P para buscar por LPN
            if (event.ctrlKey && event.key === 'p') {
                event.preventDefault();
                const lpnInput = document.getElementById('inputLPN');
                if (lpnInput) {
                    lpnInput.focus();
                    lpnInput.select();
                }
            }
            
            // Atajo Ctrl+U para buscar por Ubicación
            if (event.ctrlKey && event.key === 'u') {
                event.preventDefault();
                const ubicacionInput = document.getElementById('inputUbicacion');
                if (ubicacionInput) {
                    ubicacionInput.focus();
                    ubicacionInput.select();
                }
            }
        });

        // Feedback visual para campos de cliente y número de caja
        document.getElementById('inputCliente').addEventListener('input', function() {
            const valor = this.value.trim();
            if (valor.length > 0) {
                this.style.borderColor = '#dc3545';
                this.style.boxShadow = '0 0 0 2px rgba(220, 53, 69, 0.2)';
            } else {
                this.style.borderColor = '#ddd';
                this.style.boxShadow = 'none';
            }
        });

        document.getElementById('inputNumCaja').addEventListener('input', function() {
            const valor = this.value.trim();
            if (valor.length > 0) {
                this.style.borderColor = '#dc3545';
                this.style.boxShadow = '0 0 0 2px rgba(220, 53, 69, 0.2)';
            } else {
                this.style.borderColor = '#ddd';
                this.style.boxShadow = 'none';
            }
        });

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
            clearTimeout(timeoutCliente);
            clearTimeout(timeoutNumCaja);
            clearTimeout(timeoutLPN);
            clearTimeout(timeoutUbicacion);
        });

        // Feedback visual para mostrar que se está filtrando
        function mostrarFeedbackFiltrado() {
            const welcomeMsg = document.querySelector('.welcome-message');
            if (welcomeMsg) {
                welcomeMsg.style.opacity = '0.7';
                welcomeMsg.style.transition = 'opacity 0.2s';
                
                setTimeout(() => {
                    if (welcomeMsg) {
                        welcomeMsg.style.opacity = '1';
                    }
                }, 300);
            }
        }

        // Llamar a mostrarFeedbackFiltrado cuando se inicia el filtrado
        document.getElementById('inputCliente').addEventListener('input', mostrarFeedbackFiltrado);
        document.getElementById('inputNumCaja').addEventListener('input', mostrarFeedbackFiltrado);
        document.getElementById('inputLPN').addEventListener('input', mostrarFeedbackFiltrado);
        document.getElementById('inputUbicacion').addEventListener('input', mostrarFeedbackFiltrado);
    </script>
</body>
</html>