# Chunked File Uploads for Craft CMS

This plugin enables chunked file uploads in the control  panel of
the Craft CMS. It allows users to upload files being larger then the
file upload limit given by your web server.


## Requirements

This plugin requires Craft CMS 4.0 or later. If you are using Craft 3, please
use should switch to version 1.x of this plugin.


## Installation

To install the plugin either use the plugin store or follow these
instructions:

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require sebastianlenz/craft-chunked-uploads

3. Install the plugin:

        ./craft install/plugin chunked-uploads


## Settings

Within your control panel visit the page `Settings` and look 
for the plugin in the `Plug-ins` section. The plugin allows
you to both configure the global maximum upload size as well
as upload limits for individual folders.
