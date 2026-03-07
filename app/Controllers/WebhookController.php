<?php

namespace App\Controllers;

use App\Core\Logger;
use App\Core\BitrixService;

class WebhookController
{
    public function handleWebhook()
    {
        header('Content-Type: application/json');

        $incoming = json_decode(file_get_contents("php://input"), true);
        Logger::log(['event' => 'webhook.received', 'payload' => $incoming]);

        if (isset($incoming['data']['payload'])) {
            $payload = $incoming['data']['payload'];
        } else {
            $payload = $incoming;
        }

        switch ($payload['type']) {
            case 'lead.created':
                $this->handleLeadCreated($payload);
                break;
            case 'lead.updated':
                $this->handleLeadUpdated($payload);
                break;
            case 'lead.assigned':
                $this->handleLeadAssigned($payload);
                break;
            default:
                Logger::log(['event' => 'webhook.unknown_event', 'payload' => $payload]);
                http_response_code(400);
                echo json_encode(["error" => "Unknown event type", "received_type" => $payload['type'] ?? null]);
        }
    }

    private function handleLeadCreated($data)
    {
        try {
            $service = new BitrixService();

            // Skip Contact Creation as there are no users/contacts in temp CRM
            $bitrixContactId = 0;

            $bitrixLeadId = $service->createLead($data, $bitrixContactId);
            if (!$bitrixLeadId) {
                throw new \Exception("Error creating Bitrix Deal");
            }

            Logger::log([
                'event' => 'lead.created.temp_crm',
                'bitrix_lead_id' => $bitrixLeadId
            ]);

            echo json_encode([
                "status" => "processed for temp crm",
                "bitrix_lead_id" => $bitrixLeadId
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            Logger::log(['event' => 'lead.created.error', 'error' => $e->getMessage()]);
            echo json_encode(["error" => $e->getMessage()]);
        }
    }

    private function handleLeadUpdated($data)
    {
        Logger::log(['event' => 'lead.updated', 'data' => $data]);
        echo json_encode(["status" => "lead updated processed"]);
    }

    private function handleLeadAssigned($data)
    {
        Logger::log(['event' => 'lead.assigned', 'data' => $data]);
        echo json_encode(["status" => "lead assigned processed"]);
    }
}
