<?php
require 'config.php';

header('Content-Type: application/json');

// CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Log de errores (solo a file)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

// Sanity DB
try { $pdo->query("SELECT 1"); } catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['error' => 'DB: '.$e->getMessage()]);
  exit();
}

try {
  switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
      if (isset($_GET['id_paciente']) || isset($_GET['id_usuario_paciente']) || isset($_GET['dni_paciente'])) {
        listarAsignacionesPorPaciente();
      } else {
        listarAsignaciones();
      }
      break;

    case 'POST':
      crearAsignacion();
      break;

    case 'PUT':
      actualizarAsignacion();
      break;

    case 'DELETE':
      eliminarAsignacion();
      break;

    default:
      http_response_code(405);
      echo json_encode(['error' => 'Método no permitido']);
  }
} catch (PDOException $e) {
  http_response_code(500); echo json_encode(['error'=>'SQL: '.$e->getMessage()]);
} catch (Exception $e) {
  http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
}

/* ==========================
   Helpers
   ========================== */

/**
 * Devuelve true si existe solape ACTIVO (estado=1) para misma app+paciente en el rango dado.
 */
function existeSolape($id_aplicacion, $id_usuario_paciente, $fecha_inicio, $fecha_fin, $excluir_id = null) {
  global $pdo;
  $sql = "SELECT 1
          FROM asignaciones
          WHERE id_aplicacion = ?
            AND id_usuario_paciente = ?
            AND estado = 1
            AND fecha_inicio <= ?
            AND fecha_fin >= ?";
  $params = [(int)$id_aplicacion, (int)$id_usuario_paciente, $fecha_fin, $fecha_inicio];

  if ($excluir_id !== null) {
    $sql .= " AND id_asignacion <> ?";
    $params[] = (int)$excluir_id;
  }

  $sql .= " LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (bool)$st->fetchColumn();
}

/**
 * Resuelve ID de paciente por DNI (compat).
 */
function resolverIdPacientePorDni($dni) {
  global $pdo;
  $st = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE dni=? AND tipo_usuario='paciente' LIMIT 1");
  $st->execute([trim((string)$dni)]);
  return (int)$st->fetchColumn();
}

/**
 * Rango de dificultad permitido por app.
 */
function rangoDificultadParaApp($id_aplicacion) {
  global $pdo;
  $st = $pdo->prepare("SELECT titulo FROM aplicaciones WHERE id_aplicacion=? LIMIT 1");
  $st->execute([(int)$id_aplicacion]);
  $titulo = strtolower((string)$st->fetchColumn());

  $min = 1; $max = 5; // default
  if ($titulo) {
    if (strpos($titulo, 'space') !== false && strpos($titulo, 'ev') !== false) {
      $min = 1; $max = 5;
    } elseif ((strpos($titulo, 'platform') !== false || strpos($titulo, 'plataform') !== false) && strpos($titulo, 'jump') !== false) {
      $min = 1; $max = 3;
    }
  }
  return [$min, $max];
}

function normalizarDificultad($id_aplicacion, $dificultad_raw) {
  $dif = is_numeric($dificultad_raw) ? (int)$dificultad_raw : 1;
  if ($dif < -100 || $dif > 1000) $dif = 1; // basura defensiva
  list($min,$max) = rangoDificultadParaApp($id_aplicacion);
  if ($dif < $min) $dif = $min;
  if ($dif > $max) $dif = $max;
  return $dif;
}

/**
 * 🔎 Busca una asignación por la CLAVE ÚNICA (coincide con uq_asignacion).
 * Ajustá los campos si tu índice único incluye otros.
 */
function buscarAsignacionPorClave($id_aplicacion, $id_usuario_medico, $id_usuario_paciente, $fecha_inicio) {
  global $pdo;
  $st = $pdo->prepare("
    SELECT id_asignacion
    FROM asignaciones
    WHERE id_aplicacion = ?
      AND id_usuario_medico = ?
      AND id_usuario_paciente = ?
      AND fecha_inicio = ?
    LIMIT 1
  ");
  $st->execute([(int)$id_aplicacion, (int)$id_usuario_medico, (int)$id_usuario_paciente, $fecha_inicio]);
  $id = $st->fetchColumn();
  return $id ? (int)$id : 0;
}

/* ==========================
   GET por Paciente
   ========================== */

function listarAsignacionesPorPaciente() {
  global $pdo;

  $id_usuario_paciente = $_GET['id_usuario_paciente'] ?? $_GET['id_paciente'] ?? null;

  if (!$id_usuario_paciente && !empty($_GET['dni_paciente'])) {
    $id_resuelto = resolverIdPacientePorDni($_GET['dni_paciente']);
    if ($id_resuelto > 0) $id_usuario_paciente = $id_resuelto;
  }

  if (!$id_usuario_paciente) throw new Exception('id_usuario_paciente (o dni_paciente) es obligatorio');

  $id_usuario_medico = $_GET['id_usuario_medico'] ?? $_GET['id_usuario'] ?? null;
  $id_aplicacion     = isset($_GET['id_aplicacion']) ? (int)$_GET['id_aplicacion'] : null;

  $solo_activas = !empty($_GET['activas']);
  $fEstado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';

  $include_stats = !empty($_GET['include_stats']);

  $where = ['a.id_usuario_paciente = ?'];
  $params = [(int)$id_usuario_paciente];

  if ($id_usuario_medico) { $where[] = 'a.id_usuario_medico = ?'; $params[] = (int)$id_usuario_medico; }
  if ($id_aplicacion)     { $where[] = 'a.id_aplicacion = ?';     $params[] = (int)$id_aplicacion; }
  if ($solo_activas)      { $where[] = 'NOW() BETWEEN a.fecha_inicio AND a.fecha_fin'; }
  if ($fEstado !== '')    { $where[] = 'a.estado = ?'; $params[] = ((int)$fEstado) ? 1 : 0; }

  $statsJoin = $include_stats
    ? "LEFT JOIN (
         SELECT id_asignacion,
                SUM(tiempo_jugado) AS total_jugado_seg,
                COUNT(*)           AS cant_jugadas
         FROM juegos
         GROUP BY id_asignacion
       ) js ON js.id_asignacion = a.id_asignacion"
    : "";

  $selectStats = $include_stats
    ? ", COALESCE(js.total_jugado_seg,0) AS total_jugado_seg,
       COALESCE(js.cant_jugadas,0)       AS cant_jugadas,
       GREATEST(a.tiempo_minimo_seg - COALESCE(js.total_jugado_seg,0), 0) AS restante_seg"
    : "";

  $sql = "SELECT
            a.id_asignacion,
            a.id_aplicacion,
            ap.titulo           AS aplicacion_titulo,
            a.id_usuario_medico,
            um.nombre           AS medico_nombre,
            um.apellido         AS medico_apellido,
            a.id_usuario_paciente,
            up.nombre           AS paciente_nombre,
            up.apellido         AS paciente_apellido,
            a.fecha_inicio,
            a.fecha_fin,
            a.tiempo_minimo_seg,
            a.dificultad,
            a.estado
            $selectStats
          FROM asignaciones a
          JOIN aplicaciones ap ON ap.id_aplicacion = a.id_aplicacion
          JOIN usuarios     um ON um.id_usuario   = a.id_usuario_medico
          JOIN usuarios     up ON up.id_usuario   = a.id_usuario_paciente
          $statsJoin
          WHERE ".implode(' AND ', $where)."
          ORDER BY a.fecha_inicio DESC, a.id_asignacion DESC";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($rows ?: []);
}

/* ==========================
   GET general
   ========================== */

function listarAsignaciones() {
  global $pdo;

  $where = [];
  $params = [];

  if (!empty($_GET['id_asignacion']))         { $where[] = 'a.id_asignacion = ?';         $params[] = (int)$_GET['id_asignacion']; }
  if (!empty($_GET['id_usuario_paciente']))   { $where[] = 'a.id_usuario_paciente = ?';   $params[] = (int)$_GET['id_usuario_paciente']; }
  if (!empty($_GET['id_paciente']))           { $where[] = 'a.id_usuario_paciente = ?';   $params[] = (int)$_GET['id_paciente']; }
  if (!empty($_GET['dni_paciente'])) {
    $id_resuelto = resolverIdPacientePorDni($_GET['dni_paciente']);
    if ($id_resuelto > 0) { $where[] = 'a.id_usuario_paciente = ?'; $params[] = $id_resuelto; }
    else { echo json_encode([]); return; }
  }
  if (!empty($_GET['id_usuario_medico']))     { $where[] = 'a.id_usuario_medico = ?';     $params[] = (int)$_GET['id_usuario_medico']; }
  if (!empty($_GET['id_usuario']))            { $where[] = 'a.id_usuario_medico = ?';     $params[] = (int)$_GET['id_usuario']; }
  if (!empty($_GET['id_aplicacion']))         { $where[] = 'a.id_aplicacion = ?';         $params[] = (int)$_GET['id_aplicacion']; }
  if (!empty($_GET['activas']))               { $where[] = 'NOW() BETWEEN a.fecha_inicio AND a.fecha_fin'; }
  if (isset($_GET['estado']) && $_GET['estado'] !== '') {
    $where[] = 'a.estado = ?'; $params[] = ((int)$_GET['estado']) ? 1 : 0;
  }
  if (isset($_GET['dificultad']) && $_GET['dificultad'] !== '') {
    $where[] = 'a.dificultad = ?'; $params[] = (int)$_GET['dificultad'];
  }

  $include_stats = !empty($_GET['include_stats']);

  $statsJoin = $include_stats
    ? "LEFT JOIN (
         SELECT id_asignacion,
                SUM(tiempo_jugado) AS total_jugado_seg,
                COUNT(*)           AS cant_jugadas
         FROM juegos
         GROUP BY id_asignacion
       ) js ON js.id_asignacion = a.id_asignacion"
    : "";

  $selectStats = $include_stats
    ? ", COALESCE(js.total_jugado_seg,0) AS total_jugado_seg,
       COALESCE(js.cant_jugadas,0)       AS cant_jugadas,
       GREATEST(a.tiempo_minimo_seg - COALESCE(js.total_jugado_seg,0), 0) AS restante_seg"
    : "";

  $sql = "SELECT
            a.id_asignacion,
            a.id_aplicacion,
            ap.titulo           AS aplicacion_titulo,
            a.id_usuario_medico,
            um.nombre           AS medico_nombre,
            um.apellido         AS medico_apellido,
            a.id_usuario_paciente,
            up.nombre           AS paciente_nombre,
            up.apellido         AS paciente_apellido,
            a.fecha_inicio,
            a.fecha_fin,
            a.tiempo_minimo_seg,
            a.dificultad,
            a.estado
            $selectStats
          FROM asignaciones a
          JOIN aplicaciones ap ON ap.id_aplicacion = a.id_aplicacion
          JOIN usuarios     um ON um.id_usuario   = a.id_usuario_medico
          JOIN usuarios     up ON up.id_usuario   = a.id_usuario_paciente
          $statsJoin";

  if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= ' ORDER BY a.fecha_inicio DESC, a.id_asignacion DESC';

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($rows ?: []);
}

/* ==========================
   POST (create) – IDEMPOTENTE
   ========================== */

function crearAsignacion() {
  global $pdo;
  $data = json_decode(file_get_contents('php://input'), true) ?? [];

  // Compat mapeos
  if (isset($data['id_usuario']) && !isset($data['id_usuario_medico'])) {
    $data['id_usuario_medico'] = $data['id_usuario'];
  }
  if (isset($data['id_paciente']) && !isset($data['id_usuario_paciente'])) {
    $data['id_usuario_paciente'] = $data['id_paciente'];
  }

  foreach (['id_aplicacion','id_usuario_medico','id_usuario_paciente','fecha_inicio','fecha_fin'] as $k) {
    if (!isset($data[$k]) || $data[$k]==='') throw new Exception("Falta $k");
  }

  $id_aplicacion        = (int)$data['id_aplicacion'];
  $id_usuario_medico    = (int)$data['id_usuario_medico'];
  $id_usuario_paciente  = (int)$data['id_usuario_paciente'];
  $fecha_inicio         = $data['fecha_inicio']; // 'YYYY-MM-DD' o DATETIME
  $fecha_fin            = $data['fecha_fin'];

  // tiempo mínimo 0..7200
  $tiempo_minimo_seg    = isset($data['tiempo_minimo_seg']) && $data['tiempo_minimo_seg'] !== '' ? (int)$data['tiempo_minimo_seg'] : 0;
  if ($tiempo_minimo_seg < 0)  $tiempo_minimo_seg = 0;
  if ($tiempo_minimo_seg > 7200) $tiempo_minimo_seg = 7200;

  // dificultad segun app
  $dificultad           = normalizarDificultad($id_aplicacion, $data['dificultad'] ?? 1);

  $estado               = isset($data['estado']) ? (int)$data['estado'] : 1;
  $estado               = $estado ? 1 : 0;

  // Validaciones básicas
  if (strtotime($fecha_inicio) === false || strtotime($fecha_fin) === false) throw new Exception('Fechas inválidas');
  if (strtotime($fecha_fin) <= strtotime($fecha_inicio)) throw new Exception('fecha_fin debe ser mayor a fecha_inicio');

  // 🔁 IDEMPOTENCIA: si ya existe EXACTA por la clave única -> UPDATE y devolver OK
  $existente = buscarAsignacionPorClave($id_aplicacion, $id_usuario_medico, $id_usuario_paciente, $fecha_inicio);
  if ($existente) {
    $up = $pdo->prepare("UPDATE asignaciones
                         SET fecha_fin=?, tiempo_minimo_seg=?, dificultad=?, estado=?
                         WHERE id_asignacion=?");
    $up->execute([$fecha_fin, $tiempo_minimo_seg, $dificultad, $estado, $existente]);

    echo json_encode([
      'mensaje' => 'Asignación existente actualizada (idempotente)',
      'id_asignacion' => $existente
    ]);
    return;
  }

  // Anti-solapes con otras asignaciones activas (excluye la exacta porque arriba ya habríamos vuelto)
  if ($estado === 1 && existeSolape($id_aplicacion, $id_usuario_paciente, $fecha_inicio, $fecha_fin, null)) {
    throw new Exception('Ya existe una asignación activa solapada para ese paciente y aplicación en ese rango');
  }

  // INSERT normal
  $sql = "INSERT INTO asignaciones
          (id_aplicacion, id_usuario_medico, id_usuario_paciente, fecha_inicio, fecha_fin, tiempo_minimo_seg, dificultad, estado)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
  $st = $pdo->prepare($sql);
  $st->execute([$id_aplicacion, $id_usuario_medico, $id_usuario_paciente, $fecha_inicio, $fecha_fin, $tiempo_minimo_seg, $dificultad, $estado]);

  http_response_code(201);
  echo json_encode(['mensaje'=>'Asignación creada', 'id_asignacion'=>$pdo->lastInsertId()]);
}

/* ==========================
   PUT (update)
   ========================== */

function actualizarAsignacion() {
  global $pdo;

  parse_str($_SERVER['QUERY_STRING'] ?? '', $query);
  $data = json_decode(file_get_contents('php://input'), true) ?? [];

  $id = isset($query['id_asignacion']) ? (int)$query['id_asignacion'] : (int)($data['id_asignacion'] ?? 0);
  if (!$id) throw new Exception('id_asignacion requerido');

  // Traer actual
  $st = $pdo->prepare("SELECT * FROM asignaciones WHERE id_asignacion = ?");
  $st->execute([$id]);
  $cur = $st->fetch(PDO::FETCH_ASSOC);
  if (!$cur) throw new Exception('Asignación no encontrada');

  // Merge
  $id_aplicacion        = isset($data['id_aplicacion'])        ? (int)$data['id_aplicacion']        : (int)$cur['id_aplicacion'];
  $id_usuario_medico    = isset($data['id_usuario_medico'])    ? (int)$data['id_usuario_medico']    : (int)$cur['id_usuario_medico'];
  $id_usuario_paciente  = isset($data['id_usuario_paciente'])  ? (int)$data['id_usuario_paciente']  : (int)$cur['id_usuario_paciente'];
  $fecha_inicio         = isset($data['fecha_inicio'])         ? $data['fecha_inicio']              : $cur['fecha_inicio'];
  $fecha_fin            = isset($data['fecha_fin'])            ? $data['fecha_fin']                 : $cur['fecha_fin'];

  $tiempo_minimo_seg    = array_key_exists('tiempo_minimo_seg', $data) ? (int)$data['tiempo_minimo_seg'] : (int)$cur['tiempo_minimo_seg'];
  if ($tiempo_minimo_seg < 0)  $tiempo_minimo_seg = 0;
  if ($tiempo_minimo_seg > 7200) $tiempo_minimo_seg = 7200;

  $dificultad = array_key_exists('dificultad', $data)
                  ? normalizarDificultad($id_aplicacion, $data['dificultad'])
                  : (int)($cur['dificultad'] ?? 1);

  $estado = isset($data['estado']) ? (int)$data['estado'] : (int)$cur['estado'];
  $estado = $estado ? 1 : 0;

  // Validaciones
  if (strtotime($fecha_inicio) === false || strtotime($fecha_fin) === false) throw new Exception('Fechas inválidas');
  if (strtotime($fecha_fin) <= strtotime($fecha_inicio)) throw new Exception('fecha_fin debe ser mayor a fecha_inicio');

  // Anti-solapes (excluyendo esta)
  if ($estado === 1 && existeSolape($id_aplicacion, $id_usuario_paciente, $fecha_inicio, $fecha_fin, $id)) {
    throw new Exception('La actualización solapa con otra asignación activa del mismo paciente y aplicación');
  }

  $sql = "UPDATE asignaciones
          SET id_aplicacion = ?, id_usuario_medico = ?, id_usuario_paciente = ?,
              fecha_inicio = ?, fecha_fin = ?, tiempo_minimo_seg = ?, dificultad = ?, estado = ?
          WHERE id_asignacion = ?";
  $st2 = $pdo->prepare($sql);
  $st2->execute([$id_aplicacion, $id_usuario_medico, $id_usuario_paciente, $fecha_inicio, $fecha_fin, $tiempo_minimo_seg, $dificultad, $estado, $id]);

  echo json_encode(['mensaje'=>'Asignación actualizada']);
}

/* ==========================
   DELETE (soft por defecto)
   ========================== */

function eliminarAsignacion() {
  global $pdo;

  $id = null;
  if (isset($_GET['id_asignacion'])) $id = (int)$_GET['id_asignacion'];
  if (!$id) {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    if (isset($data['id_asignacion'])) $id = (int)$data['id_asignacion'];
  }
  if (!$id) throw new Exception('id_asignacion es obligatorio');

  $hard = isset($_GET['hard']) && $_GET['hard'] == '1';
  if ($hard) {
    $st = $pdo->prepare("DELETE FROM asignaciones WHERE id_asignacion = ?");
    $st->execute([$id]);
    echo json_encode(['mensaje'=>'Asignación eliminada (hard)']);
  } else {
    $st = $pdo->prepare("UPDATE asignaciones SET estado = 0 WHERE id_asignacion = ?");
    $st->execute([$id]);
    echo json_encode(['mensaje'=>'Asignación dada de baja (soft)']);
  }
}
