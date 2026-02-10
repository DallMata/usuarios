<?php
require 'config.php';

header('Content-Type: application/json');

// CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Debug (podés desactivar en prod)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Sanity DB
try {
    $pdo->query("SELECT 1");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error en la conexión: ' . $e->getMessage()]);
    exit();
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if (isset($_GET['id_tipo'])) {
                obtenerObservacionPorIdTipo();
            } else {
                listarTipos();
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

/* ==========================
   GET /tipoDatoJuegos.php
   - sin params: lista todos
   - ?id_tipo=NN: devuelve observacion del tipo
   ========================== */

function listarTipos() {
    global $pdo;
    $stmt = $pdo->query("SELECT id_tipo, tipo, observacion, numero FROM tipo_dato_juegos ORDER BY id_tipo ASC");
    $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$tipos) { http_response_code(404); echo json_encode(['error' => 'No hay tipos']); return; }
    echo json_encode($tipos);
}

function obtenerObservacionPorIdTipo() {
    global $pdo;

    if (!isset($_GET['id_tipo']) || $_GET['id_tipo'] === '') {
        throw new Exception('ID de tipo es obligatorio');
    }

    $id_tipo = (int)$_GET['id_tipo'];

    $stmt = $pdo->prepare("SELECT id_tipo, tipo, observacion, numero FROM tipo_dato_juegos WHERE id_tipo = ?");
    $stmt->execute([$id_tipo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // si querés sólo la observación, descomentá la línea siguiente y comentá la de arriba
        // echo json_encode(['observacion' => $row['observacion']]);
        echo json_encode($row);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontró el tipo solicitado']);
    }
}
