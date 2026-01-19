<?php
session_start();
ini_set('display_errors', 0); // Mejor desactivar en producción
error_reporting(0);

// Configuración
define('DB_HOST', 'Jorgeserver.database.windows.net');
define('DB_NAME', 'DPL');
define('DB_USER', 'Jmmc');
define('DB_PASS', 'ChaosSoldier01');
define('DB_CHARSET', 'UTF-8');

// Redirigir si ya está logueado
if (isset($_SESSION['usuario_id']) && !empty($_SESSION['usuario_id'])) {
    header("Location: ransa_main.php");
    exit;
}

$error = '';
$email = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    // Validaciones básicas
    if (empty($email) || empty($password)) {
        $error = "❌ Por favor complete todos los campos.";
    } else {
        // Conexión a la base de datos
        $connectionInfo = array(
            "Database" => DB_NAME,
            "UID" => DB_USER,
            "PWD" => DB_PASS,
            "CharacterSet" => DB_CHARSET,
            "ReturnDatesAsStrings" => true
        );
        
        $conn = sqlsrv_connect(DB_HOST, $connectionInfo);
        
        if ($conn === false) {
            // No mostrar errores de conexión al usuario en producción
            $error = "❌ Error del sistema. Intente más tarde.";
            error_log("Error de conexión SQL: " . print_r(sqlsrv_errors(), true));
        } else {
            // Consulta preparada para mayor seguridad
            $sql = "SELECT username, nombre_completo, sede FROM DPL.pruebas.UserArchivo WHERE username = ?";
            $params = array($email);
            $options = array("Scrollable" => SQLSRV_CURSOR_KEYSET);
            
            $stmt = sqlsrv_prepare($conn, $sql, $params, $options);
            
            if ($stmt && sqlsrv_execute($stmt)) {
                // Verificar si hay resultados
                $hasRows = sqlsrv_has_rows($stmt);
                
                if ($hasRows === true) {
                    $usuario = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                    
                    // Necesitamos obtener la contraseña para verificarla
                    $sql_pass = "SELECT contrasena FROM DPL.pruebas.UserArchivo WHERE username = ?";
                    $stmt_pass = sqlsrv_prepare($conn, $sql_pass, array($email));
                    
                    if ($stmt_pass && sqlsrv_execute($stmt_pass)) {
                        $row_pass = sqlsrv_fetch_array($stmt_pass, SQLSRV_FETCH_ASSOC);
                        $contrasena_bd = $row_pass['contrasena'] ?? '';
                        
                        // Verificar contraseña
                        if ($password === $contrasena_bd) {
                            // Crear sesión
                            $_SESSION['usuario_id'] = $usuario['username'];
                            $_SESSION['usuario'] = $usuario['nombre_completo'];
                            $_SESSION['correo'] = $usuario['username'];
                            $_SESSION['tienda'] = $usuario['sede'];
                            
                            // Registrar login exitoso
                            error_log("Login exitoso: " . $usuario['username'] . " - IP: " . $_SERVER['REMOTE_ADDR']);
                            
                            // Liberar recursos
                            sqlsrv_free_stmt($stmt);
                            sqlsrv_free_stmt($stmt_pass);
                            sqlsrv_close($conn);
                            
                            // Redirigir siempre a ransa_main.php
                            header("Location: ransa_main.php");
                            exit;
                        } else {
                            $error = "❌ Contraseña incorrecta.";
                        }
                        
                        sqlsrv_free_stmt($stmt_pass);
                    } else {
                        $error = "❌ Error en la verificación de credenciales.";
                    }
                } else {
                    $error = "⚠️ Usuario no encontrado.";
                }
                
                sqlsrv_free_stmt($stmt);
            } else {
                $error = "❌ Error en la consulta de usuario.";
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Portal RANSA</title>
    
    <!-- Solo cargar Bootstrap si está disponible localmente -->
    <?php if (file_exists('vendors/bootstrap/dist/css/bootstrap.min.css')): ?>
    <link href="vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php else: ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
    
    <style>
        :root {
            --primary-color: #009a3f;
            --primary-dark: #00782f;
            --secondary-color: #2A3F54;
            --light-bg: #f8f9fa;
        }
        
        body {
            background: linear-gradient(135deg, rgba(0, 154, 63, 0.9), rgba(0, 154, 63, 0.7)), 
                        url('img/imglogin.jpg') center/cover no-repeat fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
        }
        
        .login-wrapper {
            width: 100%;
            max-width: 420px;
            animation: slideUp 0.5s ease-out;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-container img {
            max-width: 180px;
            height: auto;
            transition: transform 0.3s ease;
        }
        
        .logo-container img:hover {
            transform: scale(1.05);
        }
        
        .login-title {
            text-align: center;
            margin-bottom: 30px;
            color: var(--primary-color);
            font-weight: 700;
            font-size: 1.8rem;
            position: relative;
            padding-bottom: 15px;
        }
        
        .login-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            background: linear-gradient(to right, var(--primary-color), var(--primary-dark));
            border-radius: 2px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control {
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            padding: 14px 20px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 154, 63, 0.1);
            background: white;
            outline: none;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
            width: 100%;
            padding: 16px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 16px;
            transition: all 0.3s ease;
            cursor: pointer;
            margin-top: 10px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 154, 63, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-weight: 500;
            animation: fadeIn 0.3s ease;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05));
            color: #dc3545;
            border-left: 4px solid #dc3545;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #666;
            font-size: 14px;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        /* Responsive */
        @media (max-width: 576px) {
            .login-card {
                padding: 30px 25px;
            }
            
            .login-title {
                font-size: 1.6rem;
            }
            
            body {
                padding: 15px;
            }
        }
        
        /* Efecto de carga */
        .loading {
            display: none;
            text-align: center;
            margin-top: 10px;
        }
        
        .spinner {
            border: 3px solid rgba(0, 154, 63, 0.1);
            border-radius: 50%;
            border-top: 3px solid var(--primary-color);
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Mejora para inputs de contraseña */
        .password-container {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="logo-container">
                <img src="img/logo.png" alt="Logo RANSA" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTgwIiBoZWlnaHQ9IjYwIiB2aWV3Qm94PSIwIDAgMTgwIDYwIiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxODAiIGhlaWdodD0iNjAiIHJ4PSIxMCIgZmlsbD0iIzAwOUEzRiIvPjx0ZXh0IHg9IjUwJSIgeT0iNTAlIiBkb21pbmFudC1iYXNlbGluZT0ibWlkZGxlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSJ3aGl0ZSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE4IiBmb250LXdlaWdodD0iYm9sZCI+UkFOU0E8L3RleHQ+PC9zdmc+'">
            </div>
            
            <h1 class="login-title">ACCESO AL SISTEMA</h1>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fa fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="loginForm" onsubmit="return handleLogin()">
                <div class="form-group">
                    <label class="form-label">Usuario</label>
                    <input type="text" 
                           class="form-control" 
                           name="email" 
                           required 
                           placeholder="Ingrese su usuario" 
                           value="<?php echo htmlspecialchars($email); ?>"
                           autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Contraseña</label>
                    <div class="password-container">
                        <input type="password" 
                               class="form-control" 
                               name="password" 
                               required 
                               placeholder="Ingrese su contraseña"
                               autocomplete="current-password"
                               id="passwordInput">
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="loading" id="loadingSpinner">
                    <div class="spinner"></div>
                    <p style="margin-top: 10px; color: #666;">Verificando credenciales...</p>
                </div>
                
                <button type="submit" class="btn-login" id="loginButton">
                    <i class="fa fa-sign-in-alt"></i> INICIAR SESIÓN
                </button>
            </form>
            
            <div class="login-footer">
                <p>Sistema de Gestión RANSA &copy; <?php echo date('Y'); ?></p>
                <p style="font-size: 12px; opacity: 0.7;">v1.0</p>
            </div>
        </div>
    </div>

    <script>
        // Toggle para mostrar/ocultar contraseña
        function togglePassword() {
            const passwordInput = document.getElementById('passwordInput');
            const toggleButton = document.querySelector('.toggle-password i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleButton.className = 'fa fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleButton.className = 'fa fa-eye';
            }
        }
        
        // Manejo del formulario con validación
        function handleLogin() {
            const form = document.getElementById('loginForm');
            const email = form.email.value.trim();
            const password = form.password.value.trim();
            
            if (!email || !password) {
                alert('Por favor complete todos los campos.');
                return false;
            }
            
            // Mostrar spinner y deshabilitar botón
            document.getElementById('loadingSpinner').style.display = 'block';
            document.getElementById('loginButton').disabled = true;
            document.getElementById('loginButton').innerHTML = '<i class="fa fa-spinner fa-spin"></i> PROCESANDO...';
            
            return true;
        }
        
        // Auto-focus en el campo de usuario al cargar
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="email"]').focus();
            
            // Prevenir reenvío del formulario al recargar
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
        
        // Mejorar UX: Enter para enviar
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && document.activeElement.type !== 'button') {
                document.getElementById('loginForm').requestSubmit();
            }
        });
    </script>
    
    <!-- Font Awesome si no está local -->
    <?php if (!file_exists('vendors/font-awesome/css/font-awesome.min.css')): ?>
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
    <?php endif; ?>
</body>
</html>