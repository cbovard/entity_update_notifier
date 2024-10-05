# Entity Update Notifier

## Overview

The Entity Update Notifier is a custom Drupal module that provides automated notifications for content updates. It allows administrators to configure email notifications for specific content types and vocabularies, alerting designated recipients when entities haven't been updated within a specified timeframe.

## Installation

1. Place the `entity_update_notifier` folder in your Drupal installation's `modules/custom` directory.
2. Enable the module through the Drupal admin interface or using Drush:
   ```
   drush en entity_update_notifier
   ```
3. Clear the cache:
   ```
   drush cr
   ```

## Usage

1. Go to the Entity Update Notifier settings page (`/admin/config/content/entity-update-notifier`).
2. Select the content types and vocabularies you want to monitor.
3. For each selected entity type, configure:
   - Email recipient(s)
   - Number of days before notification
   - Custom email text
   - Sort order for the notification list
4. Set the daily cron run time for sending notifications.
5. Save the configuration.

## Dependencies

- Drupal Core 9.x, 10.x or 11.x
- Node module (core)
- Taxonomy module (core)

## TODO

- Test for Language Support.
- Add phpunit tests.
- Add code documentation.
- Add to Drupal.org

## Maintainers

Chris Bovard - https://www.chrisbovard.com
Ron Merten - https://www.metalgrass.com/
