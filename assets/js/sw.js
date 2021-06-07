importScripts('https://storage.googleapis.com/workbox-cdn/releases/6.1.5/workbox-sw.js');
importScripts('https://cdn.jsdelivr.net/npm/idb@4.0.5/build/iife/with-async-ittr-min.js');

workbox.loadModule('workbox-strategies');

const TILES_DOMAIN_NAMES = [
    'global.ssl.fastly.net',
    'tile.openstreetmap.se',
    'maps.wikimedia.org',
    'tiles.lyrk.org',
    'tile.openstreetmap.fr',
    'ssl.fastly.net',
    'api.mapbox.com'
];

// Routes needed to run the app
const SYMFONY_ROUTES = [
    '/appli',
    '/api/manifest',
    '/api/gogocartojs-conf.json'
];

const UsePrecachePlugin = precache => ({
    cacheKeyWillBeUsed: async ({ request }) => {
        const url = new URL(request.url);
        return precache.getCacheKeyForURL(url.pathname);
    }
});

// We want the SW to delete outdated cache on each activation
workbox.precaching.cleanupOutdatedCaches();

// Create custom precache for Symfony routes
// The goal is to precache these routes so that they are available immediately on app install,
// but to update them when new versions are available (via the StaleWhileRevalidate strategy)
const symfonyRoutesCache = new workbox.precaching.PrecacheController({ cacheName: 'symfony-routes' });
self.addEventListener('install', event => event.waitUntil(symfonyRoutesCache.install(event)));
self.addEventListener('activate', event => event.waitUntil(symfonyRoutesCache.activate(event)));
symfonyRoutesCache.addToCacheList(SYMFONY_ROUTES.map(route => ({ url: route, revision: Date.now().toString() })));

workbox.routing.registerRoute(
    ({ url }) => SYMFONY_ROUTES.some(route => url.pathname.startsWith(route)),
    new workbox.strategies.StaleWhileRevalidate({
        cacheName: 'symfony-routes',
        plugins: [ UsePrecachePlugin(symfonyRoutesCache) ]
    })
);

// Full elements cache
// workbox.routing.registerRoute(
//     new RegExp('/api/elements/'),
//     new workbox.strategies.NetworkFirst({
//         networkTimeoutSeconds: 5,
//         cacheName: 'full-elements',
//         plugins: [
//             new workbox.expiration.ExpirationPlugin({
//                 maxEntries: 100,
//                 maxAgeSeconds: 7 * 24 * 60 * 60,
//                 purgeOnQuotaError: true
//             }),
//             new workbox.cacheableResponse.CacheableResponsePlugin({ statuses: [0, 200] })
//         ]
//     })
// );

// https://github.com/jakearchibald/idb
// https://developers.google.com/web/ilt/pwa/live-data-in-the-service-worker
// https://medium.com/jspoint/indexeddb-your-second-step-towards-progressive-web-apps-pwa-dcbcd6cc2076
// https://developer.mozilla.org/fr/docs/Web/API/IDBKeyRange
// https://localforage.github.io/localForage/#data-api-iterate
class BoundsCacheStrategy extends workbox.strategies.Strategy {
    async _handle(request, handler) {
        const db = await idb.openDB('gogocarto', 1, {
            upgrade(db) {
                // Create a store of objects
                const store = db.createObjectStore('compact-elements', {
                    keyPath: 'id'
                });
                // Create an index on the 'date' property of the objects.
                store.createIndex('lat', 'lat', { unique: false });
                store.createIndex('lng', 'lng', { unique: false });
            },
        });



        try {
            const response = await handler.fetch(request);

            if (response.ok) {
                const json = await response.clone().json();
                console.log('json', json);

                // for( const element of json.data ) {
                //     await db.add('compact-elements', {
                //         id: element[0],
                //         lat: element[2],
                //         lng: element[3],
                //         data: element
                //     });
                // }

                const tx = await db.transaction('compact-elements', 'readwrite');

                // Add all elements in one transaction
                // See https://github.com/jakearchibald/idb#article-store
                await Promise.all([
                    ...json.data.map(element => tx.store.add({
                        id: element[0],
                        lat: element[2],
                        lng: element[3],
                        data: element
                    })),
                    tx.done
                ]);
            }

            return response;
        } catch(e) {
            console.error(e);
            const requestUrl = new URL(request.url);
            const boundsJson = JSON.parse(requestUrl.searchParams.get('boundsJson'))[0];
            console.log('boundsJson lat', boundsJson._southWest.lat, boundsJson._northEast.lat);
            console.log('boundsJson lng', boundsJson._southWest.lng, boundsJson._northEast.lng);

            let data = [];

            // TODO see if we can improve performances with this solution: https://stackoverflow.com/a/32976384/7900695
            let cursor = await db.transaction('compact-elements').store.index('lat').openCursor(IDBKeyRange.bound(boundsJson._southWest.lat, boundsJson._northEast.lat));
            while (cursor) {
                if( cursor.value.lng >= boundsJson._southWest.lng && cursor.value.lng <= boundsJson._northEast.lng) {
                    data.push(cursor.value.data);
                }
                cursor = await cursor.continue();
            }

            const returnData = {
                data,
                licence: "https://opendatacommons.org/licenses/odbl/summary/",
                mapping: ["id", ["name"], "latitude", "longitude", "status", "moderationState"],
                ontology: "gogocompact"
            };

            console.log('returnData', returnData);

            return new Response(
                JSON.stringify(returnData),
            {
                status: 200,
                headers: new Headers({ 'Content-Type': 'application/json' })
            });
        }
    }
}

// Partial elements cache
workbox.routing.registerRoute(
    new RegExp('/api/elements'),
    new BoundsCacheStrategy({
        cacheName: 'partial-elements'
    })
);

// Tiles cache
workbox.routing.registerRoute(
    ({ url }) => TILES_DOMAIN_NAMES.some(domainName => url.hostname.includes(domainName)),
    new workbox.strategies.CacheFirst({
        cacheName: 'tiles',
        plugins: [
            new workbox.expiration.ExpirationPlugin({
                maxEntries: 200,
                maxAgeSeconds: 31 * 24 * 60 * 60,
                purgeOnQuotaError: true
            }),
            new workbox.cacheableResponse.CacheableResponsePlugin({ statuses: [0, 200] })
        ]
    })
);
workbox.precaching.precacheAndRoute([]);
// Following code not working, so using simple preCacheAndRoute (see above)
// workbox.precaching.precacheAndRoute(
//     self.__WB_MANIFEST,
//     // Ignore the ?ver= query, as the resources cached by the SW are automatically updated
//     { ignoreURLParametersMatching: [/^(ver|utm_.+)$/] }
// );
