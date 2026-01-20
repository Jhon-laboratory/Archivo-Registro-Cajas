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

// ============================================
// NUEVO: Función para validar existencia
// ============================================
function validarExistencia($campo, $valor, $conn) {
    if (empty($valor)) return false;
    
    $sql = "";
    if ($campo === 'LPN') {
        $sql = "SELECT TOP 1 LPN FROM DPL.pruebas.MasterTable WHERE LPN = ?";
    } elseif ($campo === 'Ubicacion') {
        $sql = "SELECT TOP 1 Ubicacion FROM DPL.pruebas.MasterTable WHERE Ubicacion = ?";
    } else {
        return false;
    }
    
    $params = array($valor);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    
    if ($stmt && sqlsrv_execute($stmt)) {
        if (sqlsrv_fetch($stmt)) {
            sqlsrv_free_stmt($stmt);
            return true; // Existe en la base de datos
        }
        sqlsrv_free_stmt($stmt);
    }
    
    return false; // No existe
}

// Variables
$mensaje = '';
$error = '';
$ultimo_lpn = '';
$ultima_ubicacion = '';

// Procesar formulario
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Conexión usando sqlsrv
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
        // Preparar datos del usuario
        $usuario = $_SESSION['usuario'];
        $sede = $_SESSION['tienda'];
        
        // ============================================
        // CORRECCIÓN: AJUSTAR HORA -4 HORAS
        // ============================================
        $timezone_offset = -5; // Ajustar 4 horas hacia atrás
        $fecha_hora_servidor = date('Y-m-d H:i:s');
        $fecha_hora = date('Y-m-d H:i:s', strtotime("$timezone_offset hours"));
        // Solo la fecha para las nuevas tablas Master
        $fecha_solo = date('Y-m-d', strtotime("$timezone_offset hours"));
        
        // También formatear para mostrar
        $fecha_formateada = date('d/m/Y H:i:s', strtotime("$timezone_offset hours"));
        
        // ============================================
        // LOG para debug (opcional)
        // ============================================
        error_log("Hora servidor: $fecha_hora_servidor");
        error_log("Hora ajustada (-4): $fecha_hora");
        error_log("Fecha para Master tables: $fecha_solo");
        
        // NUEVO: Validar datos duplicados antes de procesar
        $datosDuplicados = [];
        if (isset($_POST['lpn']) && is_array($_POST['lpn'])) {
            foreach ($_POST['lpn'] as $index => $lpn) {
                $lpn = trim($lpn);
                $ubicacion = isset($_POST['ubicacion'][$index]) ? trim($_POST['ubicacion'][$index]) : '';
                
                if (!empty($lpn)) {
                    if (validarExistencia('LPN', $lpn, $conn)) {
                        $datosDuplicados[] = "LPN: $lpn";
                    }
                }
                
                if (!empty($ubicacion)) {
                    if (validarExistencia('Ubicacion', $ubicacion, $conn)) {
                        $datosDuplicados[] = "Ubicación: $ubicacion";
                    }
                }
            }
        }
        
        // Si hay datos duplicados, no procesar
        if (!empty($datosDuplicados)) {
            $error = "❌ No se puede guardar porque hay datos duplicados en la base de datos: " . implode(", ", $datosDuplicados);
            sqlsrv_close($conn);
        } else {
            // Procesar múltiples registros si existen
            $registros_procesados = 0;
            $registros_con_error = 0;
            $errores_detalle = [];
            
            // Agrupar registros por LPN para el histórico
            $registros_por_lpn = [];
            
            // Conjuntos para evitar duplicados en las nuevas tablas Master
            $lpns_unicos = [];
            $idcajas_unicos = [];
            $ubicaciones_unicas = [];
            
            // Obtener datos del formulario
            if (isset($_POST['lpn']) && is_array($_POST['lpn'])) {
                // Primera pasada: Agrupar por LPN y contar cajas, y recolectar datos únicos
                foreach ($_POST['lpn'] as $index => $lpn) {
                    $lpn = trim($lpn);
                    $id_caja = isset($_POST['id_caja'][$index]) ? trim($_POST['id_caja'][$index]) : '';
                    $ubicacion = isset($_POST['ubicacion'][$index]) ? trim($_POST['ubicacion'][$index]) : '';
                    
                    if (!empty($lpn) && !empty($id_caja) && !empty($ubicacion)) {
                        // Agrupar para histórico
                        if (!isset($registros_por_lpn[$lpn])) {
                            $registros_por_lpn[$lpn] = [
                                'cantidad' => 0,
                                'ubicacion' => $ubicacion
                            ];
                        }
                        $registros_por_lpn[$lpn]['cantidad']++;
                        
                        // Recolectar datos únicos para las nuevas tablas Master
                        if (!empty($lpn) && !isset($lpns_unicos[$lpn])) {
                            $lpns_unicos[$lpn] = true;
                        }
                        
                        if (!empty($id_caja) && !isset($idcajas_unicos[$id_caja])) {
                            $idcajas_unicos[$id_caja] = true;
                        }
                        
                        if (!empty($ubicacion) && !isset($ubicaciones_unicas[$ubicacion])) {
                            $ubicaciones_unicas[$ubicacion] = true;
                        }
                    }
                }
                
                // Segunda pasada: Insertar en MasterTable
                foreach ($_POST['lpn'] as $index => $lpn) {
                    $lpn = trim($lpn);
                    $id_caja = isset($_POST['id_caja'][$index]) ? trim($_POST['id_caja'][$index]) : '';
                    $ubicacion = isset($_POST['ubicacion'][$index]) ? trim($_POST['ubicacion'][$index]) : '';
                    
                    // Solo procesar si LPN, ID_Caja y Ubicación no están vacíos
                    if (!empty($lpn) && !empty($id_caja) && !empty($ubicacion)) {
                        // Insertar en la tabla MasterTable CON SEDE
                        $sql_master = "INSERT INTO DPL.pruebas.MasterTable 
                                      (LPN, ID_Caja, Ubicacion, Usuario, FechaHora, Sede) 
                                      VALUES (?, ?, ?, ?, ?, ?)";
                        
                        $params_master = array(
                            $lpn,
                            $id_caja,
                            $ubicacion,
                            $usuario,
                            $fecha_hora,  // Usa la hora ajustada aquí
                            $sede
                        );
                        
                        $stmt_master = sqlsrv_prepare($conn, $sql_master, $params_master);
                        
                        if ($stmt_master) {
                            if (sqlsrv_execute($stmt_master)) {
                                $registros_procesados++;
                                $ultimo_lpn = $lpn;
                                $ultima_ubicacion = $ubicacion;
                            } else {
                                $registros_con_error++;
                                $errores_detalle[] = "Error en MasterTable registro $index: " . print_r(sqlsrv_errors(), true);
                            }
                            sqlsrv_free_stmt($stmt_master);
                        } else {
                            $registros_con_error++;
                            $errores_detalle[] = "Error preparando MasterTable registro $index: " . print_r(sqlsrv_errors(), true);
                        }
                    }
                }
                
                // ============================================
                // NUEVO: INSERTAR EN TABLAS MASTER ADICIONALES
                // ============================================
                
                $masterlpn_insertados = 0;
                $mastercaja_insertados = 0;
                $masterubicacion_insertados = 0;
                
                // Insertar LPNs únicos en MasterLPN
                foreach (array_keys($lpns_unicos) as $lpn) {
                    try {
                        $sql_masterlpn = "INSERT INTO DPL.pruebas.MasterLPN (lpn, fecha, sede) 
                                         VALUES (?, ?, ?)";
                        
                        $params_masterlpn = array($lpn, $fecha_solo, $sede);
                        $stmt_masterlpn = sqlsrv_prepare($conn, $sql_masterlpn, $params_masterlpn);
                        
                        if ($stmt_masterlpn && sqlsrv_execute($stmt_masterlpn)) {
                            $masterlpn_insertados++;
                        }
                        sqlsrv_free_stmt($stmt_masterlpn);
                    } catch (Exception $e) {
                        // Ignorar errores de duplicados (UNIQUE constraint)
                        error_log("Error insertando LPN $lpn en MasterLPN: " . $e->getMessage());
                    }
                }
                
                // Insertar IDs de caja únicos en MasterCaja
                foreach (array_keys($idcajas_unicos) as $idcaja) {
                    try {
                        $sql_mastercaja = "INSERT INTO DPL.pruebas.MasterCaja (IdCaja, fecha, sede) 
                                          VALUES (?, ?, ?)";
                        
                        $params_mastercaja = array($idcaja, $fecha_solo, $sede);
                        $stmt_mastercaja = sqlsrv_prepare($conn, $sql_mastercaja, $params_mastercaja);
                        
                        if ($stmt_mastercaja && sqlsrv_execute($stmt_mastercaja)) {
                            $mastercaja_insertados++;
                        }
                        sqlsrv_free_stmt($stmt_mastercaja);
                    } catch (Exception $e) {
                        // Ignorar errores de duplicados (UNIQUE constraint)
                        error_log("Error insertando IdCaja $idcaja en MasterCaja: " . $e->getMessage());
                    }
                }
                
                // Insertar ubicaciones únicas en MasterUbicacion
                foreach (array_keys($ubicaciones_unicas) as $ubicacion) {
                    try {
                        $sql_masterubicacion = "INSERT INTO DPL.pruebas.MasterUbicacion (Ubicacion, fecha, sede) 
                                              VALUES (?, ?, ?)";
                        
                        $params_masterubicacion = array($ubicacion, $fecha_solo, $sede);
                        $stmt_masterubicacion = sqlsrv_prepare($conn, $sql_masterubicacion, $params_masterubicacion);
                        
                        if ($stmt_masterubicacion && sqlsrv_execute($stmt_masterubicacion)) {
                            $masterubicacion_insertados++;
                        }
                        sqlsrv_free_stmt($stmt_masterubicacion);
                    } catch (Exception $e) {
                        // Ignorar errores de duplicados (UNIQUE constraint)
                        error_log("Error insertando Ubicacion $ubicacion en MasterUbicacion: " . $e->getMessage());
                    }
                }
                
                // Tercera pasada: Insertar en Historico por cada LPN único
                $historico_insertados = 0;
                if ($registros_procesados > 0 && !empty($registros_por_lpn)) {
                    foreach ($registros_por_lpn as $lpn => $datos) {
                        $cantidad_cajas = $datos['cantidad'];
                        $ubicacion_lpn = $datos['ubicacion'];
                        
                        // Texto más corto y optimizado que incluye sede
                        $accion = "Ingreso de $cantidad_cajas cajas con el LPN: $lpn, en la ubicacion $ubicacion_lpn por el usuario $usuario en Sede: $sede";
                        
                        // Insertar en la tabla Historico CON SEDE
                        $sql_historico = "INSERT INTO DPL.pruebas.Historico 
                                         (LPN, CantidadCajas, Ubicacion, FechaHora, Usuario, Accion, Sede) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?)";
                        
                        $params_historico = array(
                            $lpn,
                            $cantidad_cajas,
                            $ubicacion_lpn,
                            $fecha_hora,  // Usa la hora ajustada aquí también
                            $usuario,
                            $accion,
                            $sede
                        );
                        
                        $stmt_historico = sqlsrv_prepare($conn, $sql_historico, $params_historico);
                        
                        if ($stmt_historico) {
                            if (sqlsrv_execute($stmt_historico)) {
                                $historico_insertados++;
                            } else {
                                error_log("Error insertando en Historico para LPN $lpn: " . print_r(sqlsrv_errors(), true));
                            }
                            sqlsrv_free_stmt($stmt_historico);
                        }
                    }
                }
                
                // Mensajes finales
                if ($registros_procesados > 0) {
                    $mensaje = "✅ $registros_procesados registro(s) insertado(s) correctamente.";
                    $mensaje .= "<br>📍 <strong>Sede registrada:</strong> $sede";
                    $mensaje .= "<br>🕐 <strong>Hora registrada:</strong> $fecha_formateada (ajustada -4h)";
                    
                    // Información sobre las nuevas tablas Master
                    $mensaje .= "<br><br><strong>📊 Registros en tablas Master:</strong>";
                    $mensaje .= "<br>• <strong>MasterLPN:</strong> $masterlpn_insertados LPN(s) único(s)";
                    $mensaje .= "<br>• <strong>MasterCaja:</strong> $mastercaja_insertados ID(s) de caja único(s)";
                    $mensaje .= "<br>• <strong>MasterUbicacion:</strong> $masterubicacion_insertados ubicacion(es) única(s)";
                    
                    if ($historico_insertados > 0) {
                        $mensaje .= "<br><br>📜 $historico_insertados registro(s) en Historico.";
                    } else {
                        $mensaje .= "<br><br>⚠️ <strong>Nota:</strong> No se pudo guardar el registro histórico.";
                    }
                    
                    if ($registros_con_error > 0) {
                        $mensaje .= "<br>⚠️ Hubo $registros_con_error error(es) en MasterTable.";
                    }
                } else {
                    $error = "❌ No se insertaron registros. Verifique que los campos obligatorios estén completos.";
                    if (!empty($errores_detalle)) {
                        $error .= " Detalles: " . implode(" | ", $errores_detalle);
                    }
                }
            } else {
                $error = "❌ No se recibieron datos del formulario.";
            }
            
            sqlsrv_close($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingreso Masivo - RANSA</title>

    <!-- CSS del template EXACTAMENTE IGUAL QUE ransa_main.php -->
    <link href="vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="vendors/nprogress/nprogress.css" rel="stylesheet">
    <link href="vendors/iCheck/skins/flat/green.css" rel="stylesheet">
    <link href="vendors/select2/dist/css/select2.min.css" rel="stylesheet">
    <link href="vendors/bootstrap-progressbar/css/bootstrap-progressbar-3.3.4.min.css" rel="stylesheet">
    <link href="vendors/datatables.net-bs/css/dataTables.bootstrap.min.css" rel="stylesheet">
    <link href="build/css/custom.min.css" rel="stylesheet">

    <!-- CSS ESPECÍFICO SOLO PARA LA TABLA (NO toca el menú) -->
    <style>
        /* Fondo específico para esta página */
        body.nav-md {
            background: linear-gradient(rgba(255, 255, 255, 0.97), rgba(255, 255, 255, 0.97)), 
                        url('img/imglogin.jpg') center/cover no-repeat fixed;
            min-height: 100vh;
        }
        
        /* Estilos para el contenido específico de ingreso */
        .welcome-section {
            background: linear-gradient(135deg, rgba(0, 154, 63, 1), rgba(0, 154, 63, 0.8));
            color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 6px 20px rgba(0, 154, 63, 0.2);
            text-align: center;
        }
        
        .welcome-title {
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 1.6rem;
        }
        
        .user-info-card {
            background: rgba(255, 255, 255, 0.9);
            color: #333;
            padding: 10px 18px;
            border-radius: 8px;
            display: inline-block;
            font-weight: 600;
            font-size: 12px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            margin-top: 10px;
        }
        
        /* Formulario tipo tabla - SIN afectar el layout del menú */
        .form-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
        }
        
        .form-title {
            color: #333;
            font-weight: 600;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
            font-size: 1.3rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .ingreso-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-bottom: 20px;
        }
        
        .ingreso-table th {
            background: linear-gradient(135deg, rgba(0, 154, 63, 0.9), rgba(0, 154, 63, 0.7));
            color: white;
            padding: 10px 8px;
            text-align: center;
            font-weight: 600;
            border: none;
        }
        
        .ingreso-table td {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
            position: relative;
        }
        
        .ingreso-table tr:hover {
            background-color: #f9f9f9;
        }
        
        .ingreso-table tr:nth-child(even) {
            background-color: #fafafa;
        }
        
        .table-input {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        
        .table-input:focus {
            border-color: #009a3f;
            box-shadow: 0 0 0 2px rgba(0, 154, 63, 0.1);
            outline: none;
        }
        
        .table-input.readonly {
            background-color: #f5f5f5;
            color: #666;
            cursor: not-allowed;
        }
        
        /* NUEVO: Estilos para validación */
        .input-duplicado {
            border-color: #dc3545 !important;
            background-color: #ffe6e6 !important;
            box-shadow: 0 0 0 2px rgba(220,53,69,0.1) !important;
        }
        
        .input-valido {
            border-color: #28a745 !important;
            background-color: #f0fff4 !important;
            box-shadow: 0 0 0 2px rgba(40,167,69,0.1) !important;
        }
        
        .estado-validacion {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            z-index: 1;
        }
        
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
        
        /* Botones específicos para esta página */
        .btn-ingreso {
            background: linear-gradient(135deg, #009a3f, #00782f);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.2s ease;
            cursor: pointer;
            margin: 2px;
        }
        
        .btn-ingreso:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0, 154, 63, 0.3);
        }
        
        .btn-ingreso:disabled {
            opacity: 0.6 !important;
            cursor: not-allowed !important;
            background: linear-gradient(135deg, #6c757d, #5a6268) !important;
        }
        
        .btn-secondary-ingreso {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.2s ease;
            cursor: pointer;
            margin: 2px;
        }
        
        .btn-secondary-ingreso:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }
        
        .btn-small {
            padding: 4px 8px;
            font-size: 11px;
            margin: 1px;
            min-width: 30px;
        }
        
        .btn-group-ingreso {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        /* Alertas específicas */
        .alert-ingreso {
            border-radius: 8px;
            border: none;
            padding: 12px 15px;
            margin-bottom: 15px;
            font-size: 12px;
        }
        
        .alert-success-ingreso {
            background: rgba(40, 167, 69, 0.1);
            color: #155724;
            border-left: 3px solid #28a745;
        }
        
        .alert-danger-ingreso {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border-left: 3px solid #dc3545;
        }
        
        /* Controles de tabla */
        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .table-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .record-counter {
            font-size: 11px;
            color: #666;
            background: #f0f0f0;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
        }
        
        /* Instrucciones */
        .instructions {
            background: #f0f8ff;
            border-left: 3px solid #009a3f;
            padding: 10px 15px;
            margin-top: 15px;
            border-radius: 6px;
            font-size: 11px;
        }
        
        .instructions h5 {
            color: #009a3f;
            margin-bottom: 5px;
            font-size: 12px;
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
            margin-right: 8px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* NUEVO: Mensaje de duplicados */
        #mensajeDuplicados {
            margin-top: 15px;
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Responsive ESPECÍFICO para la tabla (NO afecta el menú) */
        @media (max-width: 768px) {
            .welcome-title {
                font-size: 1.3rem;
            }
            
            .ingreso-table {
                font-size: 11px;
                min-width: 700px; /* Para scroll horizontal en móviles */
            }
            
            .table-input {
                font-size: 11px;
                padding: 4px 6px;
            }
            
            .btn-group-ingreso {
                flex-direction: column;
                align-items: center;
            }
            
            .btn-ingreso, .btn-secondary-ingreso {
                width: 100%;
                max-width: 200px;
            }
            
            .form-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .estado-validacion {
                right: 5px;
                font-size: 12px;
            }
        }
        
        /* Footer específico */
        .footer-ingreso {
            margin-top: 20px;
            padding: 15px;
            background: rgba(0, 154, 63, 0.08);
            border-radius: 8px;
            font-size: 11px;
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
                            <span style="font-size: 12px; margin-left: 4px;">Ingreso</span>
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
                                <li class="active">
                                    <a href="ingreso.php"><i class="fa fa-archive"></i> Ingreso</a>
                                </li>
                                <li>
                                    <a href="translado.php"><i class="fa fa-refresh"></i> Translado</a>
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
                        <span style="color: white; padding: 15px; font-weight: 500;">
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
                        <!-- Mensajes -->
                        <?php if (!empty($mensaje)): ?>
                            <div class="alert-ingreso alert-success-ingreso">
                                <i class="fa fa-check-circle"></i> 
                                <div style="display: inline-block; vertical-align: top;">
                                    <?php echo $mensaje; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($error)): ?>
                            <div class="alert-ingreso alert-danger-ingreso">
                                <i class="fa fa-exclamation-circle"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Formulario tipo tabla -->
                        <div class="x_panel">
                            <div class="x_title">
                                <h2><i class="fa fa-table"></i> Ingreso Rápido - Tabla de Registros</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div class="form-container">
                                    <div class="form-title">
                                        <span>Ingreso de Cajas por Pallet</span>
                                        <div class="table-actions">
                                            <button type="button" class="btn-ingreso btn-small" onclick="agregarFila()">
                                                <i class="fa fa-plus"></i> Nueva Fila
                                            </button>
                                            <span class="record-counter" id="contadorRegistros">1 registro</span>
                                        </div>
                                    </div>

                                    <form method="POST" id="ingresoForm" onsubmit="return validarFormulario()">
                                        <table class="ingreso-table" id="tablaIngreso">
                                            <thead>
                                                <tr>
                                                    <th width="8%">#</th>
                                                    <th width="16%" class="required-field">LPN</th>
                                                    <th width="16%" class="required-field">ID Caja</th>
                                                    <th width="16%" class="required-field">Ubicación</th>
                                                    <th width="16%">Usuario</th>
                                                    <th width="13%">Fecha/Hora</th>
                                                    <th width="11%">Sede</th>
                                                    <th width="4%">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody id="cuerpoTabla">
                                                <!-- Filas se generan dinámicamente -->
                                            </tbody>
                                        </table>

                                        <!-- Botones principales -->
                                        <div class="btn-group-ingreso">
                                            <button type="submit" class="btn-ingreso" name="guardar" id="btnGuardar">
                                                <i class="fa fa-save"></i> Guardar Registros
                                            </button>
                                            
                                            <button type="button" class="btn-secondary-ingreso" onclick="window.location.href='ransa_main.php'">
                                                <i class="fa fa-arrow-left"></i> Volver al Dashboard
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <!-- Instrucciones -->
                                <div class="instructions">
                                    <h5><i class="fa fa-lightbulb-o"></i> Instrucciones Rápidas:</h5>
                                    <ul>
                                        <li><strong>Enter en cualquier campo:</strong> Crea automáticamente nueva fila para siguiente caja</li>
                                        <li><strong>LPN:</strong> Mismo número para todas las cajas del mismo pallet</li>
                                        <li><strong>ID Caja:</strong> Único por cada caja (se auto-incrementa)</li>
                                        <li><strong>Ubicación:</strong> Misma ubicación para todo el pallet</li>
                                        <li><strong>Sede:</strong> Se registra automáticamente según su usuario (<?php echo $_SESSION['tienda'] ?? 'N/A'; ?>)</li>
                                        <li><strong>Ctrl+S:</strong> Guarda todos los registros</li>
                                        <li><strong>Validación:</strong> Los campos LPN y Ubicación se validan automáticamente contra la base de datos</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER CON CLASES ESPECÍFICAS -->
            <footer class="footer-ingreso">
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
    // Variables globales
    let contadorFilas = 0;
    let ultimoLPN = '<?php echo htmlspecialchars($ultimo_lpn); ?>';
    let ultimaUbicacion = '<?php echo htmlspecialchars($ultima_ubicacion); ?>';
    let usuario = '<?php echo htmlspecialchars($_SESSION['usuario'] ?? ''); ?>';
    let sede = '<?php echo htmlspecialchars($_SESSION['tienda'] ?? ''); ?>';

    // NUEVO: Array para rastrear datos duplicados
    let datosDuplicados = {
        lpn: new Set(),
        ubicacion: new Set()
    };

    // ============================================
    // FUNCIONES DEL MENÚ LATERAL (EXACTAMENTE IGUALES)
    // ============================================
    
    // Función para cerrar sesión (IGUAL)
    function cerrarSesion() {
        if (confirm('¿Está seguro de que desea cerrar sesión?')) {
            window.location.href = 'logout.php';
        }
    }

    // Toggle del menú en dispositivos móviles (EXACTAMENTE IGUAL)
    document.getElementById('menu_toggle').addEventListener('click', function() {
        const leftCol = document.querySelector('.left_col');
        leftCol.classList.toggle('menu-open');
    });

    // ============================================
    // NUEVO: FUNCIONES DE VALIDACIÓN
    // ============================================
    
    // Función para validar campo con AJAX
    function validarCampo(campo, valor, filaId) {
        if (!valor.trim()) {
            // Si está vacío, quitar marcador de duplicado y restaurar estilo
            const input = document.querySelector(`#fila-${filaId} input[name="${campo}[]"]`);
            if (input) {
                input.classList.remove('input-duplicado', 'input-valido');
                input.style.borderColor = '';
                input.style.backgroundColor = '';
                input.style.boxShadow = '';
            }
            
            // Remover icono de estado
            const iconoEstado = document.querySelector(`#fila-${filaId} .estado-validacion`);
            if (iconoEstado) iconoEstado.remove();
            
            // Quitar de datos duplicados
            if (campo === 'lpn') {
                datosDuplicados.lpn.delete(valor);
            } else if (campo === 'ubicacion') {
                datosDuplicados.ubicacion.delete(valor);
            }
            
            actualizarEstadoBotonGuardar();
            return;
        }
        
        // Mostrar indicador de validación
        const input = document.querySelector(`#fila-${filaId} input[name="${campo}[]"]`);
        if (!input) return;
        
        // Remover icono anterior si existe
        const iconoAnterior = input.parentNode.querySelector('.estado-validacion');
        if (iconoAnterior) iconoAnterior.remove();
        
        // Crear y agregar icono de validación
        const iconoValidando = document.createElement('span');
        iconoValidando.innerHTML = ' <i class="fa fa-spinner fa-spin" style="color:#007bff;"></i>';
        iconoValidando.className = 'estado-validacion';
        iconoValidando.id = `validando-${campo}-${filaId}`;
        input.parentNode.appendChild(iconoValidando);
        
        // Hacer petición AJAX
        fetch('validar_existencia.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `campo=${campo}&valor=${encodeURIComponent(valor)}`
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en la respuesta del servidor');
            }
            return response.json();
        })
        .then(data => {
            // Remover icono de validación
            const icono = document.getElementById(`validando-${campo}-${filaId}`);
            if (icono) icono.remove();
            
            const input = document.querySelector(`#fila-${filaId} input[name="${campo}[]"]`);
            if (!input) return;
            
            // Crear icono de estado
            const iconoEstado = document.createElement('span');
            iconoEstado.className = 'estado-validacion';
            iconoEstado.style.marginLeft = '5px';
            
            if (data.existe) {
                // Marcar como duplicado
                input.classList.add('input-duplicado');
                input.classList.remove('input-valido');
                input.style.borderColor = '#dc3545';
                input.style.backgroundColor = '#ffe6e6';
                input.style.boxShadow = '0 0 0 2px rgba(220,53,69,0.1)';
                
                iconoEstado.innerHTML = ' <i class="fa fa-exclamation-triangle" style="color:#dc3545;" title="Ya existe en la base de datos"></i>';
                
                if (campo === 'lpn') {
                    datosDuplicados.lpn.add(valor);
                } else if (campo === 'ubicacion') {
                    datosDuplicados.ubicacion.add(valor);
                }
            } else {
                // Marcar como válido
                input.classList.add('input-valido');
                input.classList.remove('input-duplicado');
                input.style.borderColor = '#28a745';
                input.style.backgroundColor = '#f0fff4';
                input.style.boxShadow = '0 0 0 2px rgba(40,167,69,0.1)';
                
                iconoEstado.innerHTML = ' <i class="fa fa-check-circle" style="color:#28a745;" title="Dato válido"></i>';
                
                if (campo === 'lpn') {
                    datosDuplicados.lpn.delete(valor);
                } else if (campo === 'ubicacion') {
                    datosDuplicados.ubicacion.delete(valor);
                }
            }
            
            // Reemplazar o agregar icono de estado
            const iconoExistente = input.parentNode.querySelector('.estado-validacion');
            if (iconoExistente) {
                iconoExistente.remove();
            }
            input.parentNode.appendChild(iconoEstado);
            
            actualizarEstadoBotonGuardar();
        })
        .catch(error => {
            console.error('Error en validación:', error);
            const icono = document.getElementById(`validando-${campo}-${filaId}`);
            if (icono) icono.remove();
            
            // En caso de error, restaurar estilo normal
            const input = document.querySelector(`#fila-${filaId} input[name="${campo}[]"]`);
            if (input) {
                input.classList.remove('input-duplicado', 'input-valido');
                input.style.borderColor = '';
                input.style.backgroundColor = '';
                input.style.boxShadow = '';
            }
        });
    }

    // Función para actualizar estado del botón Guardar
    function actualizarEstadoBotonGuardar() {
        const btnGuardar = document.getElementById('btnGuardar');
        const mensajeError = document.getElementById('mensajeDuplicados');
        
        const totalDuplicados = datosDuplicados.lpn.size + datosDuplicados.ubicacion.size;
        
        if (totalDuplicados > 0) {
            // Desactivar botón
            btnGuardar.disabled = true;
            btnGuardar.style.opacity = '0.6';
            btnGuardar.style.cursor = 'not-allowed';
            
            // Crear o actualizar mensaje de error
            if (!mensajeError) {
                const nuevoMensaje = document.createElement('div');
                nuevoMensaje.id = 'mensajeDuplicados';
                nuevoMensaje.className = 'alert-ingreso alert-danger-ingreso';
                nuevoMensaje.style.marginTop = '15px';
                nuevoMensaje.innerHTML = '<i class="fa fa-exclamation-circle"></i> <strong>Advertencia:</strong> Hay datos duplicados en la base de datos. Verifique los campos marcados en rojo.';
                
                // Insertar después del formulario
                const form = document.getElementById('ingresoForm');
                form.appendChild(nuevoMensaje);
            }
            
            // Actualizar lista de duplicados en mensaje
            let listaDuplicados = [];
            if (datosDuplicados.lpn.size > 0) {
                listaDuplicados.push(`LPNs: ${Array.from(datosDuplicados.lpn).join(', ')}`);
            }
            if (datosDuplicados.ubicacion.size > 0) {
                listaDuplicados.push(`Ubicaciones: ${Array.from(datosDuplicados.ubicacion).join(', ')}`);
            }
            
            if (mensajeError) {
                mensajeError.innerHTML = `<i class="fa fa-exclamation-circle"></i> <strong>Advertencia:</strong> Datos duplicados encontrados (${listaDuplicados.join('; ')}). No se puede guardar hasta corregirlos.`;
            }
        } else {
            // Activar botón
            btnGuardar.disabled = false;
            btnGuardar.style.opacity = '1';
            btnGuardar.style.cursor = 'pointer';
            
            // Eliminar mensaje de error si existe
            if (mensajeError) {
                mensajeError.remove();
            }
        }
    }

    // ============================================
    // FUNCIONES PARA EL FORMULARIO DE INGRESO
    // ============================================
    
    // Función para obtener fecha y hora actual
    function obtenerFechaHora() {
        const ahora = new Date();
        const fecha = ahora.toLocaleDateString('es-ES');
        const hora = ahora.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        return `${fecha} ${hora}`;
    }

    // Función para obtener fecha y hora en formato SQL
    function obtenerFechaHoraSQL() {
        const ahora = new Date();
        return ahora.toISOString().slice(0, 19).replace('T', ' ');
    }

    // Función para agregar una fila a la tabla
    function agregarFila(propagarDatos = true) {
        const tbody = document.getElementById('cuerpoTabla');
        contadorFilas++;
        
        const numeroFila = contadorFilas;
        const fechaHora = obtenerFechaHora();
        
        // Obtener datos de la fila anterior si se deben propagar
        let lpnValor = '';
        let ubicacionValor = '';
        
        if (propagarDatos && numeroFila > 1) {
            const filaAnterior = document.getElementById(`fila-${numeroFila - 1}`);
            if (filaAnterior) {
                const inputLPNAnterior = filaAnterior.querySelector('input[name="lpn[]"]');
                const inputUbicacionAnterior = filaAnterior.querySelector('input[name="ubicacion[]"]');
                
                if (inputLPNAnterior) lpnValor = inputLPNAnterior.value;
                if (inputUbicacionAnterior) ubicacionValor = inputUbicacionAnterior.value;
            }
        }
        
        // ID de caja siempre en blanco
        let idCajaValor = '';
        
        // Crear nueva fila
        const nuevaFila = document.createElement('tr');
        nuevaFila.id = `fila-${numeroFila}`;
        nuevaFila.className = 'row-new';
        
        nuevaFila.innerHTML = `
            <td style="text-align: center; font-weight: bold;">${numeroFila}</td>
            <td>
                <div style="position: relative;">
                    <input type="text" 
                           class="table-input" 
                           name="lpn[]" 
                           placeholder="PALLET-001"
                           onkeydown="manejarTeclado(event, ${numeroFila}, 'lpn')"
                           onblur="validarCampo('lpn', this.value, ${numeroFila})"
                           value="${lpnValor}">
                </div>
            </td>
            <td>
                <div style="position: relative;">
                    <input type="text" 
                           class="table-input" 
                           name="id_caja[]" 
                           placeholder="CAJA-001"
                           onkeydown="manejarTeclado(event, ${numeroFila}, 'id_caja')"
                           value="${idCajaValor}">
                </div>
            </td>
            <td>
                <div style="position: relative;">
                    <input type="text" 
                           class="table-input" 
                           name="ubicacion[]" 
                           placeholder="RACK-01-A"
                           onkeydown="manejarTeclado(event, ${numeroFila}, 'ubicacion')"
                           onblur="validarCampo('ubicacion', this.value, ${numeroFila})"
                           value="${ubicacionValor}">
                </div>
            </td>
            <td>
                <input type="text" 
                       class="table-input readonly" 
                       value="${usuario}" 
                       readonly>
            </td>
            <td>
                <input type="text" 
                       class="table-input readonly small" 
                       value="${fechaHora}" 
                       readonly>
                <input type="hidden" 
                       name="fechahora_sql[]" 
                       value="${obtenerFechaHoraSQL()}">
            </td>
            <td>
                <input type="text" 
                       class="table-input readonly small" 
                       value="${sede}" 
                       readonly>
            </td>
            <td style="text-align: center;">
                <button type="button" class="btn-secondary-ingreso btn-small" onclick="eliminarFila(${numeroFila})" title="Eliminar fila">
                    <i class="fa fa-trash"></i>
                </button>
            </td>
        `;
        
        tbody.appendChild(nuevaFila);
        actualizarContador();
        
        // Si hay valores propagados, validarlos
        setTimeout(() => {
            if (lpnValor) {
                validarCampo('lpn', lpnValor, numeroFila);
            }
            if (ubicacionValor) {
                validarCampo('ubicacion', ubicacionValor, numeroFila);
            }
        }, 500);
        
        return nuevaFila;
    }

    // Función para eliminar una fila
    function eliminarFila(id) {
        const fila = document.getElementById(`fila-${id}`);
        if (fila) {
            // Remover de datosDuplicados
            const inputLPN = fila.querySelector('input[name="lpn[]"]');
            const inputUbicacion = fila.querySelector('input[name="ubicacion[]"]');
            
            if (inputLPN && inputLPN.value) {
                datosDuplicados.lpn.delete(inputLPN.value);
            }
            if (inputUbicacion && inputUbicacion.value) {
                datosDuplicados.ubicacion.delete(inputUbicacion.value);
            }
            
            fila.remove();
            contadorFilas--;
            actualizarContador();
            reordenarNumeros();
            
            // Actualizar estado del botón
            actualizarEstadoBotonGuardar();
            
            // Si no hay filas, agregar una nueva
            if (contadorFilas === 0) {
                agregarFila(false);
            }
        }
    }

    // Función para reordenar números de fila
    function reordenarNumeros() {
        const filas = document.querySelectorAll('#cuerpoTabla tr');
        filas.forEach((fila, index) => {
            const celdaNumero = fila.querySelector('td:first-child');
            if (celdaNumero) {
                celdaNumero.textContent = (index + 1);
                fila.id = `fila-${index + 1}`;
                
                // Actualizar eventos
                const inputs = fila.querySelectorAll('input');
                inputs.forEach(input => {
                    if (input.name === 'lpn[]') {
                        input.setAttribute('onkeydown', `manejarTeclado(event, ${index + 1}, 'lpn')`);
                        input.setAttribute('onblur', `validarCampo('lpn', this.value, ${index + 1})`);
                    } else if (input.name === 'id_caja[]') {
                        input.setAttribute('onkeydown', `manejarTeclado(event, ${index + 1}, 'id_caja')`);
                    } else if (input.name === 'ubicacion[]') {
                        input.setAttribute('onkeydown', `manejarTeclado(event, ${index + 1}, 'ubicacion')`);
                        input.setAttribute('onblur', `validarCampo('ubicacion', this.value, ${index + 1})`);
                    }
                });
                
                // Actualizar botón de eliminar
                const botonEliminar = fila.querySelector('button');
                if (botonEliminar) {
                    botonEliminar.setAttribute('onclick', `eliminarFila(${index + 1})`);
                }
            }
        });
        
        // Actualizar contadorFilas
        contadorFilas = filas.length;
    }

    // Función para actualizar contador
    function actualizarContador() {
        const contador = document.getElementById('contadorRegistros');
        const texto = contadorFilas === 1 ? '1 registro' : `${contadorFilas} registros`;
        contador.textContent = texto;
    }

    // Función para manejar teclado
    function manejarTeclado(event, filaId, campo) {
        const tecla = event.key;
        
        if (tecla === 'Enter') {
            event.preventDefault();
            
            // Si es la última fila y todos los campos están llenos, agregar nueva fila
            if (filaId === contadorFilas) {
                const fila = document.getElementById(`fila-${filaId}`);
                const inputLPN = fila.querySelector('input[name="lpn[]"]');
                const inputIdCaja = fila.querySelector('input[name="id_caja[]"]');
                const inputUbicacion = fila.querySelector('input[name="ubicacion[]"]');
                
                // Verificar que todos los campos obligatorios estén llenos
                if (inputLPN.value.trim() && inputIdCaja.value.trim() && inputUbicacion.value.trim()) {
                    // Agregar nueva fila automáticamente
                    const nuevaFila = agregarFila(true);
                    
                    // Focus en el campo ID Caja de la nueva fila después de un breve delay
                    setTimeout(() => {
                        if (nuevaFila) {
                            const nuevoInputIdCaja = nuevaFila.querySelector('input[name="id_caja[]"]');
                            if (nuevoInputIdCaja) {
                                nuevoInputIdCaja.focus();
                                nuevoInputIdCaja.select();
                            }
                        }
                    }, 50);
                } else {
                    // Si faltan campos, mover al siguiente campo
                    moverSiguienteCampo(filaId, campo);
                }
            } else {
                // Si no es la última fila, mover al siguiente campo
                moverSiguienteCampo(filaId, campo);
            }
        } else if (event.ctrlKey && tecla === 's') {
            event.preventDefault();
            if (!document.getElementById('btnGuardar').disabled) {
                document.getElementById('ingresoForm').submit();
            }
        } else if (tecla === 'F2') {
            event.preventDefault();
            const fila = document.getElementById(`fila-${filaId}`);
            if (fila) {
                const inputIdCaja = fila.querySelector('input[name="id_caja[]"]');
                if (inputIdCaja) {
                    inputIdCaja.focus();
                    inputIdCaja.select();
                }
            }
        }
    }

    // Función para mover al siguiente campo
    function moverSiguienteCampo(filaId, campoActual) {
        const campos = ['lpn', 'id_caja', 'ubicacion'];
        const indiceActual = campos.indexOf(campoActual);
        
        if (indiceActual < campos.length - 1) {
            // Mover al siguiente campo en la misma fila
            const fila = document.getElementById(`fila-${filaId}`);
            const siguienteCampo = fila.querySelector(`input[name="${campos[indiceActual + 1]}[]"]`);
            if (siguienteCampo) siguienteCampo.focus();
        }
    }

    // Función para validar formulario
    function validarFormulario() {
        // Verificar si hay datos duplicados
        const totalDuplicados = datosDuplicados.lpn.size + datosDuplicados.ubicacion.size;
        if (totalDuplicados > 0) {
            alert('No se puede guardar porque hay datos duplicados en la base de datos. Por favor, corrija los campos marcados en rojo.');
            return false;
        }
        
        // Resto de la validación existente...
        let registrosValidos = 0;
        let registrosInvalidos = [];
        
        document.querySelectorAll('#cuerpoTabla tr').forEach((fila, index) => {
            const lpn = fila.querySelector('input[name="lpn[]"]').value.trim();
            const idCaja = fila.querySelector('input[name="id_caja[]"]').value.trim();
            const ubicacion = fila.querySelector('input[name="ubicacion[]"]').value.trim();
            
            if (lpn && idCaja && ubicacion) {
                registrosValidos++;
            } else {
                registrosInvalidos.push(index + 1);
            }
        });
        
        if (registrosValidos === 0) {
            alert('No hay registros válidos para guardar. Complete al menos un registro completo (LPN, ID Caja y Ubicación).');
            return false;
        }
        
        if (registrosInvalidos.length > 0) {
            if (!confirm(`Hay ${registrosInvalidos.length} fila(s) incompleta(s) (filas: ${registrosInvalidos.join(', ')}). ¿Desea guardar solo los registros completos?`)) {
                return false;
            }
        }
        
        // Mostrar loading
        const botonGuardar = document.getElementById('btnGuardar');
        const textoOriginal = botonGuardar.innerHTML;
        botonGuardar.innerHTML = '<span class="loading-spinner"></span> Guardando en MasterTable e Historico...';
        botonGuardar.disabled = true;
        
        // Restaurar botón si hay error (timeout de seguridad)
        setTimeout(() => {
            botonGuardar.innerHTML = textoOriginal;
            botonGuardar.disabled = false;
        }, 10000);
        
        return true;
    }

    // ============================================
    // INICIALIZACIÓN AL CARGAR LA PÁGINA
    // ============================================
    
    document.addEventListener('DOMContentLoaded', function() {
        // Agregar la primera fila al formulario
        agregarFila(false);
        
        // Focus en el primer campo
        setTimeout(() => {
            const primeraFila = document.getElementById('fila-1');
            if (primeraFila) {
                const primerInput = primeraFila.querySelector('input[name="lpn[]"]');
                if (primerInput) primerInput.focus();
            }
        }, 100);
        
        // Atajos de teclado globales
        document.addEventListener('keydown', function(event) {
            if (event.ctrlKey && event.key === 's') {
                event.preventDefault();
                if (!document.getElementById('btnGuardar').disabled) {
                    document.getElementById('ingresoForm').submit();
                }
            }
        });
        
        // Inicializar estado del botón
        actualizarEstadoBotonGuardar();
    });
    </script>
</body>
</html>