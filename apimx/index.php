<?php
// api.php

// Definir o tipo de resposta como JSON
header('Content-Type: application/json; charset=utf-8');

// Montar a resposta
$response = [
    "success" => true,
    "message" => "API em execução",
    "result"  => new stdClass() // cria um objeto vazio em vez de array
];

// Retornar em formato JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE);