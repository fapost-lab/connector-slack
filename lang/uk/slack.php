<?php

declare(strict_types=1);

return [
    'fields' => [
        'team_id'     => ['label' => 'ID робочого простору'],
        'team_name'   => ['label' => 'Робочий простір'],
        'team_url'    => ['label' => 'Адреса робочого простору'],
        'bot_user_id' => ['label' => 'ID користувача-бота'],
        'bot_id'      => ['label' => 'ID бота'],
        'bot_name'    => ['label' => 'Ім’я бота'],
        'app_id'      => ['label' => 'ID застосунку'],
    ],
    'setup' => [
        'create' => [
            'title' => 'Створіть застосунок Slack із маніфесту',
            'text'  => 'Відкрийте api.slack.com/apps, натисніть Create New App, потім From a manifest. Оберіть робочий простір і вставте цей JSON. Request URL у ньому поки немає: спершу має з’явитися канал.',
        ],
        'install' => [
            'title' => 'Встановіть застосунок у робочий простір',
            'text'  => 'У налаштуваннях застосунку відкрийте Install App і натисніть Install to Workspace.',
        ],
        'credentials' => [
            'title' => 'Скопіюйте токен і signing secret',
            'text'  => 'Bot User OAuth Token (OAuth & Permissions) вставте в поле «Токен», Signing Secret (Basic Information, App Credentials) — у поле «Секретний токен». Збережіть канал: в цій інструкції з’явиться Request URL.',
        ],
        'request_url' => [
            'title' => 'Вставте Request URL у Slack',
            'text'  => 'В Event Subscriptions увімкніть події, вставте цю адресу в Request URL і додайте подію бота message.im; потім вставте ту саму адресу в Request URL в Interactivity & Shortcuts. Slack перевірить адресу одразу й покаже Verified.',
        ],
        'manifest' => [
            'title' => 'Або оновіть увесь застосунок маніфестом',
            'text'  => 'Замість кроку вище відкрийте App Manifest у налаштуваннях застосунку, вставте цей JSON, збережіть і підтвердьте Request URL, коли Slack попросить.',
        ],
        'check' => [
            'title' => 'Перевірте підключення',
            'text'  => 'Відкрийте застосунок у Slack, перейдіть на вкладку Messages і напишіть боту: асистент відповість там. Статус вебхука цього каналу означає лише те, що Slack прийняв токен, а не те, що адресу вставлено.',
        ],
    ],
];
