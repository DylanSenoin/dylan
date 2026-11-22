# Water Bill Management System

Complete PHP/MySQL web app for local water service providers in the Philippines.
Automates customer registration, meter readings, billing, payments, statements, and reporting.

**Stack:** PHP 8 · MySQL 8 / MariaDB 10.4+ · Bootstrap 5 · Chart.js · Dompdf

---

## Quick install (XAMPP)

### 1. Copy files
Place all files into: `C:\xampp\htdocs\waterbill\`

### 2. Start XAMPP
Open the XAMPP Control Panel → **Start Apache** → **Start MySQL**

### 3. Import the database
- Open `http://localhost/phpmyadmin`
- **Import** → choose `database/waterbill.sql` → **Go**
- Confirm `waterbill_db` appears in the left sidebar

### 4. Configure (only if needed)
Edit `config/config.php`:
- `BASE_URL` — change if your folder is not `waterbill`
- `DB_USER` / `DB_PASS` — change if your MySQL credentials differ

### 5. Create the first administrator
- Open `http://localhost/waterbill/setup.php`
- Username (min 4) + password (min 8) + confirm
- Submit
- **Delete `setup.php`** — critical for security

### 6. Log in
Open `http://localhost/waterbill/` → sign in with your admin credentials.

### 7. (Optional) Enable PDF download
```
cd C:\xampp\htdocs\waterbill
composer install
```
Without this step, PDF buttons fall back to the browser print dialog
(with a one-time warning banner — still fully usable).

---

## First-use workflow

1. **Settings** → fill in provider name, address, contact, email
2. **Water Rates** → adjust seeded residential/commercial/government rates (via phpMyAdmin)
3. **Customers** → register each household; optionally create a login
4. **Meter Readings** → record monthly readings
5. **Water Billing → Generate Bills** → batch-generate from unbilled readings
6. **Payments** → record collections as customers pay
7. **Billing Statements** → print/download statements
8. **Reports** → export collections, unpaid, overdue, consumption to CSV

---

## Billing formula

```
Water Bill = Basic Charge
           + max(0, (Consumption − Min Consumption) × Rate per m³)
           + Other Fees
           + Overdue Penalty
           − Discount
```

- **Basic Charge** = minimum charge (covers consumption up to min threshold)
- **Overdue Penalty** = `balance × penalty_rate / 100`, applied once — sweep is idempotent
- Rate is **snapshotted** into the bill row so future rate changes never rewrite history

## Partial-payment contract

| Bill due date | Resulting status after partial payment |
|---|---|
| Not past due | `partial` |
| Past due | `overdue` — never downgraded to `partial` |

This keeps the overdue sweep safe to run any number of times without double-penalizing.

---

## Roles & permissions

| Feature | Admin | Staff | Customer |
|---|---|---|---|
| Dashboard | ✔ | ✔ | ✔ (own) |
| Manage customers | ✔ | ✔ | — |
| Meter readings | ✔ | ✔ | — |
| Billing | ✔ | ✔ | — |
| Payments | ✔ | ✔ | — |
| Statements | ✔ | ✔ | own only |
| Reports | ✔ | — | — |
| User management | ✔ | — | — |
| Settings | ✔ | — | — |

---

## Automated overdue sweep

**Manual:** Water Billing → **Sweep Overdue** button

**Automatic:** schedule `cron/overdue_sweep.php`

Linux/macOS (daily 1 AM):
```
0 1 * * * /usr/bin/php /path/to/waterbill/cron/overdue_sweep.php
```

Windows Task Scheduler:
```
Program:    C:\xampp\php\php.exe
Arguments:  C:\xampp\htdocs\waterbill\cron\overdue_sweep.php
```

Safe to run repeatedly — idempotent by design.

---

## Testing checklist

### Authentication
- [ ] `setup.php` creates admin → self-disables on second load
- [ ] Login with correct creds → dashboard
- [ ] Wrong creds → error flash
- [ ] Inactive account cannot log in
- [ ] Logout invalidates session

### Roles
- [ ] Admin sees all 10 sidebar items
- [ ] Staff sees 6 (no Reports/Users/Settings)
- [ ] Customer sees 3 (Dashboard, My Bills, Logout)
- [ ] Staff hitting `/pages/users.php` → 403
- [ ] Customer hitting `/pages/statements.php` → 403

### Customers
- [ ] New customer gets auto `CUS#####` code
- [ ] Duplicate meter number rejected
- [ ] Optional login creation works; duplicate username rejected
- [ ] Deactivating preserves historical bills

### Meter readings
- [ ] First reading allows previous value
- [ ] Second reading auto-fills previous from last `current`
- [ ] Same period twice → blocked
- [ ] Current < previous → blocked
- [ ] Future reading date → blocked
- [ ] Editing an already-billed reading → blocked

### Billing
- [ ] Preview math is correct (verify by hand)
- [ ] Generate creates all bills in one transaction
- [ ] Re-generating for same period finds 0 unbilled
- [ ] Rate change after billing does not alter existing bills
- [ ] Overdue sweep: 0 → flags nothing
- [ ] Overdue sweep: 1+ past due → flags, applies penalty once
- [ ] Sweep again → 0 flagged, no double-penalty

### Payments
- [ ] Pay full balance → status `paid`, balance `₱0.00`
- [ ] Partial on not-yet-due → `partial`
- [ ] Partial on overdue → stays `overdue`
- [ ] Overpay → rejected
- [ ] Non-cash without reference → rejected
- [ ] Future-dated payment → rejected
- [ ] Receipt renders correctly
- [ ] Customer sees only their own payments
- [ ] Concurrent submission — second rejected

### Statements & PDF
- [ ] `statement-view.php` renders without site chrome
- [ ] `statement-pdf.php` downloads a PDF (with `vendor/`)
- [ ] Without `vendor/` → auto-print HTML fallback with yellow banner

### Reports
- [ ] All 7 report tabs load
- [ ] Date filters change results
- [ ] CSV downloads, opens in Excel (formula-safe)

### Settings
- [ ] Provider name change reflects on statements/receipts/login
- [ ] Due_days change affects next bill
- [ ] Invalid values rejected

### Security
- [ ] All POST forms have CSRF tokens
- [ ] SQL injection in search → no effect
- [ ] XSS attempt in customer name → rendered as text
- [ ] Passwords stored as bcrypt
- [ ] Deactivated user's session terminated

---

## Folder map

```
waterbill/
├── index.php, login.php, logout.php, setup.php
├── composer.json, README.md
├── config/{config,database}.php
├── includes/{init,functions,csrf,auth,header,sidebar,footer,forbidden,statement_render}.php
├── assets/{css,js,img}/
├── database/{waterbill.sql,ERD.md}
├── cron/overdue_sweep.php
└── pages/{dashboard, customers, customer-form, customer-view, readings, reading-form,
           bills, bill-generate, bill-view, payments, payment-form, receipt,
           statements, statement-view, statement-pdf, reports, report-export,
           users, user-form, settings, profile, my-bills, my-bill-view}.php
```

---

## Troubleshooting

**"Database connection failed"**
→ MySQL not running, or `DB_USER`/`DB_PASS` wrong in `config/config.php`

**"Security token expired"**
→ Session cookie cleared — reload and retry

**"Cannot generate bills — no unbilled readings"**
→ Record meter readings for that period first

**PDF downloads as HTML**
→ `vendor/` not installed. Run `composer install` or use the print fallback

**Charts empty**
→ No data yet — record readings, generate bills, record payments, then reload