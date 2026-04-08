## Web Push Sederhana di Laravel + Vite

### 1. Ringkasan Fitur

Project ini menambahkan fitur **Web Push Notification** ke Laravel 13 menggunakan:

- **Service Worker**: `public/sw.js`
- **Frontend**: `resources/js/app.js`, UI di `resources/views/welcome.blade.php`
- **Backend**: `app/Http/Controllers/WebPushController.php`
- **DB**: tabel `push_subscriptions` (model `App\Models\PushSubscription`)
- **Library push**: `minishlink/web-push` (VAPID / Push API standar)

Alur singkat:

1. User buka `/`, klik **Aktifkan** → browser register service worker, minta izin notifikasi, subscribe Push → kirim subscription ke Laravel.
2. Klik **Kirim** → Laravel kirim push ke semua subscription via VAPID → service worker terima event `push` dan menampilkan notifikasi.
3. Tombol **Tes lokal** memunculkan notifikasi langsung dari service worker (tanpa lewat Push Service), untuk debugging izin OS/Chrome.

---

### 2. Prasyarat

- PHP 8.3+
- Composer
- Node.js + npm
- Browser yang mendukung Web Push:
  - **Didukung**: Chrome, Edge, Firefox (desktop)
  - **Tidak ditargetkan di demo ini**: Safari (pakai APNs, protokol berbeda)

---

### 3. Setup Project

1. Clone & install dependency:

   ```bash
   composer install
   npm install
   ```

2. Buat file `.env` & kunci aplikasi:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Migrate database (default: SQLite):

   ```bash
   touch database/database.sqlite
   php artisan migrate
   ```

---

### 4. Konfigurasi VAPID (Wajib untuk Web Push)

1. Generate VAPID keys:

   ```bash
   php -r 'require "vendor/autoload.php"; $k=Minishlink\\WebPush\\VAPID::createVapidKeys(); echo "VAPID_PUBLIC_KEY=".$k["publicKey"].PHP_EOL."VAPID_PRIVATE_KEY=".$k["privateKey"].PHP_EOL;'
   ```

2. Isi ke `.env`:

   ```env
   VAPID_PUBLIC_KEY=ISI_DARI_PERINTAH_DI_ATAS
   VAPID_PRIVATE_KEY=ISI_DARI_PERINTAH_DI_ATAS
   VAPID_SUBJECT=mailto:you@example.com
   ```

   Jangan gunakan placeholder seperti `publickey` / `privatekey`. Jika salah, di browser akan muncul error:

   > Failed to execute 'atob' on 'Window': The string to be decoded is not correctly encoded

3. Reload config:

   ```bash
   php artisan config:clear
   ```

Config VAPID dibaca dari `config/webpush.php`, lalu dipakai di `WebPushController`.

---

### 5. Menjalankan Aplikasi (Localhost)

1. Jalankan Laravel:

   ```bash
   php artisan serve --host=127.0.0.1 --port=8000
   ```

2. Jalankan Vite dev server:

   ```bash
   npm run dev
   ```

3. Buka browser di:

   - `http://127.0.0.1:8000/` (atau `http://localhost:8000`, pilih satu dan konsisten).

4. Pastikan izin notifikasi:

   - **Chrome**:
     - `chrome://settings/content/notifications` → origin localhost/127.0.0.1 harus di **Allow**.
   - **macOS**:
     - `System Settings → Notifications → Google Chrome` → **Allow notifications**, alert style bukan “None”.
     - Nonaktifkan sementara **Do Not Disturb / Focus**.

---

### 6. Cara Menggunakan Fitur Web Push

Di halaman `/` terdapat card **Web Push** dengan 3 tombol:

1. **Aktifkan**

   - Mendaftarkan service worker `sw.js`.
   - Meminta izin `Notification`.
   - Menjalankan:

     ```js
     reg.pushManager.subscribe({
       userVisibleOnly: true,
       applicationServerKey: base64UrlToUint8Array(VAPID_PUBLIC_KEY),
     });
     ```

   - Mengirim subscription ke backend:
     - Endpoint: `POST /webpush/subscribe`
     - Disimpan ke tabel `push_subscriptions` (kolom `endpoint`, `p256dh`, `auth`).

2. **Tes lokal**

   - Tidak menggunakan FCM / Push Service.
   - Memanggil langsung:

     ```js
     const reg = await navigator.serviceWorker.ready;
     await reg.showNotification('Tes lokal (tanpa push)', { ... });
     ```

   - Kalau tombol ini **tidak memunculkan notifikasi**, masalah ada pada:
     - Izin notifikasi di OS / browser, atau
     - Profil browser (bukan di server Web Push).

3. **Kirim**

   - Memanggil endpoint `POST /webpush/send` dengan payload:

     ```json
     {
       "title": "Web Push",
       "body": "Notifikasi dari Laravel ...",
       "url": "CURRENT_PAGE_URL"
     }
     ```

   - Backend (`WebPushController@send`) akan:
     - Membaca semua subscription dari DB.
     - **Skip** endpoint Safari (`https://web.push.apple.com/...`) karena memakai APNs.
     - Mengirim payload ke setiap subscription via `minishlink/web-push`.
     - Menghapus subscription yang expired (HTTP 404/410).
     - Mengembalikan JSON:

       ```json
       {
         "ok": true,
         "sent": 1,
         "failed": 0,
         "skipped": 0,
         "skippedEndpoints": [],
         "errors": []
       }
       ```

4. **Status**

   - `wp-status` (`<pre id="wp-status">`) menampilkan:
     - Proses subscribe / send.
     - Respon JSON dari `/webpush/subscribe` dan `/webpush/send`.

---

### 7. Detail Kode Penting

**Service worker**: `public/sw.js`

- `install` + `activate`: `skipWaiting()` dan `clients.claim()` agar SW cepat aktif.
- `push`:
  - Parse payload JSON, fallback ke text.
  - `console.log('[sw] push', {...})` untuk debug via DevTools → Application → Service Workers → console.
  - `showNotification(title, { body, tag, renotify, requireInteraction, data: { url } })`.
- `notificationclick`:
  - Menutup notifikasi.
  - Fokus/navigate tab yang sudah ada atau `clients.openWindow(url)`.

**Frontend**: `resources/js/app.js`

- `ensureServiceWorker()`: register `sw.js` dan mengembalikan `ServiceWorkerRegistration`.
- `ensurePermission()`: `Notification.requestPermission()`.
- `subscribeWebPush()`: memanggil `pushManager.subscribe`, kirim hasilnya ke `/webpush/subscribe`.
- `sendTestNotification()`: mengirim POST ke `/webpush/send`.
- `showLocalTestNotification()`: men-trigger `reg.showNotification()` langsung (tanpa push).
- `wireButtons()`: menghubungkan tombol **Aktifkan**, **Tes lokal**, dan **Kirim**.

**Backend**:

- Model: `app/Models/PushSubscription.php`
- Migration: `database/migrations/0001_01_01_000003_create_push_subscriptions_table.php`
- Controller: `app/Http/Controllers/WebPushController.php`
  - `subscribe(Request $request)`:
    - Validasi payload subscription (`endpoint`, `keys.p256dh`, `keys.auth`).
    - `updateOrCreate` berdasarkan `endpoint`.
  - `send(Request $request)`:
    - Validasi optional `title`, `body`, `url`.
    - Setup `WebPush` dengan VAPID keys dari `config/webpush.php`.
    - Skip endpoint Safari.
    - `flush()` dan hitung `sent`, `failed`, `skipped`.
    - Hapus subscription yang expired (404/410).
- Routes: `routes/web.php`:

  ```php
  Route::post('/webpush/subscribe', [WebPushController::class, 'subscribe']);
  Route::post('/webpush/send', [WebPushController::class, 'send']);
  ```

---

### 8. Jalan di Domain Publik (ngrok / HTTPS lain)

Web Push butuh **HTTPS**. Untuk testing:

1. Jalankan tunnel (contoh ngrok):

   ```bash
   ngrok http 8000
   ```

2. Set `APP_URL` di `.env` ke URL ngrok, misalnya:

   ```env
   APP_URL=https://xxxx-xx-xx-xx.ngrok-free.app
   ```

3. Pastikan VAPID keys sudah benar di `.env`, lalu:

   ```bash
   php artisan config:clear
   ```

4. Buka URL ngrok di browser, klik **Aktifkan** lagi (origin baru → subscription baru).

Kalau muncul error `atob` di browser, cek lagi:

- `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` **bukan** placeholder.
- Tidak ada spasi/karakter aneh di awal/akhir value.

---

### 9. Batasan & Catatan

- Safari tidak didukung di demo ini (butuh integrasi APNs).
- Disarankan testing utama di Chrome / Edge / Firefox desktop.
- Jika:
  - `/webpush/send` mengembalikan `ok: true`, **dan**
  - Di console service worker terlihat log `[sw] push {...}`,
  - namun tidak ada banner,
  - maka masalah ada di pengaturan notifikasi OS / browser, bukan di kode.
