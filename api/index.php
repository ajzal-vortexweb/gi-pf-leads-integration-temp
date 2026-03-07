<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\LeadController;

$controller = new LeadController();
$leads = $controller->fetchFromPF();

header('Content-Type: application/json');
echo json_encode($leads, JSON_PRETTY_PRINT);
