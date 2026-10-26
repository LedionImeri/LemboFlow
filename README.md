# LemboFlow

> Invoicing, payments and revenue tracking for small businesses, with an AI assistant that answers questions from your own business data.

LemboFlow is a full-stack web application built with **plain PHP 8, MySQL/MariaDB and vanilla JavaScript**. It has no framework and no front-end build step, and its only Composer dependency is Dompdf. You can create professional invoices, record full or partial payments, track clients and products, export branded PDF invoices, and ask **LemboAI** questions like *"Which invoices are overdue?"*.

📄 **Sample output:** [`docs/sample-invoice.pdf`](docs/sample-invoice.pdf)

---

## ✨ Main Features

| Area | What it does |
|---|---|
| **Invoices** | Line items from your catalog or typed manually, tax rate per line, fixed or percentage discount, live totals, and automatic numbering (`INV-2026-0001`) |
| **Invoice lifecycle** | `draft` → `sent` → `paid` / `overdue` / `cancelled`. Overdue invoices are detected automatically. |
| **PDF export** | Branded A4 invoices with logo, payment details (IBAN, SWIFT) and a payment reference. DRAFT/CANCELLED watermarks, PAID/OVERDUE stamps, and multi-page support with repeated table headers. |
| **Payments** | Full or partial payments, "Mark as paid" for the remaining balance, and deleting a payment with automatic recalculation |
| **Clients** | Client records with billed, paid and outstanding totals plus their invoice history. A client with invoices can't be deleted, and the user is told why. |
| **Products & services** | Reusable catalog items with price, unit and tax rate. Items can be archived and restored. |
| **Dashboard** | KPI cards, a 12-month revenue chart (pure SVG), recent invoices, an activity feed and a first-run setup checklist |
| **LemboAI** | AI chat drawer that answers questions using your real invoice, payment and client data |
| **Live search** | AJAX search, filters and pagination on every list page |
| **Demo mode** | "View demo" creates a private, seeded demo account for each visitor, deleted automatically |
| **Responsive UI** | Mobile-first layouts: tables become cards, the sidebar becomes an off-canvas drawer, and the invoice total stays visible while you edit lines |

---

## 🛠 Tech Stack

| Layer | Technology |
|---|---|
| **Backend** | PHP 8.1+ (no framework), PDO |
| **Database** | MySQL 8 / MariaDB 10.4+ (InnoDB, utf8mb4) |
| **Frontend** | Vanilla JavaScript (ES2020), HTML5, CSS with design tokens |
| **PDF generation** | [Dompdf](https://github.com/dompdf/dompdf) 3.x via Composer |
| **AI** | [Groq API](https://groq.com) (OpenAI-compatible), default model `llama-3.3-70b-versatile` |
| **Charts & icons** | Hand-built SVG chart and a local SVG icon sprite (no CDN) |
| **Local environment** | XAMPP (Apache + MariaDB) |

---

## ⚙️ How It Works

- **Money is never a float.** All amounts are converted to integer cents and calculated with half-up rounding. PHP does this with integers and JavaScript with `BigInt`, so the live preview and the saved invoice always match. The server recalculates every total on save and never trusts totals sent by the browser.
- **Invoice numbers can't collide.** Numbers are reserved inside the same database transaction as the invoice, using an atomic `INSERT … ON DUPLICATE KEY UPDATE LAST_INSERT_ID(…)` sequence per user and year.
- **Revenue means money received.** Dashboard figures come from recorded payments, not invoice totals. A single function recalculates `amount_paid`, `payment_status` and the invoice status after every payment change.
- **Invoice lines are snapshots.** Product name, price and tax are copied onto each invoice, so editing a product never changes an issued invoice.
- **Overdue detection without cron.** Unpaid invoices past their due date are flagged whenever the dashboard, the invoice list or an invoice page loads.
- **Thin pages, central logic.** Page files validate input and render output. Business rules live in `src/`.

---

## 📁 Project Structure

```text
LemboFlow/
├── index.php              # Public landing page
├── auth/                  # Login, register, logout, demo
├── dashboard/             # KPIs, revenue chart, activity feed
├── clients/               # Client CRUD and client detail page
├── products/              # Products & services CRUD, archive
├── invoices/              # Invoice CRUD, status changes, PDF export
├── payments/              # Payment list, record and delete payments
├── settings/              # Business profile, account, password
├── api/                   # JSON endpoints (live search, chart, LemboAI)
├── src/                   # Business logic (invoices, payments, stats, pdf, lemboai, demo…)
├── includes/              # Layout, helpers, CSRF, authentication
├── templates/pdf/         # Invoice PDF template
├── assets/                # CSS, JavaScript, SVG icons and logo
├── config/                # Bootstrap, database connection, config template
├── database/              # SQL schema and demo seeder (CLI)
├── docs/                  # Sample invoice PDF
└── uploads/logos/         # Uploaded business logos
```

---

## 🚀 Installation & Setup

### Requirements

- PHP **8.1+** with these extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `gd`
- MySQL 8 or MariaDB 10.4+
- [Composer](https://getcomposer.org)
- Apache with `.htaccess` support (XAMPP works out of the box)

> **Note:** the `gd` extension is required for logos in PDFs. In XAMPP, open `php.ini`, change `;extension=gd` to `extension=gd`, then restart Apache.

### Steps

**1. Clone the repository** into your web root, for example `C:\xampp\htdocs`:

```bash
git clone https://github.com/LedionImeri/LemboFlow.git
cd LemboFlow
```

**2. Install dependencies:**

```bash
composer install
```

**3. Create the database** (see [Database Setup](#️-database-setup)).

**4. Create your local config:**

```bash
cp config/local.example.php config/local.php
```

**5. Start Apache and MySQL** in the XAMPP Control Panel and open:

```text
http://localhost/LemboFlow
```

---

## 🔧 Configuration

All settings are in `config/local.php`. This file is **gitignored and never committed**.

| Setting | Description |
|---|---|
| `app_url` | Full URL of the app, without a trailing slash (e.g. `http://localhost/LemboFlow`) |
| `app_name` | Application name |
| `debug` | `true` shows errors in the browser. Use `false` in production. |
| `timezone` | PHP and MySQL timezone (e.g. `Europe/Tirane`) |
| `db.host` / `db.port` / `db.name` / `db.user` / `db.password` | Database connection |
| `groq.api_key` | Your Groq API key (optional, for LemboAI) |
| `groq.model` | Groq model name |
| `groq.base_url` | OpenAI-compatible API endpoint (defaults to Groq) |

> ⚠️ Never commit `config/local.php` or share your API key.

---

## 🗄️ Database Setup

Import the schema with phpMyAdmin (**Import** → `database/lembo_flow.sql`) or from the command line:

```bash
mysql -u root -p < database/lembo_flow.sql
```

This creates the `lembo_flow` database with **9 InnoDB tables**:

| Table | Purpose |
|---|---|
| `users` | User accounts |
| `business_profiles` | Business details shown on invoices (1:1 with users) |
| `clients` | Customers |
| `products` | Products and services catalog |
| `invoice_sequences` | Atomic per-user, per-year invoice numbering |
| `invoices` | Invoice headers with stored totals |
| `invoice_items` | Invoice line snapshots |
| `payments` | Recorded payments (source of revenue) |
| `activities` | Activity feed |

The schema uses foreign keys, indexes and `CHECK` constraints. Clients are protected with `ON DELETE RESTRICT`, and deleted products are unlinked from invoice lines with `ON DELETE SET NULL`.

> ⚠️ Re-importing the SQL file **drops and recreates** all LemboFlow tables.

**Optional: demo data.** This creates a demo account with a year of sample data and prints a temporary login:

```bash
php database/seed_demo.php
```

---

## 🤖 AI Features: LemboAI

LemboAI is a chat assistant built into the app that answers questions about your business:

- *"How much did I earn this month?"*
- *"Which invoices are overdue?"*
- *"Who are my top clients?"*
- *"Compare this month with last month"*

**How it works:**

1. The browser sends the question to `api/lemboai.php`. The **API key never reaches the browser.**
2. PHP builds a compact, read-only summary of the user's own data: revenue, invoices by status, overdue and unpaid invoices, top clients and recent payments.
3. The summary and the question are sent to Groq, and the answer is shown in the chat.

**Built-in safeguards:**

- The model never accesses the database directly.
- The instructions limit answers to the provided data and treat user-entered text (client names, notes) as data only.
- Replies are HTML-escaped before rendering, so model output can't inject scripts.
- Conversation history is sanitized on the server.
- Rate limit of 20 questions per 10 minutes per session, and a 1,000-character limit per question.

> LemboAI is optional. Without a `groq.api_key` the rest of the app works normally and the chat says it isn't set up.

---

## 🔒 Security Features

- **User data isolation:** every query is scoped to the logged-in user. Requests for another user's records return `404`.
- **SQL injection protection:** PDO with real prepared statements (emulation disabled).
- **CSRF protection:** a token on every POST form and `fetch()` request. All state changes are POST-only.
- **XSS protection:** all output is escaped, plus a strict **Content-Security-Policy** (no inline scripts).
- **Security headers:** `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` and `Permissions-Policy`.
- **Password security:** `password_hash()` with automatic rehashing, generic login errors, and equal-time checks for unknown emails.
- **Login throttling:** after 5 failed attempts the login form is locked for 60 seconds.
- **Session security:** HttpOnly and SameSite cookies, a new session ID on login and password change, and a 2-hour idle timeout.
- **Sensitive changes:** changing your email requires your current password.
- **File uploads:** the logo type is checked from the file's contents (PNG/JPG/WebP, max 2 MB), stored under a random name, and PHP execution is disabled in `uploads/`.
- **PDF rendering:** Dompdf can't load remote resources or run PHP, and is restricted to the project folder.
- **Protected directories:** `config/`, `includes/`, `src/`, `database/` and `templates/` can't be accessed by URL.
- **Demo isolation:** each demo visitor gets a private account, deleted on logout or after 24 hours, with rate limits on starting demos.

---

## 🔮 Future Improvements

- Freeze client details (address, VAT) on issued invoices
- Multi-currency support with conversion
- Per-IP login throttling stored in the database
- Translations (multi-language UI)
- Sending invoices by email directly from the app
- Recurring invoices
- Automated test suite (PHPUnit)
- CSV / Excel export of invoices and payments

---

## 👤 Author

**Ledion Imeri**

- GitHub: [github.com/LedionImeri](https://github.com/LedionImeri)
- LinkedIn: [linkedin.com/in/your-profile](https://linkedin.com/in/your-profile)
- Instagram: [instagram.com/your-handle](https://instagram.com/your-handle)

---

<p align="center">Built with PHP, MySQL and vanilla JavaScript.</p>
