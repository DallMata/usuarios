<?php
require 'config.php';

header('Content-Type: application/json');

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if (isset($_GET['id'])) {
                obtenerUsuario();
            } elseif (isset($_GET['nombre'])) {
                filtrarUsuariosPorNombre();
            } else {
                listarUsuarios();
            }
            break;
        case 'POST':
            altaUsuario();
            break;
        case 'PUT':
            modificarUsuario();
            break;
        case 'DELETE':
            bajaUsuario();
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

function altaUsuario() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['nombre']) || !isset($data['apellido']) || !isset($data['email']) || !isset($data['password'])) {
        throw new Exception('Todos los campos son obligatorios');
    }

    $nombre = $data['nombre'];
    $apellido = $data['apellido'];
    $email = $data['email'];
    $password = password_hash($data['password'], PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, apellido, email, password) VALUES (?, ?, ?, ?)");
    $stmt->execute([$nombre, $apellido, $email, $password]);

    http_response_code(201); // Creado
    echo json_encode(['mensaje' => 'Usuario creado correctamente']);
}

function modificarUsuario() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id']) || !isset($data['nombre']) || !isset($data['apellido']) || !isset($data['email'])) {
        throw new Exception('Todos los campos son obligatorios');
    }

    $id = $data['id'];
    $nombre = $data['nombre'];
    $apellido = $data['apellido'];
    $email = $data['email'];

    $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, apellido=?, email=? WHERE id=?");
    $stmt->execute([$nombre, $apellido, $email, $id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // No encontrado
        echo json_encode(['error' => 'Usuario no encontrado']);
        return;
    }

    echo json_encode(['mensaje' => 'Usuario modificado correctamente']);
}

function bajaUsuario() {
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id'])) {
        throw new Exception('ID del usuario es obligatorio');
    }

    $id = $data['id'];

    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id=?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // No encontrado
        echo json_encode(['error' => 'Usuario no encontrado']);
        return;
    }

    echo json_encode(['mensaje' => 'Usuario eliminado correctamente']);
}

function obtenerUsuario() {
    global $pdo;

    $id = $_GET['id'];

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);

    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        http_response_code(404); // No encontrado
        echo json_encode(['error' => 'Usuario no encontrado']);
        return;
    }

    echo json_encode($usuario);
}

function listarUsuarios() {
    global $pdo;

    $stmt = $pdo->query("SELECT * FROM usuarios");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($usuarios);
}

function filtrarUsuariosPorNombre() {
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

