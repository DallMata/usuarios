<?php
require 'config.php';

header('Content-Type: application/json');

// Habilitar CORS
header("Access-Control-Allow-Origin: *"); // Cambia * a tu dominio si prefieres restringir el origen
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

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
            if (isset($_GET['dni'])) {
                buscarPacientePorDni(); // Si se pasa un DNI, busca al paciente por ese DNI
            } else {
                listarPacientes();
            }
            break;
        case 'POST':
            crearPaciente();
            break;
        case 'DELETE':
            eliminarPaciente();
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


function buscarPacientePorDni()
{
    global $pdo;

    if (!isset($_GET['dni'])) {
        throw new Exception('DNI es obligatorio');
    }

    $dni = $_GET['dni'];

    $stmt = $pdo->prepare("SELECT dni, nombre, apellido, clave, id_usuario FROM pacientes WHERE dni = ?");
    $stmt->execute([$dni]);
    $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($paciente) {
        echo json_encode($paciente); // Devolver la información del paciente encontrado
    } else {
        http_response_code(404); // No encontrado
        echo json_encode(['error' => 'Paciente no encontrado']);
    }
}
function crearPaciente()
{
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    // Verificar que todos los campos necesarios estén presentes
    if (!isset($data['dni']) || !isset($data['nombre']) || !isset($data['apellido']) || !isset($data['clave']) || !isset($data['id_usuario'])) {
        throw new Exception('Todos los campos son obligatorios');
    }

    $dni = $data['dni'];
    $nombre = $data['nombre'];
    $apellido = $data['apellido'];
    $clave = $data['clave'];
    $id_usuario = $data['id_usuario']; // ID del usuario logueado

    // Comprobar si el DNI ya existe
    $stmt = $pdo->prepare("SELECT * FROM pacientes WHERE dni = ?");
    $stmt->execute([$dni]);
    $pacientePorDNI = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pacientePorDNI) {
        http_response_code(409); // Conflicto
        echo json_encode(['error' => 'El DNI ya está en uso']);
        return;
    }

    // Insertar nuevo paciente en la base de datos
    $stmt = $pdo->prepare("INSERT INTO pacientes (dni, nombre, apellido, clave, id_usuario) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$dni, $nombre, $apellido, $clave, $id_usuario]);

    http_response_code(201); // Creado
    echo json_encode(['mensaje' => 'Paciente creado correctamente']);
}



function listarPacientes()
{
    global $pdo;

    // Verificar si se ha proporcionado un id_usuario
    if (isset($_GET['id_usuario'])) {
        $id_usuario = $_GET['id_usuario'];
        $stmt = $pdo->prepare("SELECT dni, nombre, apellido, clave, id_usuario FROM pacientes WHERE id_usuario = ?");
        $stmt->execute([$id_usuario]);
    } else {
        $stmt = $pdo->query("SELECT dni, nombre, apellido, clave, id_usuario FROM pacientes");
    }

    $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($pacientes);
}

function eliminarPaciente()
{
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        throw new Exception('ID del paciente es obligatorio');
    }

    $id = $data['id'];
    $stmt = $pdo->prepare("DELETE FROM pacientes WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['mensaje' => 'Paciente eliminado correctamente']);
}
