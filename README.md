# GH3 Hash Runs Email Gateway

WordPress plugin that allows authorised users to create and update hash runs by emailing free-form text. Emails are received via ForwardEmail.net webhook, parsed by Anthropic Claude API, and written to the existing `hash_run` post type.

## Requirements

- WordPress 5.0+
- [GH3 Hash Runs](https://github.com/nonatech-uk/hash-calendar) plugin installed and activated
- Anthropic API key (Claude)
- ForwardEmail.net account configured to forward to the webhook URL

## Installation

1. Download the latest release ZIP
2. Upload via WordPress Plugins > Add New > Upload Plugin
3. Activate the plugin
4. Go to Hash Runs > Email Gateway to configure settings

## Configuration

### Settings (Hash Runs > Email Gateway)

- **Anthropic API Key** - Your Claude API key
- **Webhook Secret** - Auto-generated token for webhook authentication
- **Authorised Emails** - One email per line; only these senders can create/update runs
- **SMTP Settings** - ForwardEmail SMTP credentials for sending confirmation emails

### ForwardEmail.net Setup

1. Note the webhook URL shown on the settings page
2. In ForwardEmail.net, configure your alias to forward to the webhook URL as a POST request

## How It Works

```
Email -> ForwardEmail.net -> Webhook POST -> WordPress REST endpoint
  -> Validate sender against authorised list
  -> Send email body to Claude API for structured extraction
  -> Create or update hash_run post
  -> Send confirmation email back to sender
```

## Example Email

```
Subject: Next Monday's run

Run 2120, hare is Speedy, next Monday at the Cricket Ground Shere.
On Inn: The William Bray
```

This creates a hash run with:
- Run #2120
- Hare: Speedy
- Location: Cricket Ground Shere
- On Inn: The William Bray
- Date: next Monday (automatically resolved)

## Updating Existing Runs

Include the run number in your email to update an existing run rather than creating a new one. Only the fields mentioned in the email will be changed.

## Email Commands

Send an email with one of these subjects to trigger a command instead of creating/updating a run:

| Subject | Description |
|---------|-------------|
| **Help** | Receive an HTML email with full usage instructions, supported fields, and examples |
| **Export** | Receive a CSV attachment of all hash runs |
| **Import** | Attach a CSV file (same format as the export) to bulk create or update runs |

## License

Non-Commercial Use License - see [LICENSE](LICENSE) for details.
