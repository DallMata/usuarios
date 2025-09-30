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
      listarAplicaciones();
      break;
    case 'POST':
      crearAplicacion();
      break;
    case 'PUT':
      actualizarAplicacion();
      break;
    case 'DELETE':
      eliminarAplicacion();
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
   - id_aplicacion
   - q (busca en titulo/descripcion)
   - order: titulo|id (asc, default titulo)
*/
function listarAplicaciones() {
  global $pdo;

  $w=[]; $p=[];
  if (!empty($_GET['id_aplicacion'])) { $w[]='id_aplicacion = ?'; $p[]=(int)$_GET['id_aplicacion']; }
  if (!empty($_GET['q'])) { $w[]='(titulo LIKE ? OR descripcion LIKE ?)'; $p[]='%'.$_GET['q'].'%'; $p[]='%'.$_GET['q'].'%'; }

  $order = ' ORDER BY titulo ASC';
  if (!empty($_GET['order']) && $_GET['order']==='id') $order = ' ORDER BY id_aplicacion ASC';

  $sql = "SELECT id_aplicacion, titulo, descripcion FROM aplicaciones";
  if ($w) $sql .= ' WHERE '.implode(' AND ',$w);
  $sql .= $order;

  $st=$pdo->prepare($sql);
  $st->execute($p);
  $rows=$st->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) { http_response_code(404); echo json_encode(['error'=>'Sin resultados']); return; }
  echo json_encode($rows);
}

/* ===== POST =====
   Body:
   { "titulo": "...", "descripcion": "..." }
*/
function crearAplicacion() {
  global $pdo;
  $d = json_decode(file_get_contents('php://input'), true) ?? [];

  foreach (['titulo','descripcion'] as $k)
    if (!isset($d[$k]) || trim($d[$k])==='') throw new Exception("Falta $k");

  $titulo = trim($d['titulo']);
  $descripcion = trim($d['descripcion']);

  if (mb_strlen($titulo) > 50) throw new Exception('titulo: máximo 50 caracteres');
  if (mb_strlen($descripcion) > 100) throw new Exception('descripcion: máximo 100 caracteres');

  // (Opcional) unicidad por título
  $st=$pdo->prepare("SELECT 1 FROM aplicaciones WHERE titulo=?");
  $st->execute([$titulo]);
  if ($st->fetchColumn()) { http_response_code(409); echo json_encode(['error'=>'Ya existe una aplicación con ese título']); return; }

  $st=$pdo->prepare("INSERT INTO aplicaciones (titulo, descripcion) VALUES (?, ?)");
  $st->execute([$titulo, $descripcion]);

  http_response_code(201);
  echo json_encode(['mensaje'=>'Aplicación creada','id_aplicacion'=>$pdo->lastInsertId()]);
}

/* ===== PUT =====
   Query o Body debe incluir id_aplicacion
   Body admite actualizar titulo y/o descripcion
*/
function actualizarAplicacion() {
  global $pdo;
  parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
  $d = json_decode(file_get_contents('php://input'), true) ?? [];

  $id = isset($q['id_aplicacion']) ? (int)$q['id_aplicacion'] : (int)($d['id_aplicacion'] ?? 0);
  if (!$id) throw new Exception('id_aplicacion requerido');

  $st=$pdo->prepare("SELECT * FROM aplicaciones WHERE id_aplicacion=?");
  $st->execute([$id]);
  $cur=$st->fetch(PDO::FETCH_ASSOC);
  if (!$cur) throw new Exception('Aplicación no encontrada');

  $titulo = isset($d['titulo']) ? trim($d['titulo']) : $cur['titulo'];
  $descripcion = isset($d['descripcion']) ? trim($d['descripcion']) : $cur['descripcion'];

  if ($titulo==='') throw new Exception('titulo no puede quedar vacío');
  if (mb_strlen($titulo) > 50) throw new Exception('titulo: máximo 50 caracteres');
  if (mb_strlen($descripcion) > 100) throw new Exception('descripcion: máximo 100 caracteres');

  // (Opcional) unicidad de título si cambió
  if ($titulo !== $cur['titulo']) {
    $chk=$pdo->prepare("SELECT 1 FROM aplicaciones WHERE titulo=? AND id_aplicacion<>?");
    $chk->execute([$titulo,$id]);
    if ($chk->fetchColumn()) { http_response_code(409); echo json_encode(['error'=>'Ya existe otra aplicación con ese título']); return; }
  }

  $up=$pdo->prepare("UPDATE aplicaciones SET titulo=?, descripcion=? WHERE id_aplicacion=?");
  $up->execute([$titulo,$descripcion,$id]);

  echo json_encode(['mensaje'=>'Aplicación actualizada']);
}

/* ===== DELETE =====
   Query o Body con id_aplicacion
   Evita borrar si está referenciada en asignaciones (FK)
*/
function eliminarAplicacion() {
  global $pdo;

  $id = isset($_GET['id_aplicacion']) ? (int)$_GET['id_aplicacion'] : 0;
  if (!$id) { $d=json_decode(file_get_contents('php://input'), true) ?? []; $id=(int)($d['id_aplicacion'] ?? 0); }
  if (!$id) throw new Exception('id_aplicacion requerido');

  // ¿Está en uso?
  $st=$pdo->prepare("SELECT COUNT(*) FROM asignaciones WHERE id_aplicacion=?");
  $st->execute([$id]);
  if ((int)$st->fetchColumn() > 0) {
    http_response_code(409);
    echo json_encode(['error'=>'No se puede eliminar: la aplicación está vinculada a asignaciones']);
    return;
  }

  $del=$pdo->prepare("DELETE FROM aplicaciones WHERE id_aplicacion=?");
  $del->execute([$id]);

  echo json_encode(['mensaje'=>'Aplicación eliminada']);
}
