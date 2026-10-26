<?php

declare(strict_types=1);

return [
    'fields' => [
        'team_id'     => ['label' => 'ID рабочего пространства'],
        'team_name'   => ['label' => 'Рабочее пространство'],
        'team_url'    => ['label' => 'Адрес рабочего пространства'],
        'bot_user_id' => ['label' => 'ID пользователя-бота'],
        'bot_id'      => ['label' => 'ID бота'],
        'bot_name'    => ['label' => 'Имя бота'],
        'app_id'      => ['label' => 'ID приложения'],
    ],
    'setup' => [
        'create' => [
            'title' => 'Создайте приложение Slack из манифеста',
            'text'  => 'Откройте api.slack.com/apps, нажмите Create New App, затем From a manifest. Выберите рабочее пространство и вставьте этот JSON. Request URL в нём пока нет: сначала должен появиться канал.',
        ],
        'install' => [
            'title' => 'Установите приложение в рабочее пространство',
            'text'  => 'В настройках приложения откройте Install App и нажмите Install to Workspace.',
        ],
        'credentials' => [
            'title' => 'Скопируйте токен и signing secret',
            'text'  => 'Bot User OAuth Token (OAuth & Permissions) вставьте в поле «Токен», Signing Secret (Basic Information, App Credentials) — в поле «Секретный токен». Сохраните канал: в этой инструкции появится Request URL.',
        ],
        'request_url' => [
            'title' => 'Вставьте Request URL в Slack',
            'text'  => 'В Event Subscriptions включите события, вставьте этот адрес в Request URL и добавьте событие бота message.im; затем вставьте тот же адрес в Request URL в Interactivity & Shortcuts. Slack проверит адрес сразу и покажет Verified.',
        ],
        'manifest' => [
            'title' => 'Или обновите всё приложение манифестом',
            'text'  => 'Вместо шага выше откройте App Manifest в настройках приложения, вставьте этот JSON, сохраните и подтвердите Request URL, когда Slack попросит.',
        ],
        'check' => [
            'title' => 'Проверьте подключение',
            'text'  => 'Откройте приложение в Slack, перейдите на вкладку Messages и напишите боту: ассистент ответит там. Статус вебхука этого канала означает только то, что Slack принял токен, а не то, что адрес вставлен.',
        ],
    ],
];
