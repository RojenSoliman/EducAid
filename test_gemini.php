<?php
$API_KEY = "AIzaSyCU7iNNvWUQv9-dBflcV-VlpIpBeTiB5dI";
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $API_KEY;

$data = [
  "contents" => [[
    "parts" => [[ "text" => "Hello Gemini!" ]]
  ]]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
  CURLOPT_POSTFIELDS => json_encode($data)
]);

$response = curl_exec($ch);
curl_close($ch);

echo $response;
