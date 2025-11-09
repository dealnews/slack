<?php

namespace DealNews\Slack;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Sends a Slack Message
 *
 * @author    Brian Moon
 * @copyright 1999 - Present Dealnews, Inc.
 * @package   DealNews\Slack
 */
class Message {

    /**
     * Default Web Hook URL
     *
     * @var string
     */
    protected string $url = '';

    /**
     * Default emoji to send
     *
     * @var string
     */
    protected string $emoji = '';

    /**
     * Default username to send the message as
     *
     * @var string
     */
    protected string $username = 'slackbot';

    /**
     * Guzzle Client
     *
     * @var \GuzzleHttp\Client
     */
    protected Client $client;

    /**
     * Create a instance of NotifySlack object.  Constructor has four optional parameters that
     * allow customization of the
     *
     * @param string|null $url      Slack hook URL
     * @param string|null $username defaults to slackbot
     * @param string|null $emoji    defaults to none
     * @param Client|null $client   Optional for testing
     */
    public function __construct(?string $url, ?string $username = null, ?string $emoji = null, ?Client $client = null) {
        $this->url      = $url;
        $this->emoji    = $emoji    ?? $this->emoji;
        $this->username = $username ?? $this->username;
        $this->client   = $client   ?? new Client([]);
    }

    /**
     * Sends the message to slack
     *
     * @param string       $channel     Either a Slack #channel or @username.
     * @param string|array $message     Either a string or a slack attachment
     * @param array        $attachments Array of additional Slack message attachments.
     *                                  See https://api.slack.com/docs/attachments
     *
     * @return bool On success, returns boolean true. On failure returns boolean
     *              false or the error message from Slack.
     *
     * @throws MessageAttachmentException|GuzzleException|MessageException
     */
    public function send(string $channel, string|array $message, array $attachments = []): bool {
        $package = $this->buildPackage($channel, $message, $attachments);

        $response = $this->client->request(
            'POST',
            $this->url,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json'    => $package,
            ]
        );

        $result = (string)$response->getBody();

        if (empty($result) || $result != 'ok') {
            if (!empty($result)) {
                throw new \RuntimeException($result, 1);
            } else {
                throw new \RuntimeException('Unknown error sending Slack message', 2);
            }
        } else {
            $return = true;
        }

        return $return;
    }

    /**
     * Builds the package to send to Slack
     *
     * @param string       $channel     Either a Slack #channel or @username.
     * @param string|array $message     Either a string or a slack attachment
     * @param array        $attachments Array of additional Slack message attachments.
     *                                  See https://api.slack.com/docs/attachments
     *
     * @return array
     * @throws MessageAttachmentException|MessageException
     */
    public function buildPackage(string $channel, array|string $message, array $attachments): array {
        $data = [
            'channel'     => $channel,
            'username'    => $this->username,
            'attachments' => [],
        ];

        if (!empty($this->emoji)) {
            $data['icon_emoji'] = $this->emoji;
        }

        if (!empty($message)) {
            if (is_string($message)) {
                $data['text'] = $message;
            } elseif (is_array($message)) {
                $data['attachments'][] = $this->validateAttachment($message);
            }
        }

        foreach ($attachments as $a) {
            $data['attachments'][] = $this->validateAttachment($a);
        }

        if (empty($data['channel']) || (empty($data['text']) && empty($data['attachments']))) {
            throw new MessageException(
                'Channel not set'
            );
        }

        return $data;
    }

    /**
     * Validates an attachment array
     *
     * @param array $attachment The attachment
     *
     * @return     array                       Validated attachment
     * @throws     MessageAttachmentException
     *
     */
    public function validateAttachment(array $attachment): array {
        $fields_filter = [
            'title' => FILTER_UNSAFE_RAW,
            'value' => FILTER_UNSAFE_RAW,
            'short' => FILTER_VALIDATE_BOOLEAN,
        ];

        $filter = [
            'fallback' => FILTER_UNSAFE_RAW,

            'color' => [
                'filter'  => FILTER_VALIDATE_REGEXP,
                'options' => [
                    'regexp' => '/^(good|warning|danger|#[\\da-fA-F]{6})$/',
                ],
            ],

            'pretext' => FILTER_UNSAFE_RAW,

            'author_name' => FILTER_UNSAFE_RAW,
            'author_link' => FILTER_VALIDATE_URL,
            'author_icon' => FILTER_VALIDATE_URL,

            'title'      => FILTER_UNSAFE_RAW,
            'title_link' => FILTER_VALIDATE_URL,

            'text' => FILTER_UNSAFE_RAW,

            'image_url' => FILTER_VALIDATE_URL,
            'thumb_url' => FILTER_VALIDATE_URL,
        ];

        ksort($attachment);

        if (isset($attachment['fields'])) {
            $original_attachment = $attachment;
            $fields              = $attachment['fields'];
            unset($attachment['fields']);
            foreach ($fields as $x => $f) {
                $filtered = filter_var_array($f, $fields_filter);
                if (is_array($filtered)) {
                    $fields[$x] = $filtered;
                }
            }
        }

        $filtered_attachment = filter_var_array($attachment, $filter);

        if (empty($filtered_attachment['fallback'])) {
            throw new MessageAttachmentException(
                'All attachments require `fallback` to be set'
            );
        }

        if (!empty($fields)) {
            $filtered_attachment['fields'] = $fields;
            $attachment                    = $original_attachment;
        }

        foreach ($filtered_attachment as $key => $value) {
            if (!array_key_exists($key, $attachment)) {
                unset($filtered_attachment[$key]);
            } else {
                if ($value != $attachment[$key]) {
                    throw new MessageAttachmentException(
                        "Invalid value for `$key`"
                    );
                }
            }
        }

        return $filtered_attachment;
    }
}
