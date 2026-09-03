<?php
header('Content-Type: text/plain; charset=utf-8');
$T = ['pass' => 0, 'fail' => 0, 'msgs' => []];

function t_true($cond, $label) {
    global $T;
    if ($cond) { $T['pass']++; } else { $T['fail']++; $T['msgs'][] = "FAIL: $label"; }
}

function t_eq($actual, $expected, $label) {
    global $T;
    if ($actual === $expected) {
        $T['pass']++;
    } else {
        $T['fail']++;
        $T['msgs'][] = "FAIL: $label\n  ожидалось: " . var_export($expected, true)
                     . "\n  получено:  " . var_export($actual, true);
    }
}

function t_report() {
    global $T;
    foreach ($T['msgs'] as $m) { echo $m . "\n"; }
    echo "\n{$T['pass']} passed, {$T['fail']} failed\n";
}

t_true(PHP_VERSION_ID >= 80300, 'PHP 8.3+');
t_report();
