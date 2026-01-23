# Keeping Contact

A WordPress plugin that extends [Personal CRM](https://github.com/akirk/personal-crm) to track contact frequency and outreach with people in your network. Optionally integrates with [Beeper](https://www.beeper.com/) to sync messaging history and enable sending messages directly from the CRM.

## Features

- **Contact Scheduling** — Set custom contact frequencies (weekly, monthly, quarterly, etc.) for each person
- **Outreach Dashboard** — See who is overdue for contact and who is coming due soon
- **Contact Logging** — Log contacts manually with type (email, call, meeting, message) and notes
- **Priority Levels** — Mark contacts as high, normal, or low priority
- **Pause Schedules** — Temporarily pause reminders for specific people
- **Beeper Integration** (optional):
  - Link CRM contacts to Beeper chat threads
  - View recent message history from within the CRM
  - Draft and send messages with AI assistance
  - Auto-sync last contact dates from message history
  - Bulk-connect existing contacts to Beeper chats
  - Relationship analysis visualization

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [Personal CRM](https://github.com/akirk/personal-crm) plugin (must be installed and activated)
- Beeper Desktop (optional, for messaging integration)

## Installation

1. Install and activate the [Personal CRM](https://github.com/akirk/personal-crm) plugin first
2. Download or clone this repository into `wp-content/plugins/keeping-contact`
3. Activate "Keeping Contact" in WordPress admin → Plugins
4. The database tables are created automatically on activation

## Configuration

### Setting Up Beeper (Optional)

Beeper integration allows you to connect your messaging history with your CRM contacts. This requires Beeper Desktop running locally.

1. Open Beeper Desktop
2. Go to Settings → Developer → Create API Token
3. In your CRM, navigate to Outreach → Settings
4. Enter your Beeper API token and save

The plugin communicates with Beeper Desktop's local API (localhost:23373) — your messages stay on your machine and are never sent to external servers.

## Usage

### Adding a Contact Schedule

1. Navigate to a person's profile in Personal CRM
2. In the sidebar, use the "Keeping Contact" dropdown to set a schedule
3. Or edit the person and set frequency, priority, and notes in the form

### Viewing Overdue Contacts

1. Go to Outreach in the main menu
2. The dashboard shows:
   - **Overdue** — People past their contact due date
   - **Due Soon** — People due within the next 2 weeks
   - **Needs Schedule** — People linked to Beeper but without a schedule

### Logging a Contact

1. From the Outreach dashboard, click "Log Contact" on any person
2. Select the contact type (email, call, meeting, message, general)
3. Set the date and add optional notes
4. Click Save

### Linking Beeper Chats

1. On a person's profile page, click "+ Connect Beeper chat"
2. Search for their chat in the modal
3. Click to link — their message history will now sync

### Sending Messages

1. On a person's profile, click "Send message" in the quick links
2. View recent conversation context
3. Draft your message (with optional AI assistance)
4. Send directly through Beeper

## Database Tables

The plugin creates three tables (prefixed with your WordPress table prefix):

- `keeping_contact_schedules` — Contact frequency settings per person
- `keeping_contact_log` — Contact history records
- `keeping_contact_beeper_chats` — Beeper chat to person mappings

## Hooks

The plugin provides actions for extending functionality:

```php
// Fires when a Beeper chat is linked to a person
do_action( 'keeping_contact_beeper_chat_linked', $username, $chat_id, $phone );
```

## Contributing

Contributions are welcome. Please open an issue to discuss significant changes before submitting a pull request.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.
