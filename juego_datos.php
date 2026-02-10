<?php
require 'config.php';

header('Content-Type: application/json; charset=UTF-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

/** ===== Debug seguro (no imprimir HTML de errores) ===== */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

/** ===== Sanity DB ===== */
try { $pdo->query("SELECT 1"); }
catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['error' => 'DB: '.$e->getMessage()]);
  exit();
}

try {
  switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
      listarJuegoDatos();
      break;

    case 'POST':
      crearJuegoDatos();
      break;

    case 'DELETE':
      eliminarJuegoDatos();
      break;

    default:
      http_response_code(405);
      echo json_encode(['error' => 'Método no permitido']);
  }
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['error' => 'SQL: '.$e->getMessage()]);
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}

/* ==========================================================
   GET /juego_datos.php
   Requiere:
     - id_juego
   Filtros opcionales:
     - id_tipo
     - tmin        (tiempo >=)
     - tmax        (tiempo <=)
     - order=asc|desc  (default asc)
   Devuelve: puntos + metadata del tipo (tipo, observacion, numero)
   ========================================================== */
function listarJuegoDatos() {
  global $pdo;

  if (empty($_GET['id_juego'])) throw new Exception('id_juego es obligatorio');
  $id_juego = (int)$_GET['id_juego'];

  $where = ['jd.id_juego = ?'];
  $p = [$id_juego];

  if (!empty($_GET['id_tipo']))                { $where[] = 'jd.id_tipo = ?';  $p[] = (int)$_GET['id_tipo']; }
  if (isset($_GET['tmin']) && $_GET['tmin']!=='') { $where[] = 'jd.tiempo >= ?'; $p[] = (int)$_GET['tmin']; }
  if (isset($_GET['tmax']) && $_GET['tmax']!=='') { $where[] = 'jd.tiempo <= ?'; $p[] = (int)$_GET['tmax']; }

  $order = (isset($_GET['order']) && strtolower($_GET['order']) === 'desc') ? 'DESC' : 'ASC';

  $sql = "SELECT
            jd.id_juego,
            jd.tiempo,
            jd.angulo,
            jd.id_tipo,
            td.tipo,
            td.observacion,
            td.numero
          FROM juego_datos jd
          JOIN tipo_dato_juegos td ON td.id_tipo = jd.id_tipo
          WHERE ".implode(' AND ', $where)."
          ORDER BY jd.tiempo $order";

  $st = $pdo->prepare($sql);
  $st->execute($p);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    http_response_code(404);
    echo json_encode(['error' => 'Sin datos para ese juego/tipo']);
    return;
  }
  echo json_encode($rows);
}

/* ==========================================================
   POST /juego_datos.php
   Body JSON:
     - Objeto: { id_juego, tiempo, angulo, id_tipo }
     - o Array de objetos: [ {...}, {...}, ... ]

   Query opcional:
     - upsert=1 -> INSERT ... ON DUPLICATE KEY UPDATE (actualiza angulo/id_tipo)

   Notas:
     - Se asume PK/UK en (id_juego, tiempo, id_tipo).
     - tiempo: int >= 0
     - angulo: decimal
   ========================================================== */
function crearJuegoDatos() {
  global $pdo;

  $payload = json_decode(file_get_contents('php://input'), true);
  if ($payload === null) throw new Exception('Body JSON inválido');

  $rows = is_array($payload) && isset($payload[0]) ? $payload : [$payload];
  $upsert = !empty($_GET['upsert']);

  // Validar y normalizar
  foreach ($rows as $i => $r) {
    foreach (['id_juego','tiempo','angulo','id_tipo'] as $k) {
      if (!isset($r[$k]) || $r[$k] === '') throw new Exception("Fila $i: falta $k");
    }
    if (!is_numeric($r['tiempo']) || (int)$r['tiempo'] < 0) throw new Exception("Fila $i: tiempo inválido");
    if (!is_numeric($r['angulo'])) throw new Exception("Fila $i: angulo inválido");

    $rows[$i]['id_juego'] = (int)$r['id_juego'];
    $rows[$i]['tiempo']   = (int)$r['tiempo'];
    $rows[$i]['id_tipo']  = (int)$r['id_tipo'];
    $rows[$i]['angulo']   = (string)$r['angulo']; // respetar decimales
  }

  if ($upsert) {
    $sql = "INSERT INTO juego_datos (id_juego, tiempo, angulo, id_tipo)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              angulo = VALUES(angulo),
              id_tipo = VALUES(id_tipo)";
  } else {
    $sql = "INSERT INTO juego_datos (id_juego, tiempo, angulo, id_tipo)
            VALUES (?, ?, ?, ?)";
  }

  $st = $pdo->prepare($sql);

  $pdo->beginTransaction();
  try {
    $n = 0;
    foreach ($rows as $r) {
      $st->execute([$r['id_juego'], $r['tiempo'], $r['angulo'], $r['id_tipo']]);
      $n++;
    }
    $pdo->commit();
  } catch (PDOException $e) {
    $pdo->rollBack();
    if (strpos($e->getMessage(), 'Duplicate entry') !== false && !$upsert) {
      throw new Exception('Registro duplicado (id_juego, tiempo, id_tipo). Usá ?upsert=1 para actualizar.');
    }
    throw $e;
  }

  http_response_code(201);
  echo json_encode([
    'mensaje' => $upsert ? 'Datos insertados/actualizados' : 'Datos insertados',
    'filas'   => $n
  ]);
}

/* ==========================================================
   DELETE /juego_datos.php
   Query:
     - id_juego (obligatorio)
     - tiempo   (opcional)
     - id_tipo  (opcional)

   Reglas:
     - id_juego                       -> borra TODO el juego
     - id_juego + tiempo              -> borra ese tiempo (todos los tipos)
     - id_juego + tiempo + id_tipo    -> borra el registro exacto
   ========================================================== */
function eliminarJuegoDatos() {
  global $pdo;

  if (empty($_GET['id_juego'])) throw new Exception('id_juego es obligatorio');
  $id_juego = (int)$_GET['id_juego'];

  $tiempo  = isset($_GET['tiempo'])  ? (int)$_GET['tiempo']  : null;
  $id_tipo = isset($_GET['id_tipo']) ? (int)$_GET['id_tipo'] : null;

  if ($tiempo !== null && $id_tipo !== null) {
    $sql = "DELETE FROM juego_datos WHERE id_juego = ? AND tiempo = ? AND id_tipo = ?";
    $p = [$id_juego, $tiempo, $id_tipo];
  } elseif ($tiempo !== null) {
    $sql = "DELETE FROM juego_datos WHERE id_juego = ? AND tiempo = ?";
    $p = [$id_juego, $tiempo];
  } else {
    $sql = "DELETE FROM juego_datos WHERE id_juego = ?";
    $p = [$id_juego];
  }

  $st = $pdo->prepare($sql);
  $st->execute($p);

  echo json_encode(['mensaje' => 'Datos eliminados', 'afectados' => $st->rowCount()]);
}
