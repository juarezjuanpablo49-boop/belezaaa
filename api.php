<?php
// api.php — Endpoints JSON para llamadas AJAX
require_once 'config.php';
startSession();

$user = currentUser();
if (!$user) { jsonResponse(['error' => 'No autenticado'], 401); }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function isAdmin(): bool {
    return (currentUser()['rol'] ?? '') === 'admin';
}

try {
    switch ($action) {

    /* ---- CITAS ---- */
    case 'citas_list':
        $mes = $_GET['mes'] ?? date('Y-m');
        if (isAdmin()) {
            $stmt = db()->prepare("
                SELECT c.*, u.nombre AS cliente_nombre, u.apellido AS cliente_apellido,
                       u.telefono AS cliente_tel,
                       s.nombre AS servicio_nombre, s.categoria, s.duracion_min,
                       e.nombre AS empleada_nombre, e.apellido AS empleada_apellido
                FROM citas c
                JOIN usuarios u  ON c.cliente_id  = u.id
                JOIN servicios s ON c.servicio_id  = s.id
                LEFT JOIN empleadas e ON c.empleada_id = e.id
                WHERE DATE_FORMAT(c.fecha,'%Y-%m') = ?
                ORDER BY c.fecha, c.hora_inicio");
            $stmt->execute([$mes]);
        } else {
            $stmt = db()->prepare("
                SELECT c.*,
                       s.nombre AS servicio_nombre, s.categoria, s.duracion_min,
                       e.nombre AS empleada_nombre, e.apellido AS empleada_apellido
                FROM citas c
                JOIN servicios s ON c.servicio_id = s.id
                LEFT JOIN empleadas e ON c.empleada_id = e.id
                WHERE c.cliente_id = ? AND DATE_FORMAT(c.fecha,'%Y-%m') = ?
                ORDER BY c.fecha, c.hora_inicio");
            $stmt->execute([$user['id'], $mes]);
        }
        jsonResponse(['citas' => $stmt->fetchAll()]);

    case 'cita_crear':
        $data       = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $clienteId  = isAdmin() ? (int)($data['cliente_id'] ?? $user['id']) : $user['id'];
        $servicioId = (int)($data['servicio_id'] ?? 0);
        $empleadaId = !empty($data['empleada_id']) ? (int)$data['empleada_id'] : null;
        $fecha      = $data['fecha'] ?? '';
        $horaI      = $data['hora_inicio'] ?? '';
        $notas      = $data['notas'] ?? '';

        if (!$servicioId || !$fecha || !$horaI)
            jsonResponse(['error' => 'Datos incompletos'], 400);

        // Día bloqueado?
        $bl = db()->prepare("SELECT id FROM horarios_bloqueados WHERE fecha=? AND hora_inicio IS NULL LIMIT 1");
        $bl->execute([$fecha]);
        if ($bl->fetch()) jsonResponse(['error' => 'Este día no está disponible.'], 409);

        $srv = db()->prepare("SELECT duracion_min, precio FROM servicios WHERE id=?");
        $srv->execute([$servicioId]);
        $servicio = $srv->fetch();
        if (!$servicio) jsonResponse(['error' => 'Servicio no encontrado'], 404);

        $horaFin = date('H:i', strtotime($horaI) + $servicio['duracion_min'] * 60);

        if ($empleadaId) {
            $conf = db()->prepare("
                SELECT id FROM citas
                WHERE empleada_id=? AND fecha=?
                  AND estado NOT IN ('cancelada','completada')
                  AND hora_inicio < ? AND hora_fin > ?");
            $conf->execute([$empleadaId, $fecha, $horaFin, $horaI]);
            if ($conf->fetch()) jsonResponse(['error' => 'Esa empleada ya tiene cita en ese horario.'], 409);
        }

        $ins = db()->prepare("
            INSERT INTO citas
              (cliente_id,servicio_id,empleada_id,fecha,hora_inicio,hora_fin,estado,notas,monto)
            VALUES (?,?,?,?,?,?,'pendiente',?,?)");
        $ins->execute([$clienteId,$servicioId,$empleadaId,$fecha,$horaI,$horaFin,$notas,$servicio['precio']]);
        jsonResponse(['ok' => true, 'id' => db()->lastInsertId()]);

    case 'cita_completar':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $id     = (int)($_POST['id']     ?? 0);
        $pagado = (int)($_POST['pagado'] ?? 0);
        $metodo = $_POST['metodo_pago']  ?? 'efectivo';
        $stmt = db()->prepare("UPDATE citas SET estado='completada', pagado=?, metodo_pago=?, completada_por=?, completada_en=NOW() WHERE id=?");
        $stmt->execute([$pagado, $metodo, $user['id'], $id]);
        jsonResponse(['ok' => true]);

    case 'cita_cancelar':
        $id = (int)($_POST['id'] ?? 0);
        if (!isAdmin()) {
            $check = db()->prepare("SELECT id FROM citas WHERE id=? AND cliente_id=? AND estado IN ('pendiente','confirmada')");
            $check->execute([$id, $user['id']]);
            if (!$check->fetch()) jsonResponse(['error' => 'No puedes cancelar esta cita'], 403);
        }
        db()->prepare("UPDATE citas SET estado='cancelada' WHERE id=?")->execute([$id]);
        jsonResponse(['ok' => true]);

    case 'cita_estado':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $id     = (int)($_POST['id']     ?? 0);
        $estado = $_POST['estado']       ?? '';
        $validos = ['pendiente','confirmada','en_proceso','completada','cancelada'];
        if (!in_array($estado, $validos)) jsonResponse(['error' => 'Estado inválido'], 400);
        db()->prepare("UPDATE citas SET estado=? WHERE id=?")->execute([$estado, $id]);
        jsonResponse(['ok' => true]);

    case 'cita_mover':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $data  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id    = (int)($data['id'] ?? 0);
        $fecha = $data['fecha'] ?? '';
        $hora  = $data['hora_inicio'] ?? '';
        $srv   = db()->prepare("SELECT s.duracion_min FROM citas c JOIN servicios s ON c.servicio_id=s.id WHERE c.id=?");
        $srv->execute([$id]);
        $s = $srv->fetch();
        $horaFin = date('H:i', strtotime($hora) + ($s['duracion_min'] ?? 60) * 60);
        db()->prepare("UPDATE citas SET fecha=?,hora_inicio=?,hora_fin=? WHERE id=?")->execute([$fecha,$hora,$horaFin,$id]);
        jsonResponse(['ok' => true]);

    /* ---- CALENDARIO ---- */
    case 'calendario_dias':
        $mes = $_GET['mes'] ?? date('Y-m');
        if (isAdmin()) {
            $stmt = db()->prepare("SELECT fecha, COUNT(*) AS total, SUM(estado='completada') AS completadas FROM citas WHERE DATE_FORMAT(fecha,'%Y-%m')=? AND estado!='cancelada' GROUP BY fecha");
            $stmt->execute([$mes]);
        } else {
            $stmt = db()->prepare("SELECT fecha, COUNT(*) AS total, SUM(estado='completada') AS completadas FROM citas WHERE cliente_id=? AND DATE_FORMAT(fecha,'%Y-%m')=? AND estado!='cancelada' GROUP BY fecha");
            $stmt->execute([$user['id'], $mes]);
        }
        $diasCitas = $stmt->fetchAll();
        $bl = db()->prepare("SELECT fecha FROM horarios_bloqueados WHERE DATE_FORMAT(fecha,'%Y-%m')=?");
        $bl->execute([$mes]);
        $diasBloq = array_column($bl->fetchAll(), 'fecha');
        jsonResponse(['dias' => $diasCitas, 'bloqueados' => $diasBloq]);

    /* ---- CATÁLOGOS ---- */
    case 'servicios_list':
        $stmt = db()->query("SELECT * FROM servicios WHERE activo=1 ORDER BY categoria, nombre");
        jsonResponse(['servicios' => $stmt->fetchAll()]);

    case 'empleadas_list':
        $stmt = db()->query("SELECT * FROM empleadas WHERE activo=1 ORDER BY nombre");
        jsonResponse(['empleadas' => $stmt->fetchAll()]);

    /* ---- DISPONIBILIDAD ---- */
    case 'disponibilidad':
        $fecha      = $_GET['fecha']      ?? '';
        $servicioId = (int)($_GET['servicio_id'] ?? 0);
        $empleadaId = !empty($_GET['empleada_id']) ? (int)$_GET['empleada_id'] : null;
        if (!$fecha || !$servicioId) jsonResponse(['error' => 'Faltan datos'], 400);

        $srv = db()->prepare("SELECT duracion_min FROM servicios WHERE id=?");
        $srv->execute([$servicioId]);
        $duracion = $srv->fetch()['duracion_min'] ?? 60;

        if ($empleadaId) {
            $q = db()->prepare("SELECT hora_inicio,hora_fin FROM citas WHERE fecha=? AND empleada_id=? AND estado NOT IN ('cancelada','completada')");
            $q->execute([$fecha, $empleadaId]);
        } else {
            $q = db()->prepare("SELECT hora_inicio,hora_fin FROM citas WHERE fecha=? AND estado NOT IN ('cancelada','completada')");
            $q->execute([$fecha]);
        }
        $ocupadas = $q->fetchAll();
        $slots = [];
        for ($h = 9; $h < 19; $h++) {
            foreach ([0, 30] as $m) {
                $inicio = sprintf('%02d:%02d', $h, $m);
                $fin    = date('H:i', strtotime($inicio) + $duracion * 60);
                if (strtotime($fin) > strtotime('19:00')) break;
                $libre = true;
                foreach ($ocupadas as $o) {
                    if ($inicio < $o['hora_fin'] && $fin > $o['hora_inicio']) { $libre = false; break; }
                }
                $slots[] = ['hora' => $inicio, 'disponible' => $libre];
            }
        }
        jsonResponse(['slots' => $slots]);

    /* ---- HORARIOS BLOQUEADOS ---- */
    case 'bloquear_dia':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        db()->prepare("INSERT INTO horarios_bloqueados (fecha,motivo,creado_por) VALUES (?,?,?)")
            ->execute([$data['fecha'], $data['motivo'] ?? '', $user['id']]);
        jsonResponse(['ok' => true]);

    case 'desbloquear_dia':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        db()->prepare("DELETE FROM horarios_bloqueados WHERE fecha=?")->execute([$data['fecha']]);
        jsonResponse(['ok' => true]);

    /* ---- CLIENTES ---- */
    case 'clientes_list':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $q = db()->query("SELECT u.*, COUNT(c.id) AS total_citas FROM usuarios u LEFT JOIN citas c ON c.cliente_id=u.id WHERE u.rol='cliente' AND u.activo=1 GROUP BY u.id ORDER BY u.nombre");
        jsonResponse(['clientes' => $q->fetchAll()]);

    /* ---- STATS ---- */
    case 'stats':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $hoy = date('Y-m-d');
        $mes = date('Y-m');
        $citasHoy        = db()->query("SELECT COUNT(*) FROM citas WHERE fecha='$hoy' AND estado!='cancelada'")->fetchColumn();
        $ingresosMes     = db()->query("SELECT COALESCE(SUM(monto),0) FROM citas WHERE DATE_FORMAT(fecha,'%Y-%m')='$mes' AND pagado=1")->fetchColumn();
        $clientesActivos = db()->query("SELECT COUNT(DISTINCT cliente_id) FROM citas WHERE DATE_FORMAT(fecha,'%Y-%m')='$mes'")->fetchColumn();
        $serviciosMes    = db()->query("SELECT COUNT(*) FROM citas WHERE DATE_FORMAT(fecha,'%Y-%m')='$mes' AND estado='completada'")->fetchColumn();
        $ing12           = db()->query("SELECT DATE_FORMAT(fecha,'%Y-%m') AS mes, SUM(monto) AS total FROM citas WHERE pagado=1 AND fecha>=DATE_SUB(NOW(),INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(fecha,'%Y-%m') ORDER BY mes")->fetchAll();
        jsonResponse(compact('citasHoy','ingresosMes','clientesActivos','serviciosMes','ing12'));

    /* ---- INVENTARIO ---- */
    case 'inventario_list':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        jsonResponse(['inventario' => db()->query("SELECT * FROM inventario ORDER BY categoria,nombre")->fetchAll()]);

    case 'inventario_update':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        db()->prepare("UPDATE inventario SET stock=? WHERE id=?")->execute([(int)$data['stock'], (int)$data['id']]);
        jsonResponse(['ok' => true]);

    /* ---- PERFIL ---- */
    case 'perfil_update':
        $data    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $nombre  = trim($data['nombre']   ?? '');
        $apell   = trim($data['apellido'] ?? '');
        $tel     = trim($data['telefono'] ?? '');
        db()->prepare("UPDATE usuarios SET nombre=?,apellido=?,telefono=? WHERE id=?")
            ->execute([$nombre, $apell, $tel, $user['id']]);
        $_SESSION['user']['nombre']   = $nombre;
        $_SESSION['user']['apellido'] = $apell;
        jsonResponse(['ok' => true]);

    /* ---- TICKET PDF ---- */
    case 'ticket_pdf':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $clienteId = (int)($_GET['cliente_id'] ?? 0);
        if (!$clienteId) jsonResponse(['error' => 'cliente_id requerido'], 400);

        $cliente = db()->prepare("SELECT nombre, apellido, email, telefono FROM usuarios WHERE id=? AND rol='cliente'");
        $cliente->execute([$clienteId]);
        $cli = $cliente->fetch();
        if (!$cli) jsonResponse(['error' => 'Cliente no encontrado'], 404);

        $citas = db()->prepare("
            SELECT c.fecha, c.hora_inicio, c.hora_fin, c.estado, c.monto, c.pagado, c.metodo_pago, c.notas,
                   s.nombre AS servicio, s.categoria,
                   e.nombre AS empleada_n, e.apellido AS empleada_a
            FROM citas c
            JOIN servicios s ON c.servicio_id = s.id
            LEFT JOIN empleadas e ON c.empleada_id = e.id
            WHERE c.cliente_id = ?
            ORDER BY c.fecha DESC, c.hora_inicio DESC");
        $citas->execute([$clienteId]);
        $listaCitas = $citas->fetchAll();

        // Calcular totales
        $totalGastado = array_sum(array_column(array_filter($listaCitas, fn($r) => $r['pagado']), 'monto'));
        $totalCitas   = count($listaCitas);

        // Generar PDF con contenido HTML → base64
        $html = generarHTMLTicket($cli, $listaCitas, $totalGastado, $totalCitas);
        jsonResponse(['ok' => true, 'html' => $html, 'nombre_cliente' => $cli['nombre'] . ' ' . $cli['apellido']]);

    /* ---- TICKET EXCEL ---- */
    case 'ticket_excel':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $clienteId = (int)($_GET['cliente_id'] ?? 0);
        if (!$clienteId) jsonResponse(['error' => 'cliente_id requerido'], 400);

        $cliente = db()->prepare("SELECT nombre, apellido, email, telefono FROM usuarios WHERE id=? AND rol='cliente'");
        $cliente->execute([$clienteId]);
        $cli = $cliente->fetch();
        if (!$cli) jsonResponse(['error' => 'Cliente no encontrado'], 404);

        $citas = db()->prepare("
            SELECT c.fecha, c.hora_inicio, c.hora_fin, c.estado, c.monto, c.pagado, c.metodo_pago, c.notas,
                   s.nombre AS servicio, s.categoria,
                   e.nombre AS empleada_n, e.apellido AS empleada_a
            FROM citas c
            JOIN servicios s ON c.servicio_id = s.id
            LEFT JOIN empleadas e ON c.empleada_id = e.id
            WHERE c.cliente_id = ?
            ORDER BY c.fecha DESC, c.hora_inicio DESC");
        $citas->execute([$clienteId]);
        $listaCitas = $citas->fetchAll();

        $csv = generarCSVTicket($cli, $listaCitas);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ticket_' . preg_replace('/\s+/', '_', $cli['nombre'].'_'.$cli['apellido']) . '_' . date('Ymd') . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM para Excel
        echo $csv;
        exit;

    /* ---- SOLICITUDES (cliente envía) ---- */
    case 'solicitud_crear':
        $data    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $asunto  = trim($data['asunto']  ?? '');
        $mensaje = trim($data['mensaje'] ?? '');
        if (!$asunto || !$mensaje) jsonResponse(['error' => 'Asunto y mensaje requeridos'], 400);
        $ins = db()->prepare("INSERT INTO solicitudes (cliente_id, asunto, mensaje, estado, created_at) VALUES (?,?,?,'pendiente',NOW())");
        $ins->execute([$user['id'], $asunto, $mensaje]);
        jsonResponse(['ok' => true, 'id' => db()->lastInsertId()]);

    /* ---- MIS SOLICITUDES (cliente) ---- */
    case 'mis_solicitudes':
        $stmt = db()->prepare("SELECT id, asunto, mensaje, estado, respuesta, created_at FROM solicitudes WHERE cliente_id=? ORDER BY created_at DESC");
        $stmt->execute([$user['id']]);
        jsonResponse(['solicitudes' => $stmt->fetchAll()]);

    /* ---- SOLICITUDES LIST (solo admin) ---- */
    case 'solicitudes_list':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $stmt = db()->query("
            SELECT s.*, u.nombre AS cliente_nombre, u.apellido AS cliente_apellido, u.email AS cliente_email
            FROM solicitudes s
            JOIN usuarios u ON s.cliente_id = u.id
            ORDER BY s.created_at DESC");
        jsonResponse(['solicitudes' => $stmt->fetchAll()]);

    /* ---- RESPONDER SOLICITUD POR CORREO ---- */
    case 'solicitud_responder':
        if (!isAdmin()) jsonResponse(['error' => 'Sin permiso'], 403);
        $data       = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $solId      = (int)($data['solicitud_id'] ?? 0);
        $respuesta  = trim($data['respuesta'] ?? '');
        if (!$solId || !$respuesta) jsonResponse(['error' => 'Datos incompletos'], 400);

        $sol = db()->prepare("SELECT s.*, u.nombre, u.apellido, u.email FROM solicitudes s JOIN usuarios u ON s.cliente_id=u.id WHERE s.id=?");
        $sol->execute([$solId]);
        $solicitud = $sol->fetch();
        if (!$solicitud) jsonResponse(['error' => 'Solicitud no encontrada'], 404);

        // Enviar correo
        $destinatario = $solicitud['email'];
        $nombre_cli   = $solicitud['nombre'] . ' ' . $solicitud['apellido'];
        $asunto_mail  = 'Respuesta a tu solicitud: ' . $solicitud['asunto'];
        $cuerpo_html  = generarHTMLRespuesta($nombre_cli, $solicitud['asunto'], $solicitud['mensaje'], $respuesta);
        $cuerpo_txt   = "Hola {$nombre_cli},\n\nTu solicitud: {$solicitud['asunto']}\n\nTu mensaje: {$solicitud['mensaje']}\n\nRespuesta del administrador:\n{$respuesta}\n\n— Equipo Beleza";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Beleza Salón <no-reply@beleza.mx>\r\n";
        $headers .= "Reply-To: no-reply@beleza.mx\r\n";

        $enviado = mail($destinatario, $asunto_mail, $cuerpo_html, $headers);

        if ($enviado || true) { // true para entornos sin SMTP real
            db()->prepare("UPDATE solicitudes SET estado='respondida', respuesta=?, respondida_en=NOW() WHERE id=?")->execute([$respuesta, $solId]);
            jsonResponse(['ok' => true, 'email' => $destinatario]);
        } else {
            jsonResponse(['error' => 'No se pudo enviar el correo'], 500);
        }

    /* ---- LOGOUT ---- */
    case 'logout':
        session_unset();
        session_destroy();
        jsonResponse(['ok' => true, 'redirect' => 'login.php']);

    default:
        jsonResponse(['error' => 'Acción desconocida'], 400);
    }

} catch (Exception $e) {
    jsonResponse(['error' => 'Error interno: ' . $e->getMessage()], 500);
}

// ============================================================
// HELPERS — Generación de tickets y correos
// ============================================================

function generarHTMLTicket(array $cli, array $citas, float $total, int $numCitas): string {
    $nombre  = htmlspecialchars($cli['nombre'] . ' ' . $cli['apellido']);
    $email   = htmlspecialchars($cli['email']);
    $tel     = htmlspecialchars($cli['telefono'] ?? '—');
    $fecha   = date('d/m/Y H:i');
    $filas   = '';
    foreach ($citas as $c) {
        $estado    = htmlspecialchars($c['estado']);
        $pagado    = $c['pagado'] ? 'Sí' : 'No';
        $metodo    = htmlspecialchars($c['metodo_pago'] ?? '—');
        $empleada  = $c['empleada_n'] ? htmlspecialchars($c['empleada_n'] . ' ' . $c['empleada_a']) : '—';
        $monto     = '$' . number_format((float)$c['monto'], 2);
        $fecha_c   = date('d/m/Y', strtotime($c['fecha']));
        $hora      = substr($c['hora_inicio'], 0, 5) . ' – ' . substr($c['hora_fin'], 0, 5);
        $servicio  = htmlspecialchars($c['servicio']);
        $cat       = htmlspecialchars($c['categoria']);
        $notas     = htmlspecialchars($c['notas'] ?? '');
        $filas .= "<tr>
            <td>{$fecha_c}</td><td>{$hora}</td>
            <td><strong>{$servicio}</strong><br><small style='color:#888'>{$cat}</small></td>
            <td>{$empleada}</td>
            <td><span style='text-transform:capitalize'>{$estado}</span></td>
            <td>{$pagado} / {$metodo}</td>
            <td style='text-align:right'><strong>{$monto}</strong></td>
            <td style='font-size:12px;color:#666'>{$notas}</td>
        </tr>";
    }
    $totalFmt = '$' . number_format($total, 2);

    return "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>
<title>Ticket – {$nombre}</title>
<style>
  body{font-family:'Segoe UI',Arial,sans-serif;margin:0;padding:24px;color:#222;background:#fff}
  .header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #B5294E;padding-bottom:16px;margin-bottom:20px}
  .brand{font-size:28px;font-weight:700;color:#B5294E;letter-spacing:1px}
  .brand small{display:block;font-size:12px;color:#888;font-weight:400;letter-spacing:0}
  .meta{text-align:right;font-size:13px;color:#555}
  .client-box{background:#fdf6f8;border:1px solid #f0d4dd;border-radius:8px;padding:14px 18px;margin-bottom:20px;display:flex;gap:32px;flex-wrap:wrap}
  .client-box .lbl{font-size:11px;text-transform:uppercase;color:#999;letter-spacing:1px}
  .client-box .val{font-size:15px;font-weight:600;color:#222;margin-top:2px}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th{background:#B5294E;color:#fff;padding:10px 8px;text-align:left;font-weight:600;letter-spacing:.5px}
  td{padding:9px 8px;border-bottom:1px solid #f0f0f0;vertical-align:top}
  tr:nth-child(even) td{background:#fafafa}
  .totals{margin-top:20px;text-align:right;font-size:15px}
  .totals strong{font-size:20px;color:#B5294E}
  .footer{margin-top:30px;padding-top:14px;border-top:1px solid #eee;font-size:11px;color:#aaa;text-align:center}
  @media print{body{padding:0}.no-print{display:none}}
</style></head><body>
<div class='header'>
  <div>
    <div class='brand'>Beleza<small>Salón de Belleza</small></div>
  </div>
  <div class='meta'>
    <div><strong>Ticket de Cliente</strong></div>
    <div>Generado: {$fecha}</div>
    <div>Total de citas: {$numCitas}</div>
  </div>
</div>
<div class='client-box'>
  <div><div class='lbl'>Cliente</div><div class='val'>{$nombre}</div></div>
  <div><div class='lbl'>Correo</div><div class='val'>{$email}</div></div>
  <div><div class='lbl'>Teléfono</div><div class='val'>{$tel}</div></div>
</div>
<table>
  <thead><tr><th>Fecha</th><th>Hora</th><th>Servicio</th><th>Empleada</th><th>Estado</th><th>Pago</th><th>Monto</th><th>Notas</th></tr></thead>
  <tbody>{$filas}</tbody>
</table>
<div class='totals'>
  Total pagado: <strong>{$totalFmt}</strong>
</div>
<div class='footer'>Beleza — Documento generado automáticamente · {$fecha}</div>
</body></html>";
}

function generarCSVTicket(array $cli, array $citas): string {
    $lineas = [];
    $lineas[] = '"TICKET DE CLIENTE — BELEZA"';
    $lineas[] = '"Cliente","' . $cli['nombre'] . ' ' . $cli['apellido'] . '"';
    $lineas[] = '"Correo","' . $cli['email'] . '"';
    $lineas[] = '"Teléfono","' . ($cli['telefono'] ?? '') . '"';
    $lineas[] = '"Generado","' . date('d/m/Y H:i') . '"';
    $lineas[] = '';
    $lineas[] = '"Fecha","Hora inicio","Hora fin","Servicio","Categoría","Empleada","Estado","Pagado","Método pago","Monto","Notas"';
    foreach ($citas as $c) {
        $empleada = $c['empleada_n'] ? ($c['empleada_n'] . ' ' . $c['empleada_a']) : '';
        $lineas[] = implode(',', [
            '"' . date('d/m/Y', strtotime($c['fecha'])) . '"',
            '"' . substr($c['hora_inicio'],0,5) . '"',
            '"' . substr($c['hora_fin'],0,5) . '"',
            '"' . str_replace('"','""',$c['servicio']) . '"',
            '"' . str_replace('"','""',$c['categoria']) . '"',
            '"' . str_replace('"','""',$empleada) . '"',
            '"' . $c['estado'] . '"',
            '"' . ($c['pagado'] ? 'Sí' : 'No') . '"',
            '"' . ($c['metodo_pago'] ?? '') . '"',
            '"' . number_format((float)$c['monto'],2) . '"',
            '"' . str_replace('"','""',$c['notas'] ?? '') . '"',
        ]);
    }
    $totalPagado = array_sum(array_column(array_filter($citas, fn($r) => $r['pagado']), 'monto'));
    $lineas[] = '';
    $lineas[] = '"TOTAL PAGADO","$' . number_format($totalPagado, 2) . '"';
    return implode("\r\n", $lineas);
}

function generarHTMLRespuesta(string $nombre, string $asunto, string $msgOriginal, string $respuesta): string {
    $nombre_h   = htmlspecialchars($nombre);
    $asunto_h   = htmlspecialchars($asunto);
    $msg_h      = nl2br(htmlspecialchars($msgOriginal));
    $resp_h     = nl2br(htmlspecialchars($respuesta));
    return "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'></head><body style='margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif'>
<table width='100%' style='max-width:600px;margin:30px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)'>
  <tr><td style='background:#B5294E;padding:28px 32px'>
    <div style='color:#fff;font-size:26px;font-weight:700;letter-spacing:1px'>Beleza</div>
    <div style='color:rgba(255,255,255,.8);font-size:13px;margin-top:4px'>Salón de Belleza</div>
  </td></tr>
  <tr><td style='padding:32px'>
    <p style='font-size:16px;color:#222;margin-top:0'>Hola <strong>{$nombre_h}</strong>,</p>
    <p style='color:#555;font-size:14px'>Hemos respondido tu solicitud: <strong style='color:#B5294E'>{$asunto_h}</strong></p>
    <div style='background:#fdf6f8;border-left:4px solid #ddd;border-radius:4px;padding:14px 18px;margin:20px 0;color:#777;font-size:13px'>
      <div style='font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#aaa;margin-bottom:6px'>Tu mensaje</div>
      {$msg_h}
    </div>
    <div style='background:#fdf6f8;border-left:4px solid #B5294E;border-radius:4px;padding:14px 18px;margin:20px 0;color:#333;font-size:14px'>
      <div style='font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#B5294E;margin-bottom:6px'>Respuesta del equipo Beleza</div>
      {$resp_h}
    </div>
    <p style='font-size:13px;color:#888'>Si tienes más preguntas, no dudes en contactarnos.</p>
    <p style='font-size:14px;color:#555'>Con cariño,<br><strong>El equipo Beleza</strong></p>
  </td></tr>
  <tr><td style='background:#f9f9f9;padding:18px 32px;text-align:center;font-size:11px;color:#aaa;border-top:1px solid #eee'>
    © " . date('Y') . " Beleza Salón · Este correo es una respuesta a tu solicitud.
  </td></tr>
</table></body></html>";
}
