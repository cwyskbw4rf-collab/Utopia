<?php

// Proxy Library Entry Point
header('Content-Type: application/json');

$response = [
    'status' => 'success',
    'message' => 'Utopia Proxy Library is running',
    'version' => '1.0.0',
    'endpoints' => [
        '/health' => 'Health check',
        '/api/status' => 'API status',
    ]
];

if ($_SERVER['PATH_INFO'] === '/health' || $_SERVER['REQUEST_URI'] === '/health') {
    echo json_encode(['status' => 'healthy']);
    exit;
}

echo json_encode($response);
?>
