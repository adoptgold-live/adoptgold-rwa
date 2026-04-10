<?php
header('Content-Type: application/json');

echo json_encode([
  'ok' => true,
  'rows' => [
    ['title'=>'System','body'=>'Community module started'],
    ['title'=>'Jobs','body'=>'New jobs will appear here']
  ]
]);
