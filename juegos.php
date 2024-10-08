<?php
require 'config.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            obtenerDatosJuego();
            break;
        case 'POST':
            altaJuego();
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



function altaJuego()
{
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['tiempo_total']) || !isset($data['Score']) || !isset($data['fecha_inicio']) || !isset($data['datos']) || !isset($data['dni_paciente'])) {
        throw new Exception('Todos los campos son obligatorios');
    }

    $tiempo_total = $data['tiempo_total'];
    $Score = $data['Score'];
    $fecha_inicio = $data['fecha_inicio'];
    $datos = $data['datos'];
    $dni_paciente = $data['dni_paciente'];

    // Inserta el juego en la tabla `juegos` con la relación al paciente
    $stmt = $pdo->prepare("INSERT INTO juegos (tiempo_jugado, fecha, puntaje, dni_paciente) VALUES (?, ?, ?, ?)");
    $stmt->execute([$tiempo_total, $fecha_inicio, $Score, $dni_paciente]);

    // Obtener el ID del juego que se acaba de insertar
    $id_juego = $pdo->lastInsertId();

    // Inserta los datos de la jugada en la tabla `juego_datos`
    $stmt = $pdo->prepare("INSERT INTO juego_datos (id_juego, tiempo, angulo, tipo) VALUES (?, ?, ?, ?)");
    foreach ($datos as $dato) {
        $stmt->execute([$id_juego, $dato['Tiempo'], $dato['Angulo'], $dato['Tipo']]);
    }

    http_response_code(201); // Creado
    echo json_encode(['mensaje' => 'Juego y datos creados correctamente']);
}

/*function obtenerDatosJuego()
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT id_juego, fecha FROM juegos");
    $stmt->execute();
    $juegos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [];

    foreach ($juegos as $juego) {
        $id_juego = $juego['id_juego'];
        $fecha = $juego['fecha'];

        $stmt = $pdo->prepare("SELECT tiempo, angulo FROM juego_datos WHERE id_juego = ?");
        $stmt->execute([$id_juego]);
        $datos_juego = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($datos_juego) {
            // Calcular la amplitud máxima
            $angles = array_column($datos_juego, 'angulo');
            $max_amplitude = 0;
            if (!empty($angles)) {
                $min_angle = min($angles);
                $max_angle = max($angles);
                $max_amplitude = $max_angle - $min_angle;
            }

            // Calcular la amplitud media
            $positive_angles = array_filter($angles, function ($angle) {
                return $angle > 0;
            });

            $negative_angles = array_filter($angles, function ($angle) {
                return $angle < 0;
            });

            $average_positive = count($positive_angles) > 0 ? array_sum($positive_angles) / count($positive_angles) : 0;
            $average_negative = count($negative_angles) > 0 ? array_sum($negative_angles) / count($negative_angles) : 0;
            $average_amplitude = abs($average_positive - $average_negative);

            $response[] = [
                'id_juego' => $id_juego,
                'fecha' => $fecha,
                'datos' => $datos_juego,
                'amplitud_maxima' => $max_amplitude,
                'amplitud_media' => $average_amplitude
            ];
        }
    }

    echo json_encode($response);
}*/

function obtenerDatosJuego()
{
    global $pdo;

    // Verifica si se ha pasado el parámetro 'dni' en la URL
    $dni = isset($_GET['dni']) ? $_GET['dni'] : null;

    // Modifica la consulta SQL para filtrar por dni_paciente si se proporciona
    if ($dni) {
        $stmt = $pdo->prepare("SELECT id_juego, fecha, tiempo_jugado, puntaje, dni_paciente FROM juegos WHERE dni_paciente = ?");
        $stmt->execute([$dni]);
    } else {
        $stmt = $pdo->prepare("SELECT id_juego, fecha, tiempo_jugado, puntaje, dni_paciente FROM juegos");
        $stmt->execute();
    }

    $juegos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [];

    foreach ($juegos as $juego) {
        $id_juego = $juego['id_juego'];
        $fecha = $juego['fecha'];
        $tiempo_jugado = $juego['tiempo_jugado'];
        $puntaje = $juego['puntaje'];
        $dni_paciente = $juego['dni_paciente'];

        $stmt = $pdo->prepare("SELECT tiempo, angulo FROM juego_datos WHERE id_juego = ?");
        $stmt->execute([$id_juego]);
        $datos_juego = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($datos_juego) {
            // Calcular la amplitud máxima
            $angles = array_column($datos_juego, 'angulo');
            $max_amplitude = 0;
            if (!empty($angles)) {
                $min_angle = min($angles);
                $max_angle = max($angles);
                $max_amplitude = $max_angle - $min_angle;
            }

            // Calcular la amplitud media
            $positive_angles = array_filter($angles, function ($angle) {
                return $angle > 0;
            });

            $negative_angles = array_filter($angles, function ($angle) {
                return $angle < 0;
            });

            $average_positive = count($positive_angles) > 0 ? array_sum($positive_angles) / count($positive_angles) : 0;
            $average_negative = count($negative_angles) > 0 ? array_sum($negative_angles) / count($negative_angles) : 0;
            $average_amplitude = abs($average_positive - $average_negative);

            $response[] = [
                'id_juego' => $id_juego,
                'fecha' => $fecha,
                'tiempo_jugado' => $tiempo_jugado,
                'puntaje' => $puntaje,
                'dni_paciente' => $dni_paciente,
                'datos' => $datos_juego,
                'amplitud_maxima' => $max_amplitude,
                'amplitud_media' => $average_amplitude
            ];
        }
    }

    echo json_encode($response);
}





function listarUsuarios()
{
    global $pdo;

    $stmt = $pdo->query("SELECT * FROM usuarios");
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

