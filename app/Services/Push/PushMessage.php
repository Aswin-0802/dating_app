<?php

declare(strict_types=1);

namespace App\Services\Push;

/**
 * One notification, in the words a member will read.
 *
 * A value object rather than an array so the same message can be handed to a
 * single send, a campaign fan-out and a test button without three different
 * shapes of the same thing.
 */
final readonly class PushMessage
{
    /**
     * @param  array<string, string|int|null>  $data  extra values the app reads on tap
     * @param  string|null  $link  where a tap should land (https, or a member-app path)
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $link = null,
        public array $data = [],
        public ?string $image = null,
    ) {}

    /**
     * The FCM v1 message body for one token.
     *
     * Every platform block is included: FCM ignores the ones that do not apply
     * to the token it is delivering to, so one payload serves phone and
     * browser and there is no branching to get wrong.
     *
     * @return array<string, mixed>
     */
    public function toFcm(string $token, string $absoluteLink): array
    {
        // FCM rejects the whole message if any data value is not a string.
        $data = [];

        foreach ($this->data as $key => $value) {
            if ($value !== null) {
                $data[$key] = (string) $value;
            }
        }

        if ($this->link !== null) {
            $data['link'] = $absoluteLink;
        }

        $notification = array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'image' => $this->image,
        ]);

        return [
            'message' => [
                'token' => $token,
                'notification' => $notification,
                'data' => $data,
                'android' => [
                    'priority' => 'HIGH',
                    'ttl' => '86400s',
                    'notification' => array_filter([
                        'channel_id' => 'default',
                        'sound' => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ]),
                ],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['sound' => 'default']],
                ],
                'webpush' => array_filter([
                    'headers' => ['TTL' => '86400'],
                    // Only fcm_options here: adding a webpush.notification as
                    // well makes some browsers show the notification twice.
                    'fcm_options' => $this->link === null ? null : ['link' => $absoluteLink],
                ]),
            ],
        ];
    }
}
