-- =============================================================================
-- LemboFlow — database schema
-- Target: MariaDB 10.4+ (XAMPP) / MySQL 8.0.16+
--
-- Import: phpMyAdmin → Import → this file, or:
--   mysql -u root < database/lembo_flow.sql
--
-- WARNING: re-importing DROPS all LemboFlow tables and their data.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS lembo_flow
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE lembo_flow;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS activities;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS invoice_items;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS invoice_sequences;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS business_profiles;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- users — one row per account
-- -----------------------------------------------------------------------------
CREATE TABLE users (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name           VARCHAR(100)  NOT NULL,
  email          VARCHAR(190)  NOT NULL,
  password_hash  VARCHAR(255)  NOT NULL,
  is_demo        TINYINT(1)    NOT NULL DEFAULT 0,
  last_login_at  DATETIME      NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- business_profiles — 1:1 with users; everything that appears on invoices
-- -----------------------------------------------------------------------------
CREATE TABLE business_profiles (
  id                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  user_id             INT UNSIGNED      NOT NULL,
  business_name       VARCHAR(150)      NOT NULL,
  email               VARCHAR(190)      NULL,
  phone               VARCHAR(40)       NULL,
  website             VARCHAR(190)      NULL,
  address_line1       VARCHAR(150)      NULL,
  address_line2       VARCHAR(150)      NULL,
  city                VARCHAR(100)      NULL,
  postal_code         VARCHAR(20)       NULL,
  country             VARCHAR(100)      NULL,
  vat_number          VARCHAR(40)       NULL,
  bank_name           VARCHAR(100)      NULL,
  iban                VARCHAR(34)       NULL,
  swift_bic           VARCHAR(11)       NULL,
  currency            CHAR(3)           NOT NULL DEFAULT 'EUR',
  invoice_prefix      VARCHAR(10)       NOT NULL DEFAULT 'INV',
  payment_terms_days  SMALLINT UNSIGNED NOT NULL DEFAULT 14,
  default_tax_rate    DECIMAL(5,2)      NOT NULL DEFAULT 0.00,
  default_notes       TEXT              NULL,
  logo_path           VARCHAR(255)      NULL,
  created_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_business_profiles_user (user_id),
  CONSTRAINT fk_business_profiles_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_bp_terms    CHECK (payment_terms_days <= 365),
  CONSTRAINT chk_bp_tax      CHECK (default_tax_rate BETWEEN 0 AND 100),
  CONSTRAINT chk_bp_currency CHECK (CHAR_LENGTH(currency) = 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- clients
-- -----------------------------------------------------------------------------
CREATE TABLE clients (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  name           VARCHAR(150) NOT NULL,
  contact_name   VARCHAR(100) NULL,
  email          VARCHAR(190) NULL,
  phone          VARCHAR(40)  NULL,
  address_line1  VARCHAR(150) NULL,
  address_line2  VARCHAR(150) NULL,
  city           VARCHAR(100) NULL,
  postal_code    VARCHAR(20)  NULL,
  country        VARCHAR(100) NULL,
  vat_number     VARCHAR(40)  NULL,
  notes          TEXT         NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_clients_user_name (user_id, name),
  KEY idx_clients_user_email (user_id, email),
  CONSTRAINT fk_clients_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- products — services and products that pre-fill invoice lines
-- -----------------------------------------------------------------------------
CREATE TABLE products (
  id           INT UNSIGNED               NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED               NOT NULL,
  name         VARCHAR(150)               NOT NULL,
  description  TEXT                       NULL,
  type         ENUM('service','product')  NOT NULL DEFAULT 'service',
  unit         VARCHAR(20)                NOT NULL DEFAULT 'hour',
  unit_price   DECIMAL(12,2)              NOT NULL DEFAULT 0.00,
  tax_rate     DECIMAL(5,2)               NOT NULL DEFAULT 0.00,
  is_active    TINYINT(1)                 NOT NULL DEFAULT 1,
  created_at   DATETIME                   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME                   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_products_user_active_name (user_id, is_active, name),
  KEY idx_products_user_type (user_id, type),
  CONSTRAINT fk_products_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_products_price CHECK (unit_price >= 0),
  CONSTRAINT chk_products_tax   CHECK (tax_rate BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- invoice_sequences — atomic per-user, per-year invoice numbering
--
--   INSERT INTO invoice_sequences (user_id, year, last_number) VALUES (?, ?, 1)
--   ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1);
--   SELECT LAST_INSERT_ID();   -- 0 on first insert → use 1
--
-- Numbers are never reused, even after an invoice is deleted.
-- -----------------------------------------------------------------------------
CREATE TABLE invoice_sequences (
  user_id      INT UNSIGNED      NOT NULL,
  year         SMALLINT UNSIGNED NOT NULL,
  last_number  INT UNSIGNED      NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, year),
  CONSTRAINT fk_invoice_sequences_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- invoices — totals are stored, so issued invoices stay frozen
--
-- status          lifecycle: draft → sent → paid / overdue / cancelled
-- payment_status  derived from payments, recalculated on every payment change
-- amount_paid     cached SUM(payments.amount), kept in sync by the app
-- total           = subtotal + tax_total − discount_amount
-- -----------------------------------------------------------------------------
CREATE TABLE invoices (
  id               INT UNSIGNED                                          NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED                                          NOT NULL,
  client_id        INT UNSIGNED                                          NOT NULL,
  invoice_number   VARCHAR(30)                                           NOT NULL,
  status           ENUM('draft','sent','paid','overdue','cancelled')     NOT NULL DEFAULT 'draft',
  payment_status   ENUM('unpaid','partial','paid')                       NOT NULL DEFAULT 'unpaid',
  issue_date       DATE                                                  NOT NULL,
  due_date         DATE                                                  NOT NULL,
  currency         CHAR(3)                                               NOT NULL DEFAULT 'EUR',
  subtotal         DECIMAL(12,2)                                         NOT NULL DEFAULT 0.00,
  tax_total        DECIMAL(12,2)                                         NOT NULL DEFAULT 0.00,
  discount_type    ENUM('none','fixed','percent')                        NOT NULL DEFAULT 'none',
  discount_value   DECIMAL(12,2)                                         NOT NULL DEFAULT 0.00,
  discount_amount  DECIMAL(12,2)                                         NOT NULL DEFAULT 0.00,
  total            DECIMAL(12,2)                                         NOT NULL DEFAULT 0.00,
  amount_paid      DECIMAL(12,2)                                         NOT NULL DEFAULT 0.00,
  notes            TEXT                                                  NULL,
  sent_at          DATETIME                                              NULL,
  paid_at          DATETIME                                              NULL,
  cancelled_at     DATETIME                                              NULL,
  created_at       DATETIME                                              NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME                                              NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoices_user_number (user_id, invoice_number),
  KEY idx_invoices_user_status_due (user_id, status, due_date),
  KEY idx_invoices_user_issue (user_id, issue_date),
  KEY idx_invoices_client (client_id),
  CONSTRAINT fk_invoices_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_invoices_client
    FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
  CONSTRAINT chk_invoices_dates    CHECK (due_date >= issue_date),
  CONSTRAINT chk_invoices_amounts  CHECK (subtotal >= 0 AND tax_total >= 0
                                          AND discount_value >= 0 AND discount_amount >= 0
                                          AND total >= 0),
  CONSTRAINT chk_invoices_percent  CHECK (discount_type <> 'percent' OR discount_value <= 100),
  CONSTRAINT chk_invoices_paid     CHECK (amount_paid >= 0 AND amount_paid <= total)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- invoice_items — snapshots: editing a product never changes an issued invoice.
-- Ownership is always checked through invoices.user_id.
-- -----------------------------------------------------------------------------
CREATE TABLE invoice_items (
  id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  invoice_id     INT UNSIGNED      NOT NULL,
  product_id     INT UNSIGNED      NULL,
  position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  name           VARCHAR(150)      NOT NULL,
  description    TEXT              NULL,
  unit           VARCHAR(20)       NULL,
  quantity       DECIMAL(10,2)     NOT NULL DEFAULT 1.00,
  unit_price     DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
  tax_rate       DECIMAL(5,2)      NOT NULL DEFAULT 0.00,
  line_subtotal  DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
  line_tax       DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
  line_total     DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_invoice_items_invoice_pos (invoice_id, position),
  KEY idx_invoice_items_product (product_id),
  CONSTRAINT fk_invoice_items_invoice
    FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_items_product
    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
  CONSTRAINT chk_items_qty    CHECK (quantity > 0),
  CONSTRAINT chk_items_price  CHECK (unit_price >= 0),
  CONSTRAINT chk_items_tax    CHECK (tax_rate BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- payments — revenue = SUM(amount). user_id is stored for fast revenue queries.
-- The app refuses to delete an invoice that has payments; the CASCADE only
-- applies when the whole account is deleted.
-- -----------------------------------------------------------------------------
CREATE TABLE payments (
  id          INT UNSIGNED                                               NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED                                               NOT NULL,
  invoice_id  INT UNSIGNED                                               NOT NULL,
  amount      DECIMAL(12,2)                                              NOT NULL,
  paid_on     DATE                                                       NOT NULL,
  method      ENUM('bank_transfer','cash','card','paypal','other')       NOT NULL DEFAULT 'bank_transfer',
  reference   VARCHAR(100)                                               NULL,
  notes       VARCHAR(255)                                               NULL,
  created_at  DATETIME                                                   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payments_user_paid_on (user_id, paid_on),
  KEY idx_payments_invoice (invoice_id),
  CONSTRAINT fk_payments_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_invoice
    FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
  CONSTRAINT chk_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- activities — feed entries. No FK to the subject on purpose, so
-- "Client Acme deleted" still reads correctly after the record is gone.
-- -----------------------------------------------------------------------------
CREATE TABLE activities (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  type          VARCHAR(40)  NOT NULL,          -- e.g. invoice.created, payment.recorded
  subject_type  VARCHAR(20)  NULL,              -- invoice | client | product | payment
  subject_id    INT UNSIGNED NULL,
  description   VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activities_user_created (user_id, created_at),
  KEY idx_activities_subject (subject_type, subject_id),
  CONSTRAINT fk_activities_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
