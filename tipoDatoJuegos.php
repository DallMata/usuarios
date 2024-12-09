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
            if (isset($_GET['id_tipo'])) {
                obtenerObservacionPorIdTipo(); // Obtener la observación para un id_tipo específico
            } else {
                listarTipos(); // Listar todos los tipos de datos
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

// Función para listar todos los tipos de datos
function listarTipos() {
    global $pdo;

    $stmt = $pdo->query("SELECT id_tipo, tipo, observacion, numero FROM tipo_dato_juegos");
    $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($tipos);
}

// Función para obtener la observación por id_tipo
function obtenerObservacionPorIdTipo() {
    global $pdo;

    if (!isset($_GET['id_tipo'])) {
        throw new Exception('ID de tipo es obligatorio');
    }

    $id_tipo = $_GET['id_tipo'];

    $stmt = $pdo->prepare("SELECT observacion FROM tipo_dato_juegos WHERE id_tipo = ?");
    $stmt->execute([$id_tipo]);
    $observacion = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($observacion) {
        echo json_encode($observacion);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontró una observación para el ID de tipo especificado']);
    }
}
?>

