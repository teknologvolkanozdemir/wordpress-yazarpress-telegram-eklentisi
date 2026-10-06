# YazarPress Telegram

WordPress sitenizde Telegram botu aracılığıyla yazı, sayfa ve kategori oluşturun.

## Kurulum

1. `yazarpress-telegram.php` dosyasını `wp-content/plugins/yazarpress-telegram/` klasörüne yükleyin ve eklentiyi etkinleştirin.
2. WordPress yönetim panelinde **Ayarlar → YazarPress Telegram** sayfasını açın.
3. Telegram bot tokenını ve botun kullanılacağı sohbetin sayısal chat ID değerini girip kaydedin. İki değer de zorunludur.
4. Sitenizin HTTPS üzerinden erişilebilir olması gerekir. Ayarlar kaydedildiğinde eklenti Telegram webhook'unu otomatik kaydeder.

Webhook yalnızca kayıtlı chat ID'den gelen mesajları işler ve Telegram'ın webhook gizli anahtarını doğrular.

## Bot komutları

- `/start`: Kullanılabilir komutları gösterir.
- `/yazi`: Başlık, içerik ve mevcut kategori adını sırayla ister. Son adımda `e` yayınlar, `t` taslak olarak kaydeder, `s` işlemi iptal eder.
- `/sayfa`: Başlık ve içerik ister; ardından aynı yayınlama seçeneklerini sunar.
- `/kategori`: Kategori adını ister ve yeni kategori oluşturur.
- `/iptal`: Devam eden işlemi iptal eder.

Yazı ve sayfa oluşturma sırasında verilen başlık ve içerik WordPress'e kaydedilir. İşlem tamamlanmadan önce `/iptal` gönderilebilir.
