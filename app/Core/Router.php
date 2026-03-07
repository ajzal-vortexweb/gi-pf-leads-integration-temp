<?php

namespace App\Core;

use App\Controllers\WebhookController;

class Router
{
    public static function route($method)
    {
        if ($method === 'POST') {
            $controller = new WebhookController();
            $controller->handleWebhook();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
    }
}
