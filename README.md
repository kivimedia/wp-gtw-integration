# WP-GTW Integration

WordPress plugin that replaces Zapier for GoToWebinar registration. Auto-detects the next upcoming session, registers form submitters via WPForms, and alerts the admin when something goes wrong.

## The Problem

GoToWebinar recurring webinars share a name across all sessions. You can't rename individual sessions. So the only way to register someone is by session ID - which changes every week. Zapier requires manually updating this ID after each session. Forget once, and registrations silently fail.

## What This Plugin Does

- Auto-detects the next upcoming session by querying the GoToWebinar API
- Auto-switches to the new session the moment the current one ends
- Fires on WPForms submission (no Zapier, no external tools)
- Caches the session ID to minimize API calls (configurable TTL)
- Retries failed registrations via WP-Cron
- Emails the admin when something fails
- Logs all registration attempts in WP Admin

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WPForms (free or pro)
- GoToWebinar account with API access (REST API v2)

## Installation

1. Download the latest release zip
2. WP Admin > Plugins > Add New > Upload Plugin
3. Activate
4. Go to Settings > GTW Integration

## Setup (under 30 minutes)

1. **Get GoToWebinar API credentials:** Log into [GoTo Developer Center](https://developer.goto.com/), create an OAuth app, get Client ID and Client Secret
2. **Enter credentials:** Settings > GTW Integration > API Credentials > Save > Test Connection
3. **Enter webinar key:** The series key from your recurring webinar URL
4. **Map form fields:** Select your WPForms form, enter the field IDs for First Name, Last Name, Email, Phone, Organization
5. **Save** and you're done

## Architecture

```
wp-gtw-integration/
  wp-gtw-integration.php        Main plugin file, hooks, WP-Cron
  includes/
    class-gtw-api.php            GoToWebinar API wrapper (OAuth, registration)
    class-gtw-session-resolver.php  Auto-detection, caching, auto-switch
    class-gtw-wpforms-handler.php   WPForms hook, field mapping
    class-gtw-logger.php            Activity log, email alerts
  admin/
    settings-page.php            Settings UI (credentials, webinar, mapping)
    log-page.php                 Activity log viewer
```

## How Auto-Switch Works

1. Registration fires via WPForms
2. Plugin checks cached session - is the end time still in the future?
3. If yes, use the cached session
4. If no (session ended), clear cache, query API for next session
5. Cache the new session for 15 minutes (configurable)
6. If no future session exists, queue for retry and email admin

## Multi-Series (v2 Ready)

The database schema supports multiple webinar series from day one. V1 UI manages one series. V2 will expose multi-series management.

## License

GPL-2.0-or-later
