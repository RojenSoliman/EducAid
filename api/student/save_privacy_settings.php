<?php
// Endpoint removed: Data Visibility switches are not supported in current scope.
header('Content-Type: application/json');
http_response_code(410);
echo json_encode(['success' => false, 'error' => 'Endpoint removed']);
