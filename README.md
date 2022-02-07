# Chunked File Uploads for Craft CMS

This plugin enables chunked file uploads across your whole application.

In particular, this plugin supports uploading chunks to an AWS bucket with a multipart upload.

The core of the plugin is derived from the `sebastianlenz/craft-chunked-uploads` plugin.
Features such as image transformation, per folder file sizes, and user interfaces are removed.


## Requirements

This plugin requires Craft CMS 3.1 or later.


## Installation

To install the plugin either use the plugin store or follow these
instructions:

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require karmabunny/craft-chunked-uploads

3. Install the plugin:

        ./craft install/plugin chunked-uploads


## Settings

Create a file `chunked.php` in your config/ folder. Here you can control the chunk size and maximum file size.

```php
return [
    // in megabytes - default 5mb.
    'chunkSize' => 5,
    // in megabytes - default 500mb.
    'maxUploadSize' => 500,
];
```
