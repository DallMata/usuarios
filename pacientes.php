<?php
require 'config.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Log de errores a archivo (no HTML en respuesta)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
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
      if (!empty($_GET['id_usuario'])) {
        listarPacientesDeMedico();   // ?id_usuario=ID_MEDICO [&activas=0]
      } else if (!empty($_GET['dni'])) {
        obtenerPacientePorDni();      // ?dni=...
      } else {
        listarPacientes();            // todos con tipo_usuario='paciente'
      }
      break;

    case 'POST':
      if (isset($_GET['create'])) {
        crearPacienteYAsignaciones(); // crea usuario + asignaciones
      } else {
        http_response_code(400);
        echo json_encode(['error'=>'Uso: POST /pacientes.php?create=true']);
      }
      break;

    case 'PUT':
      if (isset($_GET['update'])) {
        actualizarPacienteYAsignaciones(); // actualiza datos + upsert asignaciones
      } else {
        http_response_code(400);
        echo json_encode(['error'=>'Uso: PUT /pacientes.php?update=true']);
      }
      break;

    case 'DELETE':
      eliminarPacientePorDni();       // ?dni=...
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

/* =========================
   Helpers
   ========================= */

function normInicio($s) {
  $s = trim((string)$s);
  if ($s === '') return date('Y-m-d').' 00:00:00';
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s.' 00:00:00';
  return $s; // asume datetime válido
}
function normFin($s) {
  $s = trim((string)$s);
  if ($s === '') return date('Y-m-d', strtotime('+30 days')).' 23:59:59';
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s.' 23:59:59';
  return $s; // asume datetime válido
}

/* =========================
   GETs
   ========================= */

function listarPacientesDeMedico() {
  global $pdo;
  $idMedico = (int)$_GET['id_usuario'];
  // por defecto solo asignaciones vigentes; pasá activas=0 para traer todos
  $solo_activas = !isset($_GET['activas']) || $_GET['activas'] !== '0';
  $whereAct = $solo_activas ? " AND NOW() BETWEEN a.fecha_inicio AND a.fecha_fin " : "";

  $sql = "SELECT DISTINCT
            u.id_usuario      AS id_paciente,
            u.nombre,
            u.apellido,
            u.dni,
            u.email,
            u.id_membresia,
            u.estado,
            u.tipo_usuario
          FROM usuarios u
          JOIN asignaciones a ON a.id_usuario_paciente = u.id_usuario
          WHERE a.id_usuario_medico = ?
            AND u.tipo_usuario = 'paciente'
            AND u.estado = 1
            $whereAct
          ORDER BY u.apellido, u.nombre";

  $st = $pdo->prepare($sql);
  $st->execute([$idMedico]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) { http_response_code(404); echo json_encode(['error'=>'Sin resultados']); return; }
  echo json_encode($rows);
}

function obtenerPacientePorDni() {
  global $pdo;
  $dni = isset($_GET['dni']) ? trim((string)$_GET['dni']) : '';
  if ($dni === '') throw new Exception('dni requerido');

  $sql = "SELECT
            u.id_usuario AS id_paciente,
            u.nombre, u.apellido, u.dni, u.email, u.id_membresia, u.estado, u.tipo_usuario
          FROM usuarios u
          WHERE u.dni = ? AND u.tipo_usuario = 'paciente'
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$dni]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); echo json_encode(['error'=>'Paciente no encontrado']); return; }
  echo json_encode($row);
}

function listarPacientes() {
  global $pdo;
  $sql = "SELECT
            u.id_usuario AS id_paciente,
            u.nombre, u.apellido, u.dni, u.email, u.id_membresia, u.estado, u.tipo_usuario
          FROM usuarios u
          WHERE u.tipo_usuario='paciente'
          ORDER BY u.apellido, u.nombre";
  $st = $pdo->query($sql);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) { http_response_code(404); echo json_encode(['error'=>'Sin resultados']); return; }
  echo json_encode($rows);
}

/* =========================
   POST /pacientes.php?create=true
   Body:
   {
     "nombre":"...", "apellido":"...", "dni":"...", "password":"...",
     "email":"(opcional)", "id_usuario":11,
     "id_aplicaciones":[1,2],
     "tiempo_minimo_seg":600,
     "tiempos_minimos": {"1":900,"2":1500},
     "dificultades": {"1":3,"2":2}
   }
   ========================= */

function crearPacienteYAsignaciones() {
  global $pdo;

  $d = json_decode(file_get_contents('php://input'), true) ?? [];

  foreach (['nombre','apellido','dni','password','id_usuario','id_aplicaciones'] as $k) {
    if (!isset($d[$k]) || $d[$k]==='') throw new Exception("Falta $k");
  }

  $nombre   = trim((string)$d['nombre']);
  $apellido = trim((string)$d['apellido']);
  $dni      = trim((string)$d['dni']);        // STRING
  $password = (string)$d['password'];
  $idMedico = (int)$d['id_usuario'];
  $apps     = $d['id_aplicaciones'];
  if (!is_array($apps) || count($apps)===0) throw new Exception('id_aplicaciones debe ser array no vacío');

  // Email (opcional): generamos uno único si no viene
  $email = !empty($d['email'])
    ? trim((string)$d['email'])
    : ('paciente_'.$dni.'@kineplay.local');

  // Unicidad por dni/email
  $st=$pdo->prepare("SELECT 1 FROM usuarios WHERE dni=?");
  $st->execute([$dni]);
  if ($st->fetchColumn()) { http_response_code(409); echo json_encode(['error'=>'El DNI ya está en uso']); return; }

  $st=$pdo->prepare("SELECT 1 FROM usuarios WHERE email=?");
  $st->execute([$email]);
  if ($st->fetchColumn()) { http_response_code(409); echo json_encode(['error'=>'El email ya está en uso']); return; }

  $pdo->beginTransaction();

  try {
    // Crear usuario-paciente (md5 por compat)
    $sqlU = "INSERT INTO usuarios (nombre,apellido,dni,email,password,id_membresia,estado,tipo_usuario)
             VALUES (?,?,?,?,md5(?),7,1,'paciente')";
    $stU  = $pdo->prepare($sqlU);
    $stU->execute([$nombre,$apellido,$dni,$email,$password]);
    $idPaciente = (int)$pdo->lastInsertId();

    // Tiempos mínimos (seg)
    $global_min = isset($d['tiempo_minimo_seg']) ? (int)$d['tiempo_minimo_seg'] : 600;
    $tiempos_minimos = isset($d['tiempos_minimos']) && is_array($d['tiempos_minimos']) ? $d['tiempos_minimos'] : [];

    // Dificultades por app (1..5)
    $dificultades = isset($d['dificultades']) && is_array($d['dificultades']) ? $d['dificultades'] : [];

    $clamp_secs = function($s) {
      $s = (int)$s;
      if ($s < 0) $s = 0;        // permite 0 = sin mínimo si tu modelo lo usa
      if ($s > 7200) $s = 7200;
      return $s;
    };
    $global_min = $clamp_secs($global_min);

    $clamp_dif = function($x) {
      $x = (int)$x; if ($x < 1) $x = 1; if ($x > 5) $x = 5; return $x;
    };

    // INSERT inicial con ventana [ahora .. +30d]
    $sqlA = "INSERT INTO asignaciones
                (id_aplicacion, id_usuario_medico, id_usuario_paciente, fecha_inicio, fecha_fin, tiempo_minimo_seg, estado, dificultad)
             VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), ?, 1, ?)";
    $stA  = $pdo->prepare($sqlA);

    $creadas = 0;
    foreach ($apps as $appId) {
      $idApp = (int)$appId;
      if ($idApp <= 0) continue;

      $key1 = (string)$idApp;
      $key2 = $idApp;
      $min = $global_min;
      if (array_key_exists($key1, $tiempos_minimos)) $min = $clamp_secs($tiempos_minimos[$key1]);
      else if (array_key_exists($key2, $tiempos_minimos)) $min = $clamp_secs($tiempos_minimos[$key2]);

      $dif = 1;
      if (array_key_exists($key1, $dificultades)) $dif = $clamp_dif($dificultades[$key1]);
      else if (array_key_exists($key2, $dificultades)) $dif = $clamp_dif($dificultades[$key2]);

      $stA->execute([$idApp, $idMedico, $idPaciente, $min, $dif]);
      $creadas++;
    }

    $pdo->commit();
    http_response_code(201);
    echo json_encode([
      'mensaje' => 'Paciente y asignaciones creadas',
      'id_paciente' => $idPaciente,
      'asignaciones_creadas' => $creadas
    ]);
  } catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()]);
  }
}

/* =========================
   PUT /pacientes.php?update=true
   Body:
   {
     "paciente": { "dni":"...", "nombre":"...", "apellido":"..." },
     "asignaciones": [
       {
         "id_asignacion": 123,               // opcional: si falta => UPSERT
         "id_aplicacion": 1,
         "tiempo_minimo_seg": 600,           // 0..7200 (0 = sin mínimo si tu modelo lo usa)
         "fecha_inicio": "YYYY-MM-DD",       // se normaliza a 00:00:00
         "fecha_fin": "YYYY-MM-DD",          // se normaliza a 23:59:59
         "estado": 1,
         "dificultad": 3                     // opcional, 1..5
       }
     ],
     "id_usuario_medico": 11                // recomendado para inserts
   }
   ========================= */

function actualizarPacienteYAsignaciones() {
  global $pdo;

  $raw = file_get_contents('php://input');
  $d   = json_decode($raw, true);
  if (!is_array($d)) { http_response_code(400); echo json_encode(['error'=>'JSON inválido']); return; }

  if (empty($d['paciente']) || empty($d['paciente']['dni'])) {
    http_response_code(400); echo json_encode(['error'=>'Falta paciente.dni']); return;
  }
  $dni = trim((string)$d['paciente']['dni']);

  // Buscar paciente por DNI (solo tipo paciente)
  $st = $pdo->prepare("SELECT id_usuario AS id_paciente FROM usuarios WHERE dni=? AND tipo_usuario='paciente' LIMIT 1");
  $st->execute([$dni]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); echo json_encode(['error'=>'Paciente no encontrado']); return; }
  $idPaciente = (int)$row['id_paciente'];

  $nombre   = isset($d['paciente']['nombre'])   ? trim((string)$d['paciente']['nombre'])   : null;
  $apellido = isset($d['paciente']['apellido']) ? trim((string)$d['paciente']['apellido']) : null;

  // ✅ NUEVO: email opcional
  $email = array_key_exists('email', $d['paciente']) ? trim((string)$d['paciente']['email']) : null;
  if ($email === '') $email = null;

  // ✅ NUEVO: password opcional (texto plano; se hashea en SQL con md5)
  $password = array_key_exists('password', $d['paciente']) ? (string)$d['paciente']['password'] : null;
  if ($password !== null && trim($password) === '') $password = null;

  // Asignaciones actuales
  $st = $pdo->prepare("SELECT id_asignacion, id_usuario_medico FROM asignaciones WHERE id_usuario_paciente=?");
  $st->execute([$idPaciente]);
  $asigDB = $st->fetchAll(PDO::FETCH_ASSOC);

  // Resolver id_usuario_medico por defecto (para inserts)
  $idMedicoDefault = null;
  if ($asigDB && count($asigDB) > 0) $idMedicoDefault = (int)$asigDB[0]['id_usuario_medico'];
  if (isset($d['id_usuario_medico']) && (int)$d['id_usuario_medico'] > 0) $idMedicoDefault = (int)$d['id_usuario_medico'];

  // Index por id_asignacion
  $dbMap = [];
  foreach ($asigDB as $a) { $dbMap[(int)$a['id_asignacion']] = true; }

  $inPayloadIds = [];
  $toUpdate = [];
  $toUpsert = [];

  $resolveAppId = function($a) use ($pdo) {
    if (isset($a['id_aplicacion']) && (int)$a['id_aplicacion'] > 0) return (int)$a['id_aplicacion'];
    if (isset($a['aplicacion'])) {
      $val = trim((string)$a['aplicacion']);
      if ($val === '') return null;
      if (ctype_digit($val)) return (int)$val;
      try {
        $st = $pdo->prepare("SELECT id_aplicacion FROM aplicaciones WHERE titulo = ? LIMIT 1");
        $st->execute([$val]);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;
      } catch (Exception $e) {}
      $map = ['Space Evation'=>1, 'Platform Jump'=>2, 'Plataform Jump'=>2];
      return isset($map[$val]) ? (int)$map[$val] : null;
    }
    return null;
  };

  $asignaciones = isset($d['asignaciones']) && is_array($d['asignaciones']) ? $d['asignaciones'] : [];

  foreach ($asignaciones as $a) {
    $idAsig = isset($a['id_asignacion']) ? (int)$a['id_asignacion'] : 0;
    $idApp  = $resolveAppId($a);

    // tiempo mínimo (null ó número)
    $tmin   = array_key_exists('tiempo_minimo_seg', $a) ? $a['tiempo_minimo_seg'] : null;
    if ($tmin === '' || $tmin === null) $tmin = 0; // usa 0 como “sin mínimo”
    $tmin = (int)$tmin; if ($tmin < 0) $tmin = 0; if ($tmin > 7200) $tmin = 7200;

    $desde  = normInicio($a['fecha_inicio'] ?? '');
    $hasta  = normFin($a['fecha_fin'] ?? '');

    $estado = isset($a['estado']) ? (int)$a['estado'] : 1;
    $dif    = array_key_exists('dificultad', $a) ? (int)$a['dificultad'] : 1;
    if ($dif < 1) $dif = 1; if ($dif > 5) $dif = 5;

    if ($idAsig > 0 && isset($dbMap[$idAsig])) {
      $inPayloadIds[] = $idAsig;
      $toUpdate[] = [
        'id_asignacion' => $idAsig,
        'id_aplicacion' => $idApp,
        'desde'         => $desde,
        'hasta'         => $hasta,
        'tiempo'        => $tmin,
        'estado'        => $estado,
        'dificultad'    => $dif,
      ];
    } else {
      // nuevo -> UPSERT por clave única uq_asignacion
      $toUpsert[] = [
        'id_aplicacion' => $idApp,
        'desde'         => $desde,
        'hasta'         => $hasta,
        'tiempo'        => $tmin,
        'estado'        => $estado,
        'dificultad'    => $dif,
      ];
    }
  }

  // Soft delete para las que no vinieron en el payload
  $toDelete = [];
  foreach ($dbMap as $id => $_) {
    if (!in_array($id, $inPayloadIds, true)) $toDelete[] = $id;
  }

  try {
    $pdo->beginTransaction();

    // ✅ Update datos básicos del paciente (+ email + password opcional)
    if ($nombre !== null || $apellido !== null || $email !== null || $password !== null) {
      $sets = []; $vals = [];

      if ($nombre !== null)   { $sets[] = "nombre=?";   $vals[] = $nombre; }
      if ($apellido !== null) { $sets[] = "apellido=?"; $vals[] = $apellido; }
      if ($email !== null)    { $sets[] = "email=?";    $vals[] = $email; }

      // password: solo si vino (no vacío)
      if ($password !== null) { $sets[] = "password=md5(?)"; $vals[] = $password; }

      if (!empty($sets)) {
        $vals[] = $idPaciente;
        $sqlU = "UPDATE usuarios SET ".implode(',', $sets)." WHERE id_usuario=?";
        $pdo->prepare($sqlU)->execute($vals);
      }
    }

    // Updates de asignaciones existentes (por id_asignacion)
    if (!empty($toUpdate)) {
      $sql = "UPDATE asignaciones
                SET id_aplicacion     = COALESCE(?, id_aplicacion),
                    fecha_inicio      = COALESCE(?, fecha_inicio),
                    fecha_fin         = COALESCE(?, fecha_fin),
                    tiempo_minimo_seg = ?,          -- acepta 0
                    estado            = ?,
                    dificultad        = COALESCE(?, dificultad)
              WHERE id_asignacion = ? AND id_usuario_paciente = ?";
      $st = $pdo->prepare($sql);
      foreach ($toUpdate as $a) {
        $st->execute([
          $a['id_aplicacion'],
          $a['desde'],
          $a['hasta'],
          $a['tiempo'],
          $a['estado'],
          $a['dificultad'],
          $a['id_asignacion'],
          $idPaciente
        ]);
      }
    }

    // UPSERT para nuevas (id_asignacion vacío)
    if (!empty($toUpsert)) {
      if (!$idMedicoDefault) {
        throw new Exception('No se pudo determinar id_usuario_medico para nuevas asignaciones. Enviá id_usuario_medico en el payload o crea al menos una asignación previa.');
      }

      $sql = "INSERT INTO asignaciones
                (id_aplicacion, id_usuario_medico, id_usuario_paciente, fecha_inicio, fecha_fin, tiempo_minimo_seg, estado, dificultad)
              VALUES (?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE
                fecha_fin=VALUES(fecha_fin),
                tiempo_minimo_seg=VALUES(tiempo_minimo_seg),
                estado=VALUES(estado),
                dificultad=VALUES(dificultad)";
      $st = $pdo->prepare($sql);
      foreach ($toUpsert as $a) {
        if (!$a['id_aplicacion']) continue;
        $st->execute([
          $a['id_aplicacion'],
          $idMedicoDefault,
          $idPaciente,
          $a['desde'],
          $a['hasta'],
          $a['tiempo'],
          (int)$a['estado'],
          (int)$a['dificultad']
        ]);
      }
    }

    // Soft-delete de asignaciones ausentes
    if (!empty($toDelete)) {
      $in = implode(',', array_fill(0, count($toDelete), '?'));
      $params = $toDelete;
      $params[] = $idPaciente;
      $pdo->prepare("UPDATE asignaciones SET estado=0 WHERE id_asignacion IN ($in) AND id_usuario_paciente=?")->execute($params);
    }

    $pdo->commit();
    echo json_encode([
      'mensaje'      => 'Paciente y asignaciones actualizados',
      'updates'      => count($toUpdate),
      'upserts'      => count($toUpsert),
      'soft_deleted' => count($toDelete),
      'id_paciente'  => $idPaciente
    ]);
  } catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()]);
  }
}


/* =========================
   DELETE ?dni=...
   Soft-delete (estado=0)
   ========================= */
function eliminarPacientePorDni() {
  global $pdo;
  $dni = isset($_GET['dni']) ? trim((string)$_GET['dni']) : '';
  if ($dni === '') throw new Exception('dni requerido');

  $st = $pdo->prepare("UPDATE usuarios SET estado=0 WHERE dni=? AND tipo_usuario='paciente'");
  $st->execute([$dni]);

  echo json_encode(['mensaje'=>'Paciente desactivado']);
}
