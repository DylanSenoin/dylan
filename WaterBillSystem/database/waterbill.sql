DROP DATABASE IF EXISTS waterbill_db;
CREATE DATABASE waterbill_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE waterbill_db;

CREATE TABLE customers (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_code   VARCHAR(20)  NOT NULL UNIQUE,
  first_name      VARCHAR(60)  NOT NULL,
  last_name       VARCHAR(60)  NOT NULL,
  email           VARCHAR(120) DEFAULT NULL,
  phone           VARCHAR(30)  DEFAULT NULL,
  address         VARCHAR(255) NOT NULL,
  barangay        VARCHAR(80)  DEFAULT NULL,
  city            VARCHAR(80)  DEFAULT NULL,
  meter_number    VARCHAR(30)  NOT NULL UNIQUE,
  connection_type ENUM('residential','commercial','government') NOT NULL DEFAULT 'residential',
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(120) DEFAULT NULL,
  phone         VARCHAR(30)  DEFAULT NULL,
  role          ENUM('admin','staff','customer') NOT NULL DEFAULT 'staff',
  customer_id   INT UNSIGNED DEFAULT NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login    DATETIME DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE water_rates (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(100) NOT NULL,
  connection_type ENUM('residential','commercial','government') NOT NULL DEFAULT 'residential',
  min_consumption DECIMAL(10,2) NOT NULL DEFAULT 10.00,
  min_charge      DECIMAL(10,2) NOT NULL DEFAULT 200.00,
  rate_per_cubic  DECIMAL(10,2) NOT NULL DEFAULT 18.00,
  penalty_rate    DECIMAL(5,2)  NOT NULL DEFAULT 2.00,
  penalty_grace   INT NOT NULL DEFAULT 5,
  effective_date  DATE NOT NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE meter_readings (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id      INT UNSIGNED NOT NULL,
  reading_date     DATE NOT NULL,
  billing_period   DATE NOT NULL,
  previous_reading DECIMAL(10,2) NOT NULL,
  current_reading  DECIMAL(10,2) NOT NULL,
  consumption      DECIMAL(10,2) NOT NULL,
  recorded_by      INT UNSIGNED DEFAULT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reading_period (customer_id, billing_period),
  CONSTRAINT fk_mr_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_mr_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE bills (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_number        VARCHAR(30) NOT NULL UNIQUE,
  customer_id        INT UNSIGNED NOT NULL,
  reading_id         INT UNSIGNED NOT NULL,
  rate_id            INT UNSIGNED NOT NULL,
  billing_period     DATE NOT NULL,
  period_start       DATE NOT NULL,
  period_end         DATE NOT NULL,
  consumption        DECIMAL(10,2) NOT NULL,
  basic_charge       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  consumption_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  other_fees         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  penalty            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total_amount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  amount_paid        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  balance            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  due_date           DATE NOT NULL,
  status             ENUM('unpaid','partial','paid','overdue') NOT NULL DEFAULT 'unpaid',
  remarks            VARCHAR(255) DEFAULT NULL,
  generated_by       INT UNSIGNED DEFAULT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bill_period (customer_id, billing_period),
  CONSTRAINT fk_bill_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_bill_reading FOREIGN KEY (reading_id) REFERENCES meter_readings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bill_rate FOREIGN KEY (rate_id) REFERENCES water_rates(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bill_user FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payments (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_number VARCHAR(30) NOT NULL UNIQUE,
  bill_id        INT UNSIGNED NOT NULL,
  customer_id    INT UNSIGNED NOT NULL,
  amount         DECIMAL(10,2) NOT NULL,
  payment_date   DATETIME NOT NULL,
  method         ENUM('cash','gcash','maya','bank_transfer','check') NOT NULL DEFAULT 'cash',
  reference_no   VARCHAR(60) DEFAULT NULL,
  notes          VARCHAR(255) DEFAULT NULL,
  recorded_by    INT UNSIGNED DEFAULT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_bill FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pay_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE system_settings (
  setting_key   VARCHAR(60) PRIMARY KEY,
  setting_value TEXT,
  setting_group VARCHAR(40) NOT NULL DEFAULT 'general',
  label         VARCHAR(120) NOT NULL,
  input_type    ENUM('text','number','textarea','toggle') NOT NULL DEFAULT 'text'
) ENGINE=InnoDB;

INSERT INTO system_settings (setting_key, setting_value, setting_group, label, input_type) VALUES
('provider_name',      'Local Water Service Provider', 'provider', 'Water Provider Name',      'text'),
('provider_address',   'Municipal Hall, Philippines',  'provider', 'Provider Address',         'textarea'),
('provider_contact',   '(000) 000-0000',               'provider', 'Contact Number',           'text'),
('provider_email',     'billing@waterprovider.ph',     'provider', 'Email Address',            'text'),
('currency_symbol',    '₱',                            'general',  'Currency Symbol',          'text'),
('bill_prefix',        'WB',                           'billing',  'Bill Number Prefix',       'text'),
('receipt_prefix',     'OR',                           'billing',  'Receipt Number Prefix',    'text'),
('due_days',           '15',                           'billing',  'Days Until Bill Due',      'number'),
('penalty_enabled',    '1',                            'billing',  'Enable Overdue Penalty',   'toggle'),
('partial_enabled',    '1',                            'billing',  'Allow Partial Payments',   'toggle'),
('other_fees_default', '0.00',                         'billing',  'Default Other Fees',       'number');

CREATE TABLE activity_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED DEFAULT NULL,
  action      VARCHAR(60) NOT NULL,
  module      VARCHAR(60) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  ip_address  VARCHAR(45) DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO water_rates
(name, connection_type, min_consumption, min_charge, rate_per_cubic, penalty_rate, penalty_grace, effective_date, is_active) VALUES
('Residential Standard', 'residential', 10.00, 200.00, 18.00, 2.00, 5, '2026-01-01', 1),
('Commercial Standard',  'commercial',  10.00, 350.00, 25.00, 2.00, 5, '2026-01-01', 1),
('Government Standard',  'government',  10.00, 300.00, 22.00, 2.00, 5, '2026-01-01', 1);