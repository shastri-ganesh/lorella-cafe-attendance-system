/**
 * Lorella Speciality Cafe - Core Application JavaScript
 * Handles PWA service worker registration and client lifecycle.
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Service Worker Registration
    if ('serviceWorker' in navigator && (window.location.protocol === 'https:' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js')
                .then((registration) => {
                    console.log('Lorella PWA Service Worker registered with scope:', registration.scope);
                })
                .catch((err) => {
                    console.warn('Lorella PWA Service Worker registration failed:', err);
                });
        });
    }

    // 2. Connectivity Status Listeners
    window.addEventListener('online', () => {
        console.log('Network status: Online. Ready for attendance sync.');
    });

    window.addEventListener('offline', () => {
        console.warn('Network status: Offline. Attendance check-in/out requires live connection.');
    });

    // 3. PWA Install Prompt Hook
    let deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        window.lorellaInstallPrompt = deferredPrompt;
        console.log('PWA installation candidate available.');
    });
});
