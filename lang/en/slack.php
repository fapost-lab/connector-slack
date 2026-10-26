<?php

declare(strict_types=1);

return [
    'fields' => [
        'team_id'     => ['label' => 'Workspace ID'],
        'team_name'   => ['label' => 'Workspace'],
        'team_url'    => ['label' => 'Workspace URL'],
        'bot_user_id' => ['label' => 'Bot user ID'],
        'bot_id'      => ['label' => 'Bot ID'],
        'bot_name'    => ['label' => 'Bot name'],
        'app_id'      => ['label' => 'App ID'],
    ],
    'setup' => [
        'create' => [
            'title' => 'Create the Slack app from a manifest',
            'text'  => 'Open api.slack.com/apps, choose Create New App, then From a manifest. Pick your workspace and paste this JSON. It has no Request URL yet: the channel has to exist first.',
        ],
        'install' => [
            'title' => 'Install the app to your workspace',
            'text'  => 'Open Install App in the app settings and choose Install to Workspace.',
        ],
        'credentials' => [
            'title' => 'Copy the token and the signing secret',
            'text'  => 'Copy the Bot User OAuth Token (OAuth & Permissions) into the Token field and the Signing Secret (Basic Information, App Credentials) into the Secret token field. Save the channel: this guide then shows the Request URL.',
        ],
        'request_url' => [
            'title' => 'Paste the Request URL into Slack',
            'text'  => 'In Event Subscriptions switch events on, paste this URL into Request URL and add the bot event message.im; then paste the same URL into Request URL in Interactivity & Shortcuts. Slack checks the URL at once and shows Verified.',
        ],
        'manifest' => [
            'title' => 'Or update the whole app from a manifest',
            'text'  => 'Instead of the step above, open App Manifest in the app settings, paste this JSON, save, and confirm the Request URL when Slack asks.',
        ],
        'check' => [
            'title' => 'Check the connection',
            'text'  => 'Open the app in Slack, go to its Messages tab and write to the bot: the assistant answers there. The webhook status of this channel means only that Slack accepted the token, not that the URL is in place.',
        ],
    ],
];
