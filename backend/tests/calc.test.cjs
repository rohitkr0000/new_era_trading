/* Checks js/calc.js against the company spreadsheet.   Run:  node backend/tests/calc.test.cjs
 *
 * Part 1  every number of the "Calculater" and "Compounding Calculator" tabs, to the last digit.
 * Part 2  the three "after 25 / 50 days" tabs. In the sheet the expiry column (D) is typed by hand and
 *         skips one value: day 34 shows 7.76, which is the expiry of lot 10 - lot 9 (7.39) was left out, so every
 *         later row is shifted by one lot and the last row shows 0. We therefore prove two things:
 *           (a) replaying the sheet's own recurrence with its own typed numbers gives the sheet's totals exactly
 *               (181.4165 / 408.2801122 / 290.915) -> the formulas are the same as ours;
 *           (b) the exact model agrees with the sheet on every row before the slip, and the totals differ only by the
 *               amount the slip causes.                                                                               */
const { run, scenarios } = require('../../js/calc.js');
let pass = 0, fail = 0;
const near = (name, got, want, tol = 0.005) => {
  const ok = Math.abs(got - want) <= tol;
  ok ? pass++ : fail++;
  console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok ? '' : `   -> got ${got}, expected ${want}`));
};

const S = { roiPct: 5, roiDays: 25, withdrawFeePct: 10, directPct: 5, levelPct: [2, 1, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2] };
const x = scenarios(100, S);

console.log('== PART 1 - "Calculater" tab, deposit $100');
near('ROI per day (D3)', x.roiPerDay, 5);
near('Simple interest, 25 days (F3 = 125)', x.siInterest, 125);
near('Net withdrawal after the 10% charge (G3 = 112.5)', x.siNet, 112.5);
near('Simple interest final amount (E6 = 225)', x.siFinal, 225);
near('Compounding, 25 days (I3 = 338.6354941)', x.ciFinal, 338.6354941, 1e-6);
near('Compounding interest (E5 = 238.6354941)', x.ciInterest, 238.6354941, 1e-6);
near('Balance after the 25-day cycle expires (J3 = 238.6354941)', x.ciAfterExpiry, 238.6354941, 1e-6);
near('Compounding = deposit x (1 + rate)^days', x.ciFinal, 100 * Math.pow(1.05, 25), 1e-9);
near('System deposit with the sheet\'s own 5% + 10% (E3 = 85)', scenarios(100, Object.assign({}, S, { levelPct: [10] })).systemDeposit, 85);
near('Liability with SI, sheet figures (H3 = 27.5)', scenarios(100, Object.assign({}, S, { levelPct: [10] })).liability, 27.5);

console.log('\n== PART 1 - "Compounding Calculator" schedule');
near('day 1 closing (D10 = 105)', x.ci.rows[0].closing, 105);
near('day 2 interest (C11 = 5.25)', x.ci.rows[1].interest, 5.25);
near('day 3 closing (D12 = 115.7625)', x.ci.rows[2].closing, 115.7625);
near('day 10 closing (D19 = 162.8894627)', x.ci.rows[9].closing, 162.8894627, 1e-6);
near('day 20 closing (D29 = 265.3297705)', x.ci.rows[19].closing, 265.3297705, 1e-6);

console.log('\n== PART 2a - the sheet\'s own recurrence, replayed with its own typed expiry column');
const T3 = [5.0, 5.25, 5.51, 5.78, 6.08, 6.38, 6.7, 7.04, 7.76, 8.14, 8.55, 8.98, 9.43, 9.9, 10.39, 10.91, 11.46, 12.03, 12.63, 13.26, 13.93, 14.62, 15.35, 16.12, 0.0];
const T5 = [11.93, 12.28, 12.63, 12.99, 13.35, 13.71, 14.08, 14.44, 14.82, 15.17, 15.52, 15.87, 16.21, 16.55, 16.88, 17.21, 17.52, 17.83, 18.12, 18.39, 18.65, 18.88, 19.1, 19.28, 19.44];
{ let B = 238.64, cash = 0; T3.forEach(d => { cash += B * 0.05; B -= d; });                near('withdraw tab: sum of daily 5% (F4 = 181.4165)', cash, 181.4165, 1e-4); }
{ let B = 238.64;           T3.forEach(d => { B = B + B * 0.05 - d; });                    near('compounding tab: B + C - D on day 50 (F4 = 408.2801122)', B, 408.2801122, 1e-6); }
{ let B = 408.28, cash = 0; T5.forEach(d => { cash += B * 0.05; B -= d; });                near('50-day withdraw tab: sum of daily 5% (F4 = 290.915)', cash, 290.915, 1e-3); }
near('50-day withdraw tab: expiry total (F5 = 400.85)', T5.reduce((a, b) => a + b, 0), 400.85, 1e-6);

console.log('\n== PART 2b - the exact model agrees with the sheet on every row before the typing slip (days 26-33)');
const w = x.after25Withdraw.rows, c = x.after25Compound.rows;
const E = [233.64, 228.39, 222.88, 217.1, 211.02, 204.64, 197.94, 190.9];            // sheet E10..E17, "wallet balance after withdraw"
E.forEach((v, i) => near(`withdraw tab, day ${26 + i} balance after expiry (E${10 + i} = ${v})`, w[25 + i].closing, v, 0.03));
const K = [245.572, 252.6006, 259.72063, 266.9266615, 274.1929946, 281.5226443, 288.8987765, 296.3037153]; // sheet E10..E17 (compounding tab)
K.forEach((v, i) => near(`compounding tab, day ${26 + i} closing (E${10 + i} = ${v})`, c[25 + i].closing, v, 0.03));
near('day 26 interest (C10 = 11.932)', w[25].interest, 11.932, 0.01);
near('day 26 expiring lot (D10 = 5.0)', w[25].expiring, 5.0);
near('day 33 expiring lot (D17 = 7.04)', w[32].expiring, 7.04, 0.01);
near('day 34 expiring lot - sheet typed 7.76, correct value is 7.39 (lot 9)', w[33].expiring, 7.3873, 0.001);

console.log('\n== PART 2b - totals with the correct expiry sequence (documented difference to the sheet)');
const cashW = w.slice(25).reduce((a, r) => a + r.interest, 0);
near('interest withdrawn on days 26-50: model 184.66 (sheet 181.42 because of the slip)', cashW, 184.6589, 0.001);
near('balance at day 50 when compounding on: model 404.97 (sheet 408.28)', c[49].closing, 404.967, 0.001);
const w2 = x.after50Withdraw.rows;
near('interest withdrawn on days 51-75: model 285.90 (sheet 290.92)', w2.slice(50).reduce((a, r) => a + r.interest, 0), 285.9006, 0.001);

console.log('\n== properties');
near('a deposit that is only paid out in cash is fully gone after its cycle', run(100, 0.05, 25, [[25, 'cash']]).base, 0);
near('one day of compounding on $100 leaves $105', run(100, 0.05, 25, [[1, 'compound']]).base, 105);
near('total cash over a 25-day cycle on $100 = $125', run(100, 0.05, 25, [[25, 'cash']]).cash, 125);
near('results scale linearly with the deposit ($250 -> 2.5 x)', scenarios(250, S).ciFinal, 2.5 * x.ciFinal, 1e-9);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
