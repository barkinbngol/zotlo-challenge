- **Merkezi Zotlo SDK Katmanı**  
  Tüm API çağrıları `App\Services\ZotloClientService` içinde toplandı; ortam (prod/test) otomatik seçiliyor, kapsamlı logging ve hata sarmalama mevcut.

- **İdempotent Senkronizasyon Worker’ı**  
  `sync-zotlo-subscriptions` artisan komutu ile Zotlo ↔️ lokal durum eşitleniyor.  
  - `--chunk` ve `--dry-run` opsiyonlarıyla güvenli ve performanslı çalışıyor.  
  - Scheduler container’ı ile dakikalık cron çalışması docker-compose içinde kalıcı hale getirildi.

- **Webhooks ile Anında Güncelleme**  
  Zotlo’dan gelen abonelik değişiklikleri `/api/webhook/zotlo` endpoint’iyle alınıp DB’de anında işleniyor; payload log’lanıyor.

- **DB Tarafında İş Kurallarıyla Uyumlu Benzersiz İndeks**  
  “Bir kullanıcıda sadece 1 aktif abonelik” kuralı SQL seviyesinde `uniq_active_user` fonksiyonel index (generated expression) ile garanti altına alındı.

- **Performans Odaklı İndeksleme**  
  Sık kullanılan sorgular için `user_id,status`, `created_at` gibi indeksler eklendi; milyonlarca kayıt hedefi için `chunkById` kullanıldı.

- **Günlük Rapor CLI Komutu (Bonus)**  
  `report:subscriptions` komutu ile belirlenen tarih aralığında yeni/bitmiş/yenilenmiş abonelik sayıları SQL üzerinden hızlıca raporlanıyor.

- **Kayıtlı Kart Listesi Servisi**  
  Aboneye ait kayıtlı kartların token’ları `/api/cards` ile dönülüyor; ödeme adımlarında tekrar kart bilgisi istemeden kullanım mümkün.

- **Tüm API’lerde Tutarlı JSON & Validasyon**  
  Request’ler Laravel validator ile doğrulanıyor, hatalar detaylı ama güvenli biçimde dönülüyor.

- **Docker Bazlı Geliştirme Ortamı**  
  Nginx + PHP-FPM + MySQL + phpMyAdmin + Scheduler kapsülleri ile tek komutla ayağa kaldırılabilen izole geliştirme ortamı.

  ## 🚀 Kurulum & Çalıştırma

# 1. env dosyasını hazırla:

    cp src/.env.example src/.env

    APP_URL=http://localhost
    DB_HOST=db
    DB_DATABASE=zotlo
    DB_USERNAME=root
    DB_PASSWORD=root

    JWT_SECRET= #

    ZOTLO_APP_ID=128
    ZOTLO_ACCESS_KEY=xxxxxxxx
    ZOTLO_ACCESS_SECRET=yyyyyyyy
    ZOTLO_PACKAGE_ID=zotlo.premium


# 2. Docker’ı ayağa kaldır:

    docker-compose up -d --build

# 3. Composer yükle:

    docker compose exec app composer install


# 4. Uygulama anahtarları:

    docker-compose exec app php artisan key:generate
    docker-compose exec app php artisan jwt:secret

# 5. Veritabanı migrasyonları:

    docker-compose exec app php artisan migrate

# 6. Cache / optimizasyon:

    docker-compose exec app php artisan optimize:clear
    docker-compose exec app php artisan config:cache route:cache

# 7. Test isteği (örnek)

Register: POST http://localhost:8000/api/register

Login: POST http://localhost:8000/api/login

Abone Ol: POST http://localhost:8000/api/subscribe (Authorization: Bearer <token>)

İptal: POST http://localhost:8000/api/subscription/cancel (Authorization: Bearer <token>)

Kart Listesi: GET http://localhost:8000/api/cards (Authorization: Bearer <token>)



