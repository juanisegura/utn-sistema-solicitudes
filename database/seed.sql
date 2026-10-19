-- =============================================================
-- Seed de datos de prueba: UTN Sistema de Solicitudes
-- Datos de prueba NO sensibles: nombres ficticios y dominios example.com (RFC 2606).
-- Ejecutar después de schema.sql.
-- La contraseña demo del admin está documentada en el README; acá solo figura su hash.
-- =============================================================

SET NAMES utf8mb4;

INSERT INTO admins (nombre, email, password_hash) VALUES
('Administrador Demo', 'admin@example.com', '$2y$12$ZwUek0HtILZRyN8dq7u9FulFgOBhHbwk8U8ho84YIknC88KfL89ye');

INSERT INTO solicitudes (nombre, email, asunto, descripcion, estado, created_at, updated_at) VALUES
('Ana Ejemplo', 'ana.ejemplo@example.com', 'No puedo ingresar al campus virtual', 'Desde ayer, al intentar ingresar al campus virtual, aparece un error de credenciales inválidas aunque la contraseña es la correcta. Ya probé desde dos navegadores distintos sin resultado.', 'pendiente', NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY),
('Bruno Prueba', 'bruno.prueba@example.com', 'Impresora del laboratorio sin conexión', 'La impresora del laboratorio 3 no aparece en la lista de dispositivos de las computadoras desde hace una semana. Necesitamos imprimir la entrega del trabajo práctico antes del viernes.', 'en_proceso', NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 2 DAY),
('Carla Muestra', 'carla.muestra@example.com', 'Conexión VPN intermitente desde casa', 'La VPN institucional se desconecta cada 15 minutos aproximadamente cuando accedo desde mi domicilio. El cliente muestra un error de tiempo de espera agotado.', 'en_proceso', NOW() - INTERVAL 7 DAY, NOW() - INTERVAL 1 DAY),
('Diego Ficticio', 'diego.ficticio@example.com', 'Cuenta de correo institucional bloqueada', 'Mi cuenta de correo institucional quedó bloqueada tras varios intentos fallidos de inicio de sesión. Necesito recuperar el acceso para recibir las notificaciones de la cursada.', 'resuelta', NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 5 DAY),
('Elena Demo', 'elena.demo@example.com', 'Alta de usuario para nuevo docente', 'Solicito el alta de un usuario docente para la materia Programación I, con acceso al aula virtual del segundo cuatrimestre. Los datos del docente fueron enviados por mesa de entradas.', 'pendiente', NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY),
('Federico Ejemplo', 'federico.ejemplo@example.com', 'Proyector del aula 204 no enciende', 'El proyector del aula 204 no enciende desde el lunes y el control remoto no responde. La clase de la tarde necesita el equipo para la presentación del proyecto.', 'resuelta', NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 2 DAY),
('Gabriela Ficticia', 'gabriela.ficticia@example.com', 'Error al subir archivos al campus virtual', 'Al subir un PDF de 12 MB al campus virtual, la carga se corta al 90 por ciento y muestra un error genérico. Con archivos más chicos la carga funciona sin problemas.', 'en_proceso', NOW() - INTERVAL 30 HOUR, NOW() - INTERVAL 5 HOUR),
('Irene Demo', 'irene.demo@example.com', '<script>alert(1)</script> Prueba de escape', 'Fila de prueba intencional de XSS: el panel debe mostrar el asunto como texto literal y nunca ejecutar el script. También incluye la muestra de inyección SQL '' OR 1=1 -- que debe verse escapada y no ejecutarse.', 'pendiente', NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 2 HOUR);

-- Historial coherente con el estado y las fechas de cada solicitud que no está pendiente
-- (ids 1..8 en el orden del INSERT de arriba: la tabla se recrea, el AUTO_INCREMENT arranca en 1).
-- Las resueltas pasan antes por "en proceso". Autor: el admin demo.
INSERT INTO solicitud_eventos (solicitud_id, admin_id, estado_anterior, estado_nuevo, created_at)
SELECT e.solicitud_id, a.id, e.anterior, e.nuevo, e.fecha
FROM admins a
JOIN (
    SELECT 2 AS solicitud_id, 'pendiente' AS anterior, 'en_proceso' AS nuevo, NOW() - INTERVAL 2 DAY AS fecha
    UNION ALL SELECT 3, 'pendiente',  'en_proceso', NOW() - INTERVAL 1 DAY
    UNION ALL SELECT 4, 'pendiente',  'en_proceso', NOW() - INTERVAL 132 HOUR
    UNION ALL SELECT 4, 'en_proceso', 'resuelta',   NOW() - INTERVAL 5 DAY
    UNION ALL SELECT 6, 'pendiente',  'en_proceso', NOW() - INTERVAL 60 HOUR
    UNION ALL SELECT 6, 'en_proceso', 'resuelta',   NOW() - INTERVAL 2 DAY
    UNION ALL SELECT 7, 'pendiente',  'en_proceso', NOW() - INTERVAL 5 HOUR
) e
WHERE a.email = 'admin@example.com';
