# Water Bill Management System — Entity Relationship Diagram

## Relationships at a glance

    customers ──┬─── (1:N) ─── users (role='customer')
                ├─── (1:N) ─── meter_readings
                ├─── (1:N) ─── bills
                └─── (1:N) ─── payments

    meter_readings ─── (1:1) ─── bills
    water_rates ──── (1:N) ─── bills
    bills ──────────── (1:N) ─── payments
    users ──────────── (1:N) ─── activity_logs
    users ──────────── (1:N) ─── meter_readings (recorded_by)
    users ──────────── (1:N) ─── bills (generated_by)
    users ──────────── (1:N) ─── payments (recorded_by)

## Tables

### customers
- id (PK)
- customer_code (UQ)
- first_name, last_name
- email, phone
- address, barangay, city
- meter_number (UQ)
- connection_type: residential | commercial | government
- status: active | inactive

### users
- id (PK)
- username (UQ)
- password_hash
- full_name, email, phone
- role: admin | staff | customer
- customer_id (FK → customers.id, nullable — only set for customer role)
- status: active | inactive
- last_login

### water_rates
- id (PK)
- name, connection_type
- min_consumption, min_charge
- rate_per_cubic
- penalty_rate (percent), penalty_grace (days)
- effective_date, is_active

### meter_readings
- id (PK)
- customer_id (FK)
- reading_date, billing_period (first of month)
- previous_reading, current_reading, consumption
- recorded_by (FK → users.id, nullable)
- UNIQUE(customer_id, billing_period)

### bills
- id (PK)
- bill_number (UQ)
- customer_id (FK), reading_id (FK, UQ), rate_id (FK)
- billing_period, period_start, period_end
- consumption
- basic_charge, consumption_charge, other_fees
- penalty, discount
- total_amount, amount_paid, balance
- due_date
- status: unpaid | partial | paid | overdue
- generated_by (FK → users.id)
- UNIQUE(customer_id, billing_period)

### payments
- id (PK)
- receipt_number (UQ)
- bill_id (FK), customer_id (FK)
- amount, payment_date
- method: cash | gcash | maya | bank_transfer | check
- reference_no, notes
- recorded_by (FK → users.id)

### system_settings
- setting_key (PK)
- setting_value, setting_group, label, input_type

### activity_logs
- id (PK)
- user_id (FK → users.id, nullable)
- action, module, description
- ip_address, created_at