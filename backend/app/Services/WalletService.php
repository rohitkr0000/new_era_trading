<?php

namespace App\Services;

use App\Models\User;
use App\Support\Money;
use App\Support\PlanException;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Money movement: USDT deposits → purchase wallet, transfers, withdrawals (window + $5 multiples).
 */
class WalletService
{
    public function __construct(private PlanEngine $plan) {}

    /* ---------------- deposits ---------------- */

    public function requestDeposit(User $u, $amount, string $txnId): int
    {
        $s = Settings::all();
        $amount = Money::r($amount);
        $txnId = trim($txnId);
        if (Money::cmp($amount, $s['minDeposit']) < 0) throw new PlanException('Minimum deposit is ' . $s['currency'] . $s['minDeposit'] . '.');
        if (strlen($txnId) < 10) throw new PlanException('Enter a valid transaction hash / ID.');
        if (DB::table('deposits')->whereRaw('LOWER(txn_id) = ?', [strtolower($txnId)])->exists()) throw new PlanException('This transaction ID was already submitted.');
        try {
            $id = DB::table('deposits')->insertGetId(['user_id' => $u->id, 'amount' => $amount, 'txn_id' => $txnId, 'network' => $s['depositNetwork'], 'status' => 'pending', 'created_at' => Carbon::now()]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {   // two requests racing with the same hash
            throw new PlanException('This transaction ID was already submitted.');
        }
        $this->verifyDeposit($id);
        return $id;
    }

    /**
     * Check a pending deposit on the blockchain (when ETHERSCAN_API_KEY is set) and store the result.
     * With the setting "autoApproveDeposits" a fully verified deposit is credited straight away.
     * A deposit is only auto-credited when the chain confirms at least the claimed amount.
     */
    public function verifyDeposit(int $id): ?array
    {
        $d = DB::table('deposits')->where('id', $id)->first();
        if (! $d || $d->status !== 'pending' || ! ChainVerifier::enabled()) return null;
        $s = Settings::all();
        $r = ChainVerifier::check($d->txn_id, $d->amount, $s['depositAddress']);
        DB::table('deposits')->where('id', $id)->update(['verified' => $r['status'], 'verify_note' => mb_substr($r['note'], 0, 255), 'chain_amount' => $r['amount']]);
        if ($r['status'] === 'verified' && ! empty($s['autoApproveDeposits'])) {
            // A transaction hash is public: anyone could submit somebody else's. Only auto-credit when the sending wallet is the
            // member's own registered wallet; otherwise leave it for an admin, who sees the sender address in the note.
            $own = strtolower(trim((string) User::where('id', $d->user_id)->value('usdt_address')));
            if ($own === '' || $own !== strtolower((string) $r['from'])) {
                DB::table('deposits')->where('id', $id)->update(['verify_note' => mb_substr($r['note'] . ' Not auto-credited: the sender is not the wallet saved in this member\'s profile — check it, then approve by hand.', 0, 255)]);
                return $r;
            }
            try {
                $this->processDeposit($id, 'approved');
                \App\Support\Audit::log('deposit.auto-approved', null, 'deposit#' . $id, ['amount' => $d->amount, 'txn' => $d->txn_id]);
            } catch (PlanException $e) { /* already handled by an admin */ }
        }
        return $r;
    }

    public function processDeposit(int $id, string $status): void
    {
        DB::transaction(function () use ($id, $status) {
            $d = DB::table('deposits')->where('id', $id)->lockForUpdate()->first();
            if (! $d || $d->status !== 'pending') throw new PlanException('Deposit already processed.');
            DB::table('deposits')->where('id', $id)->update(['status' => $status, 'processed_at' => Carbon::now()]);
            Snapshot::forget((int) $d->user_id);
            if ($status === 'approved') {
                $u = User::lockForUpdate()->findOrFail($d->user_id);
                $this->plan->walletMove($u, 'deposit', $d->amount, 'USDT deposit ' . substr($d->txn_id, 0, 10) . '…');
            }
        });
    }

    /* ---------------- transfers ---------------- */

    public function transferToPurchase(User $member, $amount): void
    {
        $amount = Money::r($amount);
        if (! Money::pos($amount)) throw new PlanException('Enter a valid amount.');
        DB::transaction(function () use ($member, $amount) {
            $u = User::lockForUpdate()->findOrFail($member->id);
            if (Money::cmp($amount, $u->wallet) > 0) throw new PlanException('Insufficient income wallet balance.');
            $this->plan->debit($u, 'transfer', $amount, 'Transfer to purchase wallet');
            $this->plan->walletMove($u, 'transfer-in', $amount, 'From income wallet');
            DB::table('transfers')->insert(['kind' => 'self', 'from_id' => $u->id, 'to_id' => $u->id, 'amount' => $amount, 'created_at' => Carbon::now()]);
        });
    }

    /**
     * Checks a P2P transfer and shows what will happen (no money moves): the receiver's name for the confirmation step,
     * the company charge and what the receiver gets. Throws a PlanException when the transfer is not possible.
     * @return array{to: User, amount: string, fee: string, net: string}
     */
    /** @param string $source "purchase" or "income" — which of the sender's wallets pays for the transfer. */
    public function previewTransfer(User $member, string $toCode, $amount, string $source = 'purchase'): array
    {
        $source = $source === 'income' ? 'income' : 'purchase';
        $s = Settings::all();
        $amount = Money::r($amount);
        $to = User::where('code', strtoupper(trim($toCode)))->where('role', 'member')->first();
        if (! $to || $to->blocked) throw new PlanException('Member ID not found.');
        if ($to->id === $member->id) throw new PlanException('You cannot transfer to yourself.');
        if (! Money::pos($amount)) throw new PlanException('Enter a valid amount.');
        if (Money::cmp($amount, $s['minTransfer'] ?? 1) < 0) throw new PlanException('Minimum transfer is ' . $s['currency'] . ($s['minTransfer'] ?? 1) . '.');
        $col = $source === 'income' ? 'wallet' : 'purchase_wallet';
        if (Money::cmp($amount, User::where('id', $member->id)->value($col)) > 0) {
            throw new PlanException('Insufficient ' . $source . ' wallet balance.');
        }
        $fee = Money::pct($amount, $s['p2pFeePct'] ?? 0);
        return ['to' => $to, 'amount' => $amount, 'fee' => $fee, 'net' => Money::sub($amount, $fee), 'source' => $source];
    }

    /**
     * P2P (member to member, by member ID), from the sender's purchase OR income wallet — always into the receiver's
     * purchase wallet. Allowed every day including weekends. The company keeps p2pFeePct % of the amount sent.
     */
    public function transferToMember(User $member, string $toCode, $amount, string $source = 'purchase'): User
    {
        $s = Settings::all();
        $p = $this->previewTransfer($member, $toCode, $amount, $source);
        $amount = $p['amount'];
        $to = $p['to'];
        $source = $p['source'];

        return DB::transaction(function () use ($member, $to, $amount, $s, $source) {
            // lock both rows in id order to avoid deadlocks
            $ids = [$member->id, $to->id];
            sort($ids);
            $locked = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $locked[$member->id];
            $dest = $locked[$to->id];
            $col = $source === 'income' ? 'wallet' : 'purchase_wallet';
            if (Money::cmp($amount, $from->$col) > 0) throw new PlanException('Insufficient ' . $source . ' wallet balance.');
            $fee = Money::pct($amount, $s['p2pFeePct'] ?? 0);
            $net = Money::sub($amount, $fee);
            if ($source === 'income') {
                $this->plan->debit($from, 'transfer', $amount, 'Transfer to ' . $dest->name . ' (' . $dest->code . ')');
            } else {
                $this->plan->walletMove($from, 'transfer-out', Money::sub('0', $amount), 'Transfer to ' . $dest->name . ' (' . $dest->code . ')');
            }
            $this->plan->walletMove($dest, 'transfer-in', $net, 'Received from ' . $from->name . ' (' . $from->code . ')' . (Money::pos($fee) ? ' — ' . $s['currency'] . $fee . ' company charge deducted' : ''));
            $now = Carbon::now();
            DB::table('transfers')->insert(['kind' => 'member', 'from_id' => $from->id, 'to_id' => $dest->id, 'amount' => $amount, 'fee' => $fee, 'net' => $net, 'created_at' => $now]);
            if (Money::pos($fee)) {
                DB::table('deductions')->insert(['user_id' => $from->id, 'withdrawal_id' => null, 'type' => 'p2p_fee', 'note' => 'P2P charge @ ' . (float) ($s['p2pFeePct'] ?? 0) . ' % on ' . $s['currency'] . $amount . ' to ' . $dest->code . ' (' . $source . ' wallet)', 'amount' => $fee, 'status' => 'Deducted', 'created_at' => $now]);
            }
            return $dest;
        });
    }

    /* ---------------- withdrawals ---------------- */

    /** Every rule a withdrawal request must pass, without moving money (used before an OTP is sent). */
    public function checkWithdrawal(User $member, $amount, string $account, bool $force = false): string
    {
        $s = Settings::all();
        $amount = Money::r($amount);
        if (! $force) {
            $ms = Market::status();
            if (! $ms['open']) throw new PlanException($ms['reason']);
        }
        if (Money::cmp($amount, $s['minWithdraw']) < 0) throw new PlanException('Minimum withdrawal is ' . $s['currency'] . $s['minWithdraw'] . '.');
        $multiple = Money::r($s['withdrawMultiple']);
        if (Money::pos($multiple) && bccomp(bcmod($amount, $multiple, 2), '0', 2) !== 0) {
            throw new PlanException('Withdraw in multiples of ' . $s['currency'] . (float) $multiple . ' (e.g. 5, 10, 15…).');
        }
        if (trim($account) === '') throw new PlanException('Enter your payout account / address.');
        if (Money::cmp($amount, User::where('id', $member->id)->value('wallet')) > 0) throw new PlanException('Insufficient income wallet balance.');
        return $amount;
    }

    public function requestWithdrawal(User $member, $amount, string $method, string $account, bool $force = false): int
    {
        $s = Settings::all();
        $amount = $this->checkWithdrawal($member, $amount, $account, $force);

        return DB::transaction(function () use ($member, $amount, $method, $account, $s) {
            $u = User::lockForUpdate()->findOrFail($member->id);
            if (Money::cmp($amount, $u->wallet) > 0) throw new PlanException('Insufficient income wallet balance.');
            $fee = Money::pct($amount, $s['withdrawFeePct']);
            $this->plan->debit($u, 'withdrawal', $amount, 'Withdrawal request (' . $method . ')');
            $now = Carbon::now();
            $wid = DB::table('withdrawals')->insertGetId([
                'ref' => 'TMP-' . uniqid(), 'user_id' => $u->id, 'amount' => $amount, 'fee' => $fee, 'net' => Money::sub($amount, $fee),
                'method' => $method, 'account' => trim($account), 'status' => 'pending', 'due_at' => $now->copy()->addHours((int) $s['receiptHours']), 'created_at' => $now,
            ]);
            DB::table('withdrawals')->where('id', $wid)->update(['ref' => 'WD-' . (1000 + $wid)]);
            if (Money::pos($fee)) {
                DB::table('deductions')->insert(['user_id' => $u->id, 'withdrawal_id' => $wid, 'type' => 'withdrawal_fee', 'note' => 'Service Charge @ ' . $s['withdrawFeePct'] . ' % on ' . $s['currency'] . $amount, 'amount' => $fee, 'status' => 'Deducted', 'created_at' => $now]);
            }
            return $wid;
        });
    }

    public function processWithdrawal(int $id, string $status, ?string $payoutTxid = null, ?string $note = null): void
    {
        DB::transaction(function () use ($id, $status, $payoutTxid, $note) {
            $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
            if (! $w || $w->status !== 'pending') throw new PlanException('Request already processed.');
            Snapshot::forget((int) $w->user_id);
            DB::table('withdrawals')->where('id', $id)->update(['status' => $status, 'processed_at' => Carbon::now(),
                'payout_txid' => $status === 'approved' && $payoutTxid ? mb_substr(trim($payoutTxid), 0, 120) : null,
                'admin_note' => $note ? mb_substr(trim($note), 0, 255) : null]);
            if ($status === 'rejected') {
                $u = User::lockForUpdate()->findOrFail($w->user_id);
                $u->wallet = Money::add($u->wallet, $w->amount);
                $u->save();
                DB::table('ledger')->insert(['user_id' => $u->id, 'type' => 'refund', 'amount' => $w->amount, 'note' => 'Withdrawal ' . $w->ref . ' rejected — refunded', 'created_at' => Carbon::now()]);
                DB::table('deductions')->where('withdrawal_id', $id)->update(['status' => 'Reversed']);
            }
        });
    }
}
