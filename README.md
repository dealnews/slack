# Slack Library

A library for sending simple messages to Slack from PHP. We have used this internally for years. The other options out 
there seem to be out of date and no longer supported.

## Send a Message

```php

use DealNews\Slack\Message;

// Send a simple text message
$message = new Message($hook_url);
$message->send("channel", "message");

// Send a formatted message
$message->send(
    "channel",
    [
        'fallback' => 'Some fallback text for older clients',
        'color'    => '#dadada',
        'fields'   => [
            [
                'title' => 'Test',
                'value' => 'testing',
                'short' => true,
            ],
        ],
        'pretext'     => 'pretext',
        'author_name' => 'author',
        'author_link' => 'http://www.example.com/',
        'author_icon' => 'http://www.example.com/example.jpg',
        'title'       => 'title',
        'title_link'  => 'http://www.example.com/',
        'text'        => 'text',
        'image_url'   => 'http://www.example.com/example.jpg',
        'thumb_url'   => 'http://www.example.com/example.jpg',
    ]
);
```
