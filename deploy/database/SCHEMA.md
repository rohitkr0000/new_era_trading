# New Era Trading — database structure

Database `neweratrading` (10.4.32-MariaDB-log), generated 2026-11-21 05:46:05 by `php artisan app:db-schema`.

Money columns are `DECIMAL(18,2)` — never floats. Every balance change is written to a ledger table. Timestamps are stored in UTC.

## Tables

| Table | Purpose | Engine |
|---|---|---|
| `announcements` | Dashboard announcements. | InnoDB |
| `audit_logs` | Append-only trail of sign-ins, money requests and admin actions with IP. | InnoDB |
| `cache` | Laravel cache (database store). | InnoDB |
| `cache_locks` | Cache locks. | InnoDB |
| `compound_ledger` | Compounding: the daily schedule per member (opening, interest, expiring, closing). Unique per member and day = idempotent daily job. | InnoDB |
| `compound_lots` | Compounding: each live amount (the purchased amount and every day's interest) with its remaining earning days. | InnoDB |
| `deductions` | Company charges taken from members (withdrawal service charge, P2P charge). | InnoDB |
| `deposits` | USDT deposits submitted by members (txn_id is unique) with the blockchain-check result. | InnoDB |
| `failed_jobs` | Failed queue jobs. | InnoDB |
| `jobs` | Queue. | InnoDB |
| `job_batches` | Queue batches. | InnoDB |
| `kyc_submissions` | KYC documents (files live outside the web root) and the admin decision. | InnoDB |
| `ledger` | Income-wallet ledger: every credit and debit. A balance always equals the sum of its ledger rows. | InnoDB |
| `migrations` | Which migrations have run. | InnoDB |
| `orders` | Packages bought (joining / retopup). option = Investment (simple interest) or Compounding. | InnoDB |
| `otps` | E-mailed one-time codes: stored hashed, expiring, attempt-limited and bound to one request (context). | InnoDB |
| `password_reset_tokens` | Unused (password reset uses otps). | InnoDB |
| `personal_access_tokens` | Login tokens (hashed), with expiry. | InnoDB |
| `sessions` | Unused by the API (token auth). | InnoDB |
| `settings` | Plan settings overrides edited in Admin -> Plan Settings. | InnoDB |
| `tickets` | Support tickets. | InnoDB |
| `ticket_replies` | Replies on tickets. | InnoDB |
| `transfers` | P2P and own-wallet transfers with the company charge (fee / net). | InnoDB |
| `users` | Every account: members and admins. Money balances (wallet = income wallet, purchase_wallet), sponsor / binary placement, KYC state, compounding mode. | InnoDB |
| `wallet_ledger` | Purchase-wallet ledger (deposits, package purchases, P2P in/out) with running balance. | InnoDB |
| `withdrawals` | Withdrawal requests, fee / net, payout transaction hash set by the admin. | InnoDB |

## `announcements`

Dashboard announcements.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `text` | text | no |  |  |
| `created_at` | datetime | no |  |  |

**Indexes:** `PRIMARY` (id) unique

## `audit_logs`

Append-only trail of sign-ins, money requests and admin actions with IP.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `actor_id` | bigint(20) unsigned | yes | `NULL` | MUL |
| `actor` | varchar(160) | yes | `NULL` |  |
| `action` | varchar(60) | no |  | MUL |
| `target` | varchar(80) | yes | `NULL` |  |
| `meta` | text | yes | `NULL` |  |
| `ip` | varchar(45) | yes | `NULL` |  |
| `created_at` | datetime | yes | `NULL` | MUL |

**Indexes:** `audit_logs_action_index` (action) · `audit_logs_actor_id_index` (actor_id) · `audit_logs_created_at_index` (created_at) · `PRIMARY` (id) unique

## `cache`

Laravel cache (database store).

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `key` | varchar(255) | no |  | PRI |
| `value` | mediumtext | no |  |  |
| `expiration` | int(11) | no |  | MUL |

**Indexes:** `cache_expiration_index` (expiration) · `PRIMARY` (key) unique

## `cache_locks`

Cache locks.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `key` | varchar(255) | no |  | PRI |
| `owner` | varchar(255) | no |  |  |
| `expiration` | int(11) | no |  | MUL |

**Indexes:** `cache_locks_expiration_index` (expiration) · `PRIMARY` (key) unique

## `compound_ledger`

Compounding: the daily schedule per member (opening, interest, expiring, closing). Unique per member and day = idempotent daily job.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `user_id` | bigint(20) unsigned | no |  | MUL |
| `day` | date | no |  |  |
| `mode` | varchar(8) | no |  |  |
| `opening` | decimal(18,2) | no |  |  |
| `interest` | decimal(18,2) | no |  |  |
| `expiring` | decimal(18,2) | no | `0.00` |  |
| `closing` | decimal(18,2) | no |  |  |

**Indexes:** `compound_ledger_user_id_day_unique` (user_id,day) unique · `PRIMARY` (id) unique

**Foreign keys:** `user_id` → `users.id`

## `compound_lots`

Compounding: each live amount (the purchased amount and every day's interest) with its remaining earning days.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `user_id` | bigint(20) unsigned | no |  | MUL |
| `order_id` | bigint(20) unsigned | yes | `NULL` | MUL |
| `amount` | decimal(18,2) | no |  |  |
| `is_principal` | tinyint(1) | no | `0` |  |
| `created_on` | date | no |  |  |
| `days_left` | smallint(5) unsigned | no |  |  |

**Indexes:** `compound_lots_order_id_foreign` (order_id) · `compound_lots_user_id_days_left_index` (user_id,days_left) · `PRIMARY` (id) unique

**Foreign keys:** `order_id` → `orders.id` · `user_id` → `users.id`

## `deductions`

Company charges taken from members (withdrawal service charge, P2P charge).

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `user_id` | bigint(20) unsigned | no |  | MUL |
| `withdrawal_id` | bigint(20) unsigned | yes | `NULL` | MUL |
| `note` | varchar(255) | no |  |  |
| `amount` | decimal(18,2) | no |  |  |
| `status` | varchar(10) | no | `'Deducted'` |  |
| `created_at` | datetime | no |  |  |

**Indexes:** `deductions_user_id_foreign` (user_id) · `deductions_withdrawal_id_foreign` (withdrawal_id) · `PRIMARY` (id) unique

**Foreign keys:** `user_id` → `users.id` · `withdrawal_id` → `withdrawals.id`

## `deposits`

USDT deposits submitted by members (txn_id is unique) with the blockchain-check result.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `user_id` | bigint(20) unsigned | no |  | MUL |
| `amount` | decimal(18,2) | no |  |  |
| `txn_id` | varchar(120) | no |  | UNI |
| `network` | varchar(60) | no |  |  |
| `status` | varchar(10) | no | `'pending'` | MUL |
| `processed_at` | datetime | yes | `NULL` |  |
| `created_at` | datetime | no |  | MUL |
| `verified` | varchar(12) | yes | `NULL` |  |
| `verify_note` | varchar(255) | yes | `NULL` |  |
| `chain_amount` | decimal(18,2) | yes | `NULL` |  |

**Indexes:** `deposits_created_at_index` (created_at) · `deposits_status_index` (status) · `deposits_txn_id_unique` (txn_id) unique · `deposits_user_id_foreign` (user_id) · `PRIMARY` (id) unique

**Foreign keys:** `user_id` → `users.id`

## `failed_jobs`

Failed queue jobs.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `uuid` | varchar(255) | no |  | UNI |
| `connection` | text | no |  |  |
| `queue` | text | no |  |  |
| `payload` | longtext | no |  |  |
| `exception` | longtext | no |  |  |
| `failed_at` | timestamp | no | `current_timestamp()` |  |

**Indexes:** `failed_jobs_uuid_unique` (uuid) unique · `PRIMARY` (id) unique

## `jobs`

Queue.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `queue` | varchar(255) | no |  | MUL |
| `payload` | longtext | no |  |  |
| `attempts` | tinyint(3) unsigned | no |  |  |
| `reserved_at` | int(10) unsigned | yes | `NULL` |  |
| `available_at` | int(10) unsigned | no |  |  |
| `created_at` | int(10) unsigned | no |  |  |

**Indexes:** `jobs_queue_index` (queue) · `PRIMARY` (id) unique

## `job_batches`

Queue batches.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | varchar(255) | no |  | PRI |
| `name` | varchar(255) | no |  |  |
| `total_jobs` | int(11) | no |  |  |
| `pending_jobs` | int(11) | no |  |  |
| `failed_jobs` | int(11) | no |  |  |
| `failed_job_ids` | longtext | no |  |  |
| `options` | mediumtext | yes | `NULL` |  |
| `cancelled_at` | int(11) | yes | `NULL` |  |
| `created_at` | int(11) | no |  |  |
| `finished_at` | int(11) | yes | `NULL` |  |

**Indexes:** `PRIMARY` (id) unique

## `kyc_submissions`

KYC documents (files live outside the web root) and the admin decision.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `user_id` | bigint(20) unsigned | no |  | MUL |
| `doc_type` | varchar(30) | no |  |  |
| `doc_number` | varchar(60) | no |  |  |
| `front_path` | varchar(255) | no |  |  |
| `back_path` | varchar(255) | yes | `NULL` |  |
| `selfie_path` | varchar(255) | no |  |  |
| `status` | varchar(10) | no | `'pending'` | MUL |
| `note` | varchar(255) | yes | `NULL` |  |
| `reviewed_by` | bigint(20) unsigned | yes | `NULL` | MUL |
| `reviewed_at` | datetime | yes | `NULL` |  |
| `created_at` | datetime | yes | `NULL` |  |

**Indexes:** `kyc_submissions_reviewed_by_foreign` (reviewed_by) · `kyc_submissions_status_index` (status) · `kyc_submissions_user_id_foreign` (user_id) · `PRIMARY` (id) unique

**Foreign keys:** `reviewed_by` → `users.id` · `user_id` → `users.id`

## `ledger`

Income-wallet ledger: every credit and debit. A balance always equals the sum of its ledger rows.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `user_id` | bigint(20) unsigned | no |  | MUL |
| `type` | varchar(12) | no |  | MUL |
| `amount` | decimal(18,2) | no |  |  |
| `note` | varchar(255) | no |  |  |
| `from_id` | bigint(20) unsigned | yes | `NULL` | MUL |
| `order_id` | bigint(20) unsigned | yes | `NULL` | MUL |
| `created_at` | datetime | no |  | MUL |

**Indexes:** `ledger_created_at_index` (created_at) · `ledger_from_id_foreign` (from_id) · `ledger_order_id_foreign` (order_id) · `ledger_type_index` (type) · `ledger_user_id_created_at_index` (user_id,created_at) · `ledger_user_id_type_index` (user_id,type) · `PRIMARY` (id) unique

**Foreign keys:** `from_id` → `users.id` · `order_id` → `orders.id` · `user_id` → `users.id`

## `migrations`

Which migrations have run.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | int(10) unsigned | no |  | PRI auto_increment |
| `migration` | varchar(255) | no |  |  |
| `batch` | int(11) | no |  |  |

**Indexes:** `PRIMARY` (id) unique

## `orders`

Packages bought (joining / retopup). option = Investment (simple interest) or Compounding.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `ref` | varchar(20) | no |  | UNI |
| `user_id` | bigint(20) unsigned | no |  | MUL |
| `amount` | decimal(18,2) | no |  |  |
| `kind` | varchar(10) | no |  |  |
| `option` | varchar(12) | no |  | MUL |
| `pct` | decimal(6,3) | no |  |  |
| `days` | int(10) unsigned | no | `0` |  |
| `steps` | int(10) unsigned | no | `0` |  |
| `paid_days` | int(10) unsigned | no | `0` |  |
| `status` | varchar(10) | no | `'running'` | MUL |
| `created_at` | datetime | no |  | MUL |

**Indexes:** `orders_created_at_index` (created_at) · `orders_option_status_index` (option,status) · `orders_ref_unique` (ref) unique · `orders_status_index` (status) · `orders_user_id_foreign` (user_id) · `PRIMARY` (id) unique

**Foreign keys:** `user_id` → `users.id`

## `otps`

E-mailed one-time codes: stored hashed, expiring, attempt-limited and bound to one request (context).

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `user_id` | bigint(20) unsigned | no |  | MUL |
| `purpose` | varchar(20) | no |  |  |
| `code_hash` | varchar(255) | no |  |  |
| `attempts` | tinyint(3) unsigned | no | `0` |  |
| `expires_at` | datetime | no |  |  |
| `created_at` | datetime | no |  |  |
| `context` | varchar(64) | yes | `NULL` |  |

**Indexes:** `otps_user_id_purpose_index` (user_id,purpose) · `PRIMARY` (id) unique

**Foreign keys:** `user_id` → `users.id`

## `password_reset_tokens`

Unused (password reset uses otps).

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `email` | varchar(255) | no |  | PRI |
| `token` | varchar(255) | no |  |  |
| `created_at` | timestamp | yes | `NULL` |  |

**Indexes:** `PRIMARY` (email) unique

## `personal_access_tokens`

Login tokens (hashed), with expiry.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `tokenable_type` | varchar(255) | no |  | MUL |
| `tokenable_id` | bigint(20) unsigned | no |  |  |
| `name` | text | no |  |  |
| `token` | varchar(64) | no |  | UNI |
| `abilities` | text | yes | `NULL` |  |
| `last_used_at` | timestamp | yes | `NULL` |  |
| `expires_at` | timestamp | yes | `NULL` | MUL |
| `created_at` | timestamp | yes | `NULL` |  |
| `updated_at` | timestamp | yes | `NULL` |  |

**Indexes:** `personal_access_tokens_expires_at_index` (expires_at) · `personal_access_tokens_tokenable_type_tokenable_id_index` (tokenable_type,tokenable_id) · `personal_access_tokens_token_unique` (token) unique · `PRIMARY` (id) unique

## `sessions`

Unused by the API (token auth).

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | varchar(255) | no |  | PRI |
| `user_id` | bigint(20) unsigned | yes | `NULL` | MUL |
| `ip_address` | varchar(45) | yes | `NULL` |  |
| `user_agent` | text | yes | `NULL` |  |
| `payload` | longtext | no |  |  |
| `last_activity` | int(11) | no |  | MUL |

**Indexes:** `PRIMARY` (id) unique · `sessions_last_activity_index` (last_activity) · `sessions_user_id_index` (user_id)

## `settings`

Plan settings overrides edited in Admin -> Plan Settings.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `key` | varchar(60) | no |  | PRI |
| `value` | longtext | no |  |  |

**Indexes:** `PRIMARY` (key) unique

## `tickets`

Support tickets.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `ref` | varchar(20) | no |  | UNI |
| `user_id` | bigint(20) unsigned | no |  | MUL |
| `subject` | varchar(160) | no |  |  |
| `message` | text | no |  |  |
| `status` | varchar(8) | no | `'open'` | MUL |
| `created_at` | datetime | no |  |  |

**Indexes:** `PRIMARY` (id) unique · `tickets_ref_unique` (ref) unique · `tickets_status_index` (status) · `tickets_user_id_foreign` (user_id)

**Foreign keys:** `user_id` → `users.id`

## `ticket_replies`

Replies on tickets.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `ticket_id` | bigint(20) unsigned | no |  | MUL |
| `by` | varchar(6) | no |  |  |
| `text` | text | no |  |  |
| `created_at` | datetime | no |  |  |

**Indexes:** `PRIMARY` (id) unique · `ticket_replies_ticket_id_foreign` (ticket_id)

**Foreign keys:** `ticket_id` → `tickets.id`

## `transfers`

P2P and own-wallet transfers with the company charge (fee / net).

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `kind` | varchar(8) | no |  |  |
| `from_id` | bigint(20) unsigned | no |  | MUL |
| `to_id` | bigint(20) unsigned | no |  | MUL |
| `amount` | decimal(18,2) | no |  |  |
| `created_at` | datetime | no |  | MUL |
| `fee` | decimal(18,2) | no | `0.00` |  |
| `net` | decimal(18,2) | yes | `NULL` |  |

**Indexes:** `PRIMARY` (id) unique · `transfers_created_at_index` (created_at) · `transfers_from_id_foreign` (from_id) · `transfers_to_id_foreign` (to_id)

**Foreign keys:** `from_id` → `users.id` · `to_id` → `users.id`

## `users`

Every account: members and admins. Money balances (wallet = income wallet, purchase_wallet), sponsor / binary placement, KYC state, compounding mode.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `code` | varchar(20) | yes | `NULL` | UNI |
| `role` | varchar(10) | no | `'member'` | MUL |
| `name` | varchar(255) | no |  |  |
| `email` | varchar(255) | no |  | UNI |
| `email_verified_at` | timestamp | yes | `NULL` |  |
| `password` | varchar(255) | no |  |  |
| `remember_token` | varchar(100) | yes | `NULL` |  |
| `created_at` | timestamp | yes | `NULL` |  |
| `updated_at` | timestamp | yes | `NULL` |  |
| `phone` | varchar(30) | yes | `NULL` |  |
| `phone_key` | varchar(20) | yes | `NULL` | UNI |
| `country` | varchar(60) | yes | `NULL` |  |
| `city` | varchar(80) | yes | `NULL` |  |
| `usdt_address` | varchar(120) | yes | `NULL` |  |
| `txn_pin` | varchar(255) | yes | `NULL` |  |
| `blocked` | tinyint(1) | no | `0` |  |
| `sponsor_id` | bigint(20) unsigned | yes | `NULL` | MUL |
| `parent_id` | bigint(20) unsigned | yes | `NULL` | MUL |
| `leg` | varchar(5) | yes | `NULL` |  |
| `left_id` | bigint(20) unsigned | yes | `NULL` | MUL |
| `right_id` | bigint(20) unsigned | yes | `NULL` | MUL |
| `left_bv` | decimal(18,2) | no | `0.00` |  |
| `right_bv` | decimal(18,2) | no | `0.00` |  |
| `invested` | decimal(18,2) | no | `0.00` |  |
| `wallet` | decimal(18,2) | no | `0.00` |  |
| `purchase_wallet` | decimal(18,2) | no | `0.00` |  |
| `joined_at` | datetime | yes | `NULL` |  |
| `kyc_status` | varchar(10) | no | `'none'` | MUL |
| `kyc_note` | varchar(255) | yes | `NULL` |  |
| `last_login_at` | datetime | yes | `NULL` |  |
| `last_login_ip` | varchar(45) | yes | `NULL` |  |
| `compound_mode` | varchar(8) | no | `'compound'` |  |
| `compound_through` | date | yes | `NULL` |  |

**Indexes:** `PRIMARY` (id) unique · `users_code_unique` (code) unique · `users_email_unique` (email) unique · `users_kyc_status_index` (kyc_status) · `users_left_id_foreign` (left_id) · `users_parent_id_index` (parent_id) · `users_phone_key_unique` (phone_key) unique · `users_right_id_foreign` (right_id) · `users_role_index` (role) · `users_sponsor_id_index` (sponsor_id)

**Foreign keys:** `left_id` → `users.id` · `parent_id` → `users.id` · `right_id` → `users.id` · `sponsor_id` → `users.id`

## `wallet_ledger`

Purchase-wallet ledger (deposits, package purchases, P2P in/out) with running balance.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `user_id` | bigint(20) unsigned | no |  | MUL |
| `type` | varchar(14) | no |  |  |
| `amount` | decimal(18,2) | no |  |  |
| `balance` | decimal(18,2) | no |  |  |
| `note` | varchar(255) | no |  |  |
| `created_at` | datetime | no |  |  |

**Indexes:** `PRIMARY` (id) unique · `wallet_ledger_user_id_foreign` (user_id)

**Foreign keys:** `user_id` → `users.id`

## `withdrawals`

Withdrawal requests, fee / net, payout transaction hash set by the admin.

| Column | Type | Null | Default | Key / extra |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no |  | PRI auto_increment |
| `ref` | varchar(20) | no |  | UNI |
| `user_id` | bigint(20) unsigned | no |  | MUL |
| `amount` | decimal(18,2) | no |  |  |
| `fee` | decimal(18,2) | no | `0.00` |  |
| `net` | decimal(18,2) | no |  |  |
| `method` | varchar(30) | no |  |  |
| `account` | varchar(160) | no |  |  |
| `status` | varchar(10) | no | `'pending'` | MUL |
| `due_at` | datetime | yes | `NULL` |  |
| `processed_at` | datetime | yes | `NULL` |  |
| `created_at` | datetime | no |  | MUL |
| `payout_txid` | varchar(120) | yes | `NULL` |  |
| `admin_note` | varchar(255) | yes | `NULL` |  |

**Indexes:** `PRIMARY` (id) unique · `withdrawals_created_at_index` (created_at) · `withdrawals_ref_unique` (ref) unique · `withdrawals_status_index` (status) · `withdrawals_user_id_foreign` (user_id)

**Foreign keys:** `user_id` → `users.id`

