<?php
require 'config.php'; // Incluye tu archivo de configuración para la conexión a la base de datos

header('Content-Type: application/json');

// Habilitar CORS
header("Access-Control-Allow-Origin: *"); // Cambia * a tu dominio si prefieres restringir el origen
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
            if (isset($_GET['id_aplicacion'])) {
                obtenerAplicacionPorId(); // Llama a la función para obtener la aplicación por ID
            } elseif (isset($_GET['id_paciente'])) {
                listarAplicacionesPorPaciente(); // Lista las aplicaciones por paciente
            } else {
                listarAplicaciones(); // Opción por defecto para listar todas las aplicaciones
            }
            break;
        case 'POST':
            crearAplicacion(); // Crear una nueva aplicación
            break;
        case 'DELETE':
            eliminarAplicacion(); // Eliminar una aplicación existente
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


// Función para listar aplicaciones por paciente
function listarAplicacionesPorPaciente() {
    global $pdo;

    if (!isset($_GET['id_paciente'])) {
        throw new Exception('ID de paciente es obligatorio');
    }

    $id_paciente = $_GET['id_paciente'];

    // Cambia la consulta según tu estructura de base de datos
    $stmt = $pdo->prepare("
        SELECT a.id_aplicacion, a.titulo, a.descripcion 
        FROM aplicaciones a
        INNER JOIN asignaciones asg ON a.id_aplicacion = asg.id_aplicacion
        WHERE asg.id_paciente = ?
    ");
    $stmt->execute([$id_paciente]);
    $aplicaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($aplicaciones);
}


// Función para obtener una aplicación por ID
function obtenerAplicacionPorId() {
    global $pdo;

    if (!isset($_GET['id_aplicacion'])) {
        throw new Exception('ID de la aplicación es obligatorio');
    }

    $id_aplicacion = $_GET['id_aplicacion'];

    $stmt = $pdo->prepare("SELECT id_aplicacion, titulo, descripcion FROM aplicaciones WHERE id_aplicacion = ?");
    $stmt->execute([$id_aplicacion]);
    $aplicacion = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($aplicacion) {
        echo json_encode($aplicacion);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontró la aplicación especificada']);
    }
}

// Función para listar todas las aplicaciones
function listarAplicaciones() {
    global $pdo;

    $stmt = $pdo->query("SELECT id_aplicacion, titulo, descripcion FROM aplicaciones");
    $aplicaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($aplicaciones);
}

// Función para crear una nueva aplicación (opcional)
function crearAplicacion() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['titulo']) || !isset($data['descripcion'])) {
        throw new Exception('Todos los campos son obligatorios');
    }

    $titulo = $data['titulo'];
    $descripcion = $data['descripcion'];

    $stmt = $pdo->prepare("INSERT INTO aplicaciones (titulo, descripcion) VALUES (?, ?)");
    $stmt->execute([$titulo, $descripcion]);

    http_response_code(201); // Creado
    echo json_encode(['mensaje' => 'Aplicación creada correctamente']);
}

// Función para eliminar una aplicación (opcional)
function eliminarAplicacion() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id_aplicacion'])) {
        throw new Exception('ID de la aplicación es obligatorio');
    }

    $id_aplicacion = $data['id_aplicacion'];
    $stmt = $pdo->prepare("DELETE FROM aplicaciones WHERE id_aplicacion = ?");
    $stmt->execute([$id_aplicacion]);

    echo json_encode(['mensaje' => 'Aplicación eliminada correctamente']);
}
