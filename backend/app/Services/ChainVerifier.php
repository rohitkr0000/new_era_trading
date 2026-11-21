<?php

namespace App\Services;

use App\Support\Money;
use Illuminate\Support\Facades\Http;

/**
 * Confirms a USDT (BEP20) deposit on the blockchain through the Etherscan V2 API (chainid 56 = BNB Smart Chain):
 * the transaction must have succeeded and contain a USDT Transfer to the company deposit address.
 * Returns ['status' => verified|mismatch|failed|unchecked, 'amount' => ?string, 'from' => ?string (sender wallet), 'note' => string].
 *   verified  — the hash is real and at least $claimed arrived at our address
 *   mismatch  — real transfer to us, but less than claimed (amount = what really arrived)
 *   failed    — hash not found / failed / no USDT transfer to our address
 *   unchecked — no API key configured or the API could not be reached (decide by hand / retry later)
 */
class ChainVerifier
{
    private const TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public static function enabled(): bool
    {
        return (bool) config('services.chain.key');
    }

    public static function check(string $txid, string $claimed, string $companyAddress): array
    {
        if (! self::enabled()) return self::res('unchecked', null, 'On-chain check is off (no API key) — verify manually.');
        $txid = trim($txid);
        if (! preg_match('/^0x[0-9a-fA-F]{64}$/', $txid)) return self::res('failed', null, 'Not a valid transaction hash.');

        try {
            $r = Http::timeout(8)->retry(1, 300, throw: false)->get(config('services.chain.api'), [
                'chainid' => config('services.chain.chain_id'), 'module' => 'proxy', 'action' => 'eth_getTransactionReceipt',
                'txhash' => $txid, 'apikey' => config('services.chain.key'),
            ]);
        } catch (\Throwable $e) {
            return self::res('unchecked', null, 'Blockchain API unreachable: ' . $e->getMessage());
        }
        if (! $r->ok()) return self::res('unchecked', null, 'Blockchain API returned HTTP ' . $r->status() . '.');

        $rc = $r->json('result');
        if (! is_array($rc)) return self::res('unchecked', null, 'Transaction not found on the blockchain yet — it will be re-checked automatically.');
        if (($rc['status'] ?? '') !== '0x1') return self::res('failed', null, 'The transaction failed on-chain.');

        $to = strtolower(preg_replace('/^0x/i', '', trim($companyAddress)));
        $usdt = strtolower(config('services.chain.usdt'));
        $raw = '0';
        $from = null;
        foreach ($rc['logs'] ?? [] as $log) {
            $topics = $log['topics'] ?? [];
            if (strtolower($log['address'] ?? '') !== $usdt || strtolower($topics[0] ?? '') !== self::TRANSFER || count($topics) < 3) continue;
            if (substr(strtolower($topics[2]), -40) !== $to) continue;
            $raw = bcadd($raw, self::hexToDec($log['data'] ?? '0x0'), 0);
            $from ??= '0x' . substr(strtolower($topics[1]), -40);
        }
        if ($raw === '0') return self::res('failed', null, 'No USDT transfer to the company address in this transaction.');

        $amount = bcdiv($raw, bcpow('10', (string) config('services.chain.decimals')), 2);
        $amount = Money::r($amount);
        if (Money::cmp($amount, Money::r($claimed)) >= 0) return self::res('verified', $amount, 'Confirmed on-chain: ' . $amount . ' USDT received from ' . $from . '.', $from);
        return self::res('mismatch', $amount, 'Only ' . $amount . ' USDT arrived on-chain (from ' . $from . '); ' . Money::r($claimed) . ' was claimed.', $from);
    }

    private static function res(string $status, ?string $amount, string $note, ?string $from = null): array
    {
        return ['status' => $status, 'amount' => $amount, 'note' => $note, 'from' => $from];
    }

    private static function hexToDec(string $hex): string
    {
        $hex = strtolower(preg_replace('/^0x/i', '', $hex));
        $dec = '0';
        foreach (str_split($hex) as $c) $dec = bcadd(bcmul($dec, '16'), (string) hexdec($c));
        return $dec;
    }
}
