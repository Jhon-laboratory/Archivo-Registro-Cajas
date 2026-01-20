<?php
session_start();
ini_set('display_errors', 0); // Desactivar errores para AJAX
header('Content-Type: application/json');

// Configuración de conexión SQL Server
$host = 'Jorgeserver.database.windows.net';
$dbname = 'DPL';
$username = 'Jmmc';
$password = 'ChaosSoldier01';

$response = ['existe' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campo = $_POST['campo'] ?? '';
    $valor = $_POST['valor'] ?? '';
    
    if (!empty($campo) && !empty($valor)) {
        // Conexión usando sqlsrv
        $connectionInfo = array(
            "Database" => $dbname,
            "UID" => $username,
            "PWD" => $password,
            "CharacterSet" => "UTF-8"
        );
        
        $conn = sqlsrv_connect($host, $connectionInfo);
        
        if ($conn !== false) {
            // Determinar qué campo validar
            if ($campo === 'lpn') {
                $sql = "SELECT TOP 1 LPN FROM DPL.pruebas.MasterTable WHERE LPN = ?";
            } elseif ($campo === 'ubicacion') {
                $sql = "SELECT TOP 1 Ubicacion FROM DPL.pruebas.MasterTable WHERE Ubicacion = ?";
            } else {
                echo json_encode($response);
                exit;
            }
            
            $params = array($valor);
            $stmt = sqlsrv_prepare($conn, $sql, $params);
            
            if ($stmt && sqlsrv_execute($stmt)) {
                if (sqlsrv_fetch($stmt)) {
                    $response['existe'] = true;
                }
                sqlsrv_free_stmt($stmt);
            }
            
            sqlsrv_close($conn);
        }
    }
}

echo json_encode($response);
?>