<?php
require 'config.php';

header('Content-Type: application/json');
// CORS básico
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Log a archivo (evitar HTML en respuesta)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

// ===== Helpers DB =====
function getUserIdByDni(PDO $pdo, $dni) {
  $st = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE dni=? AND tipo_usuario='paciente' LIMIT 1");
  $st->execute([trim((string)$dni)]);
  $id = $st->fetchColumn();
  return $id ? (int)$id : 0;
}

function getExistingDestAsigId(PDO $pdo, $dstPaciente, $medico, $app, $ts) {
  // ¿existe alguna asignación cuyo rango contenga la fecha del juego?
  $st = $pdo->prepare("
    SELECT id_asignacion
    FROM asignaciones
    WHERE id_usuario_paciente=? AND id_usuario_medico=? AND id_aplicacion=?
      AND ? BETWEEN fecha_inicio AND fecha_fin
    ORDER BY fecha_inicio DESC
    LIMIT 1
  ");
  $st->execute([(int)$dstPaciente, (int)$medico, (int)$app, $ts]);
  $id = $st->fetchColumn();
  return $id ? (int)$id : 0;
}

function ensureDestAsigId(PDO $pdo, $dstPaciente, $medico, $app, $gameDT, $forceActive, &$createdInfo) {
  // 1) Reusar si alguna asignación contiene la fecha del juego
  $found = getExistingDestAsigId($pdo, $dstPaciente, $medico, $app, $gameDT);
  if ($found) return $found;

  // 2) Si hay que crear, decidir rango
  if ($forceActive) {
    // para que “aparezca” en el front ahora mismo
    $fi = (new DateTime('now'))->modify('-1 day');
    $ff = (new DateTime('now'))->modify('+30 days');
  } else {
    // copia con base en la fecha del juego
    $fi = new DateTime($gameDT);
    $fi->setTime(0,0,0);
    $ff = clone $fi;
    $ff->modify('+30 days');
  }

  // Evitar choque por uq_asignacion (id_medico, id_paciente, id_app, fecha_inicio)
  // Si existe ya una asignación con el mismo composite+fecha_inicio exacta,
  // desplazamos start unos segundos.
  $insert = $pdo->prepare("
    INSERT INTO asignaciones
      (id_aplicacion, id_usuario_medico, id_usuario_paciente, fecha_inicio, fecha_fin, tiempo_minimo_seg, dificultad, estado)
    VALUES (?,?,?,?,?,?,?,?)
  ");

  $check = $pdo->prepare("
    SELECT id_asignacion FROM asignaciones
    WHERE id_usuario_medico=? AND id_usuario_paciente=? AND id_aplicacion=? AND fecha_inicio=?
    LIMIT 1
  ");

  // intentos para ajustar segundos si fuera necesario
  for ($bump=0; $bump<5; $bump++) {
    $fiTry = clone $fi;
    if ($bump > 0) { $fiTry->modify("+{$bump} seconds"); }
    $tsStart = $fiTry->format('Y-m-d H:i:s');
    $tsEnd   = $ff->format('Y-m-d H:i:s');

    // si ya existe exacta, no creamos otra
    $check->execute([(int)$medico, (int)$dstPaciente, (int)$app, $tsStart]);
    $exists = $check->fetchColumn();
    if ($exists) return (int)$exists;

    try {
      $insert->execute([
        (int)$app, (int)$medico, (int)$dstPaciente,
        $tsStart, $tsEnd,
        0, // sin mínimo
        1, // dificultad por defecto
        1  // activa
      ]);
      $newId = (int)$pdo->lastInsertId();
      $createdInfo[] = [
        'id_asignacion' => $newId,
        'id_aplicacion' => (int)$app,
        'id_usuario_medico' => (int)$medico,
        'fecha_inicio' => $tsStart,
        'fecha_fin' => $tsEnd
      ];
      return $newId;
    } catch (PDOException $e) {
      // 1062: duplicate key -> probá otro segundo
      if ($e->getCode() !== '23000') throw $e;
    }
  }

  throw new Exception('No se pudo crear asignación destino (choque de unicidad persistente).');
}

function cloneJuego(PDO $pdo, $srcJuegoId, $dstAsigId, $tiempo, $fecha, $puntaje, $dryRun, &$counters) {
  if ($dryRun) {
    $counters['juegos']++;
    // contar datos:
    $st = $pdo->prepare("SELECT COUNT(*) FROM juego_datos WHERE id_juego=?");
    $st->execute([$srcJuegoId]);
    $counters['juego_datos'] += (int)$st->fetchColumn();
    return;
  }

  // Insert juego
  $insJuego = $pdo->prepare("
    INSERT INTO juegos (tiempo_jugado, fecha, id_asignacion, puntaje)
    VALUES (?,?,?,?)
  ");
  $insJuego->execute([$tiempo, $fecha, (int)$dstAsigId, (int)$puntaje]);
  $newJuegoId = (int)$pdo->lastInsertId();
  $counters['juegos']++;

  // Clonar juego_datos
  $rd = $pdo->prepare("SELECT tiempo, angulo, id_tipo FROM juego_datos WHERE id_juego=?");
  $rd->execute([$srcJuegoId]);
  $rows = $rd->fetchAll(PDO::FETCH_ASSOC);

  if (!empty($rows)) {
    $insD = $pdo->prepare("INSERT INTO juego_datos (id_juego, tiempo, angulo, id_tipo) VALUES (?,?,?,?)");
    foreach ($rows as $r) {
      $insD->execute([$newJuegoId, (int)$r['tiempo'], $r['angulo'], (int)$r['id_tipo']]);
      $counters['juego_datos']++;
    }
  }
}

// ====== Entrada ======
$src_dni = isset($_GET['src_dni']) ? trim($_GET['src_dni']) : '';
$dst_dni = isset($_GET['dst_dni']) ? trim($_GET['dst_dni']) : '';
$dry_run = !empty($_GET['dry_run']);
$force_active = !empty($_GET['force_active']); // crea asignaciones activas hoy si hace falta

if ($src_dni === '' || $dst_dni === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Parámetros requeridos: src_dni y dst_dni']);
  exit();
}

// Sanity DB
try { $pdo->query("SELECT 1"); } catch (PDOException $e) {
  http_response_code(500); echo json_encode(['error' => 'DB: '.$e->getMessage()]); exit();
}

try {
  $srcId = getUserIdByDni($pdo, $src_dni);
  $dstId = getUserIdByDni($pdo, $dst_dni);

  if (!$srcId) throw new Exception("Paciente origen no encontrado por DNI: $src_dni");
  if (!$dstId) throw new Exception("Paciente destino no encontrado por DNI: $dst_dni");

  // Todas las jugadas del origen + su app y médico desde asignaciones
  $q = $pdo->prepare("
    SELECT
      j.id_juego, j.tiempo_jugado, j.fecha, j.puntaje, j.id_asignacion AS src_asig,
      a.id_aplicacion, a.id_usuario_medico
    FROM juegos j
    JOIN asignaciones a ON a.id_asignacion = j.id_asignacion
    WHERE a.id_usuario_paciente = ?
    ORDER BY j.fecha ASC, j.id_juego ASC
  ");
  $q->execute([$srcId]);
  $jugadas = $q->fetchAll(PDO::FETCH_ASSOC);

  if (!$jugadas) {
    echo json_encode(['mensaje' => 'No hay jugadas que clonar para el paciente origen.', 'src_dni' => $src_dni, 'dst_dni' => $dst_dni]);
    exit();
  }

  $createdAsigs = [];
  $counters = ['juegos' => 0, 'juego_datos' => 0];

  if (!$dry_run) $pdo->beginTransaction();

  foreach ($jugadas as $row) {
    $app    = (int)$row['id_aplicacion'];
    $medico = (int)$row['id_usuario_medico'];
    $fecha  = (new DateTime($row['fecha']))->format('Y-m-d H:i:s');

    // buscar/crear asignación destino adecuada
    $dstAsig = ensureDestAsigId($pdo, $dstId, $medico, $app, $fecha, $force_active, $createdAsigs);

    // clonar juego + datos
    cloneJuego(
      $pdo,
      (int)$row['id_juego'],
      $dstAsig,
      $row['tiempo_jugado'],
      $fecha,
      (int)$row['puntaje'],
      $dry_run,
      $counters
    );
  }

  if (!$dry_run) $pdo->commit();

  echo json_encode([
    'ok' => true,
    'dry_run' => $dry_run ? 1 : 0,
    'force_active' => $force_active ? 1 : 0,
    'src' => ['dni' => $src_dni, 'id_usuario' => $srcId],
    'dst' => ['dni' => $dst_dni, 'id_usuario' => $dstId],
    'created_asignaciones' => $createdAsigs,
    'cloned' => $counters
  ]);
} catch (Exception $e) {
  if (!$dry_run && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
