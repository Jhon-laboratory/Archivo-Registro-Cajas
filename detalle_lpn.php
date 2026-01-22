<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario']) || !isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// Obtener parámetros de la URL
$lpn_original = isset($_GET['lpn']) ? trim($_GET['lpn']) : '';
$sede_original = isset($_GET['sede']) ? trim($_GET['sede']) : '';
$caja_seleccionada = isset($_GET['caja']) ? trim($_GET['caja']) : '';
$caja_completa = isset($_GET['caja_completa']) ? trim($_GET['caja_completa']) : '';

if (empty($lpn_original) && empty($caja_completa)) {
    header("Location: traslado.php");
    exit;
}

// Configuración de conexión SQL Server
$host = 'Jorgeserver.database.windows.net';
$dbname = 'DPL';
$username = 'Jmmc';
$password = 'ChaosSoldier01';

// Variables
$cajas = [];
$caja_especifica = null;
$total_cajas = 0;
$mensaje = '';
$error = '';
$historico = [];
$usuario_actual = $_SESSION['usuario'];
$sede_usuario = $_SESSION['tienda'];

// Variables para búsqueda de caja completa
$busqueda_por_caja_completa = false;
$id_caja_busqueda = '';

// Determinar tipo de búsqueda
if (!empty($caja_completa)) {
    $busqueda_por_caja_completa = true;
    $id_caja_busqueda = $caja_completa;
} elseif (!empty($caja_seleccionada)) {
    $busqueda_por_caja_completa = true;
    $id_caja_busqueda = $caja_seleccionada;
}

// Función para ajustar hora -5 horas
function horaAjustada($fecha_hora = null) {
    if ($fecha_hora) {
        if ($fecha_hora instanceof DateTime) {
            $fecha_ajustada = clone $fecha_hora;
            $fecha_ajustada->modify('-5 hours');
            return $fecha_ajustada;
        } else if (is_string($fecha_hora)) {
            $fecha_obj = new DateTime($fecha_hora);
            $fecha_obj->modify('-5 hours');
            return $fecha_obj;
        }
    }
    return date('d/m/Y H:i:s', strtotime('-5 hours'));
}

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
        // Búsqueda por caja completa
        if ($busqueda_por_caja_completa && !empty($id_caja_busqueda)) {
            $sql_caja_completa = "SELECT * FROM DPL.pruebas.MasterTable WHERE ID_Caja = ?";
            $params_caja = array($id_caja_busqueda);
            $stmt_caja = sqlsrv_prepare($conn, $sql_caja_completa, $params_caja);
            
            if ($stmt_caja && sqlsrv_execute($stmt_caja)) {
                if ($row = sqlsrv_fetch_array($stmt_caja, SQLSRV_FETCH_ASSOC)) {
                    if ($row['FechaHora'] instanceof DateTime) {
                        $fecha_ajustada = horaAjustada($row['FechaHora']);
                        $row['FechaHora'] = $fecha_ajustada->format('d/m/Y H:i:s');
                    } else if (is_string($row['FechaHora'])) {
                        $fecha_ajustada = horaAjustada($row['FechaHora']);
                        $row['FechaHora'] = $fecha_ajustada->format('d/m/Y H:i:s');
                    }
                    $caja_especifica = $row;
                    
                    if (empty($lpn_original)) {
                        $lpn_original = $row['LPN'];
                    }
                    if (empty($sede_original)) {
                        $sede_original = $row['Sede'];
                    }
                }
            }
        }
        
        // Obtener todas las cajas del LPN
        if (!empty($lpn_original) && !empty($sede_original)) {
            $sql_cajas = "SELECT * FROM DPL.pruebas.MasterTable WHERE LPN = ? AND Sede = ? ORDER BY ID_Caja";
            $params_cajas = array($lpn_original, $sede_original);
            $stmt_cajas = sqlsrv_prepare($conn, $sql_cajas, $params_cajas);
            
            if ($stmt_cajas && sqlsrv_execute($stmt_cajas)) {
                while ($row = sqlsrv_fetch_array($stmt_cajas, SQLSRV_FETCH_ASSOC)) {
                    if ($row['FechaHora'] instanceof DateTime) {
                        $fecha_ajustada = horaAjustada($row['FechaHora']);
                        $row['FechaHora'] = $fecha_ajustada->format('d/m/Y H:i:s');
                    } else if (is_string($row['FechaHora'])) {
                        $fecha_ajustada = horaAjustada($row['FechaHora']);
                        $row['FechaHora'] = $fecha_ajustada->format('d/m/Y H:i:s');
                    }
                    $cajas[] = $row;
                }
                $total_cajas = count($cajas);
            }
            
            // Obtener histórico
            $sql_historico = "SELECT * FROM DPL.pruebas.Historico WHERE LPN = ? AND Sede = ? ORDER BY FechaHora DESC";
            $params_historico = array($lpn_original, $sede_original);
            $stmt_historico = sqlsrv_prepare($conn, $sql_historico, $params_historico);
            
            if ($stmt_historico && sqlsrv_execute($stmt_historico)) {
                while ($row = sqlsrv_fetch_array($stmt_historico, SQLSRV_FETCH_ASSOC)) {
                    if ($row['FechaHora'] instanceof DateTime) {
                        $fecha_ajustada = horaAjustada($row['FechaHora']);
                        $row['FechaHora'] = $fecha_ajustada->format('d/m/Y H:i:s');
                    } else if (is_string($row['FechaHora'])) {
                        $fecha_ajustada = horaAjustada($row['FechaHora']);
                        $row['FechaHora'] = $fecha_ajustada->format('d/m/Y H:i:s');
                    }
                    $historico[] = $row;
                }
            }
        }
        
        // Procesar modificaciones
        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            sqlsrv_begin_transaction($conn);
            $modificaciones_realizadas = 0;
            
            // Buscar caja
            if (isset($_POST['buscar_caja_completa'])) {
                $caja_buscar = isset($_POST['caja_buscar_completa']) ? trim($_POST['caja_buscar_completa']) : '';
                if (!empty($caja_buscar)) {
                    header("Location: detalle_lpn.php?caja_completa=" . urlencode($caja_buscar));
                    exit;
                }
            }
            
            // Modificar caja específica
            if (!empty($caja_especifica) && isset($_POST['modificar_caja_especifica'])) {
                $nuevo_lpn_caja = isset($_POST['nuevo_lpn_caja']) ? trim($_POST['nuevo_lpn_caja']) : '';
                $nueva_ubicacion_caja = isset($_POST['nueva_ubicacion_caja']) ? trim($_POST['nueva_ubicacion_caja']) : '';
                
                if (!empty($nuevo_lpn_caja) || !empty($nueva_ubicacion_caja)) {
                    $sql_update = "UPDATE DPL.pruebas.MasterTable SET LPN = ?, Ubicacion = ?, Usuario = ?, FechaHora = ? WHERE ID_Caja = ?";
                    $fecha_actual = date('Y-m-d H:i:s', strtotime('-5 hours'));
                    $params_update = array(
                        !empty($nuevo_lpn_caja) ? $nuevo_lpn_caja : $caja_especifica['LPN'],
                        !empty($nueva_ubicacion_caja) ? $nueva_ubicacion_caja : $caja_especifica['Ubicacion'],
                        $usuario_actual,
                        $fecha_actual,
                        $caja_especifica['ID_Caja']
                    );
                    
                    $stmt_update = sqlsrv_prepare($conn, $sql_update, $params_update);
                    
                    if ($stmt_update && sqlsrv_execute($stmt_update)) {
                        $modificaciones_realizadas++;
                        $mensaje = "✅ Caja " . $caja_especifica['ID_Caja'] . " modificada correctamente.";
                        
                        // Histórico
                        $accion = "MODIFICACIÓN CAJA ESPECÍFICA: " . $caja_especifica['ID_Caja'];
                        $cambios = [];
                        if (!empty($nuevo_lpn_caja) && $nuevo_lpn_caja != $caja_especifica['LPN']) {
                            $cambios[] = "LPN de '{$caja_especifica['LPN']}' a '$nuevo_lpn_caja'";
                        }
                        if (!empty($nueva_ubicacion_caja) && $nueva_ubicacion_caja != $caja_especifica['Ubicacion']) {
                            $cambios[] = "Ubicación de '{$caja_especifica['Ubicacion']}' a '$nueva_ubicacion_caja'";
                        }
                        $accion .= implode(" y ", $cambios) . " - Usuario: $usuario_actual";
                        
                        $sql_hist = "INSERT INTO DPL.pruebas.Historico (LPN, CantidadCajas, Ubicacion, FechaHora, Usuario, Accion, Sede) VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $params_hist = array(
                            !empty($nuevo_lpn_caja) ? $nuevo_lpn_caja : $caja_especifica['LPN'],
                            1,
                            !empty($nueva_ubicacion_caja) ? $nueva_ubicacion_caja : $caja_especifica['Ubicacion'],
                            $fecha_actual,
                            $usuario_actual,
                            $accion,
                            $caja_especifica['Sede']
                        );
                        sqlsrv_prepare($conn, $sql_hist, $params_hist);
                        sqlsrv_execute(sqlsrv_prepare($conn, $sql_hist, $params_hist));
                    }
                }
            }
            
            // Modificar todas las cajas del LPN
            if (isset($_POST['modificar_grupal']) && !empty($cajas)) {
                $nuevo_lpn_grupal = isset($_POST['nuevo_lpn_grupal']) ? trim($_POST['nuevo_lpn_grupal']) : '';
                $nueva_ubicacion_grupal = isset($_POST['nueva_ubicacion_grupal']) ? trim($_POST['nueva_ubicacion_grupal']) : '';
                
                if (!empty($nuevo_lpn_grupal) || !empty($nueva_ubicacion_grupal)) {
                    $cajas_modificadas = [];
                    $nuevo_lpn = empty($nuevo_lpn_grupal) ? $lpn_original : $nuevo_lpn_grupal;
                    $nueva_ubicacion = empty($nueva_ubicacion_grupal) ? null : $nueva_ubicacion_grupal;
                    
                    foreach ($cajas as $caja) {
                        $sql_update = "UPDATE DPL.pruebas.MasterTable SET LPN = ?, Ubicacion = ?, Usuario = ?, FechaHora = ? WHERE ID_Caja = ?";
                        $fecha_actual = date('Y-m-d H:i:s', strtotime('-5 hours'));
                        $params_update = array(
                            $nuevo_lpn,
                            $nueva_ubicacion ?: $caja['Ubicacion'],
                            $usuario_actual,
                            $fecha_actual,
                            $caja['ID_Caja']
                        );
                        
                        $stmt_update = sqlsrv_prepare($conn, $sql_update, $params_update);
                        if ($stmt_update && sqlsrv_execute($stmt_update)) {
                            $modificaciones_realizadas++;
                            $cajas_modificadas[] = $caja['ID_Caja'];
                        }
                    }
                    
                    if (count($cajas_modificadas) > 0) {
                        $accion = "MODIFICACIÓN GRUPAL: ";
                        $cambios = [];
                        if (!empty($nuevo_lpn_grupal) && $nuevo_lpn_grupal != $lpn_original) {
                            $cambios[] = "LPN cambiado de '$lpn_original' a '$nuevo_lpn_grupal'";
                        }
                        if (!empty($nueva_ubicacion_grupal)) {
                            $cambios[] = "Ubicación cambiada a '$nueva_ubicacion_grupal'";
                        }
                        $accion .= implode(" y ", $cambios);
                        $accion .= " - Cajas afectadas: " . count($cajas_modificadas) . " - Usuario: $usuario_actual";
                        
                        $sql_hist = "INSERT INTO DPL.pruebas.Historico (LPN, CantidadCajas, Ubicacion, FechaHora, Usuario, Accion, Sede) VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $params_hist = array(
                            $nuevo_lpn_grupal ?: $lpn_original,
                            count($cajas_modificadas),
                            $nueva_ubicacion_grupal ?: $cajas[0]['Ubicacion'],
                            $fecha_actual,
                            $usuario_actual,
                            $accion,
                            $sede_original
                        );
                        sqlsrv_prepare($conn, $sql_hist, $params_hist);
                        sqlsrv_execute(sqlsrv_prepare($conn, $sql_hist, $params_hist));
                        
                        $mensaje_grupal = "✅ Se modificaron " . count($cajas_modificadas) . " cajas correctamente.";
                        $mensaje = empty($mensaje) ? $mensaje_grupal : $mensaje . "<br>" . $mensaje_grupal;
                    }
                }
            }
            
            // Modificaciones individuales
            if (isset($_POST['id_caja']) && is_array($_POST['id_caja']) && isset($_POST['accion_individual'])) {
                foreach ($_POST['id_caja'] as $index => $id_caja) {
                    $nuevo_lpn_individual = isset($_POST['lpn'][$index]) ? trim($_POST['lpn'][$index]) : '';
                    $nueva_ubicacion_individual = isset($_POST['ubicacion'][$index]) ? trim($_POST['ubicacion'][$index]) : '';
                    
                    $caja_original = null;
                    foreach ($cajas as $c) {
                        if ($c['ID_Caja'] == $id_caja) {
                            $caja_original = $c;
                            break;
                        }
                    }
                    
                    if (!$caja_original) continue;
                    
                    if ((!empty($nuevo_lpn_individual) && $nuevo_lpn_individual != $caja_original['LPN']) || 
                        (!empty($nueva_ubicacion_individual) && $nueva_ubicacion_individual != $caja_original['Ubicacion'])) {
                        
                        $sql_update = "UPDATE DPL.pruebas.MasterTable SET LPN = ?, Ubicacion = ?, Usuario = ?, FechaHora = ? WHERE ID_Caja = ?";
                        $fecha_actual = date('Y-m-d H:i:s', strtotime('-5 hours'));
                        $params_update = array(
                            $nuevo_lpn_individual ?: $caja_original['LPN'],
                            $nueva_ubicacion_individual ?: $caja_original['Ubicacion'],
                            $usuario_actual,
                            $fecha_actual,
                            $id_caja
                        );
                        
                        $stmt_update = sqlsrv_prepare($conn, $sql_update, $params_update);
                        if ($stmt_update && sqlsrv_execute($stmt_update)) {
                            $modificaciones_realizadas++;
                        }
                    }
                }
                
                if ($modificaciones_realizadas > 0 && isset($_POST['accion_individual'])) {
                    $mensaje_individual = "✅ Se modificaron $modificaciones_realizadas cajas individualmente.";
                    $mensaje = empty($mensaje) ? $mensaje_individual : $mensaje . "<br>" . $mensaje_individual;
                }
            }
            
            if ($modificaciones_realizadas > 0) {
                sqlsrv_commit($conn);
                $url = "detalle_lpn.php?lpn=" . urlencode($lpn_original) . "&sede=" . urlencode($sede_original);
                if (!empty($caja_especifica)) {
                    $url .= "&caja_completa=" . urlencode($caja_especifica['ID_Caja']);
                }
                if (!empty($mensaje)) {
                    $url .= "&msg=" . urlencode($mensaje);
                }
                header("Location: $url");
                exit;
            } else if ($_SERVER["REQUEST_METHOD"] == "POST") {
                sqlsrv_rollback($conn);
                $error = "⚠️ No se realizaron modificaciones.";
            }
        }
        
        sqlsrv_close($conn);
    }
} catch (Exception $e) {
    $error = "Excepción: " . $e->getMessage();
}

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
    <title>Detalle LPN - RANSA</title>
    <link href="vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="vendors/nprogress/nprogress.css" rel="stylesheet">
    <link href="vendors/iCheck/skins/flat/green.css" rel="stylesheet">
    <link href="vendors/select2/dist/css/select2.min.css" rel="stylesheet">
    <link href="vendors/bootstrap-progressbar/css/bootstrap-progressbar-3.3.4.min.css" rel="stylesheet">
    <link href="vendors/datatables.net-bs/css/dataTables.bootstrap.min.css" rel="stylesheet">
    <link href="build/css/custom.min.css" rel="stylesheet">
    <style>
        body.nav-md {
            background: linear-gradient(rgba(255, 255, 255, 0.97), rgba(255, 255, 255, 0.97)), 
                        url('img/imglogin.jpg') center/cover no-repeat fixed;
            min-height: 100vh;
        }
        
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
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        /* NUEVO: LPN INFO RESPONSIVE */
        .lpn-info-responsive {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
            width: 100%;
        }
        
        .lpn-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            align-items: center;
        }
        
        .lpn-info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px;
        }
        
        .lpn-info-item i {
            color: #009a3f;
            font-size: 14px;
            min-width: 20px;
        }
        
        .lpn-info-item div {
            display: flex;
            flex-direction: column;
        }
        
        .lpn-info-item small {
            font-size: 10px;
            color: #666;
            line-height: 1;
        }
        
        .lpn-info-item strong {
            font-size: 12px;
            color: #333;
            line-height: 1.2;
        }
        
        .badge-caja-especifica-responsive {
            background: #dc3545;
            color: white;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
        }
        
        .search-caja-panel {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border: 2px solid #dc3545;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.1);
        }
        
        .search-caja-title {
            color: #dc3545;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .busqueda-origen {
            background: #e9f7ef;
            border: 1px solid #b8e6c9;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 10px;
            font-size: 11px;
            color: #0d4620;
        }
        
        .caja-seleccionada-row {
            background: linear-gradient(135deg, #ffe6e6, #ffcccc);
            border: 2px solid #dc3545;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            align-items: center;
        }
        
        .caja-seleccionada-item {
            text-align: center;
        }
        
        .caja-seleccionada-label {
            font-size: 11px;
            color: #dc3545;
            font-weight: 600;
            margin-bottom: 3px;
        }
        
        .caja-seleccionada-value {
            font-weight: 600;
            font-size: 13px;
            color: #333;
        }
        
        /* FORMULARIO PARA MODIFICAR CAJA ESPECÍFICA */
        .form-caja-especifica {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
        }
        
        .form-caja-especifica h6 {
            color: #856404;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        /* FORMULARIO PARA MODIFICACIÓN MASIVA */
        .form-masivo {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
        }
        
        .form-masivo h6 {
            color: #0c5460;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 8px;
            align-items: end;
            margin-bottom: 8px;
        }
        
        .form-group-small {
            margin-bottom: 0;
        }
        
        .form-group-small label {
            font-size: 11px;
            font-weight: 600;
            color: #555;
            margin-bottom: 3px;
            display: block;
        }
        
        .form-control-sm {
            font-size: 12px;
            padding: 6px 10px;
            border-radius: 5px;
            border: 1px solid #ddd;
            height: 32px;
            width: 100%;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 600;
            height: 32px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-warning-sm {
            background: #ffc107;
            color: #212529;
            border: none;
        }
        
        .btn-warning-sm:hover {
            background: #e0a800;
        }
        
        .btn-info-sm {
            background: #17a2b8;
            color: white;
            border: none;
        }
        
        .btn-info-sm:hover {
            background: #138496;
        }
        
        .alert-detalle {
            border-radius: 6px;
            border: none;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-size: 11px;
        }
        
        .alert-success-detalle {
            background: rgba(0, 154, 63, 0.1);
            color: #0d4620;
            border-left: 3px solid #009a3f;
        }
        
        .alert-danger-detalle {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border-left: 3px solid #dc3545;
        }
        
        .alert-warning-detalle {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
            border-left: 3px solid #ffc107;
        }
        
        .badge-sede {
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 10px;
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
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .welcome-title {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .caja-seleccionada-row {
                grid-template-columns: 1fr 1fr;
            }
            
            .lpn-info-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .lpn-info-item {
                flex-direction: column;
                text-align: center;
                gap: 4px;
            }
            
            .lpn-info-item i {
                margin-bottom: 2px;
            }
            
            .lpn-info-item div {
                align-items: center;
            }
        }
        
        @media (max-width: 480px) {
            .lpn-info-grid {
                grid-template-columns: 1fr;
            }
            
            .caja-seleccionada-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="nav-md">
    <div class="container body">
        <div class="main_container">
            <!-- SIDEBAR -->
            <div class="col-md-3 left_col">
                <div class="left_col scroll-view">
                    <div class="navbar nav_title" style="border: 0;">
                        <a href="ransa_main.php" class="site_title">
                            <img src="img/logo.png" alt="RANSA Logo" style="height: 32px;">
                            <span style="font-size: 12px; margin-left: 4px;">Detalle LPN</span>
                        </a>
                    </div>
                    <div class="clearfix"></div>

                    <div class="profile clearfix">
                        <div class="profile_info">
                            <span>Bienvenido,</span>
                            <h2><?php echo $_SESSION['usuario'] ?? 'Usuario'; ?></h2>
                            <span><?php echo $_SESSION['correo'] ?? ''; ?></span>
                        </div>
                    </div>

                    <br />

                    <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">
                        <div class="menu_section">
                            <h3>Navegación</h3>
                            <ul class="nav side-menu">
                                <li><a href="ransa_main.php"><i class="fa fa-line-chart"></i> Dashboard</a></li>
                                <li><a href="ingreso.php"><i class="fa fa-archive"></i> Ingreso</a></li>
                                <li><a href="translado.php"><i class="fa fa-refresh"></i> Traslado</a></li>
                                <li class="active"><a href="detalle_lpn.php"><i class="fa fa-eye"></i> Detalle LPN</a></li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="sidebar-footer hidden-small">
                        <a title="Volver" data-toggle="tooltip" data-placement="top" onclick="window.history.back()">
                            <span class="glyphicon glyphicon-arrow-left"></span>
                        </a>
                        <a title="Actualizar" data-toggle="tooltip" data-placement="top" onclick="location.reload()">
                            <span class="glyphicon glyphicon-refresh"></span>
                        </a>
                        <a title="Salir" data-toggle="tooltip" data-placement="top" onclick="cerrarSesion()">
                            <span class="glyphicon glyphicon-off"></span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- NAVBAR -->
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

            <!-- CONTENIDO -->
            <div class="right_col" role="main">
                <div class="page-title"></div>
                <div class="clearfix"></div>
                
                <div class="row">
                    <div class="col-md-12 col-sm-12">
                        <div class="x_panel">
                            <div class="x_content">
                                <!-- Encabezado -->
                                <div class="welcome-section">
                                    <div class="welcome-title">
                                        <span><i class="fa fa-cube"></i> 
                                            <?php if ($busqueda_por_caja_completa): ?>
                                                Detalle de Caja Específica
                                            <?php else: ?>
                                                Detalle del Pallet (LPN)
                                            <?php endif; ?>
                                        </span>
                                        
                                        <!-- NUEVO: LPN INFO RESPONSIVE -->
                                        
                                    </div>
                                </div>

                                <!-- Info búsqueda -->
                                

                                <!-- Mensajes -->
                                <?php if (!empty($mensaje)): ?>
                                    <div class="alert-detalle alert-success-detalle">
                                        <i class="fa fa-check-circle"></i> <?php echo $mensaje; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($error)): ?>
                                    <div class="alert-detalle alert-danger-detalle">
                                        <i class="fa fa-exclamation-circle"></i> <?php echo $error; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Búsqueda de caja -->
                                <div class="search-caja-panel">
                                    <div class="search-caja-title">
                                        <i class="fa fa-search"></i> Buscar Caja por ID Completo
                                    </div>
                                    <form method="POST" id="formBuscarCaja">
                                        <div class="form-row">
                                            <div class="form-group-small">
                                                <label>ID Completo de Caja:</label>
                                                <input type="text" name="caja_buscar_completa" id="inputCajaCompleta"
                                                       class="form-control-sm" 
                                                       placeholder="Ej: 0000123C0000001850"
                                                       value="<?php echo htmlspecialchars($id_caja_busqueda); ?>"
                                                       required>
                                            </div>
                                            <div>
                                                <button type="submit" class="btn btn-danger btn-sm" name="buscar_caja_completa" value="1">
                                                    <i class="fa fa-search"></i> Buscar Caja
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                                <!-- Caja seleccionada -->
                                <?php if (!empty($caja_especifica)): ?>
                                    <div class="caja-seleccionada-row">
                                        <div class="caja-seleccionada-item">
                                            <div class="caja-seleccionada-label">ID de Caja</div>
                                            <div class="caja-seleccionada-value">
                                                <code><?php echo htmlspecialchars($caja_especifica['ID_Caja']); ?></code>
                                            </div>
                                        </div>
                                        <div class="caja-seleccionada-item">
                                            <div class="caja-seleccionada-label">LPN</div>
                                            <div class="caja-seleccionada-value"><?php echo htmlspecialchars($caja_especifica['LPN']); ?></div>
                                        </div>
                                        <div class="caja-seleccionada-item">
                                            <div class="caja-seleccionada-label">Ubicación</div>
                                            <div class="caja-seleccionada-value"><?php echo htmlspecialchars($caja_especifica['Ubicacion']); ?></div>
                                        </div>
                                        <div class="caja-seleccionada-item">
                                            <div class="caja-seleccionada-label">Última Modificación</div>
                                            <div class="caja-seleccionada-value"><?php echo htmlspecialchars($caja_especifica['FechaHora']); ?></div>
                                        </div>
                                    </div>
                                <?php elseif ($busqueda_por_caja_completa && empty($caja_especifica)): ?>
                                    <div class="alert-detalle alert-warning-detalle">
                                        <i class="fa fa-exclamation-triangle"></i> 
                                        No se encontró la caja "<?php echo htmlspecialchars($id_caja_busqueda); ?>" en la base de datos.
                                    </div>
                                <?php endif; ?>

                                <!-- =========================================== -->
                                <!-- FORMULARIO 1: MODIFICAR CAJA ESPECÍFICA -->
                                <!-- =========================================== -->
                                <?php if (!empty($caja_especifica)): ?>
                                <div class="form-caja-especifica">
                                    <h6><i class="fa fa-edit"></i> Modificar esta caja específica</h6>
                                    <form method="POST">
                                        <div class="form-row">
                                            <div class="form-group-small">
                                                <label>Nuevo LPN (opcional):</label>
                                                <input type="text" name="nuevo_lpn_caja" class="form-control-sm" 
                                                       placeholder="Dejar vacío para mantener '<?php echo htmlspecialchars($caja_especifica['LPN']); ?>'">
                                            </div>
                                            <div class="form-group-small">
                                                <label>Nueva Ubicación (opcional):</label>
                                                <input type="text" name="nueva_ubicacion_caja" class="form-control-sm" 
                                                       placeholder="Dejar vacío para mantener '<?php echo htmlspecialchars($caja_especifica['Ubicacion']); ?>'">
                                            </div>
                                            <div>
                                                <button type="submit" class="btn btn-warning btn-sm" name="modificar_caja_especifica" value="1">
                                                    <i class="fa fa-edit"></i> Modificar Solo Esta Caja
                                                </button>
                                            </div>
                                        </div>
                                        <small style="color: #666; font-size: 10px;">
                                            Solo afectará a la caja: <?php echo htmlspecialchars($caja_especifica['ID_Caja']); ?>
                                        </small>
                                    </form>
                                </div>
                                <?php endif; ?>

                                <!-- =========================================== -->
                                <!-- FORMULARIO 2: MODIFICACIÓN MASIVA -->
                                <!-- =========================================== -->
                                <?php if (!empty($lpn_original) && $total_cajas > 0): ?>
                                <div class="form-masivo">
                                    <h6><i class="fa fa-users"></i> Modificación Masiva de Todas las Cajas del LPN</h6>
                                    <form method="POST" id="formGrupal">
                                        <div class="form-row">
                                            <div class="form-group-small">
                                                <label>Nuevo LPN (opcional):</label>
                                                <input type="text" name="nuevo_lpn_grupal" id="nuevo_lpn_grupal"
                                                       class="form-control-sm" 
                                                       placeholder="Dejar vacío para mantener '<?php echo htmlspecialchars($lpn_original); ?>'">
                                            </div>
                                            <div class="form-group-small">
                                                <label>Nueva Ubicación (opcional):</label>
                                                <input type="text" name="nueva_ubicacion_grupal" id="nueva_ubicacion_grupal"
                                                       class="form-control-sm" 
                                                       placeholder="Dejar vacío para mantener ubicación actual">
                                            </div>
                                            <div>
                                                <button type="submit" class="btn btn-info btn-sm" name="modificar_grupal" value="1">
                                                    <i class="fa fa-edit"></i> Aplicar a Todas las Cajas
                                                </button>
                                            </div>
                                        </div>
                                        <small style="color: #666; font-size: 10px;">
                                            Afectará a <?php echo $total_cajas; ?> cajas del LPN <?php echo htmlspecialchars($lpn_original); ?>
                                        </small>
                                    </form>
                                </div>
                                <?php endif; ?>

                                <!-- Tabs para Cajas e Histórico -->
                                <?php if (!empty($lpn_original) && $total_cajas > 0): ?>
                                <ul class="nav nav-tabs" id="detalleTabs" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="cajas-tab" data-toggle="tab" href="#cajas" role="tab">
                                            <i class="fa fa-cubes"></i> Cajas del LPN (<?php echo $total_cajas; ?>)
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="historico-tab" data-toggle="tab" href="#historico" role="tab">
                                            <i class="fa fa-history"></i> Histórico (<?php echo count($historico); ?>)
                                        </a>
                                    </li>
                                </ul>

                                <div class="tab-content" id="detalleTabsContent">
                                    <!-- Tab de Cajas -->
                                    <div class="tab-pane fade show active" id="cajas" role="tabpanel">
                                        <form method="POST" id="formIndividual">
                                            <div style="background: white; border-radius: 10px; padding: 15px; margin-top: 15px; overflow-x: auto;">
                                                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                                                    <thead>
                                                        <tr>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">#</th>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">ID Caja</th>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">LPN</th>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">Ubicación</th>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">Usuario</th>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">Fecha/Hora</th>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">Sede</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($cajas as $index => $caja): ?>
                                                            <tr style="<?php echo (!empty($caja_especifica) && $caja['ID_Caja'] == $caja_especifica['ID_Caja']) ? 'background-color: #ffe6e6; border-left: 3px solid #dc3545;' : ''; ?>">
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee;">
                                                                    <?php echo $index + 1; ?>
                                                                    <?php if (!empty($caja_especifica) && $caja['ID_Caja'] == $caja_especifica['ID_Caja']): ?>
                                                                        <span style="color: #dc3545;">★</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee;">
                                                                    <?php echo htmlspecialchars($caja['ID_Caja']); ?>
                                                                    <input type="hidden" name="id_caja[]" value="<?php echo htmlspecialchars($caja['ID_Caja']); ?>">
                                                                </td>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee;">
                                                                    <input type="text" name="lpn[]" style="width: 100%; padding: 4px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px;"
                                                                           value="<?php echo htmlspecialchars($caja['LPN']); ?>">
                                                                </td>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee;">
                                                                    <input type="text" name="ubicacion[]" style="width: 100%; padding: 4px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px;"
                                                                           value="<?php echo htmlspecialchars($caja['Ubicacion']); ?>">
                                                                </td>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee;">
                                                                    <?php echo htmlspecialchars($caja['Usuario']); ?>
                                                                </td>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee;">
                                                                    <?php echo htmlspecialchars($caja['FechaHora']); ?>
                                                                </td>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: center;">
                                                                    <span class="badge-sede badge-<?php echo strtolower($caja['Sede']); ?>">
                                                                        <?php echo htmlspecialchars($caja['Sede']); ?>
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                                
                                                <div style="margin-top: 15px; text-align: center;">
                                                    <button type="submit" class="btn btn-success btn-sm" name="accion_individual" value="guardar">
                                                        <i class="fa fa-save"></i> Guardar Cambios Individuales
                                                    </button>
                                                    <a href="translado.php" class="btn btn-secondary btn-sm">
                                                        <i class="fa fa-arrow-left"></i> Volver a Traslado
                                                    </a>
                                                </div>
                                            </div>
                                        </form>
                                    </div>

                                    <!-- Tab de Histórico -->
                                    <div class="tab-pane fade" id="historico" role="tabpanel">
                                        <div style="background: white; border-radius: 10px; padding: 15px; margin-top: 15px; overflow-x: auto;">
                                            <?php if (!empty($historico)): ?>
                                                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                                                    <thead>
                                                        <tr>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">Fecha/Hora</th>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">LPN</th>
                                                            <th style="background: #009a3f; color: white; padding: 8px;"># Cajas</th>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">Ubicación</th>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">Usuario</th>
                                                            <th style="background: #009a3f; color: white; padding: 8px;">Acción</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($historico as $registro): ?>
                                                            <tr>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee;">
                                                                    <?php echo htmlspecialchars($registro['FechaHora']); ?>
                                                                </td>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee;">
                                                                    <?php echo htmlspecialchars($registro['LPN']); ?>
                                                                </td>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: center;">
                                                                    <?php echo $registro['CantidadCajas']; ?>
                                                                </td>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee;">
                                                                    <?php echo htmlspecialchars($registro['Ubicacion']); ?>
                                                                </td>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee;">
                                                                    <?php echo htmlspecialchars($registro['Usuario']); ?>
                                                                </td>
                                                                <td style="padding: 6px; border-bottom: 1px solid #eee;">
                                                                    <?php echo htmlspecialchars($registro['Accion']); ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            <?php else: ?>
                                                <p style="text-align: center; color: #666; padding: 20px;">
                                                    No hay registro histórico para este LPN.
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <footer style="margin-top: 15px; padding: 12px; background: rgba(0, 154, 63, 0.05); border-radius: 6px; font-size: 10px;">
                <div class="pull-right">
                    <i class="fa fa-calendar"></i> Sistema Ransa Archivo - Bolivia 
                </div>
                <div class="clearfix"></div>
            </footer>
        </div>
    </div>

    <script src="vendors/jquery/dist/jquery.min.js"></script>
    <script src="vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="vendors/fastclick/lib/fastclick.js"></script>
    <script src="vendors/nprogress/nprogress.js"></script>
    <script src="build/js/custom.min.js"></script>

    <script>
        function cerrarSesion() {
            if (confirm('¿Está seguro de que desea cerrar sesión?')) {
                window.location.href = 'logout.php';
            }
        }

        document.getElementById('menu_toggle').addEventListener('click', function() {
            document.querySelector('.left_col').classList.toggle('menu-open');
        });

        $(document).ready(function() {
            $('#detalleTabs a').on('click', function (e) {
                e.preventDefault();
                $(this).tab('show');
            });
        });

        document.addEventListener('keydown', function(event) {
            if (event.ctrlKey && event.key === 'f') {
                event.preventDefault();
                const searchInput = document.getElementById('inputCajaCompleta');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                const searchInput = document.getElementById('inputCajaCompleta');
                if (searchInput) {
                    searchInput.focus();
                }
            }, 100);
        });

        <?php if (!empty($lpn_original) && $total_cajas > 0): ?>
        document.getElementById('formGrupal').addEventListener('submit', function(e) {
            const nuevoLPN = document.getElementById('nuevo_lpn_grupal').value.trim();
            const nuevaUbicacion = document.getElementById('nueva_ubicacion_grupal').value.trim();
            
            if (!nuevoLPN && !nuevaUbicacion) {
                alert('Debe ingresar al menos un valor (LPN o Ubicación) para modificar.');
                e.preventDefault();
                return false;
            }
            
            const totalCajas = <?php echo $total_cajas; ?>;
            let mensaje = `¿Está seguro de aplicar cambios a ${totalCajas} caja(s)?\n\n`;
            
            if (nuevoLPN) {
                mensaje += `• Nuevo LPN: ${nuevoLPN}\n`;
            } else {
                mensaje += `• LPN se mantendrá: <?php echo htmlspecialchars($lpn_original); ?>\n`;
            }
            
            if (nuevaUbicacion) {
                mensaje += `• Nueva Ubicación: ${nuevaUbicacion}\n`;
            } else {
                mensaje += `• Ubicación se mantendrá\n`;
            }
            
            if (!confirm(mensaje)) {
                e.preventDefault();
                return false;
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>