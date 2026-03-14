# PressWire Importer

A WordPress plugin that seamlessly imports press releases from PressWire India (presswireindia.com) and publishes them as blog posts on your WordPress site. Perfect for news portals, this plugin features field mapping and category mapping for customized content integration, ensuring a user-friendly experience.

## Features

- **Automated Import**: Pulls press releases directly from the PressWire India API.
- **Field Mapper**: Customize how API fields map to WordPress post fields (title, content, excerpt, etc.).
- **Category Mapper**: Assign categories to imported posts based on predefined rules.
- **Scheduler**: Set up automated imports at regular intervals.
- **Admin Interface**: User-friendly settings panel for configuration.

## Installation

1. Download the plugin zip file from the [GitHub releases](https://github.com/gsazad/presswire/releases).
2. Upload the plugin to your WordPress site's `/wp-content/plugins/` directory, or install it directly via the WordPress admin panel (Plugins > Add New > Upload Plugin).
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Navigate to **Settings > PressWire Importer** to configure the plugin.

## Usage

1. **API Configuration**: The default API endpoint is `https://presswireindia.com/api/v1/news/releases`. You can modify this in the settings if needed.
2. **Field Mapping**: Use the field mapper to define how incoming data fields correspond to WordPress post fields.
3. **Category Mapping**: Set rules to automatically assign categories to imported posts.
4. **Scheduling**: Enable the scheduler to run imports automatically (e.g., daily or hourly).
5. **Manual Import**: Run a one-time import from the admin panel.

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher

## Changelog

### Version 1.3
- Improved field and category mapping interfaces.
- Added scheduler for automated imports.
- Bug fixes and performance improvements.

## License

This plugin is licensed under the GPL v2 or later.

## Contributing

Contributions are welcome! Please fork the repository and submit a pull request. For major changes, open an issue first to discuss what you would like to change.

## Support

If you encounter any issues or have questions, please open an issue on [GitHub](https://github.com/gsazad/presswire/issues).

## Author

Developed by wire.sarabit.com for PressWire India.