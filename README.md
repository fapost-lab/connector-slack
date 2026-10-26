# fapost/connector-slack

A [FaPost](https://docs.fapost.in) channel connector for Slack: a tenant's assistant answers in a Slack
workspace through a Slack app, with the same flows that answer in Telegram.

It is an optional connector package (`type: fapost-connector`), installed on top of Core through the build
overlay and reached only through the connector contract in `fapost/foundation` — Core holds no Slack code.
It is also the contract's first outside connector: the gaps it found went back into the contract.

Docs: [Connectors](https://docs.fapost.in/extending/connectors) and
[Channel drivers](https://docs.fapost.in/extending/channel-drivers).

## What it does

- Receives Slack's Events API and interactivity requests at one URL per channel and checks Slack's signature (the
  signing secret, with the five-minute window against replays). It answers Slack's URL check itself.
- Turns a person's text or file in a direct message with the app into a FaPost message, and a press on one of its
  buttons into a callback. The app's own messages, edits, deletions and every other event are dropped quietly.
- Sends text (Slack mrkdwn, split in sections), callback buttons (Block Kit), images and files by public link, and
  removes the buttons of a message that was sent earlier.
- Reads the workspace and the app from Slack when the channel is saved, and shows a link that opens the app.
- Downloads the files people send, with the bot token.

## Requirements

- FaPost Core with `fapost/foundation` 0.33 or newer (`composer.json` requires `>=0.33 <1.0`). A Core that locks an
  older Foundation does not have the ingress spec version 2 this connector needs.
- PHP 8.4 or newer, Laravel 12 or 13.
- A Slack workspace where you may create an app, and a public `https` address for Core's webhook (Slack refuses
  `http` and `localhost`; a tunnel does for development).

## Install

The package is added on top of Core with a [composer overlay](https://docs.fapost.in/extending/development-setup),
so Core's own `composer.json` stays untouched. From the root of a Core checkout:

```bash
git clone https://github.com/fapost-lab/connector-slack.git packages/connector-slack
```

Add these entries to your `composer.overlay.json` (create the file if there is none; keep what is already in it):

```json
{
  "repositories": [{ "type": "path", "url": "packages/connector-slack" }],
  "require": { "fapost/connector-slack": "@dev" }
}
```

```bash
composer overlay
```

Further Composer commands in that checkout need `COMPOSER=composer.local.json`. Then let Core validate the
manifest and register the channel type:

```bash
php artisan platform:update
```

`Slack` then appears among the channel types of an assistant.

## Set up a Slack app

One Slack app serves one FaPost channel. The channel's page shows the steps with the values to copy; this is the
same in short. It takes two rounds, because the Request URL exists only once the channel does.

**First, without a URL**

1. In the channel form choose `Slack`. The setup guide shows a manifest. At
   [api.slack.com/apps](https://api.slack.com/apps) choose **Create New App**, **From a manifest**, pick the
   workspace and paste it.
2. **Install App**, **Install to Workspace**.
3. Copy the **Bot User OAuth Token** (`xoxb-…`, under OAuth & Permissions) into the channel's *Token*, and the
   **Signing Secret** (Basic Information, App Credentials; 32 hexadecimal characters) into *Secret token*. Save.

Saving checks the token with Slack (`auth.test`, `bots.info`) and fills the workspace, bot and app fields. A
*registered* webhook status means the token was accepted, not that the URL is in place.

**Then, with the channel's URL**

4. Reopen the channel. The guide now shows the **Request URL**. Paste it into *Event Subscriptions* (switch events
   on and add the bot event `message.im`) **and** into *Interactivity & Shortcuts*. It is the same URL for both.
   Slack checks it at once and shows *Verified*. Or paste the second manifest it shows into *App Manifest*, which
   sets both, and confirm the URL when Slack asks.
5. Open the app in Slack, go to its **Messages** tab and write to it.

If the channel's public hash is rotated, the URL changes: paste the new one into both places again.

The bot scopes are `chat:write`, `im:history`, `users:read` and `files:read`, the same in both manifests, so the
second round never asks for a reinstall.

## Capabilities and limits

| | |
|---|---|
| Direct messages with the app | yes; channels and mentions are not handled |
| Text | yes, as mrkdwn; HTML (`b`, `i`, `s`, `code`, `pre`, `a`) from flows is translated, other tags are dropped |
| Callback buttons | yes, one actions block per row, up to 25 buttons in a row, labels cut at 75 characters, up to 50 blocks in a message |
| Reply, contact and location buttons | no: Slack cannot send a label as the person's own message and has nothing like contact or location buttons. A flow that uses them is warned about |
| Removing buttons of an earlier message | yes (`chat.update`) |
| Buttons of older messages stay pressable | yes |
| Media from FaPost to Slack | by an expiring public link: PNG, JPEG and GIF as an image, everything else as a link. The link lives 24 hours, so an image can disappear from the history after that |
| Media from Slack to FaPost | yes, any file the app may read |
| Typing indicator | no |

## Development

Check the package out into Core's `packages/` directory (as in *Install*), then run its tests inside Core, with Core's
autoloader — `tests/bootstrap.php` finds Core by itself, or from `FAPOST_CORE_PATH`:

```bash
cd packages/connector-slack
php ../../vendor/bin/phpunit -c phpunit.xml
../../vendor/bin/pint --test --config pint.json src tests lang
```

The feature tests (`tests/Feature`) use Core's connector testing kit, `Fapost\Testing\ConnectorTestCase`: they post
signed requests to Core's real webhook route, send through Core's message sender and run its webhook registration,
with Slack faked by `Http::fake()`. A test run does not have the package in Core's `composer.json`, so the test boots it with the kit's
`bootConnector(SlackConnectorServiceProvider::class)`, which validates the manifest in this `composer.json` and registers
the provider's channels through Core's connector registrar. `phpunit.xml` leaves Core's connector registry open
(`SOLUTIONS_FREEZE_CONSOLE_REGISTRY=false`) for that.

The Slack requests and responses the tests use are in `tests/fixtures/slack/`; each file names the page of
docs.slack.dev it was derived from in its `_source` key. They hold no real token or identifier.

CI checks the package against Core's contract line and against the latest Core release (skipped while that release
locks a Foundation older than 0.33), and proves that Core still boots without the package.

## Contributing

Contributions are licensed under Apache-2.0 and need the Contributor Licence Agreement, which a bot asks you to
sign on your first pull request.

## License

Apache-2.0, see [LICENSE](LICENSE).
