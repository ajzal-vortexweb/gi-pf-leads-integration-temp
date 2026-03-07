<?php

namespace App\Core;

class Logger
{
    public static function log($data)
    {
        $date = new \DateTime();

        $date->setTimezone(new \DateTimeZone('Asia/Dubai'));

        $logDir = __DIR__ . '/../../logs/' . $date->format('Y/m/d');
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $logFile = "$logDir/api.log";

        $logEntry = [
            'timestamp' => $date->format('Y-m-d H:i:s'),
            'data' => $data
        ];

        $formatted = json_encode($logEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($logFile, $formatted . "\n\n", FILE_APPEND);
    }
}
