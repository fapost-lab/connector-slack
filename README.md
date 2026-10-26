# fapost/connector-slack

A [FaPost](https://docs.fapost.in) channel connector for Slack: a tenant's assistant answers in a Slack
workspace through a Slack app, with the same flows that answer in Telegram.

It is an optional connector package (`type: fapost-connector`), installed on top of Core through the build
overlay and reached only through the connector contract in `fapost/foundation` — Core holds no Slack code.
It is also the contract's first outside connector: the gaps it finds go back into the contract.

**Status: in development.** Nothing here is usable yet; the plan lives in Core's `channel-connectors`
specification, Phase 3.

## What it will do

- Receive Slack's Events API and interactivity requests, verified by Slack's signing secret.
- Send text, Block Kit buttons and media by public link; update a message to remove its buttons.
- One Slack app per FaPost channel: the bot token and signing secret are the channel's settings, and the
  app is created from a manifest the channel screen gives you.

Docs: [Channel drivers](https://docs.fapost.in/extending/channel-drivers).

## Contributing

Contributions are licensed under Apache-2.0 and need the Contributor Licence Agreement, which a bot asks you to
sign on your first pull request.

## License

Apache-2.0, see [LICENSE](LICENSE).
