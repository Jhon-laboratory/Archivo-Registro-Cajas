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
$estadisticas = [
    'total_hoy' => 0,
    'total_ingresos' => 0,
    'total_traslados' => 0,
    'ultimas_actividades' => []
];
$sede_usuario = isset($_SESSION['tienda']) ? $_SESSION['tienda'] : '';

// Conectar a la base de datos para obtener estadísticas rápidas
try {
    $connectionInfo = array(
        "Database" => $dbname,
        "UID" => $username,
        "PWD" => $password,
        "CharacterSet" => "UTF-8",
        "ConnectionPooling" => true,
        "ConnectTimeout" => 3
    );
    
    $conn = sqlsrv_connect($host, $connectionInfo);
    
    if ($conn !== false) {
        $fecha_hoy = date('Y-m-d');
        
        // Solo obtener estadísticas básicas
        if ($sede_usuario && $sede_usuario !== 'ADMIN') {
            $sql_estadisticas = "SELECT 
                COUNT(CASE WHEN CAST(FechaHora AS DATE) = ? THEN 1 END) as total_hoy,
                COUNT(CASE WHEN Accion LIKE '%ingreso%' OR Accion LIKE '%INGRESO%' THEN 1 END) as total_ingresos,
                COUNT(CASE WHEN Accion LIKE '%traslado%' OR Accion LIKE '%TRASLADO%' THEN 1 END) as total_traslados
                FROM DPL.pruebas.Historico 
                WHERE Sede = ?";
            
            $params = array($fecha_hoy, $sede_usuario);
        } else {
            $sql_estadisticas = "SELECT 
                COUNT(CASE WHEN CAST(FechaHora AS DATE) = ? THEN 1 END) as total_hoy,
                COUNT(CASE WHEN Accion LIKE '%ingreso%' OR Accion LIKE '%INGRESO%' THEN 1 END) as total_ingresos,
                COUNT(CASE WHEN Accion LIKE '%traslado%' OR Accion LIKE '%TRASLADO%' THEN 1 END) as total_traslados
                FROM DPL.pruebas.Historico";
            
            $params = array($fecha_hoy);
        }
        
        $stmt = sqlsrv_prepare($conn, $sql_estadisticas, $params);
        
        if ($stmt && sqlsrv_execute($stmt)) {
            if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $estadisticas['total_hoy'] = $row['total_hoy'] ?? 0;
                $estadisticas['total_ingresos'] = $row['total_ingresos'] ?? 0;
                $estadisticas['total_traslados'] = $row['total_traslados'] ?? 0;
            }
        }
        
        // Obtener últimas 5 actividades rápidamente
        $sql_ultimas = "SELECT TOP 5 LPN, Accion, Usuario, Sede, FechaHora 
                        FROM DPL.pruebas.Historico";
        
        if ($sede_usuario && $sede_usuario !== 'ADMIN') {
            $sql_ultimas .= " WHERE Sede = ?";
            $params_ultimas = array($sede_usuario);
        } else {
            $params_ultimas = array();
        }
        
        $sql_ultimas .= " ORDER BY FechaHora DESC";
        
        $stmt_ultimas = sqlsrv_prepare($conn, $sql_ultimas, $params_ultimas);
        
        if ($stmt_ultimas && sqlsrv_execute($stmt_ultimas)) {
            while ($row = sqlsrv_fetch_array($stmt_ultimas, SQLSRV_FETCH_ASSOC)) {
                if ($row['FechaHora'] instanceof DateTime) {
                    $row['FechaHora'] = $row['FechaHora']->format('H:i');
                } else {
                    $row['FechaHora'] = substr($row['FechaHora'], 11, 5);
                }
                $estadisticas['ultimas_actividades'][] = $row;
            }
        }
        
        sqlsrv_close($conn);
    }
} catch (Exception $e) {
    error_log("Error en ransa_main: " . $e->getMessage());
}

// Mostrar mensaje si viene por parámetro
$mensaje = isset($_GET['msg']) ? urldecode($_GET['msg']) : '';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - RANSA</title>

    <!-- CSS del template EXACTAMENTE IGUAL QUE reportes.php -->
    <link href="vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="vendors/nprogress/nprogress.css" rel="stylesheet">
    <link href="vendors/iCheck/skins/flat/green.css" rel="stylesheet">
    <link href="vendors/select2/dist/css/select2.min.css" rel="stylesheet">
    <link href="vendors/bootstrap-progressbar/css/bootstrap-progressbar-3.3.4.min.css" rel="stylesheet">
    <link href="vendors/datatables.net-bs/css/dataTables.bootstrap.min.css" rel="stylesheet">
    <link href="build/css/custom.min.css" rel="stylesheet">

    <!-- CSS ESPECÍFICO SOLO PARA DASHBOARD (NO toca el menú) -->
    <style>
        /* Fondo específico para esta página */
        body.nav-md {
            background: linear-gradient(rgba(245, 247, 250, 0.97), rgba(245, 247, 250, 0.97)), 
                        url('img/imglogin.jpg') center/cover no-repeat fixed;
            min-height: 100vh;
        }
        
        /* Estilos para el dashboard - RADIOFRECUENCIA OPTIMIZADO */
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
        
        /* Estadísticas Rápidas */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid #e8e8e8;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 154, 63, 0.15);
        }
        
        .stat-icon {
            font-size: 28px;
            color: #009a3f;
            margin-bottom: 10px;
            opacity: 0.9;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #009a3f;
            line-height: 1.2;
            margin: 10px 0;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        /* Acciones Rápidas */
        .quick-actions-panel {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            border: 1px solid #e8e8e8;
        }
        
        .section-title {
            color: #333;
            font-weight: 600;
            margin-bottom: 15px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
        }
        
        .quick-action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px 15px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: 2px solid transparent;
            border-radius: 8px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .quick-action-btn:hover {
            background: linear-gradient(135deg, #009a3f, #00782f);
            color: white;
            border-color: #009a3f;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 154, 63, 0.3);
        }
        
        .quick-action-btn i {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .quick-action-btn span {
            font-size: 12px;
            font-weight: 600;
            line-height: 1.3;
        }
        
        /* Actividad Reciente */
        .activity-panel {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
            border: 1px solid #e8e8e8;
        }
        
        .activity-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .activity-item {
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e9f7ef;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #009a3f;
            flex-shrink: 0;
            font-size: 16px;
        }
        
        .activity-content {
            flex-grow: 1;
            min-width: 0;
        }
        
        .activity-lpn {
            font-weight: 600;
            color: #333;
            font-size: 13px;
            margin-bottom: 3px;
        }
        
        .activity-desc {
            color: #666;
            font-size: 12px;
            margin-bottom: 5px;
            line-height: 1.4;
        }
        
        .activity-meta {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #888;
        }
        
        .activity-user {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .activity-time {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* Alertas */
        .alert-dashboard {
            border-radius: 8px;
            border: none;
            padding: 12px 15px;
            margin-bottom: 15px;
            font-size: 12px;
        }
        
        .alert-success-dashboard {
            background: rgba(0, 154, 63, 0.1);
            color: #0d4620;
            border-left: 4px solid #009a3f;
        }
        
        /* Badges */
        .badge-sede {
            padding: 3px 8px;
            border-radius: 4px;
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
        
        /* Info Cards */
        .info-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #009a3f;
            font-size: 11px;
            margin-top: 15px;
        }
        
        .info-card h6 {
            color: #009a3f;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 12px;
        }
        
        /* Footer específico */
        .footer-dashboard {
            margin-top: 20px;
            padding: 15px;
            background: rgba(0, 154, 63, 0.05);
            border-radius: 8px;
            font-size: 11px;
            border-top: 1px solid #e0e0e0;
        }
        
        /* Responsive ESPECÍFICO (NO afecta el menú) */
        @media (max-width: 768px) {
            .welcome-title {
                font-size: 1.2rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .quick-actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stat-number {
                font-size: 26px;
            }
            
            .stat-card {
                padding: 15px 10px;
            }
            
            .activity-meta {
                flex-direction: column;
                gap: 3px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
            
            .activity-item {
                flex-direction: column;
                text-align: center;
            }
            
            .activity-icon {
                margin: 0 auto;
            }
        }
        
        /* Clases de utilidad */
        .text-truncate {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .cursor-pointer {
            cursor: pointer;
        }
        
        /* Animaciones suaves */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>

<body class="nav-md">
    <div class="container body">
        <div class="main_container">
            <!-- SIDEBAR EXACTAMENTE IGUAL QUE reportes.php -->
            <div class="col-md-3 left_col">
                <div class="left_col scroll-view">
                    <div class="navbar nav_title" style="border: 0;">
                        <a href="ransa_main.php" class="site_title">
                            <img src="img/logo.png" alt="RANSA Logo" style="height: 32px;">
                            <span style="font-size: 12px; margin-left: 4px;">Dashboard</span>
                        </a>
                    </div>
                    <div class="clearfix"></div>

                    <!-- Información del usuario -->
                    <div class="profile clearfix">
                        <div class="profile_info">
                            <span>Bienvenido,</span>
                            <h2><?php echo htmlspecialchars($_SESSION['usuario'] ?? 'Usuario'); ?></h2>
                            <span><?php echo htmlspecialchars($_SESSION['correo'] ?? ''); ?></span>
                        </div>
                    </div>

                    <br />

                    <!-- MENU EXACTAMENTE IGUAL -->
                    <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">
                        <div class="menu_section">
                            <h3>Navegación</h3>
                            <ul class="nav side-menu">
                                <li class="active">
                                    <a href="ransa_main.php"><i class="fa fa-dashboard"></i> Dashboard</a>
                                </li>
                                <li>
                                    <a href="ingreso.php"><i class="fa fa-sign-in"></i> Ingreso</a>
                                </li>
                                <li>
                                    <a href="translado.php"><i class="fa fa-exchange"></i> Traslado</a>
                                </li>
                                <li>
                                    <a href="reportes.php"><i class="fa fa-file-text"></i> Reportes</a>
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
                            <?php echo htmlspecialchars($_SESSION['usuario'] ?? 'Usuario'); ?>
                            <small style="opacity: 0.8; margin-left: 10px;">
                                <i class="fa fa-map-marker"></i> 
                                <?php echo htmlspecialchars($_SESSION['tienda'] ?? 'N/A'); ?>
                            </small>
                        </span>
                    </div>
                </div>
            </div>

            <!-- CONTENIDO PRINCIPAL CON CLASES DEL TEMPLATE -->
            <div class="right_col" role="main">
                <div class="page-title">
                    
                </div>
                
                <div class="clearfix"></div>
                
                <div class="row">
                    <div class="col-md-12 col-sm-12">
                        <div class="x_panel">
                            
                            <div class="x_content fade-in">
                                <!-- Sección de Bienvenida -->
                                <div class="welcome-section">
                                    <div class="welcome-title">
                                        <span><i class="fa fa-home"></i> Sistema RANSA - Dashboard </span>
                                        <div class="user-info-card">
                                            <i class="fa fa-user"></i> <?php echo htmlspecialchars($_SESSION['usuario'] ?? 'Usuario'); ?>
                                            <i class="fa fa-building"></i> <?php echo htmlspecialchars($_SESSION['tienda'] ?? 'Sede Principal'); ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Mensaje Flash -->
                                <?php if (!empty($mensaje)): ?>
                                    <div class="alert-dashboard alert-success-dashboard">
                                        <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?>
                                        <button type="button" class="close" style="float: right; background: none; border: none; font-size: 16px; line-height: 1;" onclick="this.parentElement.style.display='none'">&times;</button>
                                    </div>
                                <?php endif; ?>

                                <!-- Estadísticas Rápidas -->
                                <div class="stats-grid">
                                    <div class="stat-card">
                                        <div class="stat-icon">
                                            <i class="fa fa-calendar-check-o"></i>
                                        </div>
                                        <div class="stat-number">
                                            <?php echo $estadisticas['total_hoy']; ?>
                                        </div>
                                        <div class="stat-label">
                                            Movimientos Hoy
                                        </div>
                                        <small style="color: #888; font-size: 10px; margin-top: 5px;">
                                            <?php echo date('d/m/Y'); ?>
                                        </small>
                                    </div>
                                    
                                    <div class="stat-card">
                                        <div class="stat-icon">
                                            <i class="fa fa-sign-in"></i>
                                        </div>
                                        <div class="stat-number">
                                            <?php echo $estadisticas['total_ingresos']; ?>
                                        </div>
                                        <div class="stat-label">
                                            Total Ingresos
                                        </div>
                                        <small style="color: #888; font-size: 10px; margin-top: 5px;">
                                            Registro histórico
                                        </small>
                                    </div>
                                    
                                    <div class="stat-card">
                                        <div class="stat-icon">
                                            <i class="fa fa-exchange"></i>
                                        </div>
                                        <div class="stat-number">
                                            <?php echo $estadisticas['total_traslados']; ?>
                                        </div>
                                        <div class="stat-label">
                                            Total Traslados
                                        </div>
                                        <small style="color: #888; font-size: 10px; margin-top: 5px;">
                                            Registro histórico
                                        </small>
                                    </div>
                                </div>

                                <!-- Acciones Rápidas -->
                                <div class="quick-actions-panel">
                                    <div class="section-title">
                                        <i class="fa fa-bolt"></i> Acciones Rápidas
                                        <span style="margin-left: auto; font-size: 11px; color: #666;">
                                            Atajos: F1 a F4
                                        </span>
                                    </div>
                                    
                                    <div class="quick-actions-grid">
                                        <a href="ingreso.php" class="quick-action-btn" title="F2 - Nuevo Ingreso">
                                            <i class="fa fa-sign-in"></i>
                                            <span>Nuevo Ingreso</span>
                                        </a>
                                        
                                        <a href="translado.php" class="quick-action-btn" title="F3 - Nuevo Traslado">
                                            <i class="fa fa-exchange"></i>
                                            <span>Nuevo Traslado</span>
                                        </a>
                                        
                                        <a href="reportes.php" class="quick-action-btn" title="F4 - Ver Reportes">
                                            <i class="fa fa-file-text"></i>
                                            <span>Ver Reportes</span>
                                        </a>
                                        
                                        <a href="reportes.php?fecha=<?php echo date('Y-m-d'); ?>" class="quick-action-btn" title="Ver actividades de hoy">
                                            <i class="fa fa-search"></i>
                                            <span>Actividad de Hoy</span>
                                        </a>
                                    </div>
                                </div>

                                <!-- Actividad Reciente -->
                                <div class="activity-panel">
                                    <div class="section-title">
                                        <i class="fa fa-history"></i> Actividad Reciente
                                        <span style="margin-left: auto; font-size: 11px; color: #666;">
                                            Últimas 5 actividades
                                            <?php if ($sede_usuario !== 'ADMIN'): ?>
                                                <span style="color: #009a3f; margin-left: 5px;">
                                                    <i class="fa fa-filter"></i> Sede: <?php echo htmlspecialchars($sede_usuario); ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="activity-list">
                                        <?php if (!empty($estadisticas['ultimas_actividades'])): ?>
                                            <?php foreach ($estadisticas['ultimas_actividades'] as $actividad): ?>
                                                <div class="activity-item">
                                                    <div class="activity-icon">
                                                        <?php if (stripos($actividad['Accion'], 'ingreso') !== false): ?>
                                                            <i class="fa fa-sign-in"></i>
                                                        <?php elseif (stripos($actividad['Accion'], 'traslado') !== false): ?>
                                                            <i class="fa fa-exchange"></i>
                                                        <?php else: ?>
                                                            <i class="fa fa-history"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="activity-content">
                                                        <div class="activity-lpn text-truncate">
                                                            <?php echo htmlspecialchars($actividad['LPN']); ?>
                                                        </div>
                                                        <div class="activity-desc">
                                                            <?php echo htmlspecialchars($actividad['Accion']); ?>
                                                        </div>
                                                        <div class="activity-meta">
                                                            <span class="activity-user">
                                                                <i class="fa fa-user"></i> 
                                                                <?php echo htmlspecialchars($actividad['Usuario']); ?>
                                                            </span>
                                                            <span class="activity-time">
                                                                <i class="fa fa-clock-o"></i> 
                                                                <?php echo htmlspecialchars($actividad['FechaHora']); ?>
                                                                <?php if ($sede_usuario === 'ADMIN'): ?>
                                                                    • <span class="badge-sede badge-<?php echo strtolower($actividad['Sede']); ?>">
                                                                        <?php echo htmlspecialchars($actividad['Sede']); ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div style="text-align: center; padding: 30px; color: #999;">
                                                <i class="fa fa-inbox" style="font-size: 40px; margin-bottom: 15px; opacity: 0.5;"></i>
                                                <p>No hay actividad registrada</p>
                                                <small style="font-size: 11px;">Realice su primera operación</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div style="text-align: center; margin-top: 15px;">
                                        <a href="reportes.php" class="btn btn-default btn-sm" style="font-size: 11px; padding: 6px 20px;">
                                            <i class="fa fa-eye"></i> Ver Todas las Actividades
                                        </a>
                                    </div>
                                </div>


                                
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER CON CLASES ESPECÍFICAS -->
            <footer class="footer-dashboard">
                <div class="pull-right">
                    <i class="fa fa-clock-o"></i>
                    Sistema Ransa Archivo - Bolivia 
                </div>
                <div class="clearfix"></div>
            </footer>
        </div>
    </div>

    <!-- SCRIPTS EXACTAMENTE IGUALES QUE reportes.php -->
    <script src="vendors/jquery/dist/jquery.min.js"></script>
    <script src="vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="vendors/fastclick/lib/fastclick.js"></script>
    <script src="vendors/nprogress/nprogress.js"></script>
    <script src="build/js/custom.min.js"></script>

    <script>
        // Función para cerrar sesión (IGUAL QUE reportes.php)
        function cerrarSesion() {
            if (confirm('¿Está seguro de que desea cerrar sesión?')) {
                window.location.href = 'logout.php';
            }
        }

        // Toggle del menú (EXACTAMENTE IGUAL QUE reportes.php)
        document.getElementById('menu_toggle').addEventListener('click', function() {
            const leftCol = document.querySelector('.left_col');
            leftCol.classList.toggle('menu-open');
        });

        // Reloj en tiempo real
        function updateClock() {
            const now = new Date();
            const timeStr = now.getHours().toString().padStart(2, '0') + ':' + 
                           now.getMinutes().toString().padStart(2, '0') + ':' + 
                           now.getSeconds().toString().padStart(2, '0');
            
            document.getElementById('live-clock').textContent = timeStr;
            document.getElementById('footer-clock').textContent = timeStr;
        }
        
        setInterval(updateClock, 1000);

        // Atajos de teclado optimizados para radiofrecuencia
        document.addEventListener('keydown', function(event) {
            // F1 = Dashboard
            if (event.key === 'F1') {
                event.preventDefault();
                window.location.href = 'ransa_main.php';
            }
            
            // F2 = Ingreso
            if (event.key === 'F2') {
                event.preventDefault();
                window.location.href = 'ingreso.php';
            }
            
            // F3 = Traslado
            if (event.key === 'F3') {
                event.preventDefault();
                window.location.href = 'translado.php';
            }
            
            // F4 = Reportes
            if (event.key === 'F4') {
                event.preventDefault();
                window.location.href = 'reportes.php';
            }
            
            // F5 = Recargar
            if (event.key === 'F5') {
                event.preventDefault();
                location.reload();
            }
            
            // Ctrl+F = Buscar en reportes
            if (event.ctrlKey && event.key === 'f') {
                event.preventDefault();
                window.location.href = 'reportes.php';
                setTimeout(() => {
                    const input = document.querySelector('#inputBusqueda');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                }, 100);
            }
            
            // Ctrl+L = Limpiar búsqueda
            if (event.ctrlKey && event.key === 'l') {
                event.preventDefault();
                window.location.href = 'ransa_main.php';
            }
            
            // Esc = Limpiar mensajes
            if (event.key === 'Escape') {
                const alerts = document.querySelectorAll('.alert-dashboard');
                alerts.forEach(alert => alert.style.display = 'none');
            }
        });

        // Auto-refresh inteligente (cada 5 minutos)
        let lastActivity = Date.now();
        const autoRefreshTime = 300000; // 5 minutos
        
        // Detectar actividad del usuario
        ['click', 'keydown', 'mousemove', 'scroll'].forEach(event => {
            document.addEventListener(event, () => {
                lastActivity = Date.now();
            });
        });
        
        function checkAutoRefresh() {
            const now = Date.now();
            const timeSinceLastActivity = now - lastActivity;
            
            // Solo recargar si el usuario está inactivo por 5 minutos
            if (timeSinceLastActivity >= autoRefreshTime && !document.hidden) {
                // Verificar si hay campos de formulario activos
                const activeElement = document.activeElement;
                const isInputFocused = ['INPUT', 'TEXTAREA', 'SELECT'].includes(activeElement.tagName);
                
                if (!isInputFocused) {
                    console.log('Auto-refresh por inactividad');
                    location.reload();
                }
            }
        }
        
        // Revisar cada minuto
        setInterval(checkAutoRefresh, 60000);

        // Pre-cargar páginas frecuentes (optimización)
        document.addEventListener('DOMContentLoaded', function() {
            // Pre-cargar después de 3 segundos
            setTimeout(() => {
                const pages = ['ingreso.php', 'translado.php', 'reportes.php'];
                pages.forEach(page => {
                    const link = document.createElement('link');
                    link.rel = 'prefetch';
                    link.href = page;
                    document.head.appendChild(link);
                });
            }, 3000);
            
            // Inicializar tooltips
            $('[data-toggle="tooltip"]').tooltip();
            
            // Añadir efecto de carga suave
            document.querySelectorAll('.stat-card, .quick-action-btn').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(10px)';
            });
            
            // Animación de entrada escalonada
            setTimeout(() => {
                document.querySelectorAll('.stat-card').forEach((el, index) => {
                    setTimeout(() => {
                        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                        el.style.opacity = '1';
                        el.style.transform = 'translateY(0)';
                    }, index * 100);
                });
                
                setTimeout(() => {
                    document.querySelectorAll('.quick-action-btn').forEach((el, index) => {
                        setTimeout(() => {
                            el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                            el.style.opacity = '1';
                            el.style.transform = 'translateY(0)';
                        }, index * 100);
                    });
                }, 400);
            }, 100);
        });

        // Función para exportar datos (futura implementación)
        function exportDashboardData() {
            const data = {
                usuario: '<?php echo $_SESSION["usuario"] ?? ""; ?>',
                sede: '<?php echo $_SESSION["tienda"] ?? ""; ?>',
                estadisticas: <?php echo json_encode($estadisticas); ?>,
                fecha: new Date().toISOString()
            };
            
            // Aquí se podría implementar exportación a PDF o Excel
            console.log('Datos para exportar:', data);
            alert('Función de exportación en desarrollo');
        }

        // Detectar tipo de dispositivo y optimizar
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        if (isMobile) {
            // Optimizaciones para móviles
            document.body.classList.add('mobile-device');
            
            // Aumentar tamaño de elementos táctiles
            document.querySelectorAll('.quick-action-btn, .btn').forEach(el => {
                el.style.minHeight = '44px';
                el.style.padding = '15px 10px';
            });
        }

        
    </script>
</body>
</html>