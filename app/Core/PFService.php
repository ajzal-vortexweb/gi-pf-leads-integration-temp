<?php

namespace App\Core;

require_once __DIR__ . '/../Config/config.php';

class PFService
{
    private $accessToken;

    public function authenticate()
    {
        $payload = json_encode([
            'apiKey'    => PF_API_KEY,
            'apiSecret' => PF_API_SECRET
        ]);

        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" .
                    "Accept: application/json\r\n" .
                    "User-Agent: Mozilla/5.0\r\n",
                'content' => $payload,
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents(PF_TOKEN_URL, false, $context);

        $data = json_decode($response, true);

        if (isset($data['accessToken'])) {
            $this->accessToken = $data['accessToken'];
            return $this->accessToken;
        } else {
            global $http_response_header;
            throw new \Exception("Authentication failed:\n" . $response . "\nHeaders: " . json_encode($http_response_header));
        }
    }

    public function fetchLeads()
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }

        $options = [
            'http' => [
                'method'  => 'GET',
                'header'  => "Authorization: Bearer {$this->accessToken}\r\n" .
                    "Accept: application/json\r\n" .
                    "User-Agent: Mozilla/5.0\r\n"
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents(PF_LEADS_URL, false, $context);

        return json_decode($response, true);
    }

    public function fetchListings($filters = [])
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }

        $queryString = http_build_query($filters);

        $url = PF_LISTINGS_URL . ($queryString ? "?$queryString" : '');

        $options = [
            'http' => [
                'method'  => 'GET',
                'header'  => "Authorization: Bearer {$this->accessToken}\r\n" .
                    "Accept: application/json\r\n" .
                    "User-Agent: Mozilla/5.0\r\n"
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            error_log("Failed to fetch listings from: $url");
            return null;
        }

        return json_decode($response, true);
    }
}
