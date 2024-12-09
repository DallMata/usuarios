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
            if (isset($_GET['id_paciente'])) {
                listarAsignacionesPorPaciente(); // Llama a la función para listar asignaciones por paciente
            } else {
                listarAsignaciones(); // Opción por defecto para listar todas las asignaciones
            }
            break;
        case 'POST':
            crearAsignacion(); // Crear una nueva asignación
            break;
        case 'DELETE':
            eliminarAsignacion(); // Eliminar una asignación existente
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

// Función para listar asignaciones por paciente
function listarAsignacionesPorPaciente() {
    global $pdo;

    if (!isset($_GET['id_paciente'])) {
        throw new Exception('ID del paciente es obligatorio');
    }

    $id_paciente = $_GET['id_paciente'];

    $stmt = $pdo->prepare("
        SELECT id_asignacion, id_aplicacion, id_usuario, id_paciente, fecha_inicio, fecha_fin
        FROM asignaciones
        WHERE id_paciente = ?
    ");
    $stmt->execute([$id_paciente]);
    $asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($asignaciones) {
        echo json_encode($asignaciones);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontraron asignaciones para el paciente especificado']);
    }
}

// Función para listar todas las asignaciones
function listarAsignaciones() {
    global $pdo;

    $stmt = $pdo->query("SELECT * FROM asignaciones");
    $asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($asignaciones);
}

// Función para crear una nueva asignación
function crearAsignacion() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    // Validar que los campos requeridos estén presentes
    if (
        !isset($data['id_aplicacion']) || 
        !isset($data['id_usuario']) || 
        !isset($data['id_paciente']) || 
        !isset($data['fecha_inicio']) || 
        !isset($data['fecha_fin'])
    ) {
        throw new Exception('Todos los campos son obligatorios: id_aplicacion, id_usuario, id_paciente, fecha_inicio, fecha_fin');
    }

    // Extraer los datos del cuerpo de la solicitud
    $id_aplicacion = $data['id_aplicacion'];
    $id_usuario = $data['id_usuario'];
    $id_paciente = $data['id_paciente'];
    $fecha_inicio = $data['fecha_inicio'];
    $fecha_fin = $data['fecha_fin'];

    // Insertar la asignación en la base de datos
    $stmt = $pdo->prepare("
        INSERT INTO asignaciones (id_aplicacion, id_usuario, id_paciente, fecha_inicio, fecha_fin) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$id_aplicacion, $id_usuario, $id_paciente, $fecha_inicio, $fecha_fin]);

    // Enviar respuesta de éxito
    http_response_code(201); // Creado
    echo json_encode(['mensaje' => 'Asignación creada correctamente']);
}


// Función para eliminar una asignación
function eliminarAsignacion() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id_asignacion'])) {
        throw new Exception('ID de la asignación es obligatorio');
    }

    $id_asignacion = $data['id_asignacion'];
    $stmt = $pdo->prepare("DELETE FROM asignaciones WHERE id_asignacion = ?");
    $stmt->execute([$id_asignacion]);

    echo json_encode(['mensaje' => 'Asignación eliminada correctamente']);
}
