<?php
require 'config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

/* ===== Debug seguro (no imprimir HTML de errores en producción) ===== */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

/* ===== Sanity DB ===== */
try { $pdo->query("SELECT 1"); }
catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['error' => 'DB: '.$e->getMessage()]);
  exit();
}

/* ===== Router ===== */
try {
  switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
      listarUsuarios();
      break;

    case 'POST':
      if (isset($_GET['login']))        loginUsuario();
      else if (isset($_GET['register'])) crearUsuario();
      else                                crearUsuario();
      break;

    case 'PUT':
      actualizarUsuario();
      break;

    case 'DELETE':
      eliminarUsuario();
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


/* =====================================================================
   GET /usuarios.php
   Filtros:
     - id_usuario
     - dni
     - email
     - tipo (mapea a columna tipo_usuario)   ej: tipo=paciente | medico | admin
     - estado (0/1)
     - q (busca por nombre/apellido)
     - pacientes_de_medico=<id_medico>  [activas=1 para filtrar por rango de fechas]
   ===================================================================== */
function listarUsuarios() {
  global $pdo;

  $w = [];
  $p = [];

  if (!empty($_GET['id_usuario'])) { $w[] = 'u.id_usuario = ?';     $p[] = (int)$_GET['id_usuario']; }
  if (!empty($_GET['dni']))        { $w[] = 'u.dni = ?';            $p[] = (int)$_GET['dni']; }
  if (!empty($_GET['email']))      { $w[] = 'u.email = ?';          $p[] = $_GET['email']; }
  if (!empty($_GET['tipo']))       { $w[] = 'u.tipo_usuario = ?';   $p[] = $_GET['tipo']; }
  if (isset($_GET['estado']) && $_GET['estado'] !== '') { $w[] = 'u.estado = ?'; $p[] = (int)$_GET['estado']; }
  if (!empty($_GET['q'])) {
    $w[] = '(u.nombre LIKE ? OR u.apellido LIKE ?)';
    $q = '%'.$_GET['q'].'%';
    $p[] = $q; $p[] = $q;
  }

  // Pacientes de un médico (vía asignaciones)
  if (!empty($_GET['pacientes_de_medico'])) {
    $idMed = (int)$_GET['pacientes_de_medico'];
    $solo_activas = !empty($_GET['activas']);
    $act = $solo_activas ? " AND NOW() BETWEEN a.fecha_inicio AND a.fecha_fin" : "";
    $w2 = $w ? (' AND '.implode(' AND ', $w)) : '';

    $sql = "SELECT DISTINCT
              u.id_usuario,
              u.nombre,
              u.apellido,
              u.dni,
              u.email,
              u.id_membresia,
              u.estado,
              u.tipo_usuario AS tipo_usuario,
              u.tipo_usuario AS tipo   -- alias de compatibilidad
            FROM usuarios u
            JOIN asignaciones a ON a.id_usuario_paciente = u.id_usuario
            WHERE a.id_usuario_medico = ? $act $w2
            ORDER BY u.apellido, u.nombre";

    // meter primero el id del médico
    array_unshift($p, $idMed);
  } else {
    // Listado general
    $sql = "SELECT
              u.id_usuario,
              u.nombre,
              u.apellido,
              u.dni,
              u.email,
              u.id_membresia,
              u.estado,
              u.tipo_usuario AS tipo_usuario,
              u.tipo_usuario AS tipo   -- alias
            FROM usuarios u";
    if ($w) $sql .= ' WHERE '.implode(' AND ', $w);
    $sql .= ' ORDER BY u.apellido, u.nombre';
  }

  $st = $pdo->prepare($sql);
  $st->execute($p);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    http_response_code(404);
    echo json_encode(['error' => 'Sin resultados']);
    return;
  }
  echo json_encode($rows);
}


/* =====================================================================
   POST /usuarios.php?register=true  (o sin query también crea)
   Body JSON:
   {
     "nombre": "...",
     "apellido": "...",
     "dni": 123,
     "email": "...",
     "password": "...",
     "tipo": "paciente" | "medico" | "admin" (opcional, default "paciente"),
     "id_membresia": 7 (opcional, default 7),
     "estado": 1 (opcional, default 1)
   }
   ===================================================================== */
function crearUsuario() {
  global $pdo;
  $d = json_decode(file_get_contents('php://input'), true) ?? [];

  foreach (['nombre','apellido','dni','email','password'] as $k) {
    if (!isset($d[$k]) || $d[$k] === '') throw new Exception("Falta $k");
  }

  $tipo         = isset($d['tipo']) ? $d['tipo'] : 'paciente';
  $id_membresia = isset($d['id_membresia']) ? (int)$d['id_membresia'] : 7;
  $estado       = isset($d['estado']) ? (int)$d['estado'] : 1;

  // unicidad
  $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = ?");
  $st->execute([$d['email']]);
  if ($st->fetchColumn()) { http_response_code(409); echo json_encode(['error'=>'El email ya está en uso']); return; }

  $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE dni = ?");
  $st->execute([(int)$d['dni']]);
  if ($st->fetchColumn()) { http_response_code(409); echo json_encode(['error'=>'El DNI ya está en uso']); return; }

  // Inserta con md5 por compat; luego login lo migra a bcrypt
  $sql = "INSERT INTO usuarios
            (nombre, apellido, dni, email, password, id_membresia, estado, tipo_usuario)
          VALUES
            (?,?,?,?,md5(?),?,?,?)";
  $st = $pdo->prepare($sql);
  $st->execute([
    $d['nombre'], $d['apellido'], (int)$d['dni'], $d['email'],
    $d['password'], $id_membresia, $estado, $tipo
  ]);

  http_response_code(201);
  echo json_encode(['mensaje' => 'Usuario creado', 'id_usuario' => $pdo->lastInsertId()]);
}


/* =====================================================================
   POST /usuarios.php?login=true
   Body JSON (o form-data):
   - login/email/dni + password/clave
   Solo PACIENTES activos (estado = 1, tipo_usuario = 'paciente')
   Migra password a bcrypt si estaba en md5 o texto plano.
   ===================================================================== */
function loginUsuario() {
  global $pdo;

  $raw  = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (json_last_error() !== JSON_ERROR_NONE || !$data) {
    $data = $_POST ?? [];
  }

  $login = trim((string)($data['email'] ?? $data['dni'] ?? $data['login'] ?? ''));
  $pass  = trim((string)($data['password'] ?? $data['clave'] ?? ''));

  if ($login === '' || $pass === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan credenciales']);
    return;
  }

  // Buscar pacientes activos por email o dni
  $rows = [];
  if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
    $stmt = $pdo->prepare(
      "SELECT * FROM usuarios
       WHERE estado = 1 AND tipo_usuario = 'paciente' AND email = ?
       LIMIT 1"
    );
    $stmt->execute([$login]);
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $rows = [$r];
  } else {
    $dni = preg_replace('/\D+/', '', $login);
    $stmt = $pdo->prepare(
      "SELECT * FROM usuarios
       WHERE estado = 1 AND tipo_usuario = 'paciente' AND dni = ?
       ORDER BY id_usuario ASC"
    );
    $stmt->execute([$dni]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  if (!$rows) {
    http_response_code(404);
    echo json_encode(['error' => 'Usuario no encontrado']);
    return;
  }

  // Validar password; soporta bcrypt/argon2, md5, texto plano
  $usuarioOk = null;
  foreach ($rows as $u) {
    $hash = (string)($u['password'] ?? '');
    $ok = false;

    if (preg_match('/^\$2y\$/', $hash) || preg_match('/^\$argon2/', $hash)) {
      $ok = password_verify($pass, $hash);
    } elseif (preg_match('/^[a-f0-9]{32}$/i', $hash)) {
      $ok = (md5($pass) === strtolower($hash));
    } else {
      $ok = hash_equals($hash, $pass);
    }

    if ($ok) { $usuarioOk = $u; break; }
  }

  if (!$usuarioOk) {
    http_response_code(401);
    echo json_encode(['error' => 'Contraseña incorrecta']);
    return;
  }

  // Migrar a bcrypt si no lo está
  $stored = (string)$usuarioOk['password'];
  if (!preg_match('/^\$2y\$/', $stored) && !preg_match('/^\$argon2/', $stored)) {
    $nuevo = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10]);
    $upd = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?");
    $upd->execute([$nuevo, $usuarioOk['id_usuario']]);
  }

  echo json_encode([
    'mensaje' => 'Login exitoso',
    'usuario' => [
      'id_usuario'   => (int)$usuarioOk['id_usuario'],
      'nombre'       => $usuarioOk['nombre'],
      'apellido'     => $usuarioOk['apellido'],
      'email'        => $usuarioOk['email'],
      'dni'          => (int)$usuarioOk['dni'],
      'id_membresia' => (int)$usuarioOk['id_membresia'],
      'estado'       => (int)$usuarioOk['estado'],
      'tipo_usuario' => $usuarioOk['tipo_usuario'],
      'tipo'         => $usuarioOk['tipo_usuario'], // alias de compatibilidad
    ]
  ]);
}


/* =====================================================================
   PUT /usuarios.php?id_usuario=...
   Body JSON: campos a actualizar (password opcional)
   ===================================================================== */
function actualizarUsuario() {
  global $pdo;

  parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
  $d = json_decode(file_get_contents('php://input'), true) ?? [];

  $id = isset($q['id_usuario']) ? (int)$q['id_usuario'] : (int)($d['id_usuario'] ?? 0);
  if (!$id) throw new Exception('id_usuario requerido');

  $st = $pdo->prepare("SELECT * FROM usuarios WHERE id_usuario = ?");
  $st->execute([$id]);
  $cur = $st->fetch(PDO::FETCH_ASSOC);
  if (!$cur) throw new Exception('Usuario no encontrado');

  $nombre      = array_key_exists('nombre', $d)       ? $d['nombre']      : $cur['nombre'];
  $apellido    = array_key_exists('apellido', $d)     ? $d['apellido']    : $cur['apellido'];
  $dni         = array_key_exists('dni', $d)          ? (int)$d['dni']    : (int)$cur['dni'];
  $email       = array_key_exists('email', $d)        ? $d['email']       : $cur['email'];
  $id_mem      = array_key_exists('id_membresia', $d) ? (int)$d['id_membresia'] : (int)$cur['id_membresia'];
  $estado      = array_key_exists('estado', $d)       ? (int)$d['estado'] : (int)$cur['estado'];
  $tipo        = array_key_exists('tipo', $d)         ? $d['tipo']        : $cur['tipo_usuario']; // aceptar "tipo" en body
  $passwordRaw = array_key_exists('password', $d)     ? $d['password']    : null;

  if ($passwordRaw !== null && $passwordRaw !== '') {
    $sql = "UPDATE usuarios
            SET nombre=?, apellido=?, dni=?, email=?, password=md5(?),
                id_membresia=?, estado=?, tipo_usuario=?
            WHERE id_usuario=?";
    $params = [$nombre,$apellido,$dni,$email,$passwordRaw,$id_mem,$estado,$tipo,$id];
  } else {
    $sql = "UPDATE usuarios
            SET nombre=?, apellido=?, dni=?, email=?,
                id_membresia=?, estado=?, tipo_usuario=?
            WHERE id_usuario=?";
    $params = [$nombre,$apellido,$dni,$email,$id_mem,$estado,$tipo,$id];
  }

  $st2 = $pdo->prepare($sql);
  $st2->execute($params);
  echo json_encode(['mensaje' => 'Usuario actualizado']);
}


/* =====================================================================
   DELETE /usuarios.php?id_usuario=...
   (soft delete: estado = 0)
   ===================================================================== */
function eliminarUsuario() {
  global $pdo;

  $id = isset($_GET['id_usuario']) ? (int)$_GET['id_usuario'] : 0;
  if (!$id) {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id_usuario'] ?? 0);
  }
  if (!$id) throw new Exception('id_usuario requerido');

  $st = $pdo->prepare("UPDATE usuarios SET estado = 0 WHERE id_usuario = ?");
  $st->execute([$id]);

  echo json_encode(['mensaje' => 'Usuario desactivado']);
}
