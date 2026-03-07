<?php

// Set response headers
header('Content-Type: application/json');

echo json_encode(['status' => 'ok', 'message' => 'API is running', 'version' => '1.0.0', 'timestamp' => date('Y-m-d H:i:s')]);