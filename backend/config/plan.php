<?php

/*
 | New Era Trading — default plan settings (from New_Era_Trading_Guide.pdf).
 | Admins override any key from Admin → Plan Settings (stored in the `settings` table).
 */
return [
    'siteName'         => 'New Era Trading',
    'currency'         => '$',
    'roiPct'           => 5,      // % of each package, per trading day
    'roiDays'          => 25,     // a package earns ROI for 25 trading days, then its cycle expires (0 = until the earning cap)
    'directPct'        => 5,      // direct referral income (joining package)
    'retopupPct'       => 5,      // retopup income (later packages)
    'levelPct'         => [2, 1, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2], // level income on every downline investment: L1 2%, L2 1%, L3–L12 0.2% each (New_Era guide, page 5)
    'capNonWorking'    => 2,      // earning cap × invested when the member has fewer than capDirectsRequired active directs
    'capWorking'       => 5,      // earning cap × invested for a WORKING member (see capMode)
    'capMode'          => 'business', // how a member becomes "working": 'business' = level-1 business ≥ capBusinessMultiple × own investment (guide, terms #4) · 'directs' = ≥ capDirectsRequired active directs
    'capBusinessMultiple' => 5,   // level-1 (direct team) business must reach this multiple of the member's own invested amount
    'capDirectsRequired' => 12,   // active direct referrals that unlock all 12 income levels (and the 5X cap when capMode = 'directs')
    'levelUnlockPerLevel' => true, // level k income is unlocked only with ≥ k active direct referrals
    'joinMultiple'     => 5,      // joining / retopup amounts must be multiples of this ($5, $10, $15 …)
    'minJoin'          => 5,
    'minWithdraw'      => 5,
    'withdrawMultiple' => 5,
    'minTransfer'      => 1,      // smallest P2P transfer
    'p2pFeePct'        => 5,      // company charge on a member-to-member (P2P) transfer, taken from the amount sent
    'withdrawFeePct'   => 10,     // withdrawal charge (company spreadsheet: "Net Withdrawal Amt after 10% Charge")
    'withdrawStart'    => 10,     // 10 AM
    'withdrawEnd'      => 14,     // 2 PM
    'receiptHours'     => 24,
    'tradingDays'      => [1, 2, 3, 4, 5], // Mon–Fri (0 = Sunday)
    'timezone'         => 'Asia/Kolkata',
    'tzLabel'          => 'IST',
    'minDeposit'       => 5,
    'depositNetwork'   => 'USDT · BEP20 (BSC)',
    'depositAddress'   => '0xDEMO0000000000000000000000000000000NEWERA',
    'depositQr'        => null,   // set by Admin -> Plan Settings when a custom QR image is uploaded ("png:1732000000"); null = the site draws the QR from depositAddress
    'emailVerifyRequired' => true,  // members must confirm their e-mail (OTP) before withdrawing
    'kycRequired'      => true,     // members must have an approved KYC before withdrawing
    'autoApproveDeposits' => false, // credit deposits automatically once the blockchain confirms them (needs ETHERSCAN_API_KEY)
];
