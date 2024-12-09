<?php
require 'config.php'; // Incluye tu archivo de configuración para la conexión a la base de datos

header('Content-Type: application/json');

// Habilitar CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Verificar la conexión a la base de datos
try {
    $pdo->query("SELECT 1");
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
            if (isset($_GET['id_usuario'])) {
                obtenerMembresiaPorUsuario(); // Obtener la membresía de un usuario específico
            } else {
                listarMembresias(); // Listar todas las membresías
            }
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

// Función para listar todas las membresías
function listarMembresias() {
    global $pdo;

    $stmt = $pdo->query("SELECT id_membresia, titulo, observacion, sesiones_maximas FROM membresias");
    $membresias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($membresias);
}

// Función para obtener la membresía de un usuario específico
function obtenerMembresiaPorUsuario() {
    global $pdo;

    if (!isset($_GET['id_usuario'])) {
        throw new Exception('ID de usuario es obligatorio');
    }

    $id_usuario = $_GET['id_usuario'];

    // Cambia la consulta según tu estructura de base de datos
    $stmt = $pdo->prepare("SELECT m.id_membresia, m.titulo, m.observacion, m.sesiones_maximas
                           FROM membresias m
                           INNER JOIN usuarios u ON u.id_membresia = m.id_membresia
                           WHERE u.id_usuario = ?");
    $stmt->execute([$id_usuario]);
    $membresia = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($membresia) {
        echo json_encode($membresia);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontró la membresía para el usuario especificado']);
    }
}
?>
