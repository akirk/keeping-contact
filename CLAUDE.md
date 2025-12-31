# CLAUDE.md

Keeping Contact is a WordPress plugin that extends the Personal CRM plugin to track contact frequency and outreach with people in your network. It integrates with Beeper (a unified messaging platform) to sync messaging history and enable sending messages directly from the CRM.

## Architecture

### Plugin Dependencies

- **Requires**: `personal-crm` plugin (must be installed and active)
- **Extends**: Personal CRM's routing, storage, and hook systems
- **Uses**: WpApp framework for WordPress application development

### Core Classes

**`KeepingContact\KeepingContact`** (`includes/keeping-contact.php`)
- Main plugin class, singleton pattern via `init($crm)` and `get_instance()`
- Registers all WordPress hooks, AJAX handlers, and routes
- Hooks into Personal CRM via filters: `personal_crm_person_sidebar`, `personal_crm_person_quick_links`, `personal_crm_build_url`

**`KeepingContact\Storage`** (`includes/storage.php`)
- Extends `WpApp\BaseStorage` for database operations
- Two custom tables: `keeping_contact_schedules` (contact frequency settings per person) and `keeping_contact_log` (contact history records)
- Key methods: `get_contact_stats()`, `get_overdue_contacts()`, `log_contact()`

**`KeepingContact\Beeper`** (`includes/beeper.php`)
- API client for Beeper Desktop local API (`localhost:23373`)
- Token stored in WordPress options (`keeping_contact_beeper_token`)
- Key methods: `search_chats()`, `get_chat_messages()`, `send_message()`, `get_recent_context()`

### Routes

Routes are registered via Personal CRM's routing system:
- `/crm/outreach` - Outreach dashboard
- `/crm/outreach/{person}` - Individual outreach page
- `/crm/conversations/{person}` - Message drafting with AI assistant
- `/crm/analysis/{person}` - Relationship analysis visualization
- `/crm/analysis-group/{group}` - Group-level relationship analysis

### Beeper Integration

Chat mappings stored in `keeping_contact_beeper_chats` table (chat_id, username).

AJAX endpoints prefixed with `kc_beeper_*` handle search, link/unlink, sync, and message sending.

### Hooks Integration Pattern

When extending Personal CRM functionality, use the filter system:
```php
// Quick links use apply_filters with structured array
add_filter( 'personal_crm_person_quick_links', [ $this, 'render_quick_links' ], 10, 4 );

// Quick link structure
$quick_links['link-id'] = [
    'url'     => '...',
    'label'   => '...',
    'icon'    => '✏️',
    'target'  => '_blank',  // optional
    'onclick' => '...',     // optional
    'title'   => '...',     // optional
    'class'   => '...',     // optional
];
```

### JavaScript Assets

- `beeper.js` - Beeper modal search and linking functionality
- `conversation.js` - Message drafting with AI integration
- `analysis.js` / `analysis-group.js` - Relationship visualization with message loading
