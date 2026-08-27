# BPM — ระบบบริหารจัดการงบประมาณสาขาวิชา

## What Is This?

**BPM** (Budget Project Management) is a web-based budget tracking system for academic departments at the Faculty of Medical Science, University of Phayao (MEDSCI). It answers three core questions:
1. How much budget has each department been allocated (by line item)?
2. How much has been spent and what remains (real-time)?
3. Have there been budget transfers between items and are they approved?

Built in **plain PHP 8.2 + MariaDB**, deployed on **IIS** with **MEDSCI ACC SSO** login (no local passwords stored). Exports monthly/quarterly reports as Excel/PDF with full Thai font support.

---

## Project Structure

```
D:/Dev/BPM/
├── composer.json                 # Dependencies: phpspreadsheet, dompdf
├── docker-compose.yml            # Dev container: MariaDB + PHP 8.2 FastCGI
├── docker/php.Dockerfile         # PHP 8.2 image config
│
├── public/                        # Web root (URL-facing)
│   ├── index.php                 # Dashboard (main entry)
│   ├── login.php                 # SSO login redirect (no form)
│   ├── sso_callback.php          # SSO callback handler
│   ├── logout.php                # Session destroy + SLO redirect
│   ├── error.php                 # SSO error pages (not debug stack traces)
│   ├── pending-access.php        # "Waiting for admin to assign role" page
│   │
│   ├── transactions.php          # Record expenses/income
│   ├── transfers.php             # Request/view budget transfers
│   ├── reports.php               # Monthly/quarterly reports (read-only)
│   │
│   ├── admin/
│   │   ├── users.php             # Assign roles + departments to SSO users
│   │   ├── departments.php       # CRUD departments (configure)
│   │   ├── fiscal-years.php      # Create/open/close fiscal years
│   │   ├── budget-groups.php     # Configure budget categories (tagging only)
│   │   └── allocations.php       # Manage line-item allocations per dept+year
│   │
│   └── actions/                  # Form POST handlers (PRG pattern)
│       ├── create-transaction.php
│       ├── create-transfer.php
│       ├── decide-transfer.php  # Admin approve/reject transfers
│       ├── save-user-role.php
│       └── ...etc
│
├── src/                          # Core library code
│   ├── bootstrap.php             # Loaded first by every public/*.php (session, error handlers, timezone)
│   ├── partials/
│   │   ├── layout_start.php     # HTML header + sidebar nav
│   │   ├── layout_end.php       # HTML footer
│   │   └── icons.php            # Inline SVG icons
│   │
│   └── lib/                      # Business logic (no DB query boilerplate)
│       ├── config.php            # Load & cache config/config.php (memoized)
│       ├── db.php                # PDO connection factory
│       ├── auth.php              # SSO flow + session guards
│       ├── fiscal_year.php       # Thai fiscal year (Oct 1 - Sep 30) + quarter logic
│       ├── budget.php            # Balance calculations (single source of truth)
│       └── export.php            # Excel/PDF generation
│
├── sql/
│   └── schema.sql                # Full schema + seed data (MySQL/MariaDB)
│
├── config/
│   ├── config.example.php        # Template (commit this, never commit config.php)
│   └── config.php                # (NOT IN GIT) SSO credentials + DB secrets
│
└── logs/                         # Error & audit logs (created at runtime)
```

---

## Key Files & Where to Start

| File | Purpose | Read When |
|------|---------|-----------|
| `spec.md` | **Full spec** — business rules, data model, SSO flow, error handling, security | Understanding requirements & design decisions (it's detailed!) |
| `sql/schema.sql` | Database schema + FK relationships | Understanding the data model |
| `src/bootstrap.php` | Central init (timezone, session, error handler) | First: loaded by every page before any output |
| `src/lib/auth.php` | SSO login/callback/provisioning + session guards | Understanding auth flow |
| `src/lib/budget.php` | Balance calculation engine (centralized) | How budget math works |
| `src/lib/fiscal_year.php` | Thai fiscal year (Oct 1 - Sep 30) + quarter math | Understanding date handling |
| `public/index.php` | Dashboard (department overview) | See how pages are structured |
| `config/config.example.php` | Config template (copy to `config.php`) | Deployment setup |

---

## How to Run It

### Development Setup (Docker)

```bash
# Clone & install dependencies
git clone <repo>
cd BPM
composer install

# Start services
docker-compose up -d

# Wait for DB to be ready (~15s)
docker-compose exec db healthcheck.sh --connect --innodb_initialized

# Access app
# http://localhost:8080/login.php

# To log in (dev bypass):
# Username: admin.dev
# Password: dev1234
# This account is auto-provisioned as SSO user wittaya.su — ADMIN will assign roles in admin/users.php
```

### Production Setup (IIS)

1. **PHP Configuration**
   - PHP 8.2+ via FastCGI (not iisnode — that's Node.js only)
   - Enable extensions: `ext-pdo`, `ext-curl`, `ext-gd`, `ext-zip`
   - Session handler: PHP native (file-based okay, or Redis if desired)

2. **Database**
   - MariaDB 10.6+ or MySQL 8.0+
   - Charset: `utf8mb4`, Collation: `utf8mb4_unicode_ci` (Thai support)
   - Import `sql/schema.sql`

3. **Config**
   - Copy `config/config.example.php` → `config/config.php` (outside webroot, e.g., `D:\apps\bpm\config\config.php`)
   - Fill in: DB credentials, SSO client ID/secret (register at MEDSCI ACC first)
   - Set `sso_ssl_verify = true` in production

4. **IIS Site**
   - Point to `public/` directory as web root
   - Set `index.php` as default document
   - FastCGI handler: `.php → php-cgi.exe`

5. **First User (SSO)**
   - Deploy & test via dev bypass if possible
   - Admin logs in via MEDSCI ACC SSO, gets auto-provisioned with `role = NULL`
   - Go to `admin/users.php` in a separate session, assign `ADMIN` role + department = null
   - Restart/reload to see dashboard

---

## Notable Patterns & Tech

### SSO Authentication (MEDSCI ACC)

```
login.php ──redirect──> MEDSCI ACC login ──redirect──> sso_callback.php ──POST verify──> MEDSCI ACC API
                                                               │
                                                           (verify OK)
                                                               v
                                                     Provision local user
                                                     Start PHP session
                                                     Redirect to dashboard
```

- **State validation** (CSRF protection): `sso_state` stored in session, verified on callback with `hash_equals()`
- **Token lifecycle**: 120 seconds, single-use (2nd verify call fails automatically)
- **Role assignment**: SSO only confirms "is this a MEDSCI person?" — BPM admin must assign `ADMIN`/`DEPT_STAFF`/`EXECUTIVE_VIEWER` roles
- **Logout**: Server-side session destroy + SLO redirect to MEDSCI ACC to clear their session too

### Post/Redirect/Get Pattern (PRG)

Every form submission → POST to `public/actions/*.php` → Validate & DB write → `header("Location: ...")` → GET the result page. No `<?= $_POST ?>` in templates; prevents re-submission on F5.

### PDO Prepared Statements

```php
$stmt = $db->prepare('SELECT * FROM budget_line_items WHERE id = ? AND department_id = ?');
$stmt->execute([$lineItemId, $departmentId]);
$result = $stmt->fetch();
```

Always use placeholders. No string concatenation for SQL (prevents injection). `PDO::ATTR_EMULATE_PREPARES = false` in `db.php` to force real prepared statements on MariaDB.

### Budget Calculation (Single Source of Truth)

Function `bpm_line_item_balance()` in `src/lib/budget.php` centralizes all balance math:

```
Total Budget = Starting Amount + Approved Transfers In - Approved Transfers Out
Balance      = Total Budget - Expenses + Income
```

All reports/dashboards call this, never duplicate the logic. For concurrent updates, uses `FOR UPDATE` row locking inside DB transactions.

### Thai Fiscal Year Handling

```php
// Fiscal year 2570 (BE) = Oct 1, 2026 - Sep 30, 2027 (AD)
bpm_fiscal_year_of_date('2026-10-15') // => 2570
bpm_fiscal_quarter('2026-10-15')       // => Q1 (Oct-Dec)
```

All dates stored as `DATE` (Y-m-d), timezone fixed to `Asia/Bangkok` in `bootstrap.php`.

### Excel/PDF Export with Thai Fonts

- **PhpSpreadsheet** (Composer): Generates .xlsx directly (no LibreOffice binary needed)
- **Dompdf** (Composer): HTML → PDF, but stock fonts don't support Thai
  - Custom font: `Noto Sans Thai` (included in `src/fonts/`)
  - Must set `Options::setChroot()` to let Dompdf read local font files
  - Dompdf sandbox is strict by default — requires `isRemoteEnabled = true` to load any files

Example:
```php
$options = new Options();
$options->setChroot([realpath(__DIR__.'/../fonts')]);
$options->set('isRemoteEnabled', true);
Dompdf::setOptions($options);
// Now registerFont() will find the TTF file
```

### Role-Based Access Control (Backend-Enforced)

```php
bpm_require_role('ADMIN', 'EXECUTIVE_VIEWER')  // Guard: dies if not one of these roles

// Inside action handler:
$user = bpm_require_login();
if ($user['role'] !== 'ADMIN') {
    http_response_code(403);
    die('ไม่มีสิทธิ์');
}
```

Server always checks role + ownership (if `DEPT_STAFF`, verify their `department_id` matches the data being modified). **Never trust role from client/JavaScript.**

### Budget Line Items (Per-Department + Fiscal Year)

- **Not** a shared taxonomy across departments
- Each department has its own set of line items for each fiscal year
- Example: Dept A might have "ค่าเดินทาง" (travel), Dept B might have "ค่าครุภัณฑ์" (equipment)
- Imported from Excel files (script: `scripts/import_allocations.php` or manual CRUD in `admin/allocations.php`)

### Budget Transfers (Approval Workflow)

- `DEPT_STAFF` submits transfer request (from one line item → another within same department + fiscal year)
- Status starts as `PENDING`
- `ADMIN` reviews & sets to `APPROVED` or `REJECTED`
- **Important**: This is "for tracking only" — actual budget authorization happens outside BPM (paper/email). BPM just records it.

### Audit Logging

`audit_logs` table records who did what & when:
- User role changes
- Transfer approvals/rejections
- Budget line item updates (tracks old vs new value as JSON)

Helps with accountability & debugging.

---

## Tech Stack Summary

| Layer | Technology | Notes |
|-------|-----------|-------|
| **Language** | PHP 8.2+ | Strict types (`declare(strict_types=1)` in every file) |
| **Database** | MariaDB 10.6+ / MySQL 8.0+ | InnoDB, utf8mb4_unicode_ci, prepared statements only |
| **Auth** | MEDSCI ACC SSO (OAuth-like redirect) | No local passwords, session via PHP native |
| **Web Server** | IIS FastCGI (Production), Docker Apache (Dev) | No frameworks, plain routing (public/*.php mapped to URLs) |
| **Frontend** | Server-rendered HTML + vanilla JS/Alpine.js | No Node.js build step, CSS only |
| **Charts** | Chart.js (CDN) | Dashboard KPI trends |
| **Export** | PhpSpreadsheet + Dompdf (Composer) | Excel/PDF with Thai fonts |
| **Dependency Mgmt** | Composer | `composer install` required on production |

---

## Configuration (Before Deploying)

1. **Create `config/config.php`** (copy from `config/config.example.php`):
   ```php
   return [
       'app' => [
           'debug' => false,  // true only on dev
           'url' => 'https://www.medsci.up.ac.th/bpm',  // Adjust to your domain/path
       ],
       'db' => [
           'host' => 'localhost',
           'port' => 3306,
           'database' => 'bpm',
           'user' => 'bpm_app',
           'password' => 'your_secure_password',
       ],
       'sso' => [
           'client_id' => 'BPM',
           'client_secret' => 'your_secret_from_medsci_acc',
           'login_url' => 'https://www.medsci.up.ac.th/msc_acc/sso/login.php',
           'verify_url' => 'https://www.medsci.up.ac.th/msc_acc/api/verify.php',
           'logout_url' => 'https://www.medsci.up.ac.th/msc_acc/sso/logout.php',
           'redirect_uri' => 'https://www.medsci.up.ac.th/bpm/sso_callback.php',
       ],
       'sso_ssl_verify' => true,  // false only if you trust cert locally (dev)
       'session' => [
           'idle_timeout_seconds' => 1800,  // 30 mins
       ],
   ];
   ```

2. **Register with MEDSCI ACC** (one-time setup):
   - Go to `https://www.medsci.up.ac.th/msc_acc/admin/clients.php`
   - Create client: name="BPM", ID="BPM", redirect_uri must match exactly
   - Save the generated `client_secret` to `config/config.php`

3. **Database Initialization**:
   ```sql
   -- If manually (not Docker):
   mysql -h localhost -u root -p < sql/schema.sql
   -- This creates `bpm` database, tables, seed departments/fiscal_years
   ```

4. **Dependencies**:
   ```bash
   composer install
   # This pulls phpspreadsheet & dompdf into vendor/
   ```

---

## Common Tasks

### Adding a New Department

1. `admin/departments.php` → Add form
2. Form POSTs to `public/actions/` handler
3. Must happen before assigning line items or users to that dept

### Setting Up a New Fiscal Year

1. `admin/fiscal-years.php` → Click "New Fiscal Year"
2. Choose year (BE), system auto-calculates Oct 1 - Sep 30 date range
3. Status = `OPEN` by default
4. Optionally import line-item allocations from Excel

### Assigning Roles to New SSO User

1. User logs in via MEDSCI ACC SSO → gets auto-provisioned with `role = NULL`
2. Sees "Waiting for admin..." page
3. Admin goes to `admin/users.php` → finds user → assign role + department
4. User logs out/back in → sees dashboard with correct permissions

### Recording an Expense

1. `transactions.php` → "New Transaction"
2. Choose line item, enter amount, date, description
3. If line item has `requires_travel_detail = 1`, fill in instructor name + purpose
4. Submit → Form POSTs to `actions/create-transaction.php`
5. Server validates balance (can't overspend if not allowed)
6. Redirect back to transactions list with success message

### Approving a Budget Transfer

1. `transfers.php` → View pending transfers
2. Click "Approve" or "Reject"
3. Server moves status from `PENDING` → `APPROVED` or `REJECTED`
4. Balance recalculations happen automatically (via `bpm_line_item_balance()`)

---

## Important Notes

- **No passwords stored**: All auth via MEDSCI ACC, never store user passwords in BPM
- **Strict types**: Every PHP file has `declare(strict_types=1)` — catches type errors early
- **No magic numbers**: Hardcoded values (like Thai BE offset 543) live in functions (`src/lib/fiscal_year.php`)
- **Thai dates matter**: Everything converts to/from "Buddhist Era" (BE) for display, but DB stores Gregorian dates (Y-m-d) for math
- **Timezone is centralized**: Set once in `src/bootstrap.php` (`Asia/Bangkok`), not scattered across code
- **Session data is minimal**: Only `user_id` cached in `$_SESSION`, role/dept fetched fresh from DB every request (allows admin to revoke permissions instantly)

---

## Links & References

- **Spec (detailed)**: `spec.md` — full business rules, flow diagrams, data model rationale
- **SSO Integration**: `sso_integration_guide.md` (external, from MEDSCI ACC)
- **UI/UX Guidelines**: `BPM_UI_UX_GUIDELINES.md` (external)
- **API Docs**: MEDSCI ACC provides at `https://www.medsci.up.ac.th/msc_acc/docs/`

---

Generated: 2026-08-27
