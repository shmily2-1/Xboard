<?php

// Standalone, read-only regression test: no Laravel boot, database, orders or payments.
// Run from any directory: php tests/Regression/money-serialization.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = getenv('XBOARD_TEST_PROJECT_ROOT') ?: dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
// Optional staged code for pre-deployment tests; never used by the web application.
if ($stage = getenv('XBOARD_TEST_STAGE')) {
    foreach (['app/Support/Money.php', 'app/Http/Resources/PlanResource.php', 'app/Models/Payment.php'] as $file) {
        require $stage . '/' . $file;
    }
}

use App\Http\Resources\PlanResource;
use App\Models\Payment;
use App\Models\Plan;
use App\Support\Money;

$count = 0;
function same($expected, $actual, string $label): void
{
    global $count;
    ++$count;
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

foreach ([
    [19.9, 1990], ['19.90', 1990], [16.9, 1690], [69.9, 6990],
    [29.9, 2990], [0, 0], ['0.00', 0], [0.01, 1], [0.29, 29],
    ['29.99', 2999], ['9999.99', 999999], ['1.005', 101], ['0.005', 1],
] as [$major, $cents]) {
    same($cents, Money::toCents($major), 'Major-unit conversion');
}
// Sweep valid two-decimal prices to catch low-bit floating-point truncation.
for ($cents = 0; $cents <= 100000; ++$cents) {
    same($cents, Money::toCents($cents / 100), 'Float price ' . $cents);
    same($cents, Money::toCents(number_format($cents / 100, 2, '.', '')), 'String price ' . $cents);
}

foreach (Plan::LEGACY_PERIOD_MAPPING as $legacy => $period) {
    foreach ([['19.90', 1990], [16.9, 1690], [69.9, 6990], [0, 0], [null, null]] as [$price, $expected]) {
        $resource = new class(['prices' => [$period => $price]]) extends PlanResource {
            public function periodPrices(): array { return $this->getPeriodPrices(); }
        };
        $prices = $resource->periodPrices();
        $json = json_decode(json_encode($prices, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        same($expected, $prices[$legacy], 'Resource cents: ' . $period);
        same($expected, $json[$legacy], 'JSON integer/null: ' . $period);
        foreach (Plan::LEGACY_PERIOD_MAPPING as $other => $_) {
            if ($other !== $legacy) same(null, $prices[$other], 'Unavailable period stays null');
        }
    }
}

foreach ([['2.50', 2.5], ['0.00', 0.0], ['100.00', 100.0], ['0.01', 0.01], [null, null]] as [$raw, $expected]) {
    $payment = new Payment();
    $payment->setRawAttributes(['handling_fee_percent' => $raw, 'handling_fee_fixed' => '30']);
    same($expected, $payment->handling_fee_percent, 'Percentage model cast');
    same($expected, $payment->toArray()['handling_fee_percent'], 'Percentage serialization cast');
    same(30, $payment->handling_fee_fixed, 'Fixed fee integer cast');
    $decoded = json_decode($payment->toJson(), true, 512, JSON_THROW_ON_ERROR);
    same($raw === null, $decoded['handling_fee_percent'] === null, 'Null percentage preserved');
    if ($raw !== null) same(false, is_string($decoded['handling_fee_percent']), 'JSON percentage is numeric');
    same(30, $decoded['handling_fee_fixed'], 'JSON fixed fee is integer');
}
$nullFees = new Payment();
$nullFees->setRawAttributes(['handling_fee_fixed' => null, 'handling_fee_percent' => null]);
same(null, $nullFees->toArray()['handling_fee_fixed'], 'Null fixed fee preserved');
$fees = new Payment();
$fees->setRawAttributes(['handling_fee_percent' => '2.50', 'handling_fee_fixed' => '30']);
same(80, (int) round(Money::toCents('19.90') * ($fees->handling_fee_percent / 100) + $fees->handling_fee_fixed), 'Fee percentage retains 2.5 percent semantics');
echo "PASS: {$count} assertions; no database access or payment/order creation.\n";