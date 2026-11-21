# New Era Trading (neweratrading.net)

Plain HTML + CSS + JavaScript front end talking to a **Laravel 12 (PHP 8.2) JSON API** with a **MariaDB / MySQL** database.
All money rules run on the server (bcmath decimals, database transactions); the browser only displays them.

**Run it on this PC:** double-click `start-backend.bat`, then open `index.html` (or `login.html`) in a browser.

## Demo accounts (development only)
`php artisan migrate:fresh --seed` creates a demo network (a demo member, an admin and 12 team members). **No password is stored in the code.**
Put your own in `backend/.env` before seeding (`DEMO_MODE=true` shows them on the login page of that demo server):
```
DEMO_MODE=true
DEMO_MEMBER_PASSWORD=choose-one-1
DEMO_ADMIN_PASSWORD=choose-another-2
DEMO_PIN=654321
```
Any that is missing is generated randomly and printed once at the end of the seeding. A live server is installed with `php artisan app:install` and has no demo accounts.

## Structure
```
index.html, login.html, register.html, forgot.html     public site
user/dashboard.html                                    member hub (single page app, 28 pages: wallet, team, income, KYC ...)
admin/                                                 dashboard, members, deposits, payouts, KYC, tickets, audit log, plan settings

css/        design (base, site, panel, member)
js/data.js  API client (Store): reads a snapshot from /api/bootstrap, writes return Promises
js/app.js   admin layout + UI helpers          js/member/*  member hub (shell, kit, pages-*)

backend/    Laravel app
  app/Services/   PlanEngine (invest, ROI, direct / level income, caps) - WalletService (deposits, transfers, withdrawals)
                  OtpService, Notifier (e-mails), ChainVerifier (blockchain deposit check), Snapshot (JSON for the front end), Market (trading window)
  app/Console/Commands/   app:install, app:check-production, app:backup, app:restore, app:test-mail
  database/migrations/    schema          tests/   verify.bat (logic tests), loadtest.php
deploy/build-release.ps1   builds the upload package (see DEPLOY.md)
.tools/db-data             this project's own MariaDB data folder (port 3307) for local development
```

## Income plan (rules in force)
Source: **New_Era_Trading_Guide.pdf** (the "proper" PDF) and the company spreadsheet (simple interest / compounding calculators).
Currency is **USD ($)**. Every value is editable in **Admin -> Plan Settings** (timezone Asia/Kolkata).

| Rule | Value |
|---|---|
| Joining / retopup | minimum **$5**, only in multiples of **$5** (5, 10, 15, 20, 25 ...) |
| ROI | **5% per trading day** (Mon-Fri) on each package, for **25 trading days** (the cycle), then the package expires |
| Direct referral | **5%** of the referred member's joining amount, and **5% again on every later investment** (retopup income). Unlimited directs. |
| Level income (12 levels) | paid on the **invested amount**: **L1 2%, L2 1%, L3-L12 0.2% each** (5% in total). Example: 100 members on level 1 each investing $5 = $10. |
| Level unlock | level *k* is paid only when the upline has at least *k* active direct referrals; all 12 levels need **12 directs** |
| Earning cap | **2X** of own investment (non-working). **5X (working)** when the member's **level-1 business** (what their direct referrals invested) reaches **5 x their own investment** |
| Withdrawal | minimum **$5**, multiples of **$5**; **10:00 AM - 2:00 PM IST, Monday-Friday only**; received within 24 hours; **10% withdrawal charge** (from the spreadsheet) |
| One account per person | one user per **mobile number** (last 10 digits compared) and per **e-mail**; a match refuses registration |

### Simple interest and compounding (the spreadsheet)
`js/calc.js` is the one implementation of the spreadsheet maths (public page `calculator.html`, member hub *Wallet -> Calculator*, *Admin -> Calculator*
with the company view: system deposit and liability). Model: every amount that earns is a **lot** that earns 5% per day for 25 days and then expires.
* **Simple interest** - the 5% is paid out as cash: $100 -> 25 x $5 = **$125** (**$112.50** after the 10% charge).
* **Compounding** - each day's interest becomes a new lot: $100 x 1.05^25 = **$338.64**; after the deposit's cycle expires **$238.64** remains.
* **After 25 days** the balance either pays its daily 5% as cash (*withdraw*) or keeps compounding; the same after 50 days.
`backend/tests/calc.test.cjs` checks every number against the sheet. The three "after 25 / 50 days" tabs of the sheet contain one typing slip
(the expiry of day 34 is typed 7.76, the right value is 7.39), so their totals differ slightly: 184.66 (sheet 181.42), 404.97 (sheet 408.28), 285.90 (sheet 290.92).

**Live engine:** packages pay **simple interest** (cash ROI for 25 trading days). Compounding is available as the calculator and, in the engine,
as a manual re-invest from the income wallet; fully automatic compounding lots are not built yet.

## Security & go-live features
| Feature | How it works |
|---|---|
| E-mail OTP | 6 digits, stored hashed, valid 10 min, 5 attempts, 1 new code per 60 s. Used for: password change, **forgot password** (`forgot.html`), **e-mail verification** |
| Welcome / withdrawal mails | registration, withdrawal request, approval / rejection (with the payout transaction hash), KYC result, password changed / reset |
| Withdrawal gate | needs transaction PIN **+ verified e-mail + approved KYC** (switchable in Admin -> Plan Settings) and the Mon-Fri 10:00-14:00 IST window |
| KYC | member uploads ID front/back + selfie (JPG/PNG/PDF, 4 MB each); stored outside the web folder; only admins can view (token needed), every view is logged; approve / reject with a reason |
| Audit log | sign-ins (also failed), registrations, PIN / password changes, every money request and every admin action, with IP; passwords / PINs / codes are never stored. Admin -> Audit Log |
| Deposits | unique transaction hash; with `ETHERSCAN_API_KEY` each hash is verified on the blockchain (USDT to the company address, amount); optional auto-credit |
| Payouts | admin sends USDT from the company wallet, then approves and pastes the payout hash (shown to the member). No wallet key is ever on the server |
| Login protection | 5 wrong passwords -> 15 minute lock (per e-mail + IP), tokens expire after 7 days, reset signs out every device |
| Backups | nightly `app:backup` (database + KYC files), keeps the newest 14, `app:restore` to a spare database to test |
| Demo vs live | `DEMO_MODE=true` (development): demo logins, simulated joining payment, reset button. `DEMO_MODE=false` (live): none of them; money enters only through verified deposits |

Sender of every mail: `New Era Trading <no-reply@neweratrading.net>`. In development `MAIL_MAILER=log` writes mails (and OTPs) to
`backend/storage/logs/laravel.log`; for real delivery see **DEPLOY.md** (SMTP + SPF/DKIM/DMARC).

## Everyday commands (run in `backend/`)
| Command | Purpose |
|---|---|
| `php artisan app:install --admin-email=... --deposit-address=0x...` | first-time live setup: tables + admin + company root `NET1001`, no demo data |
| `php artisan app:check-production` | go-live checklist (PASS / WARN / FAIL), exit code 1 on any FAIL |
| `php artisan app:test-mail you@gmail.com` | send one real test mail to check SMTP |
| `php artisan app:backup` / `app:restore <file>` | backup / restore |
| `php artisan net:accrue` / `net:verify-deposits` | ROI credit / blockchain re-check (both run from the scheduler) |

## Tests
* `backend\tests\verify.bat` - 151 checks of the plan rules, OTP, deposit verification, payouts, audit and backups on a throw-away SQLite database (your real data is never touched).
* `backend\tests\loadtest.php` - load test (see DEPLOY.md); `backend\tests\seed_bulk.php` adds fake members to a `*load*` database for it.

## Going live
Follow **DEPLOY.md** step by step (`deploy\build-release.ps1` builds the upload package). Still on you: a lawyer's review of the plan,
Terms / Privacy pages, server firewall, off-server backup copies, and a monitored support mailbox.

## Local development database
`start-backend.bat` runs this project's **own** MariaDB on port **3307** (data in `.tools\db-data`), separate from XAMPP's MySQL on 3306,
so the two can never damage each other. Connection settings are in `backend/.env`.

## Brand & theme
- **Name:** New Era Trading — neweratrading.net (member IDs use the `NET` prefix, e.g. `NET1001`).
- **Logo:** `images/logo.png` (transparent, 512px), `images/logo-128.png`, favicons `images/favicon.ico|png`. The untouched original is `images/logo-original.png`.
- **Palette** (taken from the logo): gold `#ffc933 → #f59e0b`, navy `#040c24 / #061230`, royal blue `#0f3d9e / #1e6cff`, silver text `#eaf1ff`, candle green `#22c55e` / red `#ef4444`.
  - Home page tokens live in `:root` of `css/home.css`; panels/auth pages in `css/base.css`.
- **Fonts:** self-hosted variable fonts in `fonts/` (loaded by `css/fonts.css`, no external requests) — **Exo 2** (upright + italic, matches the slanted NET logo) for headings, numbers and the brand, **Manrope** for body and UI text. Tokens: `--font-head`, `--font-body`.

## Member hub (`user/dashboard.html`)
A single-page app (hash routes) with a top navigation + mega menus, bento dashboard and mobile tab bar.

```
user/dashboard.html          app shell (loads the scripts below)
css/member.css               member hub design system
js/member/kit.js             icons, data tables (search · date range · paging · totals · CSV), modals, PIN prompt, SVG charts
js/member/shell.js           menu definition, router, top bar, notifications, drawer, tab bar
js/member/pages-dashboard.js dashboard
js/member/pages-network.js   team report · team level report · binary tree
js/member/pages-wallet.js    deposit USDT · deposit history · purchase package · purchased history · FD history · wallet statement · wallet transfer
js/member/pages-money.js     10 income reports + all income statement · withdrawal · history · self transfer · transfer history · deductions
js/member/pages-account.js   profile · password · txn PIN · downloads · open ticket · ticket history
```
Add a page: register it with `M.page('/group/name', { title, render(el, ctx) })` and add a row to `M.MENU` in `shell.js`.

Money flow: **deposit (admin approves) → purchase wallet → package → income wallet → withdraw / transfer**.
Engine-driven income: direct, level, binary, daily reward (ROI), ROI on ROI, sign-up bonus. FD staking, salary,
withdrawal bonus and rewards have pages and ledger types but no automatic payouts yet.
Admin gained **Deposits** and **Tickets** pages, plus new plan settings and announcements in Plan Settings.
