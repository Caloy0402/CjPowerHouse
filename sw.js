const CACHE_NAME = 'cj-powerhouse-v1';
const urlsToCache = [
  '/Motorshop/LandingPage.php',
  '/Motorshop/Mobile-Dashboard.php',
  '/Motorshop/css/styles.css',
  '/Motorshop/landingpage.css',
  '/Motorshop/image/logo.png'
];

self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(function(cache) {
        return cache.addAll(urlsToCache);
      })
  );
});

self.addEventListener('fetch', function(event) {
  event.respondWith(
    caches.match(event.request)
      .then(function(response) {
        if (response) {
          return response;
        }
        return fetch(event.request);
      }
    )
  );
});

