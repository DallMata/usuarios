<?php
require 'config.php';

header('Content-Type: application/json');

// Habilitar CORS
header("Access-Control-Allow-Origin: *"); // Puedes cambiar * a http://localhost:4321 si prefieres restringir el origen
//header("Access-Control-Allow-Origin: http://localhost:4321"); // Reemplaza * con tu dominio si prefieres restringirlo

header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");



try {
    $pdo->query("SELECT 1");
    //print("mensaje' => 'Conexion a la base de datos exitosa");
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error en la conexión: ' . $e->getMessage()]);
    exit();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Manejar la preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if (isset($_GET['nombre'])) {
                filtrarUsuariosPorNombre();
            } else {
                listarUsuarios();
            }
            break;
        case 'POST':
            if (isset($_GET['login'])) {
                loginUsuario();  // Llama a la función de login
            } else if(isset($_GET['register']) ) {
                crearUsuario();
            }
            break;
        case 'PUT':
            modificarUsuario();
            break;
        case 'DELETE':
            eliminarUsuario();
            break;
        default:
            http_response_code(405); // Método no permitido
            echo json_encode(['error' => 'Método no permitido']);
    }
} catch (PDOException $e) {
    http_response_code(500); // Error del servidor
    echo json_encode(['error' => 'Error en la base de datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400); // Solicitud incorrecta
    echo json_encode(['error' => $e->getMessage()]);
}

function loginUsuario()
{
    global $pdo;

    // Obtener los datos enviados en la solicitud
    $data = json_decode(file_get_contents('php://input'), true);

    // Verificar si ambos campos fueron proporcionados
    if (!isset($data['email']) || !isset($data['password'])) {
        throw new Exception('El email y la contraseña son obligatorios');
    }

    $email = $data['email'];
    $password = $data['password'];

    // Para verificar los datos que estás enviando
    error_log("Email enviado: $email"); // Log del email
    error_log("Contraseña enviada: $password"); // Log de la contraseña

    // Buscar al usuario en la base de datos usando el email
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    // Si no existe el usuario con ese email
    if (!$usuario) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado']);
        return;
    }

    // Verificar si la contraseña ingresada coincide con la almacenada (sin hashing para pruebas)
    if (md5($password) === $usuario['password']) {
        // Si la contraseña es correcta, retornar éxito
        echo json_encode([
            'mensaje' => 'Login exitoso',
            'usuario' => [
                'id' => $usuario['id'], // Asegúrate de que 'id' sea la columna correcta
                'nombre' => $usuario['nombre'],
                'apellido' => $usuario['apellido'],
                'email' => $usuario['email'],
                'dni' => $usuario['dni']
            ]]);
    } else {
        // Si la contraseña es incorrecta
        http_response_code(401); // No autorizado
        echo json_encode(['error' => 'Contraseña incorrecta']);
    }
}


function crearUsuario()
{
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['nombre']) || !isset($data['apellido']) || !isset($data['dni']) || !isset($data['email']) || !isset($data['password'])) {
        throw new Exception('Todos los campos son obligatorios');
    }

    $nombre = $data['nombre'];
    $apellido = $data['apellido'];
    $dni = $data['dni'];
    $email = $data['email'];
    $password = $data['password']; // Sin hashing para pruebas

    // Comprobar si el email ya existe
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $usuarioPorEmail = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuarioPorEmail) {
        http_response_code(409); // Conflicto
        echo json_encode(['error' => 'El email ya está en uso']);
        return;
    }

    // Comprobar si el DNI ya existe
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE dni = ?");
    $stmt->execute([$dni]);
    $usuarioPorDNI = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuarioPorDNI) {
        http_response_code(409); // Conflicto
        echo json_encode(['error' => 'El DNI ya está en uso']);
        return;
    }

    // Si no existe ni el email ni el DNI, crear el nuevo usuario
    $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, apellido, dni, email, password) VALUES (?, ?, ?, ?, md5(?))");
    $stmt->execute([$nombre, $apellido, $dni, $email, $password]);

    http_response_code(201); // Creado
    echo json_encode(['mensaje' => 'Usuario creado correctamente']);
}
function listarUsuarios()
{
    global $pdo;

    $stmt = $pdo->query("SELECT id, nombre, apellido, email FROM usuarios");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($usuarios);
}

function filtrarUsuariosPorNombre()
{
    global $pdo;

    $nombre = $_GET['nombre'];

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE nombre LIKE ?");
    $stmt->execute(["%$nombre%"]);

    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$usuarios) {
        http_response_code(404); // No encontrado
        echo json_encode(['error' => 'No se encontraron usuarios']);
        return;
    }

    echo json_encode($usuarios);
}

function modificarUsuario()
{
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id']) || !isset($data['nombre']) || !isset($data['apellido']) || !isset($data['email'])) {
        throw new Exception('Todos los campos son obligatorios');
    }

    $id = $data['id'];
    $nombre = trim($data['nombre']);
    $apellido = trim($data['apellido']);
    $email = trim($data['email']);
    $password = isset($data['password']) ? password_hash(trim($data['password']), PASSWORD_BCRYPT) : null;

    $stmt = $pdo->prepare("UPDATE usuarios SET nombre = ?, apellido = ?, email = ?" . ($password ? ", password = ?" : "") . " WHERE id = ?");
    
    if ($password) {
        $stmt->execute([$nombre, $apellido, $email, $password, $id]);
    } else {
        $stmt->execute([$nombre, $apellido, $email, $id]);
    }

    echo json_encode(['mensaje' => 'Usuario actualizado correctamente']);
}

function eliminarUsuario()
{
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        throw new Exception('ID del usuario es obligatorio');
    }

    $id = $data['id'];
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['mensaje' => 'Usuario eliminado correctamente']);
}
