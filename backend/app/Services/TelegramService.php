<?php
// app/Services/TelegramService.php

namespace App\Services;

use GuzzleHttp\Client;
use App\Models\Group;
use App\Models\ScheduledPost;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected $client;
    protected $apiUrl;

    public function __construct()
    {
        $this->client = new Client();
        $this->apiUrl = 'https://api.telegram.org/bot' . config('services.telegram.bot_token');
    }

    public function sendMessage($chatId, $text, $media = [])
    {
        try {
            if (empty($media)) {
                // Send text message
                $response = $this->client->post($this->apiUrl . '/sendMessage', [
                    'json' => [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'parse_mode' => 'HTML'
                    ]
                ]);
            } else if (count($media) === 1) {
                // Send single media
                $mediaItem = $media[0];
                $method = $mediaItem['type'] === 'photo' ? 'sendPhoto' : 'sendVideo';
                
                $response = $this->client->post($this->apiUrl . '/' . $method, [
                    'multipart' => [
                        [
                            'name' => 'chat_id',
                            'contents' => $chatId
                        ],
                        [
                            'name' => $mediaItem['type'],
                            'contents' => fopen(storage_path('app/public' . parse_url($mediaItem['url'], PHP_URL_PATH)), 'r')
                        ],
                        [
                            'name' => 'caption',
                            'contents' => $text
                        ],
                        [
                            'name' => 'parse_mode',
                            'contents' => 'HTML'
                        ]
                    ]
                ]);
            } else {
                // Send media group
                $mediaGroup = [];
                foreach ($media as $index => $mediaItem) {
                    $mediaGroup[] = [
                        'type' => $mediaItem['type'],
                        'media' => 'attach://file' . $index,
                        'caption' => $index === 0 ? $text : '',
                        'parse_mode' => 'HTML'
                    ];
                }

                $multipart = [
                    [
                        'name' => 'chat_id',
                        'contents' => $chatId
                    ],
                    [
                        'name' => 'media',
                        'contents' => json_encode($mediaGroup)
                    ]
                ];

                foreach ($media as $index => $mediaItem) {
                    $multipart[] = [
                        'name' => 'file' . $index,
                        'contents' => fopen(storage_path('app/public' . parse_url($mediaItem['url'], PHP_URL_PATH)), 'r')
                    ];
                }

                $response = $this->client->post($this->apiUrl . '/sendMediaGroup', [
                    'multipart' => $multipart
                ]);
            }

            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::error('Failed to send message to Telegram', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function checkUserIsAdmin($chatId, $userId)
    {
        try {
            $response = $this->client->get($this->apiUrl . '/getChatMember', [
                'query' => [
                    'chat_id' => $chatId,
                    'user_id' => $userId
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            $status = $data['result']['status'];

            return in_array($status, ['creator', 'administrator']);
        } catch (\Exception $e) {
            Log::error('Failed to check admin status', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getUpdates($offset = null)
    {
        try {
            $params = [
                'timeout' => 30,
                'allowed_updates' => ['message', 'my_chat_member']
            ];

            if ($offset) {
                $params['offset'] = $offset;
            }

            $response = $this->client->get($this->apiUrl . '/getUpdates', [
                'query' => $params
            ]);

            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::error('Failed to get updates', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function setWebhook($url)
    {
        try {
            $response = $this->client->post($this->apiUrl . '/setWebhook', [
                'json' => [
                    'url' => $url,
                    'allowed_updates' => ['message', 'my_chat_member']
                ]
            ]);

            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::error('Failed to set webhook', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getBotChats()
    {
        try {
            // Get updates to see what chats the bot is in
            $response = $this->client->get($this->apiUrl . '/getUpdates', [
                'query' => [
                    'limit' => 100,
                    'allowed_updates' => ['message', 'my_chat_member']
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            
            if (!$data['ok']) {
                \Log::error('Failed to get updates', ['error' => $data['description'] ?? 'unknown']);
                return [];
            }
            
            $chats = [];
            $seenChats = [];
            
            foreach ($data['result'] as $update) {
                $chat = null;
                
                // Extract chat from different update types
                if (isset($update['message']['chat'])) {
                    $chat = $update['message']['chat'];
                } elseif (isset($update['my_chat_member']['chat'])) {
                    $chat = $update['my_chat_member']['chat'];
                }
                
                if ($chat && in_array($chat['type'], ['group', 'supergroup']) && !in_array($chat['id'], $seenChats)) {
                    $chats[] = $chat;
                    $seenChats[] = $chat['id'];
                }
            }
            
            \Log::info('getBotChats found chats', [
                'total_updates' => count($data['result']),
                'unique_chats' => count($chats)
            ]);
            
            return $chats;
            
        } catch (\Exception $e) {
            \Log::error('Failed to get bot chats', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getChatInfo($chatId)
    {
        try {
            // Don't modify the chatId - use it exactly as provided
            \Log::info('Getting chat info', ['chat_id' => $chatId]);
            
            $response = $this->client->get($this->apiUrl . '/getChat', [
                'query' => ['chat_id' => $chatId]
            ]);

            $data = json_decode($response->getBody(), true);
            
            if ($data['ok']) {
                \Log::info('Successfully got chat info', [
                    'chat_id' => $chatId,
                    'chat_title' => $data['result']['title'] ?? 'unknown',
                    'chat_type' => $data['result']['type'] ?? 'unknown'
                ]);
                return $data['result'];
            } else {
                \Log::error('Telegram API returned error', [
                    'chat_id' => $chatId,
                    'error_code' => $data['error_code'] ?? 'unknown',
                    'description' => $data['description'] ?? 'unknown'
                ]);
                return null;
            }
        } catch (\Exception $e) {
            \Log::error('Failed to get chat info', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getBotInfo()
    {
        try {
            $response = $this->client->get($this->apiUrl . '/getMe');
            $data = json_decode($response->getBody(), true);
            
            if ($data['ok']) {
                return $data['result'];
            }
            
            return null;
        } catch (\Exception $e) {
            \Log::error('Failed to get bot info', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getChatMemberCount($chatId)
    {
        try {
            $response = $this->client->get($this->apiUrl . '/getChatMemberCount', [
                'query' => ['chat_id' => $chatId]
            ]);

            return json_decode($response->getBody(), true)['result'];
        } catch (\Exception $e) {
            Log::error('Failed to get chat member count', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}