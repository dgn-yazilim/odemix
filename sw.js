// PORTAL Service Worker — Web Push bildirimleri

self.addEventListener('push', function(event) {
    event.waitUntil(
        Promise.all([
            // Portal genel bildirimleri (yeni müşteri, vb.) — ekip mesajlaşması 2026-09-23'te kaldırıldı
            fetch('/push_bildirim.php?api=sw', { credentials: 'same-origin' })
                .then(function(r) { return r.ok ? r.json() : []; })
                .catch(function() { return []; })
        ]).then(function(sonuclar) {
            var bildirimler = sonuclar[0] || [];
            var islemler    = [];

            // Portal bildirimleri
            bildirimler.forEach(function(b) {
                var ikonlar = { musteri: '👤', genel: '🔔', duyuru: '📢' };
                var ikon    = ikonlar[b.tip] || '🔔';
                islemler.push(
                    self.registration.showNotification(ikon + ' ' + b.baslik, {
                        body:             b.govde || '',
                        icon:             '/portal_icon.png',
                        badge:            '/portal_badge.png',
                        data:             { url: self.registration.scope + (b.url || 'dashboard.php') },
                        tag:              'portal-bildirim-' + b.id,
                        requireInteraction: true,
                        vibrate:          [200, 100, 200],
                    })
                );
            });

            // Bildirim yoksa genel bildirim
            if (islemler.length === 0) {
                islemler.push(
                    self.registration.showNotification('🔔 PORTAL', {
                        body: 'Yeni bildiriminiz var.',
                        icon: '/portal_icon.png',
                        data: { url: self.registration.scope + 'dashboard.php' },
                        tag:  'portal-genel',
                    })
                );
            }

            return Promise.all(islemler);
        })
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url)
        || self.registration.scope + 'dashboard.php';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
            for (var i = 0; i < list.length; i++) {
                if ('focus' in list[i]) return list[i].focus();
            }
            return clients.openWindow(url);
        })
    );
});

self.addEventListener('activate', function(event) {
    event.waitUntil(clients.claim());
});
