-- Ejecutar este script en tu base de datos beleza_db
-- Agrega la tabla de solicitudes de clientes

CREATE TABLE IF NOT EXISTS `solicitudes` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `cliente_id`     INT UNSIGNED    NOT NULL,
  `asunto`         VARCHAR(200)    NOT NULL,
  `mensaje`        TEXT            NOT NULL,
  `estado`         ENUM('pendiente','respondida','cerrada') NOT NULL DEFAULT 'pendiente',
  `respuesta`      TEXT            NULL,
  `respondida_en`  DATETIME        NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cliente` (`cliente_id`),
  KEY `idx_estado`  (`estado`),
  CONSTRAINT `fk_sol_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
