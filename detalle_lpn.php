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
$caja_seleccionada = isset($_GET['caja']) ? trim($_GET['caja']) : ''; // NUEVO: ID de caja específica

if (empty($lpn_original) || empty($sede_original)) {
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
$caja_especifica = null; // NUEVO: Para guardar detalles de la caja seleccionada
$total_cajas = 0;
$mensaje = '';
$error = '';
$historico = [];
$usuario_actual = $_SESSION['usuario'];
$sede_usuario = $_SESSION['tienda'];

// Función para ajustar hora -5 horas
function horaAjustada($fecha_hora = null) {
    if ($fecha_hora) {
        // Si es un objeto DateTime
        if ($fecha_hora instanceof DateTime) {
            $fecha_ajustada = clone $fecha_hora;
            $fecha_ajustada->modify('-5 hours');
            return $fecha_ajustada;
        }
        // Si es string
        else if (is_string($fecha_hora)) {
            $fecha_obj = new DateTime($fecha_hora);
            $fecha_obj->modify('-5 hours');
            return $fecha_obj;
        }
    }
    // Si no se pasa parámetro, devuelve hora actual ajustada
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
        // NUEVO: Buscar información específica de la caja si se proporcionó
        if (!empty($caja_seleccionada)) {
            $sql_caja_especifica = "SELECT * 
                                   FROM DPL.pruebas.MasterTable 
                                   WHERE ID_Caja = ? AND LPN = ? AND Sede = ?";
            
            $params_caja = array($caja_seleccionada, $lpn_original, $sede_original);
            $stmt_caja = sqlsrv_prepare($conn, $sql_caja_especifica, $params_caja);
            
            if ($stmt_caja && sqlsrv_execute($stmt_caja)) {
                if ($row = sqlsrv_fetch_array($stmt_caja, SQLSRV_FETCH_ASSOC)) {
                    // Formatear fecha ajustando -5 horas
                    if ($row['FechaHora'] instanceof DateTime) {
                        $fecha_ajustada = horaAjustada($row['FechaHora']);
                        $row['FechaHora'] = $fecha_ajustada->format('d/m/Y H:i:s');
                    } else if (is_string($row['FechaHora'])) {
                        $fecha_ajustada = horaAjustada($row['FechaHora']);
                        $row['FechaHora'] = $fecha_ajustada->format('d/m/Y H:i:s');
                    }
                    $caja_especifica = $row;
                }
            }
        }
        
        // Obtener todas las cajas del LPN
        $sql_cajas = "SELECT * 
                     FROM DPL.pruebas.MasterTable 
                     WHERE LPN = ? AND Sede = ?
                     ORDER BY ID_Caja";
        
        $params_cajas = array($lpn_original, $sede_original);
        $stmt_cajas = sqlsrv_prepare($conn, $sql_cajas, $params_cajas);
        
        if ($stmt_cajas && sqlsrv_execute($stmt_cajas)) {
            while ($row = sqlsrv_fetch_array($stmt_cajas, SQLSRV_FETCH_ASSOC)) {
                // Formatear fecha ajustando -5 horas
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
        
        // Obtener histórico del LPN
        $sql_historico = "SELECT * 
                         FROM DPL.pruebas.Historico 
                         WHERE LPN = ? AND Sede = ?
                         ORDER BY FechaHora DESC";
        
        $params_historico = array($lpn_original, $sede_original);
        $stmt_historico = sqlsrv_prepare($conn, $sql_historico, $params_historico);
        
        if ($stmt_historico && sqlsrv_execute($stmt_historico)) {
            while ($row = sqlsrv_fetch_array($stmt_historico, SQLSRV_FETCH_ASSOC)) {
                // Formatear fecha ajustando -5 horas
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
        
        // Procesar modificaciones si se envió el formulario
        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            // Iniciar transacción
            sqlsrv_begin_transaction($conn);
            
            $modificaciones_realizadas = 0;
            $cambios_detalle = [];
            $nuevo_lpn_grupal = isset($_POST['nuevo_lpn_grupal']) ? trim($_POST['nuevo_lpn_grupal']) : '';
            $nueva_ubicacion_grupal = isset($_POST['nueva_ubicacion_grupal']) ? trim($_POST['nueva_ubicacion_grupal']) : '';
            
            // NUEVO: Procesar búsqueda por caja desde el formulario
            if (isset($_POST['buscar_caja'])) {
                $caja_buscar = isset($_POST['caja_buscar']) ? trim($_POST['caja_buscar']) : '';
                if (!empty($caja_buscar)) {
                    // Redirigir con el parámetro de caja
                    $url = "detalle_lpn.php?lpn=" . urlencode($lpn_original) . "&sede=" . urlencode($sede_original) . "&caja=" . urlencode($caja_buscar);
                    header("Location: $url");
                    exit;
                }
            }
            
            // NUEVO: Procesar modificación de caja específica
            if (!empty($caja_seleccionada) && isset($_POST['modificar_caja_especifica'])) {
                $nuevo_lpn_caja = isset($_POST['nuevo_lpn_caja']) ? trim($_POST['nuevo_lpn_caja']) : '';
                $nueva_ubicacion_caja = isset($_POST['nueva_ubicacion_caja']) ? trim($_POST['nueva_ubicacion_caja']) : '';
                
                if (!empty($nuevo_lpn_caja) || !empty($nueva_ubicacion_caja)) {
                    // Buscar la caja original
                    $caja_original = null;
                    foreach ($cajas as $c) {
                        if ($c['ID_Caja'] == $caja_seleccionada) {
                            $caja_original = $c;
                            break;
                        }
                    }
                    
                    if ($caja_original) {
                        $sql_update_caja = "UPDATE DPL.pruebas.MasterTable 
                                           SET LPN = ?, Ubicacion = ?, Usuario = ?, FechaHora = ?
                                           WHERE ID_Caja = ? AND LPN = ? AND Sede = ?";
                        
                        $fecha_actual = date('Y-m-d H:i:s', strtotime('-5 hours'));
                        $params_update_caja = array(
                            !empty($nuevo_lpn_caja) ? $nuevo_lpn_caja : $caja_original['LPN'],
                            !empty($nueva_ubicacion_caja) ? $nueva_ubicacion_caja : $caja_original['Ubicacion'],
                            $usuario_actual,
                            $fecha_actual,
                            $caja_seleccionada,
                            $lpn_original,
                            $sede_original
                        );
                        
                        $stmt_update_caja = sqlsrv_prepare($conn, $sql_update_caja, $params_update_caja);
                        
                        if ($stmt_update_caja && sqlsrv_execute($stmt_update_caja)) {
                            $modificaciones_realizadas++;
                            
                            // Crear mensaje para histórico
                            $accion_caja = "MODIFICACIÓN DE CAJA ESPECÍFICA: Caja $caja_seleccionada ";
                            $cambios_caja = [];
                            
                            if (!empty($nuevo_lpn_caja) && $nuevo_lpn_caja != $caja_original['LPN']) {
                                $cambios_caja[] = "LPN cambiado de '{$caja_original['LPN']}' a '$nuevo_lpn_caja'";
                            }
                            if (!empty($nueva_ubicacion_caja) && $nueva_ubicacion_caja != $caja_original['Ubicacion']) {
                                $cambios_caja[] = "Ubicación cambiada de '{$caja_original['Ubicacion']}' a '$nueva_ubicacion_caja'";
                            }
                            
                            $accion_caja .= implode(" y ", $cambios_caja);
                            $accion_caja .= " - Usuario: $usuario_actual";
                            
                            // Insertar en histórico
                            $sql_hist_caja = "INSERT INTO DPL.pruebas.Historico 
                                            (LPN, CantidadCajas, Ubicacion, FechaHora, Usuario, Accion, Sede) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                            
                            $params_hist_caja = array(
                                !empty($nuevo_lpn_caja) ? $nuevo_lpn_caja : $caja_original['LPN'],
                                1,
                                !empty($nueva_ubicacion_caja) ? $nueva_ubicacion_caja : $caja_original['Ubicacion'],
                                $fecha_actual,
                                $usuario_actual,
                                $accion_caja,
                                $sede_original
                            );
                            
                            sqlsrv_prepare($conn, $sql_hist_caja, $params_hist_caja);
                            sqlsrv_execute(sqlsrv_prepare($conn, $sql_hist_caja, $params_hist_caja));
                            
                            $mensaje = "✅ Caja $caja_seleccionada modificada correctamente.";
                            if (!empty($nuevo_lpn_caja) && $nuevo_lpn_caja != $lpn_original) {
                                $lpn_original = $nuevo_lpn_caja;
                            }
                        }
                    }
                }
            }
            
            // Verificar si se trata de modificación grupal
            $modificacion_grupal = false;
            if (!empty($nuevo_lpn_grupal) || !empty($nueva_ubicacion_grupal)) {
                $modificacion_grupal = true;
            }
            
            // Procesar modificaciones grupales
            if ($modificacion_grupal && empty($caja_seleccionada)) {
                $cajas_modificadas = [];
                
                // Preparar los nuevos valores
                $nuevo_lpn = empty($nuevo_lpn_grupal) ? $lpn_original : $nuevo_lpn_grupal;
                $nueva_ubicacion = empty($nueva_ubicacion_grupal) ? null : $nueva_ubicacion_grupal;
                
                // Actualizar todas las cajas del LPN
                foreach ($cajas as $caja) {
                    $sql_update = "UPDATE DPL.pruebas.MasterTable 
                                  SET LPN = ?, Ubicacion = ?, Usuario = ?, FechaHora = ?
                                  WHERE ID_Caja = ? AND LPN = ? AND Sede = ?";
                    
                    $fecha_actual = date('Y-m-d H:i:s', strtotime('-5 hours'));
                    $params_update = array(
                        $nuevo_lpn,
                        $nueva_ubicacion ?: $caja['Ubicacion'],
                        $usuario_actual,
                        $fecha_actual,
                        $caja['ID_Caja'],
                        $lpn_original,
                        $sede_original
                    );
                    
                    $stmt_update = sqlsrv_prepare($conn, $sql_update, $params_update);
                    
                    if ($stmt_update && sqlsrv_execute($stmt_update)) {
                        $modificaciones_realizadas++;
                        $cajas_modificadas[] = $caja['ID_Caja'];
                    }
                }
                
                if ($modificaciones_realizadas > 0) {
                    // Crear mensaje para el histórico
                    $accion = "MODIFICACIÓN GRUPAL: ";
                    $cambios = [];
                    
                    if (!empty($nuevo_lpn_grupal) && $nuevo_lpn_grupal != $lpn_original) {
                        $cambios[] = "LPN cambiado de '$lpn_original' a '$nuevo_lpn_grupal'";
                    }
                    if (!empty($nueva_ubicacion_grupal)) {
                        $cambios[] = "Ubicación cambiada a '$nueva_ubicacion_grupal'";
                    }
                    
                    $accion .= implode(" y ", $cambios);
                    $accion .= " - Cajas afectadas: " . implode(", ", $cajas_modificadas);
                    $accion .= " - Usuario: $usuario_actual";
                    
                    // Insertar en histórico
                    $sql_hist = "INSERT INTO DPL.pruebas.Historico 
                                (LPN, CantidadCajas, Ubicacion, FechaHora, Usuario, Accion, Sede) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)";
                    
                    $params_hist = array(
                        $nuevo_lpn_grupal ?: $lpn_original,
                        $modificaciones_realizadas,
                        $nueva_ubicacion_grupal ?: $cajas[0]['Ubicacion'],
                        $fecha_actual,
                        $usuario_actual,
                        $accion,
                        $sede_original
                    );
                    
                    sqlsrv_prepare($conn, $sql_hist, $params_hist);
                    sqlsrv_execute(sqlsrv_prepare($conn, $sql_hist, $params_hist));
                    
                    $mensaje = "✅ Se modificaron $modificaciones_realizadas cajas correctamente.";
                    $lpn_original = $nuevo_lpn_grupal ?: $lpn_original;
                }
            } 
            // Procesar modificaciones individuales
            else if (isset($_POST['id_caja']) && is_array($_POST['id_caja'])) {
                foreach ($_POST['id_caja'] as $index => $id_caja) {
                    $nuevo_lpn_individual = isset($_POST['lpn'][$index]) ? trim($_POST['lpn'][$index]) : '';
                    $nueva_ubicacion_individual = isset($_POST['ubicacion'][$index]) ? trim($_POST['ubicacion'][$index]) : '';
                    
                    // Buscar la caja original para comparar
                    $caja_original = null;
                    foreach ($cajas as $c) {
                        if ($c['ID_Caja'] == $id_caja) {
                            $caja_original = $c;
                            break;
                        }
                    }
                    
                    if (!$caja_original) continue;
                    
                    // Verificar si hay cambios
                    $hay_cambios = false;
                    $cambios_caja = [];
                    
                    if (!empty($nuevo_lpn_individual) && $nuevo_lpn_individual != $caja_original['LPN']) {
                        $hay_cambios = true;
                        $cambios_caja[] = "LPN de '{$caja_original['LPN']}' a '$nuevo_lpn_individual'";
                    }
                    
                    if (!empty($nueva_ubicacion_individual) && $nueva_ubicacion_individual != $caja_original['Ubicacion']) {
                        $hay_cambios = true;
                        $cambios_caja[] = "Ubicación de '{$caja_original['Ubicacion']}' a '$nueva_ubicacion_individual'";
                    }
                    
                    if ($hay_cambios) {
                        $sql_update = "UPDATE DPL.pruebas.MasterTable 
                                      SET LPN = ?, Ubicacion = ?, Usuario = ?, FechaHora = ?
                                      WHERE ID_Caja = ?";
                        
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
                            $cambios_detalle[] = "Caja $id_caja: " . implode(" y ", $cambios_caja);
                            
                            // Insertar en histórico para cambios individuales importantes
                            if (!empty($nuevo_lpn_individual) && $nuevo_lpn_individual != $caja_original['LPN']) {
                                $accion_individual = "MODIFICACIÓN INDIVIDUAL: Caja $id_caja cambió de LPN '{$caja_original['LPN']}' a '$nuevo_lpn_individual'";
                                if (!empty($nueva_ubicacion_individual)) {
                                    $accion_individual .= " y ubicación a '$nueva_ubicacion_individual'";
                                }
                                $accion_individual .= " - Usuario: $usuario_actual";
                                
                                $sql_hist_ind = "INSERT INTO DPL.pruebas.Historico 
                                                (LPN, CantidadCajas, Ubicacion, FechaHora, Usuario, Accion, Sede) 
                                                VALUES (?, ?, ?, ?, ?, ?, ?)";
                                
                                $params_hist_ind = array(
                                    $nuevo_lpn_individual,
                                    1,
                                    $nueva_ubicacion_individual ?: $caja_original['Ubicacion'],
                                    $fecha_actual,
                                    $usuario_actual,
                                    $accion_individual,
                                    $sede_original
                                );
                                
                                sqlsrv_prepare($conn, $sql_hist_ind, $params_hist_ind);
                                sqlsrv_execute(sqlsrv_prepare($conn, $sql_hist_ind, $params_hist_ind));
                            }
                        }
                    }
                }
                
                if ($modificaciones_realizadas > 0) {
                    $mensaje = "✅ Se modificaron $modificaciones_realizadas cajas correctamente.";
                    if (!empty($cambios_detalle)) {
                        $mensaje .= "<br><small>Detalles: " . implode("; ", $cambios_detalle) . "</small>";
                    }
                }
            }
            
            // Confirmar o revertir transacción
            if ($modificaciones_realizadas > 0) {
                sqlsrv_commit($conn);
                // Recargar datos actualizados
                $url = "detalle_lpn.php?lpn=" . urlencode($lpn_original) . "&sede=" . urlencode($sede_original);
                if (!empty($caja_seleccionada)) {
                    $url .= "&caja=" . urlencode($caja_seleccionada);
                }
                if (!empty($mensaje)) {
                    $url .= "&msg=" . urlencode($mensaje);
                }
                header("Location: $url");
                exit;
            } else {
                sqlsrv_rollback($conn);
                $error = "⚠️ No se realizaron modificaciones. Verifique los datos ingresados.";
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
    <title>Detalle LPN - RANSA</title>

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
        
        /* Estilos para el contenido específico */
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
        
        .lpn-info {
            background: rgba(255, 255, 255, 0.95);
            color: #333;
            padding: 8px 15px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 12px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 8px;
        }
        
        /* NUEVO: Panel de búsqueda por caja */
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
        
        .search-caja-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .search-caja-input {
            flex: 1;
        }
        
        /* NUEVO: Fila de caja seleccionada */
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
        
        /* Panel de modificación grupal */
        .group-edit-panel {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border: 2px dashed #009a3f;
        }
        
        .group-edit-title {
            color: #009a3f;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .group-edit-form {
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
        
        .btn-detalle {
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
        
        .btn-detalle:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0, 154, 63, 0.3);
        }
        
        .btn-danger-detalle {
            background: linear-gradient(135deg, #dc3545, #c82333);
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
        
        .btn-danger-detalle:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(220, 53, 69, 0.3);
        }
        
        .btn-warning-detalle {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: #212529;
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
        
        .btn-warning-detalle:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }
        
        .btn-secondary-detalle {
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
        
        .btn-secondary-detalle:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }
        
        /* Tabla de cajas */
        .cajas-container, .historico-container {
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
        
        .cajas-table, .historico-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        
        .cajas-table th {
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
        
        .cajas-table td {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        
        .cajas-table tr:hover {
            background-color: #f9f9f9;
        }
        
        .cajas-table tr.caja-seleccionada-tabla {
            background-color: #ffe6e6;
            border-left: 3px solid #dc3545;
        }
        
        .cajas-table tr.editing {
            background-color: #fff8e1;
        }
        
        .table-input {
            width: 100%;
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 12px;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        
        .table-input:focus {
            border-color: #009a3f;
            box-shadow: 0 0 0 2px rgba(0, 154, 63, 0.1);
            outline: none;
            background-color: #fff8e1;
        }
        
        .table-input.readonly {
            background-color: #f5f5f5;
            color: #666;
            cursor: not-allowed;
            border: 1px solid #eee;
        }
        
        /* Alertas */
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
        
        .alert-info-detalle {
            background: rgba(23, 162, 184, 0.1);
            color: #0c5460;
            border-left: 3px solid #17a2b8;
        }
        
        .alert-warning-detalle {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
            border-left: 3px solid #ffc107;
        }
        
        .alert-danger-detalle {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border-left: 3px solid #dc3545;
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
        
        .badge-caja-especifica {
            background: #dc3545;
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
        
        /* Historico específico */
        .historico-table th {
            background: linear-gradient(135deg, rgba(23, 162, 184, 0.9), rgba(23, 162, 184, 0.7));
            color: white;
        }
        
        .historico-table tr:hover {
            background-color: #f0f8ff;
        }
        
        .accion-cell {
            max-width: 300px;
            word-wrap: break-word;
        }
        
        /* Botones de acción */
        .btn-action {
            padding: 4px 8px;
            font-size: 11px;
            border-radius: 3px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        
        .btn-edit {
            background: #17a2b8;
            color: white;
        }
        
        .btn-edit:hover {
            background: #138496;
            transform: translateY(-1px);
        }
        
        .btn-save {
            background: #28a745;
            color: white;
        }
        
        .btn-save:hover {
            background: #218838;
            transform: translateY(-1px);
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }
        
        /* Tabs */
        .nav-tabs {
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 15px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #666;
            font-weight: 600;
            font-size: 12px;
            padding: 8px 15px;
            border-radius: 5px 5px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            color: #009a3f;
            background-color: #fff;
            border-bottom: 2px solid #009a3f;
        }
        
        .nav-tabs .nav-link:hover {
            color: #009a3f;
            border-color: transparent;
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
        
        /* Footer específico */
        .footer-detalle {
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
            
            .search-caja-form {
                flex-direction: column;
            }
            
            .caja-seleccionada-row {
                grid-template-columns: 1fr;
            }
            
            .group-edit-form {
                grid-template-columns: 1fr;
            }
            
            .cajas-table, .historico-table {
                font-size: 11px;
                min-width: 700px; /* Para scroll horizontal en móviles */
            }
            
            .cajas-table th,
            .cajas-table td,
            .historico-table th,
            .historico-table td {
                padding: 6px 4px;
            }
            
            .btn-detalle, .btn-danger-detalle, .btn-warning-detalle, .btn-secondary-detalle {
                width: 100%;
                justify-content: center;
                margin-bottom: 5px;
            }
            
            .cajas-container, .historico-container {
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
                            <span style="font-size: 12px; margin-left: 4px;">Detalle LPN</span>
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
                                    <a href="detalle_lpn.php?lpn=<?php echo urlencode($lpn_original); ?>&sede=<?php echo urlencode($sede_original); ?><?php echo !empty($caja_seleccionada) ? '&caja=' . urlencode($caja_seleccionada) : ''; ?>"><i class="fa fa-eye"></i> Detalle LPN</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- FOOTER EXACTAMENTE IGUAL -->
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
                <div class="page-title">
                    
                </div>
                
                <div class="clearfix"></div>
                
                <div class="row">
                    <div class="col-md-12 col-sm-12">
                        <div class="x_panel">
                            
                            <div class="x_content">
                                <!-- Sección de Bienvenida -->
                                <div class="welcome-section">
                                    <div class="welcome-title">
                                        <span><i class="fa fa-cube"></i> Detalle del Pallet</span>
                                        <div class="lpn-info">
                                            <i class="fa fa-barcode"></i> LPN: <strong><?php echo htmlspecialchars($lpn_original); ?></strong>
                                            <i class="fa fa-map-marker"></i> Sede: <strong><?php echo htmlspecialchars($sede_original); ?></strong>
                                            <i class="fa fa-cubes"></i> Cajas: <strong><?php echo $total_cajas; ?></strong>
                                            <?php if (!empty($caja_seleccionada)): ?>
                                                <span class="badge-caja-especifica">
                                                    <i class="fa fa-box"></i> Caja: <?php echo htmlspecialchars($caja_seleccionada); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

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

                                <!-- NUEVO: Panel de Búsqueda por Caja -->
                                <div class="search-caja-panel">
                                    <div class="search-caja-title">
                                        <i class="fa fa-search"></i> Buscar Caja Específica en este LPN
                                    </div>
                                    <form method="POST" class="search-caja-form">
                                        <div class="search-caja-input">
                                            <label style="font-size: 11px; font-weight: 600; color: #555; margin-bottom: 4px; display: block;">
                                                Ingrese ID de Caja:
                                            </label>
                                            <input type="text" 
                                                   name="caja_buscar" 
                                                   class="form-control" 
                                                   placeholder="Ej: CAJA-001"
                                                   value="<?php echo htmlspecialchars($caja_seleccionada); ?>"
                                                   autocomplete="off">
                                        </div>
                                        <div>
                                            <button type="submit" class="btn-danger-detalle" name="buscar_caja" value="1">
                                                <i class="fa fa-search"></i> Buscar Caja
                                            </button>
                                            <?php if (!empty($caja_seleccionada)): ?>
                                                <a href="detalle_lpn.php?lpn=<?php echo urlencode($lpn_original); ?>&sede=<?php echo urlencode($sede_original); ?>" 
                                                   class="btn-secondary-detalle" style="margin-left: 5px;">
                                                    <i class="fa fa-times"></i> Limpiar
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>

                                <!-- NUEVO: Fila de Caja Seleccionada (si existe) -->
                                <?php if (!empty($caja_seleccionada) && !empty($caja_especifica)): ?>
                                    <div class="caja-seleccionada-row">
                                        <div class="caja-seleccionada-item">
                                            <div class="caja-seleccionada-label">ID de Caja</div>
                                            <div class="caja-seleccionada-value"><?php echo htmlspecialchars($caja_especifica['ID_Caja']); ?></div>
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
                                        <div class="caja-seleccionada-item">
                                            <div class="caja-seleccionada-label">Usuario</div>
                                            <div class="caja-seleccionada-value"><?php echo htmlspecialchars($caja_especifica['Usuario']); ?></div>
                                        </div>
                                        <div class="caja-seleccionada-item">
                                            <div class="caja-seleccionada-label">Sede</div>
                                            <div class="caja-seleccionada-value">
                                                <span class="badge-sede badge-<?php echo strtolower($caja_especifica['Sede']); ?>">
                                                    <?php echo htmlspecialchars($caja_especifica['Sede']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- NUEVO: Formulario para modificar solo esta caja -->
                                    <div class="group-edit-panel" style="border-color: #dc3545;">
                                        <div class="group-edit-title">
                                            <i class="fa fa-edit"></i> Modificar Solo Esta Caja
                                        </div>
                                        <form method="POST" onsubmit="return confirm('¿Está seguro de modificar solo esta caja específica?')">
                                            <div class="group-edit-form">
                                                <div>
                                                    <label>Nuevo LPN (opcional):</label>
                                                    <input type="text" 
                                                           name="nuevo_lpn_caja" 
                                                           class="form-control" 
                                                           placeholder="Dejar vacío para mantener '<?php echo htmlspecialchars($caja_especifica['LPN']); ?>'"
                                                           value="">
                                                </div>
                                                
                                                <div>
                                                    <label>Nueva Ubicación (opcional):</label>
                                                    <input type="text" 
                                                           name="nueva_ubicacion_caja" 
                                                           class="form-control" 
                                                           placeholder="Dejar vacío para mantener '<?php echo htmlspecialchars($caja_especifica['Ubicacion']); ?>'"
                                                           value="">
                                                </div>
                                                
                                                <div>
                                                    <button type="submit" class="btn-danger-detalle" name="modificar_caja_especifica" value="1">
                                                        <i class="fa fa-edit"></i> Modificar Solo Esta Caja
                                                    </button>
                                                    <small style="display: block; color: #666; margin-top: 5px; font-size: 10px;">
                                                        Solo afectará a la caja <?php echo htmlspecialchars($caja_seleccionada); ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                <?php elseif (!empty($caja_seleccionada)): ?>
                                    <div class="alert-detalle alert-warning-detalle">
                                        <i class="fa fa-exclamation-triangle"></i> 
                                        No se encontró la caja "<?php echo htmlspecialchars($caja_seleccionada); ?>" en este LPN.
                                    </div>
                                <?php endif; ?>

                                <!-- Panel de Modificación Grupal (solo si no hay caja específica seleccionada) -->
                                <?php if (empty($caja_seleccionada)): ?>
                                <div class="group-edit-panel">
                                    <div class="group-edit-title">
                                        <i class="fa fa-users"></i> Modificación Masiva de Todas las Cajas
                                    </div>
                                    <form method="POST" id="formGrupal" onsubmit="return confirm('¿Está seguro de aplicar estos cambios a TODAS las <?php echo $total_cajas; ?> cajas?')">
                                        <div class="group-edit-form">
                                            <div>
                                                <label>Nuevo LPN (opcional):</label>
                                                <input type="text" 
                                                       name="nuevo_lpn_grupal" 
                                                       class="form-control" 
                                                       placeholder="Dejar vacío para mantener '<?php echo htmlspecialchars($lpn_original); ?>'"
                                                       value="">
                                            </div>
                                            
                                            <div>
                                                <label>Nueva Ubicación (opcional):</label>
                                                <input type="text" 
                                                       name="nueva_ubicacion_grupal" 
                                                       class="form-control" 
                                                       placeholder="Dejar vacío para mantener ubicación actual"
                                                       value="">
                                            </div>
                                            
                                            <div>
                                                <button type="submit" class="btn-warning-detalle" name="accion_grupal" value="aplicar">
                                                    <i class="fa fa-edit"></i> Aplicar a Todas las Cajas
                                                </button>
                                                <small style="display: block; color: #666; margin-top: 5px; font-size: 10px;">
                                                    Afectará a <?php echo $total_cajas; ?> cajas
                                                </small>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                <?php endif; ?>

                                <!-- Tabs para Cajas e Histórico -->
                                <ul class="nav nav-tabs" id="detalleTabs" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="cajas-tab" data-toggle="tab" href="#cajas" role="tab">
                                            <i class="fa fa-cubes"></i> Cajas (<?php echo $total_cajas; ?>)
                                            <?php if (!empty($caja_seleccionada)): ?>
                                                <span class="badge-caja-especifica" style="margin-left: 5px;">
                                                    <i class="fa fa-box"></i> 1 seleccionada
                                                </span>
                                            <?php endif; ?>
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
                                        <form method="POST" id="formIndividual" onsubmit="return validarFormulario()">
                                            <div class="cajas-container">
                                                <div class="section-title">
                                                    <i class="fa fa-edit"></i> Modificación Individual de Cajas
                                                    <span style="margin-left: auto; font-size: 11px; color: #666;">
                                                        Puede editar LPN y Ubicación individualmente
                                                    </span>
                                                </div>
                                                
                                                <?php if ($total_cajas > 0): ?>
                                                    <table class="cajas-table">
                                                        <thead>
                                                            <tr>
                                                                <th width="5%">#</th>
                                                                <th width="15%">ID Caja</th>
                                                                <th width="15%">LPN</th>
                                                                <th width="15%">Ubicación</th>
                                                                <th width="15%">Usuario</th>
                                                                <th width="15%">Fecha/Hora</th>
                                                                <th width="10%">Sede</th>
                                                                <th width="10%">Acciones</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($cajas as $index => $caja): ?>
                                                                <tr class="<?php echo (!empty($caja_seleccionada) && $caja['ID_Caja'] == $caja_seleccionada) ? 'caja-seleccionada-tabla' : ''; ?>">
                                                                    <td>
                                                                        <?php echo $index + 1; ?>
                                                                        <?php if (!empty($caja_seleccionada) && $caja['ID_Caja'] == $caja_seleccionada): ?>
                                                                            <span style="color: #dc3545; margin-left: 3px;">★</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td>
                                                                        <?php echo htmlspecialchars($caja['ID_Caja']); ?>
                                                                        <input type="hidden" name="id_caja[]" value="<?php echo htmlspecialchars($caja['ID_Caja']); ?>">
                                                                    </td>
                                                                    <td>
                                                                        <input type="text" 
                                                                               name="lpn[]" 
                                                                               class="table-input" 
                                                                               value="<?php echo htmlspecialchars($caja['LPN']); ?>"
                                                                               placeholder="Nuevo LPN">
                                                                    </td>
                                                                    <td>
                                                                        <input type="text" 
                                                                               name="ubicacion[]" 
                                                                               class="table-input" 
                                                                               value="<?php echo htmlspecialchars($caja['Ubicacion']); ?>"
                                                                               placeholder="Nueva ubicación">
                                                                    </td>
                                                                    <td>
                                                                        <input type="text" 
                                                                               class="table-input readonly" 
                                                                               value="<?php echo htmlspecialchars($caja['Usuario']); ?>" 
                                                                               readonly>
                                                                    </td>
                                                                    <td>
                                                                        <input type="text" 
                                                                               class="table-input readonly" 
                                                                               value="<?php echo htmlspecialchars($caja['FechaHora']); ?>" 
                                                                               readonly>
                                                                    </td>
                                                                    <td style="text-align: center;">
                                                                        <span class="badge-sede badge-<?php echo strtolower($caja['Sede']); ?>">
                                                                            <?php echo htmlspecialchars($caja['Sede']); ?>
                                                                        </span>
                                                                    </td>
                                                                    <td style="text-align: center;">
                                                                        <button type="button" class="btn-action btn-edit" onclick="activarEdicion(this)">
                                                                            <i class="fa fa-edit"></i> Editar
                                                                        </button>
                                                                        <?php if (!empty($caja_seleccionada) && $caja['ID_Caja'] == $caja_seleccionada): ?>
                                                                            <br>
                                                                            <small style="color: #dc3545; font-size: 9px;">
                                                                                <i class="fa fa-search"></i> Búsqueda actual
                                                                            </small>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                    
                                                    <!-- Botones de acción -->
                                                    <div style="margin-top: 15px; text-align: center;">
                                                        <button type="submit" class="btn-detalle" name="accion_individual" value="guardar">
                                                            <i class="fa fa-save"></i> Guardar Cambios Individuales
                                                        </button>
                                                        <button type="button" class="btn-secondary-detalle" onclick="limpiarEdiciones()">
                                                            <i class="fa fa-times"></i> Limpiar Cambios
                                                        </button>
                                                        <button type="button" class="btn-secondary-detalle" onclick="window.history.back()">
                                                            <i class="fa fa-arrow-left"></i> Volver al Listado
                                                        </button>
                                                    </div>
                                                    
                                                <?php else: ?>
                                                    <div class="alert-detalle alert-warning-detalle">
                                                        <i class="fa fa-exclamation-triangle"></i> 
                                                        No se encontraron cajas para este LPN en la sede seleccionada.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </div>

                                    <!-- Tab de Histórico -->
                                    <div class="tab-pane fade" id="historico" role="tabpanel">
                                        <div class="historico-container">
                                            <div class="section-title">
                                                <i class="fa fa-history"></i> Histórico de Cambios
                                                <span style="margin-left: auto; font-size: 11px; color: #666;">
                                                    Registro de todas las acciones realizadas
                                                </span>
                                            </div>
                                            
                                            <?php if (!empty($historico)): ?>
                                                <table class="historico-table">
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
                                                        <?php foreach ($historico as $registro): ?>
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
                                                                <td class="accion-cell">
                                                                    <small style="color: #555;">
                                                                        <?php echo htmlspecialchars($registro['Accion']); ?>
                                                                    </small>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            <?php else: ?>
                                                <div class="alert-detalle alert-info-detalle">
                                                    <i class="fa fa-info-circle"></i> 
                                                    No hay registro histórico para este LPN.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Instrucciones -->
                                <div class="instructions">
                                    <h5><i class="fa fa-lightbulb-o"></i> Instrucciones de Uso:</h5>
                                    <ul>
                                        <?php if (!empty($caja_seleccionada)): ?>
                                            <li><strong>Caja Específica:</strong> Está visualizando/modificando solo la caja <strong><?php echo htmlspecialchars($caja_seleccionada); ?></strong></li>
                                            <li><strong>Modificación Individual:</strong> Los cambios solo afectarán a esta caja específica</li>
                                        <?php else: ?>
                                            <li><strong>Modificación Grupal:</strong> Cambia LPN/Ubicación de TODAS las cajas del pallet</li>
                                        <?php endif; ?>
                                        <li><strong>Modificación Individual:</strong> Edite cada caja por separado usando los campos</li>
                                        <li><strong>LPN:</strong> Si cambia el LPN, el pallet se "traslada" a otro código</li>
                                        <li><strong>Ubicación:</strong> Cambia la ubicación física del pallet/caja</li>
                                        <li><strong>Ctrl+S:</strong> Guarda cambios individuales</li>
                                        <li><strong>Ctrl+G:</strong> Va al formulario grupal</li>
                                        <li><strong>Esc:</strong> Cancela la edición actual</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER CON CLASES ESPECÍFICAS -->
            <footer class="footer-detalle">
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

        // Activar pestañas Bootstrap
        $(document).ready(function() {
            $('#detalleTabs a').on('click', function (e) {
                e.preventDefault();
                $(this).tab('show');
            });
        });

        // Función para activar edición de una fila
        function activarEdicion(boton) {
            const fila = boton.closest('tr');
            const inputs = fila.querySelectorAll('input[type="text"]:not(.readonly)');
            
            // Activar todos los inputs editables
            inputs.forEach(input => {
                input.style.backgroundColor = '#fff8e1';
                input.style.borderColor = '#009a3f';
                input.focus();
            });
            
            // Cambiar botón a "Guardar"
            boton.innerHTML = '<i class="fa fa-save"></i> Guardar';
            boton.className = 'btn-action btn-save';
            boton.onclick = function() { guardarEdicion(this); };
            
            fila.classList.add('editing');
        }

        // Función para guardar edición de una fila
        function guardarEdicion(boton) {
            const fila = boton.closest('tr');
            const inputs = fila.querySelectorAll('input[type="text"]:not(.readonly)');
            
            // Restaurar estilo normal
            inputs.forEach(input => {
                input.style.backgroundColor = '';
                input.style.borderColor = '#ddd';
            });
            
            // Cambiar botón a "Editar"
            boton.innerHTML = '<i class="fa fa-edit"></i> Editar';
            boton.className = 'btn-action btn-edit';
            boton.onclick = function() { activarEdicion(this); };
            
            fila.classList.remove('editing');
            
            // Mostrar mensaje de confirmación
            const idCaja = fila.querySelector('input[name="id_caja[]"]').value;
            alert(`Cambios para la caja ${idCaja} listos para guardar. Presione "Guardar Cambios Individuales" al final.`);
        }

        // Función para limpiar todas las ediciones
        function limpiarEdiciones() {
            if (confirm('¿Está seguro de que desea descartar todos los cambios no guardados?')) {
                document.querySelectorAll('.cajas-table tr').forEach(fila => {
                    const inputs = fila.querySelectorAll('input[type="text"]:not(.readonly)');
                    inputs.forEach(input => {
                        input.style.backgroundColor = '';
                        input.style.borderColor = '#ddd';
                    });
                    
                    const botones = fila.querySelectorAll('.btn-action');
                    botones.forEach(boton => {
                        if (boton.classList.contains('btn-save')) {
                            boton.innerHTML = '<i class="fa fa-edit"></i> Editar';
                            boton.className = 'btn-action btn-edit';
                            boton.onclick = function() { activarEdicion(this); };
                        }
                    });
                    
                    fila.classList.remove('editing');
                });
            }
        }

        // Función para validar formulario individual
        function validarFormulario() {
            let cambiosDetectados = false;
            let cambiosDetalle = [];
            
            document.querySelectorAll('.cajas-table tr').forEach((fila, index) => {
                const idCajaInput = fila.querySelector('input[name="id_caja[]"]');
                const lpnInput = fila.querySelector('input[name="lpn[]"]');
                const ubicacionInput = fila.querySelector('input[name="ubicacion[]"]');
                
                if (idCajaInput && lpnInput && ubicacionInput) {
                    const idCaja = idCajaInput.value;
                    const nuevoLPN = lpnInput.value.trim();
                    const nuevaUbicacion = ubicacionInput.value.trim();
                    
                    if (nuevoLPN || nuevaUbicacion) {
                        cambiosDetectados = true;
                        cambiosDetalle.push(`Caja ${idCaja}`);
                    }
                }
            });
            
            if (!cambiosDetectados) {
                alert('No se detectaron cambios para guardar. Modifique al menos un campo LPN o Ubicación.');
                return false;
            }
            
            return confirm(`¿Está seguro de guardar los cambios en ${cambiosDetalle.length} caja(s)?`);
        }

        // Atajos de teclado
        document.addEventListener('keydown', function(event) {
            // Ctrl+S para guardar cambios individuales
            if (event.ctrlKey && event.key === 's') {
                event.preventDefault();
                document.getElementById('formIndividual').submit();
            }
            
            // Ctrl+G para ir a modificación grupal (solo si no hay caja específica)
            <?php if (empty($caja_seleccionada)): ?>
            if (event.ctrlKey && event.key === 'g') {
                event.preventDefault();
                document.querySelector('input[name="nuevo_lpn_grupal"]').focus();
            }
            <?php endif; ?>
            
            // Ctrl+B para buscar caja
            if (event.ctrlKey && event.key === 'b') {
                event.preventDefault();
                const cajaInput = document.querySelector('input[name="caja_buscar"]');
                if (cajaInput) {
                    cajaInput.focus();
                    cajaInput.select();
                }
            }
            
            // Esc para limpiar ediciones
            if (event.key === 'Escape') {
                const elementoActivo = document.activeElement;
                if (elementoActivo.tagName === 'INPUT' && !elementoActivo.classList.contains('readonly')) {
                    elementoActivo.style.backgroundColor = '';
                    elementoActivo.style.borderColor = '#ddd';
                }
            }
        });

        // Auto-focus en el campo de búsqueda de caja
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                <?php if (!empty($caja_seleccionada)): ?>
                    // Si hay caja específica, enfocar en su formulario de modificación
                    const cajaInput = document.querySelector('input[name="nuevo_lpn_caja"]');
                    if (cajaInput) {
                        cajaInput.focus();
                    }
                <?php else: ?>
                    // Si no hay caja específica, enfocar en el buscador
                    const searchInput = document.querySelector('input[name="caja_buscar"]');
                    if (searchInput) {
                        searchInput.focus();
                    }
                <?php endif; ?>
            }, 100);
        });

        // Confirmación para formulario grupal
        <?php if (empty($caja_seleccionada)): ?>
        document.getElementById('formGrupal').addEventListener('submit', function(e) {
            const nuevoLPN = this.querySelector('input[name="nuevo_lpn_grupal"]').value.trim();
            const nuevaUbicacion = this.querySelector('input[name="nueva_ubicacion_grupal"]').value.trim();
            
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

        // NUEVO: Resaltar la caja seleccionada en la tabla
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($caja_seleccionada)): ?>
                // Hacer scroll a la caja seleccionada
                const cajaSeleccionada = document.querySelector('.caja-seleccionada-tabla');
                if (cajaSeleccionada) {
                    setTimeout(() => {
                        cajaSeleccionada.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 300);
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>