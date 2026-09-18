<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InventoryMovementType;
use App\Support\Money;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Str;

/**
 * Seeds the reference data orders are built from: categories, customers,
 * products, and the opening stock receipt for every product.
 *
 * Rows are generated from static word lists and a seeded random number
 * generator rather than Faker, because Faker costs milliseconds per call and
 * this seeder writes hundreds of thousands of catalog rows.
 */
final class CatalogSeeder
{
    /**
     * Share of the catalog that exists on day one of the seeded window.
     *
     * The rest trickles in over the remaining days, so an order placed at any
     * point in the window always has a large catalog to draw from and never
     * references a product that did not exist yet.
     */
    private const BOOTSTRAP_SHARE = 0.5;

    /** Portion of the window the bootstrap catalog is created over. */
    private const BOOTSTRAP_WINDOW_SHARE = 0.08;

    /** Opening stock range, wide enough that no product can ever go negative. */
    private const OPENING_STOCK_MIN = 400;

    private const OPENING_STOCK_MAX = 4_000;

    /** Product price range in integer cents. */
    private const PRICE_MIN_CENTS = 199;

    private const PRICE_MAX_CENTS = 149_999;

    /** @var list<string> */
    private const CATEGORY_NAMES = [
        'Electronics', 'Books', 'Computer Accessories', 'Mobile Accessories', 'Home Appliances',
        'Office Supplies', 'Gaming Gear', 'Audio Equipment', 'Smart Home', 'Wearables',
        'Networking', 'Storage', 'Cameras', 'Lighting', 'Power & Cables',
        'Kitchen', 'Outdoors', 'Fitness', 'Toys', 'Stationery',
    ];

    /** @var list<string> */
    private const PRODUCT_ADJECTIVES = [
        'Compact', 'Wireless', 'Premium', 'Rugged', 'Portable', 'Ultra', 'Classic', 'Pro',
        'Lightweight', 'Industrial', 'Ergonomic', 'Modular',
    ];

    /** @var list<string> */
    private const PRODUCT_NOUNS = [
        'Keyboard', 'Mouse', 'Monitor', 'Headset', 'Dock', 'Cable', 'Stand', 'Charger',
        'Router', 'Speaker', 'Webcam', 'Hub', 'Adapter', 'Battery', 'Tripod', 'Lamp',
    ];

    /** @var list<string> */
    private const CUSTOMER_FIRST_NAMES = [
        'Amina', 'Bilal', 'Chen', 'Dara', 'Elif', 'Farid', 'Grace', 'Hana', 'Imran', 'Jonas',
        'Kiran', 'Lena', 'Mateo', 'Nadia', 'Omar', 'Priya', 'Rafael', 'Sana', 'Tariq', 'Yuki',
    ];

    /** @var list<string> */
    private const CUSTOMER_LAST_NAMES = [
        'Ahmed', 'Bakker', 'Costa', 'Diallo', 'Eriksen', 'Ferrari', 'Gomez', 'Haddad',
        'Ibrahim', 'Jensen', 'Kowalski', 'Lindqvist', 'Moreau', 'Novak', 'Oyelaran', 'Park',
    ];

    /**
     * @param  OutputStyle|null  $output  Console output for progress bars, if any.
     * @param  string  $userId  Demo user recorded as the creator of every row.
     * @param  int  $chunkSize  Rows per bulk insert.
     * @param  int  $windowStart  Unix timestamp the seeded history begins at.
     * @param  int  $now  Unix timestamp the seeded history ends at.
     */
    public function __construct(
        private readonly ?OutputStyle $output,
        private readonly string $userId,
        private readonly int $chunkSize,
        private readonly int $windowStart,
        private readonly int $now,
    ) {}

    /**
     * Get the timestamp by which the bootstrap catalog is fully created.
     *
     * Orders are seeded from this point onwards so every order can reference
     * products and customers that already exist.
     */
    public static function catalogReadyAt(int $windowStart, int $now): int
    {
        return $windowStart + (int) (($now - $windowStart) * self::BOOTSTRAP_WINDOW_SHARE);
    }

    /**
     * Seed categories, customers, products, and opening stock movements.
     */
    public function seed(int $categoryCount, int $customerCount, int $productCount): SeedCatalog
    {
        $categoryIds = $this->seedCategories($categoryCount);

        [$customerIds, $customerCreatedAt] = $this->seedCustomers($customerCount);

        return $this->seedProducts($productCount, $categoryIds, $customerIds, $customerCreatedAt);
    }

    /**
     * Seed the category rows and return their identifiers.
     *
     * @return list<string>
     */
    private function seedCategories(int $count): array
    {
        $inserter = new ChunkedInserter('categories', $this->chunkSize);
        $ids = [];

        // Categories exist before anything that references them.
        $span = (int) (($this->now - $this->windowStart) * 0.02);

        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::ulid();
            $ids[] = $id;

            $name = self::CATEGORY_NAMES[$i % count(self::CATEGORY_NAMES)];
            $suffix = intdiv($i, count(self::CATEGORY_NAMES));
            $name = $suffix === 0 ? $name : $name.' '.($suffix + 1);
            $createdAt = $this->formatAt($this->windowStart + (int) ($span * $i / max(1, $count)));

            $inserter->add([
                'id' => $id,
                'name' => $name,
                'slug' => Str::slug($name),
                'status' => $i % 17 === 0 && $i > 0 ? 'archived' : 'active',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'created_by' => $this->userId,
                'updated_by' => null,
            ]);

            if ($inserter->isFull()) {
                $inserter->flush();
            }
        }

        $inserter->flush();
        $this->report('categories', $inserter->written());

        return $ids;
    }

    /**
     * Seed the customer rows.
     *
     * @return array{0: list<string>, 1: list<int>}
     */
    private function seedCustomers(int $count): array
    {
        $inserter = new ChunkedInserter('customers', $this->chunkSize);
        $bar = $this->output?->createProgressBar($count);
        $bar?->setFormat(' seeding customers        %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');

        $ids = [];
        $createdAtList = [];
        $firstNames = count(self::CUSTOMER_FIRST_NAMES);
        $lastNames = count(self::CUSTOMER_LAST_NAMES);

        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::ulid();
            $createdAt = $this->spreadAt($i, $count);
            $ids[] = $id;
            $createdAtList[] = $createdAt;

            $name = self::CUSTOMER_FIRST_NAMES[$i % $firstNames].' '.self::CUSTOMER_LAST_NAMES[intdiv($i, $firstNames) % $lastNames];
            $formatted = $this->formatAt($createdAt);

            $inserter->add([
                'id' => $id,
                'name' => $name,
                'email' => sprintf('customer%06d@example.com', $i + 1),
                'phone' => '+8801'.str_pad((string) ($i + 1), 9, '0', STR_PAD_LEFT),
                'created_at' => $formatted,
                'updated_at' => $formatted,
                'created_by' => $this->userId,
                'updated_by' => null,
            ]);

            if ($inserter->isFull()) {
                $inserter->flush();
            }

            if ($i % 5_000 === 0) {
                $bar?->setProgress($i);
            }
        }

        $inserter->flush();
        $bar?->finish();
        $this->output?->newLine();
        $this->report('customers', $inserter->written());

        return [$ids, $createdAtList];
    }

    /**
     * Seed products plus the opening stock receipt recorded for each of them.
     *
     * @param  list<string>  $categoryIds
     * @param  list<string>  $customerIds
     * @param  list<int>  $customerCreatedAt
     */
    private function seedProducts(int $count, array $categoryIds, array $customerIds, array $customerCreatedAt): SeedCatalog
    {
        $products = new ChunkedInserter('products', $this->chunkSize);
        $movements = new ChunkedInserter('inventory_movements', $this->chunkSize);
        $bar = $this->output?->createProgressBar($count);
        $bar?->setFormat(' seeding products         %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');

        $ids = [];
        $prices = [];
        $createdAtList = [];
        $openingStock = [];

        $categories = count($categoryIds);
        $adjectives = count(self::PRODUCT_ADJECTIVES);
        $nouns = count(self::PRODUCT_NOUNS);
        $restock = InventoryMovementType::Restock->value;

        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::ulid();
            $createdAt = $this->spreadAt($i, $count);
            $priceCents = mt_rand(self::PRICE_MIN_CENTS, self::PRICE_MAX_CENTS);
            $opening = mt_rand(self::OPENING_STOCK_MIN, self::OPENING_STOCK_MAX);

            $ids[] = $id;
            $prices[] = $priceCents;
            $createdAtList[] = $createdAt;
            $openingStock[] = $opening;

            $name = self::PRODUCT_ADJECTIVES[$i % $adjectives].' '.self::PRODUCT_NOUNS[intdiv($i, $adjectives) % $nouns].' '.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT);
            $formatted = $this->formatAt($createdAt);
            $statusRoll = $i % 100;

            $products->add([
                'id' => $id,
                'category_id' => $categoryIds[$i % $categories],
                'name' => $name,
                'sku' => 'SKU-'.str_pad((string) ($i + 1), 7, '0', STR_PAD_LEFT),
                'description' => $name.' — stocked line item used for order processing and inventory reporting.',
                'price' => Money::centsToDollars($priceCents),
                'status' => match (true) {
                    $statusRoll < 4 => 'draft',
                    $statusRoll < 8 => 'archived',
                    default => 'active',
                },
                'created_at' => $formatted,
                'updated_at' => $formatted,
                'created_by' => $this->userId,
                'updated_by' => null,
            ]);

            // The opening balance is a ledger entry too, so replaying every
            // movement for a product reproduces its stock from zero.
            $movements->add([
                'id' => (string) Str::ulid(),
                'product_id' => $id,
                'order_id' => null,
                'type' => $restock,
                'quantity_delta' => $opening,
                'quantity_after' => $opening,
                'reserved_delta' => 0,
                'reserved_after' => 0,
                'created_at' => $formatted,
                'updated_at' => $formatted,
                'created_by' => $this->userId,
                'updated_by' => null,
            ]);

            if ($products->isFull() || $movements->isFull()) {
                $products->flush();
                $movements->flush();
            }

            if ($i % 5_000 === 0) {
                $bar?->setProgress($i);
            }
        }

        $products->flush();
        $movements->flush();
        $bar?->finish();
        $this->output?->newLine();
        $this->report('products', $products->written());
        $this->report('opening stock movements', $movements->written());

        return new SeedCatalog(
            customerIds: $customerIds,
            customerCreatedAt: $customerCreatedAt,
            productIds: $ids,
            productPriceCents: $prices,
            productCreatedAt: $createdAtList,
            openingStock: $openingStock,
        );
    }

    /**
     * Get the creation timestamp for the given catalog index.
     *
     * The first half of the catalog is created during the short bootstrap
     * window, the remainder is spread evenly across the rest of the period.
     * The result is non-decreasing in $index, so catalog arrays stay sorted by
     * creation time and the order seeder can walk them with a cursor.
     */
    private function spreadAt(int $index, int $count): int
    {
        $span = $this->now - $this->windowStart;
        $bootstrapSpan = (int) ($span * self::BOOTSTRAP_WINDOW_SHARE);
        $bootstrapCount = max(1, (int) ($count * self::BOOTSTRAP_SHARE));

        if ($index < $bootstrapCount) {
            return $this->windowStart + (int) ($bootstrapSpan * $index / $bootstrapCount);
        }

        $remaining = max(1, $count - $bootstrapCount);

        return $this->windowStart + $bootstrapSpan
            + (int) (($span - $bootstrapSpan) * ($index - $bootstrapCount) / $remaining);
    }

    /**
     * Format a unix timestamp for a datetime column.
     */
    private function formatAt(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Print how many rows a phase wrote.
     */
    private function report(string $label, int $rows): void
    {
        $this->output?->writeln(sprintf('  <info>%s</info>: %s rows', $label, number_format($rows)));
    }
}
