<?php
require 'config.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Debug (desactivar en prod si querés)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Sanity DB
try { $pdo->query("SELECT 1"); } catch (PDOException $e) {
  http_response_code(500); echo json_encode(['error'=>'DB: '.$e->getMessage()]); exit();
}

try {
  switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
      listarMembresias();
      break;
    case 'POST':
      crearMembresia();
      break;
    case 'PUT':
      actualizarMembresia();
      break;
    case 'DELETE':
      eliminarMembresia();
      break;
    default:
      http_response_code(405);
      echo json_encode(['error'=>'Método no permitido']);
  }
} catch (PDOException $e) {
  http_response_code(500); echo json_encode(['error'=>'SQL: '.$e->getMessage()]);
} catch (Exception $e) {
  http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
}

/* ===== GET =====
   Filtros:
   - id_membresia
   - q (busca en titulo/observacion)
   - order: titulo|id (asc, default titulo)
*/
function listarMembresias() {
  global $pdo;

  // 1) Si piden la membresía de un usuario específico:
  if (!empty($_GET['id_usuario'])) {
    $idUsuario = (int)$_GET['id_usuario'];

    // Busca la membresía asignada a ese usuario
    $sql = "SELECT m.id_membresia,
                   m.titulo,
                   m.observacion,
                   m.sesiones_maximas,
                   m.precio
            FROM usuarios u
            INNER JOIN membresias m ON m.id_membresia = u.id_membresia
            WHERE u.id_usuario = ?
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$idUsuario]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      http_response_code(404);
      echo json_encode(['error' => 'El usuario no tiene membresía asignada']);
      return;
    }

    echo json_encode($row);
    return;
  }

  // 2) Comportamiento original (listado general con filtros)
  $w = []; 
  $p = [];
  if (!empty($_GET['id_membresia'])) { $w[]='id_membresia = ?'; $p[]=(int)$_GET['id_membresia']; }
  if (!empty($_GET['q'])) { $w[]='(titulo LIKE ? OR observacion LIKE ?)'; $p[]='%'.$_GET['q'].'%'; $p[]='%'.$_GET['q'].'%'; }

  $order = ' ORDER BY titulo ASC';
  if (!empty($_GET['order']) && $_GET['order']==='id') $order = ' ORDER BY id_membresia ASC';

  $sql = "SELECT id_membresia,
                 titulo,
                 observacion,
                 sesiones_maximas,
                 precio
          FROM membresias";
  if ($w) $sql .= ' WHERE '.implode(' AND ',$w);
  $sql .= $order;

  $st = $pdo->prepare($sql);
  $st->execute($p);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) { 
    http_response_code(404); 
    echo json_encode(['error'=>'Sin resultados']); 
    return; 
  }
  echo json_encode($rows);
}

/* ===== POST =====
   Body:
   { "titulo": "...", "observacion": "...", "sesiones_maximas": 10, "precio": 97 }
*/
function crearMembresia() {
  global $pdo;
  $d = json_decode(file_get_contents('php://input'), true) ?? [];

  foreach (['titulo','observacion','sesiones_maximas','precio'] as $k)
    if (!isset($d[$k]) || $d[$k]==='') throw new Exception("Falta $k");

  $titulo = trim($d['titulo']);
  $observacion = trim($d['observacion']);
  $ses_max = (int)$d['sesiones_maximas'];

  if (!is_numeric($d['precio'])) throw new Exception('precio debe ser numérico');
  $precio = (float)$d['precio'];

  if ($ses_max <= 0) throw new Exception('sesiones_maximas debe ser > 0');
  if ($precio < 0) throw new Exception('precio debe ser >= 0');
  if (mb_strlen($titulo) > 50) throw new Exception('titulo: máximo 50 caracteres');
  if (mb_strlen($observacion) > 100) throw new Exception('observacion: máximo 100 caracteres');

  // (Opcional) unicidad por título
  $st=$pdo->prepare("SELECT 1 FROM membresias WHERE titulo=?");
  $st->execute([$titulo]);
  if ($st->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['error'=>'Ya existe una membresía con ese título']);
    return;
  }

  $st=$pdo->prepare(
    "INSERT INTO membresias (titulo, observacion, sesiones_maximas, precio)
     VALUES (?, ?, ?, ?)"
  );
  $st->execute([$titulo, $observacion, $ses_max, $precio]);

  http_response_code(201);
  echo json_encode(['mensaje'=>'Membresía creada','id_membresia'=>$pdo->lastInsertId()]);
}

/* ===== PUT =====
   Query o Body debe incluir id_membresia
   Body admite actualizar titulo / observacion / sesiones_maximas / precio
*/
function actualizarMembresia() {
  global $pdo;
  parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
  $d = json_decode(file_get_contents('php://input'), true) ?? [];

  $id = isset($q['id_membresia']) ? (int)$q['id_membresia'] : (int)($d['id_membresia'] ?? 0);
  if (!$id) throw new Exception('id_membresia requerido');

  $st=$pdo->prepare("SELECT * FROM membresias WHERE id_membresia=?");
  $st->execute([$id]);
  $cur=$st->fetch(PDO::FETCH_ASSOC);
  if (!$cur) throw new Exception('Membresía no encontrada');

  $titulo      = isset($d['titulo']) ? trim($d['titulo']) : $cur['titulo'];
  $observacion = isset($d['observacion']) ? trim($d['observacion']) : $cur['observacion'];
  $ses_max     = isset($d['sesiones_maximas']) ? (int)$d['sesiones_maximas'] : (int)$cur['sesiones_maximas'];

  if (isset($d['precio'])) {
    if (!is_numeric($d['precio'])) throw new Exception('precio debe ser numérico');
    $precio = (float)$d['precio'];
  } else {
    $precio = (float)$cur['precio'];
  }

  if ($titulo==='') throw new Exception('titulo no puede quedar vacío');
  if ($ses_max <= 0) throw new Exception('sesiones_maximas debe ser > 0');
  if ($precio < 0) throw new Exception('precio debe ser >= 0');
  if (mb_strlen($titulo) > 50) throw new Exception('titulo: máximo 50 caracteres');
  if (mb_strlen($observacion) > 100) throw new Exception('observacion: máximo 100 caracteres');

  // Unicidad de título si cambió
  if ($titulo !== $cur['titulo']) {
    $chk=$pdo->prepare("SELECT 1 FROM membresias WHERE titulo=? AND id_membresia<>?");
    $chk->execute([$titulo,$id]);
    if ($chk->fetchColumn()) {
      http_response_code(409);
      echo json_encode(['error'=>'Ya existe otra membresía con ese título']);
      return;
    }
  }

  $up=$pdo->prepare(
    "UPDATE membresias
     SET titulo=?, observacion=?, sesiones_maximas=?, precio=?
     WHERE id_membresia=?"
  );
  $up->execute([$titulo,$observacion,$ses_max,$precio,$id]);

  echo json_encode(['mensaje'=>'Membresía actualizada']);
}

/* ===== DELETE =====
   Evita borrar si hay usuarios referenciando esa membresía (FK)
*/
function eliminarMembresia() {
  global $pdo;

  $id = isset($_GET['id_membresia']) ? (int)$_GET['id_membresia'] : 0;
  if (!$id) {
    $d=json_decode(file_get_contents('php://input'), true) ?? [];
    $id=(int)($d['id_membresia'] ?? 0);
  }
  if (!$id) throw new Exception('id_membresia requerido');

  // ¿Está en uso por usuarios?
  $st=$pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_membresia=?");
  $st->execute([$id]);
  if ((int)$st->fetchColumn() > 0) {
    http_response_code(409);
    echo json_encode(['error'=>'No se puede eliminar: hay usuarios usando esta membresía']);
    return;
  }

  $del=$pdo->prepare("DELETE FROM membresias WHERE id_membresia=?");
  $del->execute([$id]);

  echo json_encode(['mensaje'=>'Membresía eliminada']);
}
