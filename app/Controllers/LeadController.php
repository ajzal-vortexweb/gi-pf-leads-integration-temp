<?php

namespace App\Controllers;

use App\Core\PFService;
use App\Core\Logger;

class LeadController
{
    public function fetchFromPF()
    {
        try {
            $service = new PFService();
            $leads = $service->fetchLeads();

            Logger::log(['event' => 'pf.fetch.leads', 'result' => $leads]);
            return $leads;
        } catch (\Exception $e) {
            Logger::log(['event' => 'pf.fetch.error', 'error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }
}
