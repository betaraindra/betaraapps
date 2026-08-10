<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$months = [1=>'Januari', 'Februari'];
$ts = strtotime('2026-08-01');
echo $months[date('n', $ts)];
