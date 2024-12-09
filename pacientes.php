<?php
require 'config.php';

header('Content-Type: application/json');

// Habilitar CORS
header("Access-Control-Allow-Origin: *");
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
                if (isset($_GET['action']) && $_GET['action'] === 'aplicaciones') {
                    buscarAplicacionesPorDni();
                } else {
                    buscarPacientePorDni();
                }
            } elseif (isset($_GET['id_usuario'])) {
                listarPacientesPorUsuario();
            } else {
                listarPacientes();
            }
            break;
            case 'POST':
                if (isset($_GET['create'])) {
                    crearPaciente();
                } else if (isset($_GET['login'])) { // Cambié 'create' a 'login' para diferenciar la acción
                    loginPaciente();
                } elseif (isset($_GET['update'])) { // Nueva acción para modificar
                    modificarPaciente();
                }
                break;
        case 'DELETE':
            if(isset($_GET['dni'])){
                print("Entre a eliminar");
                eliminarPaciente();
                
            }
            else{
                throw new Exception('ID del paciente es obligatorio');
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Método no permitido']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error en la base de datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

function modificarPaciente() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    // Validar que los campos necesarios estén presentes
    if (!isset($data['dni']) || !isset($data['nombre']) || !isset($data['apellido']) || !isset($data['id_aplicaciones'])) {
        throw new Exception('Los campos DNI, nombre, apellido e id_aplicaciones son obligatorios');
    }

    $dni = $data['dni'];
    $nombre = $data['nombre'];
    $apellido = $data['apellido'];
    $clave = isset($data['clave']) ? $data['clave'] : null; // La clave es opcional
    $id_aplicaciones = $data['id_aplicaciones']; // Este debe ser un array con los IDs de las aplicaciones

    if (!is_array($id_aplicaciones) || count($id_aplicaciones) === 0) {
        throw new Exception('Debe proporcionarse al menos una aplicación para la asignación');
    }

    // Verificar si el paciente existe
    $stmt = $pdo->prepare("SELECT id_paciente FROM pacientes WHERE dni = ?");
    $stmt->execute([$dni]);
    $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paciente) {
        http_response_code(404);
        echo json_encode(['error' => 'Paciente no encontrado']);
        return;
    }

    $id_paciente = $paciente['id_paciente'];

    // Actualizar los datos del paciente (clave opcional)
    if ($clave) {
        $stmt = $pdo->prepare("UPDATE pacientes SET nombre = ?, apellido = ?, clave = md5(?) WHERE dni = ?");
        $stmt->execute([$nombre, $apellido, $clave, $dni]);
    } else {
        $stmt = $pdo->prepare("UPDATE pacientes SET nombre = ?, apellido = ? WHERE dni = ?");
        $stmt->execute([$nombre, $apellido, $dni]);
    }

    // Actualizar asignaciones: primero eliminar las existentes para este paciente
    $stmt = $pdo->prepare("DELETE FROM asignaciones WHERE id_paciente = ?");
    $stmt->execute([$id_paciente]);

    // Insertar las nuevas asignaciones
    if (isset($data['id_usuario'])) {
        $id_usuario = $data['id_usuario'];
        $fecha_fin = date('Y-m-d H:i:s', strtotime('+1 month'));

        $stmt = $pdo->prepare("INSERT INTO asignaciones (id_aplicacion, id_usuario, id_paciente, fecha_inicio, fecha_fin) VALUES (?, ?, ?, NOW(), ?)");

        foreach ($id_aplicaciones as $id_aplicacion) {
            $stmt->execute([$id_aplicacion, $id_usuario, $id_paciente, $fecha_fin]);
        }
    }

    http_response_code(200);
    echo json_encode(['mensaje' => 'Paciente y asignaciones modificados correctamente']);
}


function listarPacientesPorUsuario() {
    global $pdo;

    if (!isset($_GET['id_usuario'])) {
        throw new Exception('ID del usuario es obligatorio');
    }

    $id_usuario = $_GET['id_usuario'];

    // Obtener los pacientes a través de la tabla asignaciones, usando DISTINCT
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.id_paciente, p.dni, p.nombre, p.apellido, p.clave, p.estado
        FROM pacientes p
        JOIN asignaciones a ON p.id_paciente = a.id_paciente
        WHERE a.id_usuario = ? AND p.estado = 1
    ");
    $stmt->execute([$id_usuario]);
    $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($pacientes) {
        echo json_encode($pacientes);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontraron pacientes para el usuario especificado']);
    }
}

function buscarAplicacionesPorDni() {
    global $pdo;

    if (!isset($_GET['dni'])) {
        throw new Exception('DNI es obligatorio');
    }

    $dni = $_GET['dni'];

    $stmt = $pdo->prepare("
        SELECT a.id_aplicacion, a.titulo, a.descripcion
        FROM pacientes p
        JOIN asignaciones asg ON p.id_paciente = asg.id_paciente
        JOIN aplicaciones a ON asg.id_aplicacion = a.id_aplicacion
        WHERE p.dni = ?
    ");
    $stmt->execute([$dni]);
    $aplicaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($aplicaciones) {
        echo json_encode($aplicaciones);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontraron aplicaciones para el paciente con el DNI proporcionado']);
    }
}

function buscarPacientePorDni() {
    global $pdo;

    if (!isset($_GET['dni'])) {
        throw new Exception('DNI es obligatorio');
    }



    $dni = $_GET['dni'];

    $stmt = $pdo->prepare("SELECT id_paciente, dni, nombre, apellido, clave, estado FROM pacientes WHERE dni = ?");
    $stmt->execute([$dni]);
    $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($paciente) {
        echo json_encode($paciente);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Paciente no encontrado']);
    }
}

function crearPaciente() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    // Validar que los campos necesarios estén presentes
    if (!isset($data['dni']) || !isset($data['nombre']) || !isset($data['apellido']) || !isset($data['clave']) || !isset($data['id_aplicaciones'])) {
        throw new Exception('Todos los campos son obligatorios');
    }

    $dni = $data['dni'];
    $nombre = $data['nombre'];
    $apellido = $data['apellido'];
    $clave = $data['clave'];
    $id_aplicaciones = $data['id_aplicaciones']; // Este debe ser un array con los IDs de las aplicaciones

    if (!is_array($id_aplicaciones) || count($id_aplicaciones) === 0) {
        throw new Exception('Debe proporcionarse al menos una aplicación para la asignación');
    }

    // Comprobar si el DNI ya existe
    $stmt = $pdo->prepare("SELECT * FROM pacientes WHERE dni = ?");
    $stmt->execute([$dni]);
    $pacientePorDNI = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pacientePorDNI) {
        http_response_code(409);
        echo json_encode(['error' => 'El DNI ya está en uso']);
        return;
    }

    // Insertar nuevo paciente en la base de datos
    $stmt = $pdo->prepare("INSERT INTO pacientes (dni, nombre, apellido, clave, estado) VALUES (?, ?, ?, md5(?), 1)");
    $stmt->execute([$dni, $nombre, $apellido, $clave]);

    // Obtener el ID del nuevo paciente
    $id_paciente = $pdo->lastInsertId();

    // Insertar asignaciones para cada aplicación
    if (isset($data['id_usuario'])) {
        $id_usuario = $data['id_usuario'];
        $fecha_fin = date('Y-m-d H:i:s', strtotime('+1 month'));

        $stmt = $pdo->prepare("INSERT INTO asignaciones (id_aplicacion, id_usuario, id_paciente, fecha_inicio, fecha_fin) VALUES (?, ?, ?, NOW(), ?)");

        foreach ($id_aplicaciones as $id_aplicacion) {
            $stmt->execute([$id_aplicacion, $id_usuario, $id_paciente, $fecha_fin]);
        }
    }

    http_response_code(201);
    echo json_encode(['mensaje' => 'Paciente creado correctamente']);
}


function listarPacientes() {
    global $pdo;

    $stmt = $pdo->query("SELECT dni, nombre, apellido, clave, estado FROM pacientes");
    $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($pacientes);
}

function eliminarPaciente() {
    global $pdo;

    $dni = $_GET['dni'];
;
    $stmt = $pdo->prepare("UPDATE pacientes SET estado = 0 WHERE dni = ?");
    $stmt->execute([$dni]);

    echo json_encode(['mensaje' => 'Paciente eliminado correctamente']);
}

function loginPaciente() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['dni']) || !isset($data['clave'])) {
        error_log("Datos recibidos: " . json_encode($data));
        throw new Exception('El DNI y la clave son obligatorios');
    }

    $dni = trim($data['dni']);
    $clave = trim($data['clave']);

    error_log("DNI enviado: $dni");
    error_log("Clave enviada (hash): " . md5($clave));

    $stmt = $pdo->prepare("SELECT * FROM pacientes WHERE dni = ?");
    $stmt->execute([$dni]);
    $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paciente) {
        http_response_code(404);
        echo json_encode(['error' => 'Paciente no encontrado']);
        return;
    }

    if (md5($clave) === $paciente['clave']) {
        echo json_encode([
            'mensaje' => 'Login exitoso',
            'paciente' => [
                'id_paciente' => $paciente['id_paciente'],
                'dni' => $paciente['dni'],
                'nombre' => $paciente['nombre'],
                'apellido' => $paciente['apellido'],
                'estado' => $paciente['estado']
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Clave incorrecta']);
    }
}
