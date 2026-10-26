# חיפוש חכם בקטלוג מוצרים

תרגיל בית ל-HTZone: חיפוש בעברית ובאנגלית לפי שם ולפי משמעות, על קטלוג של 1000 מוצרים.

> 🚧 בעבודה. ה-README המלא (החלטות, בדיקות, מגבלות ועבודה עם Claude) ייכתב בסיום.
> בינתיים: [docs/decisions.md](docs/decisions.md) · [docs/claude-log.md](docs/claude-log.md)

## הרצה מקומית

דרישות: Docker, PHP 8.3+, Composer, Node.

```bash
composer install
cp .env.example .env            # ולהוסיף OPENAI_API_KEY
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed       # טעינת הקטלוג מ-data/catalog.csv
./vendor/bin/sail artisan catalog:embed        # וקטורים (OpenAI)
npm install && npm run build
```

- אתר: http://localhost
- API: `GET /api/search?q=אייפון&page=1`
- phpMyAdmin: http://localhost:8080

## בדיקות

```bash
./vendor/bin/sail artisan test           # Pest
./vendor/bin/sail artisan search:eval    # סט הזהב: רלוונטיות וזמנים
```
