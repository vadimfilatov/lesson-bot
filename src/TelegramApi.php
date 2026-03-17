<?php

declare(strict_types=1);

namespace App;

class TelegramApi
{
    private string $apiUrl;

    public function __construct(private string $token)
    {
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}";
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->request('sendMessage', $params);
    }

    public function removeKeyboard(int $chatId, string $text): array
    {
        return $this->sendMessage($chatId, $text, [
            'remove_keyboard' => true,
        ]);
    }

    private function request(string $method, array $params): array
    {
        $ch = curl_init("{$this->apiUrl}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            error_log("Telegram API error: {$error}");
            return ['ok' => false, 'description' => $error];
        }

        curl_close($ch);

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            error_log("Telegram API invalid JSON: {$response}");
            return ['ok' => false, 'description' => 'Invalid JSON response'];
        }

        if (!($decoded['ok'] ?? false)) {
            error_log("Telegram API error [{$httpCode}]: " . ($decoded['description'] ?? 'unknown'));
        }

        return $decoded;
    }
}
