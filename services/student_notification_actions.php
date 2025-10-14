<?php
// Student notifications API temporarily disabled to avoid hitting incomplete schema.
header('Content-Type: application/json');
echo json_encode([
    'success' => false,
    'message' => 'Student notification service temporarily disabled.'
]);
