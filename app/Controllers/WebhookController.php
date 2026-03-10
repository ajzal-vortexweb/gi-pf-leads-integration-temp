<?php

namespace App\Controllers;

require_once __DIR__ . '/../Config/config.php';

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

        // Deduplication Check
        $leadId = $payload['id'] ?? null;
        if ($leadId && $this->isLeadProcessed($leadId)) {
            Logger::log(['event' => 'webhook.duplicate_ignored', 'lead_id' => $leadId]);
            echo json_encode(["status" => "duplicate ignored", "lead_id" => $leadId]);
            return;
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
            $leadId = $data['id'] ?? null;

            $sender = $data['payload']['sender'] ?? null;
            if (!$sender || empty($sender['contacts'])) {
                throw new \Exception("Missing sender contact details");
            }

            $bitrixContactId = $service->createContact($sender);
            if (!$bitrixContactId) {
                throw new \Exception("Error creating Bitrix contact");
            }

            $bitrixLeadId = $service->createLead($data, $bitrixContactId);
            if (!$bitrixLeadId) {
                throw new \Exception("Error creating Bitrix lead");
            }

            if ($leadId && $bitrixLeadId) {
                $this->saveLeadId($leadId);
            }

            Logger::log([
                'event' => 'lead.created',
                'bitrix_contact_id' => $bitrixContactId,
                'bitrix_lead_id' => $bitrixLeadId
            ]);

            echo json_encode([
                "status" => "lead created processed",
                "bitrix_contact_id" => $bitrixContactId,
                "bitrix_lead_id" => $bitrixLeadId
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            Logger::log(['event' => 'lead.created.error', 'error' => $e->getMessage()]);
            echo json_encode(["error" => $e->getMessage()]);
        }
    }

    /**
     * Checks if a lead ID has already been processed
     */
    private function isLeadProcessed($id): bool
    {
        if (!file_exists(LEAD_FILE)) {
            return false;
        }
        $processedIds = file(LEAD_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return in_array($id, $processedIds);
    }

    /**
     * Saves a lead ID to the processed leads file
     */
    private function saveLeadId($id): void
    {
        file_put_contents(LEAD_FILE, $id . PHP_EOL, FILE_APPEND);
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
