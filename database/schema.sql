-- schema.sql — Estructura de la base del sistema de solicitudes.
-- Idempotente: borra y recrea las tablas (se pierden los datos). Compatible con
-- MariaDB >= 10.6 y MySQL 8. Cargar con: php scripts/instalar-db.php

SET NAMES utf8mb4;

DROP TABLE IF EXISTS solicitudes;
DROP TABLE IF EXISTS admins;

CREATE TABLE admins (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(100) NOT NULL,
    email          VARCHAR(254) NOT NULL,
    -- Hash de password_hash(); 255 deja margen si cambia el algoritmo por defecto.
    password_hash  VARCHAR(255) NOT NULL,
    activo         TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at  DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE solicitudes (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre       VARCHAR(100) NOT NULL,
    email        VARCHAR(254) NOT NULL,
    asunto       VARCHAR(150) NOT NULL,
    -- El límite de 5000 caracteres se valida en PHP; TEXT admite más.
    descripcion  TEXT         NOT NULL,
    -- Mismos valores que el enum App\Models\Estado (ADR-0006).
    estado       ENUM('pendiente','en_proceso','resuelta') NOT NULL DEFAULT 'pendiente',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Cubre el listado del panel: filtro por estado ordenado por fecha.
    KEY idx_estado_fecha (estado, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
