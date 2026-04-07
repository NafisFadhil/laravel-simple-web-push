import './bootstrap';

function setStatus(text) {
    const el = document.getElementById('wp-status');
    if (!el) return;
    el.textContent = text ?? '';
}

function getVapidPublicKey() {
    return document.querySelector('meta[name="vapid-public-key"]')?.getAttribute('content') || '';
}

function base64UrlToUint8Array(base64UrlString) {
    const padding = '='.repeat((4 - (base64UrlString.length % 4)) % 4);
    const base64 = (base64UrlString + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

async function ensureServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        throw new Error('Service Worker not supported in this browser.');
    }
    const reg = await navigator.serviceWorker.register('/sw.js');
    return reg;
}

async function ensurePermission() {
    if (!('Notification' in window)) {
        throw new Error('Notification API not supported in this browser.');
    }
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') {
        throw new Error(`Notification permission is ${perm}`);
    }
}

async function subscribeWebPush() {
    setStatus('Registering service worker...');
    const reg = await ensureServiceWorker();

    setStatus('Requesting notification permission...');
    await ensurePermission();

    const vapidPublicKey = getVapidPublicKey();
    if (!vapidPublicKey) {
        throw new Error('Missing VAPID public key (meta[name="vapid-public-key"]).');
    }

    setStatus('Subscribing to push manager...');
    const existing = await reg.pushManager.getSubscription();
    const subscription =
        existing ||
        (await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlToUint8Array(vapidPublicKey),
        }));

    setStatus('Saving subscription to server...');
    const res = await window.axios.post('/webpush/subscribe', subscription);

    setStatus(`Subscribed. Server id: ${res.data?.id ?? '(unknown)'}`);
}

async function sendTestNotification() {
    setStatus('Sending notification...');
    const res = await window.axios.post('/webpush/send', {
        title: 'Web Push',
        body: `Notifikasi dari Laravel (${new Date().toLocaleString()})`,
        url: window.location.href,
    });

    setStatus(JSON.stringify(res.data, null, 2));
}

function wireButtons() {
    const btnSubscribe = document.getElementById('wp-subscribe');
    const btnSend = document.getElementById('wp-send');
    if (!btnSubscribe || !btnSend) return;

    btnSend.disabled = false;

    btnSubscribe.addEventListener('click', async () => {
        btnSubscribe.disabled = true;
        try {
            await subscribeWebPush();
        } catch (e) {
            setStatus(e?.message || String(e));
        } finally {
            btnSubscribe.disabled = false;
        }
    });

    btnSend.addEventListener('click', async () => {
        btnSend.disabled = true;
        try {
            await sendTestNotification();
        } catch (e) {
            setStatus(e?.response?.data ? JSON.stringify(e.response.data, null, 2) : (e?.message || String(e)));
        } finally {
            btnSend.disabled = false;
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    wireButtons();
});
