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


## Usage

The plugin will kick in on requests to the `assets/upload` controller when:

- has headers `content-range` and `content-disposition`
- has a file field named `assets-upload`

The Craft JS lib will be automatically patched to perform this.

For frontend uploads, you can use the `jquery.fileupload` plugin or this lightweight ChunkedUploader:

```
{% do view.registerAssetBundle("karmabunny\\ChunkedUploads\\assets\\ChunkedUploader") %}
```

To use this library:

```js
new ChunkedUploader({
    file: input.files[0],
    query: [
        [CSRF_TOKEN_NAME]: CSRF_TOKEN_VALUE,
        elementId: 100,
        fieldId: 200,
    ],
})
.on('progress' event => {
    // event.loaded
    // event.total
    // event.progress (0-1)
})
.on('chunk', event => {
   // event.data - (mutable)
   // event.error - modify this to throw an error
})
.then(data => {
    // yay!
})
.catch(error => {
    // oh no!
})
```


Options (defaults):

- file: (required)
- field: 'assets-upload'
- method: 'POST'
- url: '/actions/assets/upload'
- query: {}
- headers: {}
- chunkSize: 5 * 1000 * 1024 (5MB)


Query parameters:

- `elementId` required
- `fieldId` required
- `save` - optional, one of 'append' or 'replace'


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
