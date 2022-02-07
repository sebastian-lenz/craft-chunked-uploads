(function(window) {
    const toBytes = 1048576;

    let settings = {
        chunkSize: 5,
        maxUploadSize: 500,
    };

    try {
        var scriptTag = document.querySelector("script[data-key='karmabunny/chunked-uploads/chunked']");
        settings = JSON.parse(scriptTag.getAttribute('data-settings'));
    } catch (error) {
        console.error(error);
    }

    /**
     * Chunked file uploader.
     *
     * Usage:
     * ```
     * new ChunkedUploader({
     *     file: input.files[0],
     *     field: 'assets-upload',
     *     url: '/actions/assets/upload',
     *     query: [
     *         [CSRF_TOKEN_NAME]: CSRF_TOKEN_VALUE,
     *         elementId: 100,
     *         fieldId: 200,
     *     ],
     * })
     * .on('progress' event => {
     *     // event.loaded
     *     // event.total
     *     // event.progress (0-1)
     * })
     * .on('chunk', event => {
     *    // event.data - (mutable)
     *    // event.error - modify this to throw an error
     * })
     * .then(data => {
     *     // yay!
     * })
     * .catch(error => {
     *     // oh no!
     * })
     * ```
     *
     * Options (defaults):
     * - file: (required)
     * - field: 'assets-upload'
     * - method: 'POST'
     * - url: '/actions/assets/upload'
     * - query: {}
     * - headers: {}
     * - chunkSize: 5 * 1000 * 1024 (5MB)
     *
     * @param {object} config
     */
    window.ChunkedUpload = function(config) {
        config = Object.assign({
            file: null,
            field: 'assets-upload',
            method: 'POST',
            url: 'actions/assets/upload',
            query: {},
            headers: {},
            chunkSize: settings.chunkSize * toBytes,
        }, config);

        if (!(config.file instanceof File)) {
            throw new Error('Not a file.');
        }

        // Load in additional query data.
        const formData = new FormData();

        if (config.query) {
            for (let key in config.query) {
                // But skip any blobs. They'll just cause trouble.
                if (config.query[key] instanceof Blob) continue;

                formData.set(key, config.query[key]);
            }
        }

        const state = {
            events: {},
            chunkOffset: 0,
            uploadTotal: 0,
        }

        /**
         * Internal sending loop.
         *
         * @param {Function} resolve
         * @param {Function} reject
         * @returns {void}
         */
        function send(resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open(config.method, config.url);

            // Trigger progress events.
            xhr.upload.addEventListener('progress', event => {
                state.uploadTotal = state.chunkOffset + event.loaded;

                emit('progress', {
                    lengthComputable: true,
                    loaded: state.uploadTotal,
                    total: config.file.size,
                    percent: state.uploadTotal / config.file.size,
                });
            });

            // Handle the body response.
            // - Throw errors when the occur.
            // - Otherwise resolve(finished).
            xhr.addEventListener('readystatechange', () => {
                if (xhr.readyState !== 4) return;

                // Bad status.
                if (xhr.status >= 300) {
                    reject(new Error(xhr.responseText));
                    return;
                }

                const event = {
                    data: xhr.responseText,
                    error: null,
                };
                emit('chunk', event);

                // Event says no.
                if (event.error) {
                    reject(new Error(event.error));
                    return;
                }

                // Ok fine.
                const finished = state.chunkOffset >= config.file.size;
                resolve(finished ? event.data : null);
            });

            // By default we'd expect a JSON response, but we can still
            // override this with the header config.
            xhr.setRequestHeader('Accept', 'application/json');

            // Any extra headers.
            for (let key in config.headers) {
                xhr.setRequestHeader(key, config.headers[key]);
            }

            // Under the chunk limit, or disabled.
            // Send the whole thing with no chunking headers.s
            if (config.chunkSize == 0 || config.file.size <= config.chunkSize) {
                state.chunkOffset = config.file.size;
                formData.set(config.field, config.file, config.file.name);
            }
            // Slice me a chunk.
            else {
                const chunk = config.file.slice(state.chunkOffset, state.chunkOffset + config.chunkSize);
                formData.set(config.field, chunk, config.file.name);

                // Increment the chunk window.
                const chunkStart = state.chunkOffset;
                state.chunkOffset = state.chunkOffset + chunk.size;
                const chunkEnd = state.chunkOffset - 1;

                const range = 'bytes ' + chunkStart + '-' + chunkEnd + '/' + config.file.size;
                const disposition = 'form-data; name="' + config.field + '"; filename="' + config.file.name + '"';

                // These are required to trigger the chunked upload helper.
                xhr.setRequestHeader('content-range', range);
                xhr.setRequestHeader('content-disposition', disposition);
            }

            // Go.
            xhr.send(formData);
        }


        /**
         * Trigger an event.
         *
         * @param {string} name
         * @param {any} data
         * @returns {void}
         */
        function emit(name, data) {
            if (!state.events[name]) return;

            for (let key in state.events[name]) {
                state.events[name][key](data);
            }
        }

        class ChunkedUpload extends Promise {
            on(event, callback) {
                state.events[event] = state.events[event] || [];
                state.events[event].push(callback);
                return this;
            }
        }


        return (function loop(data) {
            // No data means we're not done.
            if (!data) {
                return new ChunkedUpload(send)
                .then(loop);
            }

            // All done.
            return data;
        })(null);
    }
})(window);
