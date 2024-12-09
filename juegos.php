<?php
require 'config.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE, PUT");
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
            modificarJuego();
            break;
        case 'DELETE':
            bajaJuego();
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

function altaJuego() {
    global $pdo;

    // Decodifica el JSON
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Verificar que los campos esenciales estén presentes
    if (!isset($data['fecha']) || !isset($data['tiempo']) || !isset($data['puntaje'])  || !isset($data['fecha']) || !isset($data['dni'])) {
        throw new Exception('Todos los campos son obligatorios');
    }

    // Guardar información general del juego en la tabla `juegos`
    $tiempo = $data['tiempo'];
    $fecha = $data['fecha'];
    $puntaje = $data['puntaje'];
    $dni = $data['dni'];


    $stmt = $pdo->prepare("INSERT INTO juegos (tiempo_jugado, fecha, puntaje, dni_paciente) VALUES (?, ?, ?, ?)");
    $stmt->execute([$tiempo, $fecha, $puntaje, $dni]);

    // Obtener el ID del juego que se acaba de insertar
    $id_juego = $pdo->lastInsertId();

    // Guardar cada registro de datos en la tabla `juego_datos`
    foreach ($data['datos'] as $dato) {
        if (!isset($dato['tiempo']) || !isset($dato['angulo']) || !isset($dato['tipo'])) {
            throw new Exception('Cada dato debe tener Tiempo, Angulo y Tipo');
        }

        $tiempo = $dato['tiempo'];
        $angulo = $dato['angulo'];
        $id_tipo = $dato['tipo'];

        $stmt = $pdo->prepare("INSERT INTO juego_datos (id_juego, tiempo, angulo, id_tipo) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id_juego, $tiempo, $angulo, $id_tipo]);
    }

    http_response_code(201); // Creado
    echo json_encode(['mensaje' => 'Juego y datos creados correctamente']);
}


/*function obtenerDatosJuego()
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

        // Obtener los datos del juego junto con el id_tipo
        $stmt = $pdo->prepare("SELECT tiempo, angulo, id_tipo FROM juego_datos WHERE id_juego = ?");
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

            // Obtener los tipos de datos de los juegos
            $tipo_datos = [];
            foreach ($datos_juego as $dato) {
                $stmt = $pdo->prepare("SELECT tipo FROM tipo_dato_juegos WHERE id_tipo = ?");
                $stmt->execute([$dato['id_tipo']]);
                $tipo = $stmt->fetch(PDO::FETCH_ASSOC);
                $tipo_datos[] = $tipo ? $tipo['tipo'] : null; // Agrega el tipo o null si no se encuentra
            }

            $response[] = [
                'id_juego' => $id_juego,
                'fecha' => $fecha,
                'tiempo_jugado' => $tiempo_jugado,
                'puntaje' => $puntaje,
                'dni_paciente' => $dni_paciente,
                'datos' => $datos_juego,
                'tipos' => $tipo_datos, // Agrega la información de tipo
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

    // Verifica si se han pasado los parámetros `id_tipo` y `dni_paciente` en la URL
    $id_tipo = isset($_GET['id_tipo']) ? $_GET['id_tipo'] : null;
    $dni_paciente = isset($_GET['dni']) ? $_GET['dni'] : null;

    if (!$id_tipo || !$dni_paciente) {
        http_response_code(400); // Solicitud incorrecta
        echo json_encode(['error' => 'Se requieren los parámetros id_tipo y dni']);
        return;
    }

    // Obtener los juegos del paciente filtrados por `id_tipo`
    $stmt = $pdo->prepare(
        "SELECT j.id_juego, j.fecha, j.tiempo_jugado, j.puntaje, j.dni_paciente, jd.tiempo, jd.angulo, jd.id_tipo
         FROM juegos j
         INNER JOIN juego_datos jd ON j.id_juego = jd.id_juego
         WHERE j.dni_paciente = ? AND jd.id_tipo = ?"
    );
    $stmt->execute([$dni_paciente, $id_tipo]);

    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organizar los datos por juego
    $response = [];
    foreach ($resultados as $fila) {
        $id_juego = $fila['id_juego'];

        // Si el juego no está en la respuesta, inicialízalo
        if (!isset($response[$id_juego])) {
            $response[$id_juego] = [
                'id_juego' => $fila['id_juego'],
                'fecha' => $fila['fecha'],
                'tiempo_jugado' => $fila['tiempo_jugado'],
                'puntaje' => $fila['puntaje'],
                'dni_paciente' => $fila['dni_paciente'],
                'datos' => [],
                'amplitud_maxima' => 0, // Inicializar la amplitud máxima
                'amplitud_media' => 0, // Inicializar la amplitud media
            ];
        }

        // Agregar el dato relacionado al juego
        $response[$id_juego]['datos'][] = [
            'tiempo' => $fila['tiempo'],
            'angulo' => $fila['angulo'],
            'id_tipo' => $fila['id_tipo'],
        ];
    }

    // Calcular amplitud máxima y amplitud media para cada juego
    foreach ($response as $id_juego => &$juego) {
        $angles = array_column($juego['datos'], 'angulo');

        // Filtrar ángulos válidos (excluir 0 y null)
        $valid_angles = array_filter($angles, fn($angle) => $angle !== 0 && $angle !== null);

        if (!empty($valid_angles)) {
            // Calcular amplitud máxima
            $juego['amplitud_maxima'] = max($valid_angles) - min($valid_angles);

            // Calcular amplitud media
            $positive_angles = array_filter($valid_angles, fn($angle) => $angle > 0);
            $negative_angles = array_filter($valid_angles, fn($angle) => $angle < 0);

            $average_positive = count($positive_angles) > 0 ? array_sum($positive_angles) / count($positive_angles) : 0;
            $average_negative = count($negative_angles) > 0 ? array_sum($negative_angles) / count($negative_angles) : 0;

            $juego['amplitud_media'] = abs($average_positive - $average_negative);
        }
    }

    // Reindexar el array para que sea un listado
    echo json_encode(array_values($response));
}






function modificarJuego()
{
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id_juego']) || !isset($data['tiempo']) || !isset($data['angulo']) || !isset($data['id_tipo'])) {
        throw new Exception('Todos los campos son obligatorios');
    }

    $id_juego = $data['id_juego'];
    $tiempo = $data['tiempo'];
    $angulo = $data['angulo'];
    $id_tipo = $data['id_tipo'];

    // Actualiza el juego en la tabla `juego_datos`
    $stmt = $pdo->prepare("UPDATE juego_datos SET tiempo = ?, angulo = ?, id_tipo = ? WHERE id_juego = ?");
    $stmt->execute([$tiempo, $angulo, $id_tipo, $id_juego]);

    echo json_encode(['mensaje' => 'Juego actualizado correctamente']);
}

function bajaJuego()
{
    global $pdo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id_juego'])) {
        throw new Exception('El ID del juego es obligatorio');
    }

    $id_juego = $data['id_juego'];

    // Elimina el juego de la tabla `juego_datos`
    $stmt = $pdo->prepare("DELETE FROM juego_datos WHERE id_juego = ?");
    $stmt->execute([$id_juego]);

    // También puedes eliminar el juego de la tabla `juegos` si es necesario
    $stmt = $pdo->prepare("DELETE FROM juegos WHERE id_juego = ?");
    $stmt->execute([$id_juego]);

    echo json_encode(['mensaje' => 'Juego eliminado correctamente']);
}
