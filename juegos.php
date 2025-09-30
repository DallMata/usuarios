<?php
require 'config.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Debug en dev
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Sanity DB
try { $pdo->query("SELECT 1"); } catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['error'=>'DB: '.$e->getMessage()]);
  exit();
}

try {
  switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
      listarJuegos();
      break;

    case 'POST':
      crearJuego();
      break;

    case 'DELETE':
      eliminarJuego();
      break;

    default:
      http_response_code(405);
      echo json_encode(['error'=>'Método no permitido']);
  }
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['error'=>'SQL: '.$e->getMessage()]);
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}

/* ==========================
   GET /juegos.php
   Filtros (opcionales):
     - id_juego
     - id_asignacion
     - id_usuario_paciente
     - id_usuario_medico
     - id_aplicacion
     - desde=YYYY-mm-dd [HH:MM:SS]
     - hasta=YYYY-mm-dd [HH:MM:SS]
   ========================== */
function listarJuegos() {
  global $pdo;
  $where = [];
  $p = [];

  if (!empty($_GET['id_juego']))            { $where[] = 'j.id_juego = ?';             $p[] = (int)$_GET['id_juego']; }
  if (!empty($_GET['id_asignacion']))       { $where[] = 'j.id_asignacion = ?';        $p[] = (int)$_GET['id_asignacion']; }
  if (!empty($_GET['id_usuario_paciente'])) { $where[] = 'a.id_usuario_paciente = ?';  $p[] = (int)$_GET['id_usuario_paciente']; }
  if (!empty($_GET['id_usuario_medico']))   { $where[] = 'a.id_usuario_medico = ?';    $p[] = (int)$_GET['id_usuario_medico']; }
  if (!empty($_GET['id_aplicacion']))       { $where[] = 'a.id_aplicacion = ?';        $p[] = (int)$_GET['id_aplicacion']; }
  if (!empty($_GET['desde']))               { $where[] = 'j.fecha >= ?';               $p[] = $_GET['desde']; }
  if (!empty($_GET['hasta']))               { $where[] = 'j.fecha <= ?';               $p[] = $_GET['hasta']; }

  $sql = "SELECT
            j.id_juego, j.tiempo_jugado, j.fecha, j.puntaje, j.id_asignacion,
            a.id_usuario_paciente, a.id_usuario_medico, a.id_aplicacion, a.tiempo_minimo_seg,
            a.estado AS asignacion_estado
          FROM juegos j
          JOIN asignaciones a ON a.id_asignacion = j.id_asignacion";
  if ($where) $sql .= ' WHERE '.implode(' AND ',$where);
  $sql .= ' ORDER BY j.fecha DESC';

  $st = $pdo->prepare($sql);
  $st->execute($p);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) { http_response_code(404); echo json_encode(['error'=>'Sin resultados']); return; }
  echo json_encode($rows);
}

/* ==========================
   POST /juegos.php
   Body JSON:
   {
     "id_usuario_paciente": number,       // o "dni" / "dni_paciente"
     "id_aplicacion": number,             // o "juego"/"aplicacion_titulo"/"titulo"
     "tiempo_jugado": number,             // en segundos (>=0)
     "puntaje": number (opcional),
     "fecha": "YYYY-mm-dd HH:MM:SS" (opcional; default NOW()),

     // Opcional: telemetría
     "datos": [
       { "tiempo": 0, "angulo": 10.5, "id_tipo": 29 },
       ...
     ]
     // También acepta "juego_datos" o "angulos" (ver normalización más abajo)
   }

   Flujo:
   - Resuelve id_usuario_paciente y id_aplicacion (por id o nombre).
   - Busca la asignación ACTIVA (estado=1) que cubra "fecha".
   - Inserta en juegos (vinculado a esa asignación).
   - Inserta juego_datos si vinieron.
   - Calcula total acumulado (SUM tiempo_jugado) para esa asignación.
   - Si total >= tiempo_minimo_seg (>0), cierra la asignación (estado=0).
   ========================== */
function crearJuego() {
  global $pdo;

  // JSON o form
  $d = json_decode(file_get_contents('php://input'), true);
  if (json_last_error() !== JSON_ERROR_NONE || !$d) { $d = $_POST ?? []; }

  // ===== Compat nombres =====
  // Paciente: id o dni
  $idUsuario = $d['id_usuario_paciente'] ?? $d['id_usuario'] ?? null;
  $dni       = $d['dni'] ?? $d['dni_paciente'] ?? null;
  if (!$idUsuario && $dni) {
    $st = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE dni=? AND tipo_usuario='paciente' LIMIT 1");
    $st->execute([trim((string)$dni)]);
    $idUsuario = (int)$st->fetchColumn();
  }

  // Aplicación: id o nombre (juego/aplicacion_titulo)
  $idAplic = $d['id_aplicacion'] ?? null;
  if (!$idAplic) {
    $titulo = $d['juego'] ?? $d['aplicacion_titulo'] ?? $d['titulo'] ?? null;
    if ($titulo) {
      $st = $pdo->prepare("SELECT id_aplicacion FROM aplicaciones WHERE titulo LIKE ? LIMIT 1");
      $st->execute([$titulo]);
      $idAplic = (int)$st->fetchColumn();
      if (!$idAplic) {
        $st = $pdo->prepare("SELECT id_aplicacion FROM aplicaciones WHERE REPLACE(LOWER(titulo),' ','') = REPLACE(LOWER(?),' ','') LIMIT 1");
        $st->execute([$titulo]);
        $idAplic = (int)$st->fetchColumn();
      }
    }
  }

  // Duración y puntaje
  $tiempo  = $d['tiempo_jugado'] ?? $d['duracion_seg'] ?? $d['duracion'] ?? $d['elapsed_seconds'] ?? null;
  $puntaje = $d['puntaje'] ?? $d['score'] ?? 0;

  // Fecha (opcional)
  $fecha = !empty($d['fecha']) ? $d['fecha'] : date('Y-m-d H:i:s');

  // Telemetría opcional (varios formatos válidos)
  $datos = [];
  if (!empty($d['datos']) && is_array($d['datos']))                $datos = $d['datos'];
  elseif (!empty($d['juego_datos']) && is_array($d['juego_datos'])) $datos = $d['juego_datos'];
  elseif (!empty($d['angulos']) && is_array($d['angulos']))         $datos = $d['angulos'];

  // ===== Validaciones mínimas =====
  if (!$idUsuario)      throw new Exception("Campo requerido: id_usuario_paciente (o dni)");
  if (!$idAplic)        throw new Exception("Campo requerido: id_aplicacion (o juego/aplicacion_titulo)");
  if ($tiempo === null) throw new Exception("Campo requerido: tiempo_jugado (o duracion_seg)");
  if (!is_numeric($tiempo) || $tiempo < 0) throw new Exception('tiempo_jugado inválido');
  if (strtotime($fecha) === false) throw new Exception('fecha inválida');

  $tiempo  = (float)$tiempo;
  $puntaje = (int)$puntaje;

  // ===== Buscar asignación ACTIVA =====
  $sqlAsig = "SELECT id_asignacion, tiempo_minimo_seg, estado
              FROM asignaciones
              WHERE id_usuario_paciente = ?
                AND id_aplicacion = ?
                AND estado = 1
                AND ? BETWEEN fecha_inicio AND fecha_fin
              ORDER BY fecha_inicio DESC
              LIMIT 1";
  $st = $pdo->prepare($sqlAsig);
  $st->execute([(int)$idUsuario, (int)$idAplic, $fecha]);
  $asig = $st->fetch(PDO::FETCH_ASSOC);
  if (!$asig) throw new Exception('No hay asignación activa para ese paciente/aplicación/fecha');

  // ===== Transacción: insert en juegos + (opcional) juego_datos + auto-cierre =====
  $pdo->beginTransaction();
  try {
    // Insert principal en juegos
    $ins = $pdo->prepare("INSERT INTO juegos (tiempo_jugado, fecha, puntaje, id_asignacion)
                          VALUES (?, ?, ?, ?)");
    $ins->execute([$tiempo, $fecha, $puntaje, (int)$asig['id_asignacion']]);
    $idJuego = (int)$pdo->lastInsertId();

    // Insert telemetría si vino algo (normalizamos formatos variados)
    if ($datos && is_array($datos)) {
      // Normalizar a objetos {tiempo, angulo, id_tipo}
      $norm = [];
      foreach ($datos as $row) {
        if (is_array($row) && array_keys($row) === array_keys(array_values($row))) {
          // array posicional: [tiempo, angulo]
          $t = isset($row[0]) ? (int)$row[0] : null;
          $a = isset($row[1]) ? (float)$row[1] : null;
          if ($t !== null && $a !== null) { $norm[] = ['tiempo'=>$t, 'angulo'=>$a, 'id_tipo'=>null]; }
        } elseif (is_array($row)) {
          // objeto con claves
          $t = isset($row['tiempo']) ? (int)$row['tiempo'] : null;
          $a = isset($row['angulo']) ? (float)$row['angulo'] : null;
          $id_t = isset($row['id_tipo']) ? (int)$row['id_tipo'] : null;
          if ($t !== null && $a !== null) { $norm[] = ['tiempo'=>$t, 'angulo'=>$a, 'id_tipo'=>$id_t]; }
        }
      }

      if ($norm) {
        $insD = $pdo->prepare("INSERT INTO juego_datos (id_juego, tiempo, angulo, id_tipo) VALUES (?, ?, ?, ?)");
        foreach ($norm as $r) {
          // Si no mandan id_tipo, guardamos con NULL? La PK compuesta requiere id_tipo;
          // en ese caso preferimos no insertar ese punto para no romper integridad.
          if (!isset($r['id_tipo']) || $r['id_tipo'] === null) continue;
          $insD->execute([$idJuego, (int)$r['tiempo'], (float)$r['angulo'], (int)$r['id_tipo']]);
        }
      }
    }

    // Calcular total acumulado para la asignación (suma de todos los juegos vinculados)
    $stSum = $pdo->prepare("SELECT COALESCE(SUM(tiempo_jugado),0) FROM juegos WHERE id_asignacion = ?");
    $stSum->execute([(int)$asig['id_asignacion']]);
    $totalAsignacion = (int)$stSum->fetchColumn();

    $cerradaAhora = false;
    $tiempoMin = (int)$asig['tiempo_minimo_seg'];

    // Cerrar si corresponde (mínimo > 0 y total >= mínimo)
    if ($tiempoMin > 0 && $totalAsignacion >= $tiempoMin) {
      $stClose = $pdo->prepare("UPDATE asignaciones SET estado = 0 WHERE id_asignacion = ? AND estado = 1");
      $stClose->execute([(int)$asig['id_asignacion']]);
      $cerradaAhora = ($stClose->rowCount() > 0);
    }

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
      'mensaje'               => 'Juego creado',
      'id_juego'              => $idJuego,
      'id_asignacion'         => (int)$asig['id_asignacion'],
      'tiempo_minimo_seg'     => $tiempoMin,
      'total_jugado_seg'      => $totalAsignacion,
      'asignacion_cerrada'    => $cerradaAhora,
      'asignacion_estado'     => $cerradaAhora ? 0 : 1,  // estado resultante
    ]);

  } catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
  }
}

/* ==========================
   DELETE /juegos.php?id_juego=...
   o body: { "id_juego": ... }
   ========================== */
function eliminarJuego() {
  global $pdo;

  $id = null;
  if (isset($_GET['id_juego'])) {
    $id = (int)$_GET['id_juego'];
  } else {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    if (isset($d['id_juego'])) $id = (int)$d['id_juego'];
  }
  if (!$id) throw new Exception('id_juego requerido');

  $st = $pdo->prepare("DELETE FROM juegos WHERE id_juego = ?");
  $st->execute([$id]);

  echo json_encode(['mensaje'=>'Juego eliminado']);
}
